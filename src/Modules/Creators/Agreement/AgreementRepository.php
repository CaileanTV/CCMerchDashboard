<?php
/**
 * Storage of the agreement's versions and the creators' acceptances.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Agreement;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_agreement_versions` and `merchboard_agreement_acceptances`
 * (ADR-092). Both are only added to: a published version and an acceptance
 * are never changed or deleted (the opt-in uninstall keeps them).
 */
class AgreementRepository {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The newest version, or null before the first is published.
	 *
	 * @throws DatabaseException When it cannot be read.
	 */
	public function latest(): ?AgreementVersion {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT version, body, material, published_at FROM %i ORDER BY version DESC LIMIT 1', $this->table( Tables::AGREEMENT_VERSIONS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; read when an agreement is shown.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the agreement.' );
		}

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The versions with their number of acceptances, newest first.
	 *
	 * @return list<array{version: AgreementVersion, acceptances: int}>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function versions(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT v.version, v.body, v.material, v.published_at, COUNT( a.id ) AS acceptances FROM %i v LEFT JOIN %i a ON a.version = v.version GROUP BY v.version, v.body, v.material, v.published_at ORDER BY v.version DESC', $this->table( Tables::AGREEMENT_VERSIONS ), $this->table( Tables::AGREEMENT_ACCEPTANCES ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables; admin screen.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the agreement versions.' );
		}

		return array_map(
			static fn( array $row ): array => array(
				'version'     => self::hydrate( $row ),
				'acceptances' => (int) ( $row['acceptances'] ?? 0 ),
			),
			$rows
		);
	}

	/**
	 * The newest version a creator must accept: the newest material
	 * version, or 0 when none is published.
	 *
	 * @throws DatabaseException When it cannot be read.
	 */
	public function latest_material(): int {
		$wpdb    = $this->wpdb;
		$version = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX( version ) FROM %i WHERE material = 1', $this->table( Tables::AGREEMENT_VERSIONS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; a live check.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the agreement.' );
		}

		return is_numeric( $version ) ? (int) $version : 0;
	}

	/**
	 * Publishes a version (the next number).
	 *
	 * @param string            $body     Sanitized text.
	 * @param bool              $material Whether it must be accepted again.
	 * @param int|null          $user_id  Who publishes.
	 * @param DateTimeImmutable $now      Now (UTC).
	 * @return int The version.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function publish( string $body, bool $material, ?int $user_id, DateTimeImmutable $now ): int {
		$version = ( $this->latest()->version ?? 0 ) + 1;
		$stored  = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			$this->table( Tables::AGREEMENT_VERSIONS ),
			array(
				'version'      => $version,
				'body'         => $body,
				'material'     => $material ? 1 : 0,
				'published_at' => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'published_by' => $user_id,
			),
			array( '%d', '%s', '%d', '%s', '%d' )
		);

		if ( 1 !== $stored ) {
			throw new DatabaseException( 'Could not publish the agreement.' );
		}

		return $version;
	}

	/**
	 * The newest version a creator accepted, or 0.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function accepted( int $creator_id ): int {
		$wpdb    = $this->wpdb;
		$version = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX( version ) FROM %i WHERE creator_id = %d', $this->table( Tables::AGREEMENT_ACCEPTANCES ), $creator_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; a live check.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the creator\'s acceptance.' );
		}

		return is_numeric( $version ) ? (int) $version : 0;
	}

	/**
	 * Stores an acceptance.
	 *
	 * @param int               $creator_id Creator.
	 * @param int|null          $user_id    Who accepted.
	 * @param int               $version    Version.
	 * @param bool              $adult      Whether they confirmed being 18 or older.
	 * @param DateTimeImmutable $now        Now (UTC).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function accept( int $creator_id, ?int $user_id, int $version, bool $adult, DateTimeImmutable $now ): void {
		$stored = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			$this->table( Tables::AGREEMENT_ACCEPTANCES ),
			array(
				'creator_id'      => $creator_id,
				'user_id'         => $user_id,
				'version'         => $version,
				'adult_confirmed' => $adult ? 1 : 0,
				'accepted_at'     => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%d', '%s' )
		);

		if ( 1 !== $stored ) {
			throw new DatabaseException( 'Could not store the acceptance.' );
		}
	}

	/**
	 * Deletes a creator's acceptances. Only for a signup that was never
	 * verified and is removed with its user (ADR-091 pkt. 6).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When they cannot be deleted.
	 */
	public function delete_for_unverified( int $creator_id ): void {
		$deleted = $this->wpdb->delete( $this->table( Tables::AGREEMENT_ACCEPTANCES ), array( 'creator_id' => $creator_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not delete the acceptances.' );
		}
	}

	/**
	 * A version from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): AgreementVersion {
		$at = is_string( $row['published_at'] ?? null ) ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $row['published_at'], new DateTimeZone( 'UTC' ) ) : false;

		if ( ! is_numeric( $row['version'] ?? null ) || ! is_string( $row['body'] ?? null ) || false === $at ) {
			throw new DatabaseException( 'Invalid agreement row.' );
		}

		$material = is_scalar( $row['material'] ?? null ) ? (string) $row['material'] : '0';

		return new AgreementVersion( (int) $row['version'], $row['body'], '1' === $material, $at );
	}

	/**
	 * Full table name.
	 *
	 * @param string $table Tables constant.
	 */
	private function table( string $table ): string {
		return Tables::name( $this->wpdb, $table );
	}
}
