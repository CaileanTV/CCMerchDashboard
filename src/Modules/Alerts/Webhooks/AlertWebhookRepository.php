<?php
/**
 * Storage of the creators' webhooks.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_alert_webhooks` (schema version 27, ADR-073). Times are UTC.
 */
class AlertWebhookRepository {

	private const COLUMNS = 'id, creator_id, name, format, url_encrypted, url_host, url_hint, secret_encrypted, template, enabled, failures, paused_at, last_delivery_at, created_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Adds a webhook, switched on.
	 *
	 * @param int         $creator_id       Creator.
	 * @param string      $name             Cleaned name.
	 * @param string      $format           AlertWebhook::JSON or DISCORD.
	 * @param string      $url_encrypted    The address, encrypted.
	 * @param string      $url_host         Host.
	 * @param string      $url_hint         Hint.
	 * @param string|null $secret_encrypted The secret, encrypted; null for Discord.
	 * @param string      $template         Discord's template.
	 * @return int Id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( int $creator_id, string $name, string $format, string $url_encrypted, string $url_host, string $url_hint, ?string $secret_encrypted, string $template ): int {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'creator_id'    => $creator_id,
			'name'          => $name,
			'format'        => $format,
			'url_encrypted' => $url_encrypted,
			'url_host'      => $url_host,
			'url_hint'      => $url_hint,
			'template'      => $template,
			'enabled'       => 1,
			'failures'      => 0,
			'created_at'    => $now,
			'updated_at'    => $now,
		);

		if ( null !== $secret_encrypted ) {
			$data['secret_encrypted'] = $secret_encrypted;
		}

		if ( false === $this->wpdb->insert( $this->table(), $data ) || '' !== $this->wpdb->last_error ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			throw new DatabaseException( 'Could not store the webhook.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * A webhook, or null.
	 *
	 * @param int $id Webhook.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find( int $id ): ?AlertWebhook {
		$rows = $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d LIMIT 1', $this->table(), $id );

		return isset( $rows[0] ) ? self::hydrate( $rows[0] ) : null;
	}

	/**
	 * One of a creator's webhooks, or null.
	 *
	 * @param int $creator_id Creator.
	 * @param int $id         Webhook.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find_for_creator( int $creator_id, int $id ): ?AlertWebhook {
		$rows = $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d AND creator_id = %d LIMIT 1', $this->table(), $id, $creator_id );

		return isset( $rows[0] ) ? self::hydrate( $rows[0] ) : null;
	}

	/**
	 * A creator's webhooks, oldest first.
	 *
	 * @param int $creator_id Creator.
	 * @return list<AlertWebhook>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function for_creator( int $creator_id ): array {
		return array_map( self::hydrate( ... ), $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d ORDER BY id', $this->table(), $creator_id ) );
	}

	/**
	 * Every webhook, by creator (the administrator's overview).
	 *
	 * @return list<AlertWebhook>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function all(): array {
		return array_map( self::hydrate( ... ), $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i ORDER BY creator_id, id', $this->table() ) );
	}

	/**
	 * Changes a webhook.
	 *
	 * @param int                  $id     Webhook.
	 * @param array<string, mixed> $values Columns.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update( int $id, array $values ): void {
		$values['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		if ( false === $this->wpdb->update( $this->table(), $values, array( 'id' => $id ) ) || '' !== $this->wpdb->last_error ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			throw new DatabaseException( 'Could not change the webhook.' );
		}
	}

	/**
	 * Notes a delivery's outcome: a success clears the failures; a failure
	 * counts one more and pauses the webhook at $pause_after in a row.
	 *
	 * @param int               $id          Webhook.
	 * @param bool              $success     Delivered.
	 * @param int               $pause_after Failures in a row that pause it.
	 * @param DateTimeImmutable $now         Now.
	 * @return bool Whether it was paused now.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function record( int $id, bool $success, int $pause_after, DateTimeImmutable $now ): bool {
		$time   = $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$wpdb   = $this->wpdb;
		$paused = null !== $this->find( $id )?->paused_at;
		// MySQL assigns left to right: the pause sees the failures counted already.
		$sql = $success
			? $wpdb->prepare( 'UPDATE %i SET failures = 0, last_delivery_at = %s WHERE id = %d', $this->table(), $time, $id )
			: $wpdb->prepare( 'UPDATE %i SET failures = failures + 1, last_delivery_at = %s, paused_at = IF(paused_at IS NULL AND failures >= %d, %s, paused_at) WHERE id = %d', $this->table(), $time, $pause_after, $time, $id );

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the webhook update.' );
		}

		if ( false === $wpdb->query( $sql ) || '' !== $wpdb->last_error ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; a custom table.
			throw new DatabaseException( 'Could not note the webhook delivery.' );
		}

		return ! $paused && null !== $this->find( $id )?->paused_at;
	}

	/**
	 * Deletes a webhook and its deliveries.
	 *
	 * @param int $id Webhook.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $id ): void {
		$wpdb = $this->wpdb;

		if ( false === $wpdb->delete( Tables::name( $wpdb, Tables::ALERT_WEBHOOK_DELIVERIES ), array( 'webhook_id' => $id ), array( '%d' ) ) || false === $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ) || '' !== $wpdb->last_error ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom tables.
			throw new DatabaseException( 'Could not delete the webhook.' );
		}
	}

	/**
	 * Rows of a prepared query.
	 *
	 * @phpstan-param literal-string $query
	 *
	 * @param string     $query   Query with placeholders.
	 * @param int|string ...$args Values.
	 * @return list<array<mixed>>
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function rows( string $query, int|string ...$args ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared; a custom table.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the webhooks.' );
		}

		return $rows;
	}

	/**
	 * A webhook from a row.
	 *
	 * @param array<mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): AlertWebhook {
		$utc  = new DateTimeZone( 'UTC' );
		$time = static fn( mixed $value ): ?DateTimeImmutable => is_string( $value ) ? new DateTimeImmutable( $value, $utc ) : null;
		$text = static fn( string $key ): string => is_string( $row[ $key ] ?? null ) ? $row[ $key ] : '';
		$int  = static fn( string $key ): int => is_numeric( $row[ $key ] ?? null ) ? (int) $row[ $key ] : 0;

		try {
			$created   = $time( $row['created_at'] ?? null ) ?? new DateTimeImmutable( 'now', $utc );
			$paused    = $time( $row['paused_at'] ?? null );
			$delivered = $time( $row['last_delivery_at'] ?? null );
		} catch ( Exception ) {
			throw new DatabaseException( 'Invalid webhook row.' );
		}

		return new AlertWebhook(
			$int( 'id' ),
			$int( 'creator_id' ),
			$text( 'name' ),
			$text( 'format' ),
			$text( 'url_encrypted' ),
			$text( 'url_host' ),
			$text( 'url_hint' ),
			is_string( $row['secret_encrypted'] ?? null ) ? $row['secret_encrypted'] : null,
			$text( 'template' ),
			1 === $int( 'enabled' ),
			$int( 'failures' ),
			$paused,
			$delivered,
			$created
		);
	}

	/**
	 * The webhooks' table.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::ALERT_WEBHOOKS );
	}
}
