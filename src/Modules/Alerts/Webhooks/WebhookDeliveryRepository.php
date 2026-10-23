<?php
/**
 * Storage of the webhooks' deliveries.
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
 * `merchboard_alert_webhook_deliveries` (schema version 27, ADR-073): the
 * newest KEEP deliveries per webhook. Times are UTC.
 */
class WebhookDeliveryRepository {

	/**
	 * Deliveries kept per webhook.
	 */
	public const KEEP = 50;

	private const COLUMNS = 'id, webhook_id, creator_id, event_id, kind, order_ref, payload, status, http_status, error, created_at, sent_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Adds a pending delivery and drops the oldest beyond KEEP.
	 *
	 * @param AlertWebhook $webhook   Webhook.
	 * @param string       $event_id  Event id.
	 * @param string       $kind      purchase, test or resend.
	 * @param string       $order_ref Order reference.
	 * @param string       $payload   Body.
	 * @return int Id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( AlertWebhook $webhook, string $event_id, string $kind, string $order_ref, string $payload ): int {
		$wpdb = $this->wpdb;
		$done = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			$this->table(),
			array(
				'webhook_id' => $webhook->id,
				'creator_id' => $webhook->creator_id,
				'event_id'   => $event_id,
				'kind'       => $kind,
				'order_ref'  => $order_ref,
				'payload'    => $payload,
				'status'     => WebhookDelivery::PENDING,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $done || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the webhook delivery.' );
		}

		$id = (int) $wpdb->insert_id;
		$this->prune( $webhook->id );

		return $id;
	}

	/**
	 * A delivery, or null.
	 *
	 * @param int $id Delivery.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find( int $id ): ?WebhookDelivery {
		$rows = $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d LIMIT 1', $this->table(), $id );

		return isset( $rows[0] ) ? self::hydrate( $rows[0] ) : null;
	}

	/**
	 * A creator's newest deliveries.
	 *
	 * @param int $creator_id Creator.
	 * @param int $limit      Most.
	 * @return list<WebhookDelivery>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function recent( int $creator_id, int $limit ): array {
		return array_map( self::hydrate( ... ), $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d ORDER BY id DESC LIMIT %d', $this->table(), $creator_id, max( 1, $limit ) ) );
	}

	/**
	 * Notes the outcome of a delivery.
	 *
	 * @param int         $id          Delivery.
	 * @param string      $status      WebhookDelivery constant.
	 * @param int|null    $http_status The answer's status code.
	 * @param string|null $error       Why it failed (at most 190 characters).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function finish( int $id, string $status, ?int $http_status, ?string $error ): void {
		$values = array(
			'status'  => $status,
			'sent_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( null !== $http_status ) {
			$values['http_status'] = $http_status;
		}

		if ( null !== $error ) {
			$values['error'] = mb_substr( $error, 0, 190 );
		}

		if ( false === $this->wpdb->update( $this->table(), $values, array( 'id' => $id ) ) || '' !== $this->wpdb->last_error ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			throw new DatabaseException( 'Could not note the webhook delivery.' );
		}
	}

	/**
	 * Drops a webhook's deliveries beyond the newest KEEP.
	 *
	 * @param int $webhook_id Webhook.
	 * @throws DatabaseException When they cannot be deleted.
	 */
	private function prune( int $webhook_id ): void {
		$wpdb = $this->wpdb;
		$keep = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE webhook_id = %d ORDER BY id DESC LIMIT 1 OFFSET %d', $this->table(), $webhook_id, self::KEEP - 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A custom table.

		if ( ! is_numeric( $keep ) ) {
			return;
		}

		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE webhook_id = %d AND id < %d', $this->table(), $webhook_id, (int) $keep );

		if ( null === $sql || false === $wpdb->query( $sql ) || '' !== $wpdb->last_error ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; a custom table.
			throw new DatabaseException( 'Could not drop old webhook deliveries.' );
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
			throw new DatabaseException( 'Could not read the webhook deliveries.' );
		}

		return $rows;
	}

	/**
	 * A delivery from a row.
	 *
	 * @param array<mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): WebhookDelivery {
		$utc  = new DateTimeZone( 'UTC' );
		$text = static fn( string $key ): string => is_string( $row[ $key ] ?? null ) ? $row[ $key ] : '';
		$int  = static fn( string $key ): int => is_numeric( $row[ $key ] ?? null ) ? (int) $row[ $key ] : 0;

		try {
			$created = new DateTimeImmutable( '' !== $text( 'created_at' ) ? $text( 'created_at' ) : 'now', $utc );
			$sent    = '' !== $text( 'sent_at' ) ? new DateTimeImmutable( $text( 'sent_at' ), $utc ) : null;
		} catch ( Exception ) {
			throw new DatabaseException( 'Invalid webhook delivery row.' );
		}

		return new WebhookDelivery(
			$int( 'id' ),
			$int( 'webhook_id' ),
			$int( 'creator_id' ),
			$text( 'event_id' ),
			$text( 'kind' ),
			$text( 'order_ref' ),
			$text( 'payload' ),
			$text( 'status' ),
			is_numeric( $row['http_status'] ?? null ) ? (int) $row['http_status'] : null,
			is_string( $row['error'] ?? null ) ? $row['error'] : null,
			$created,
			$sent
		);
	}

	/**
	 * The deliveries' table.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::ALERT_WEBHOOK_DELIVERIES );
	}
}
