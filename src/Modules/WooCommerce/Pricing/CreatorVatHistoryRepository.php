<?php
/**
 * Storage of the history of the creators' VAT status.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_creator_vat_history` (ADR-062 K06). Insert-only: there is no
 * method to change or delete a registration. The status that applies on a
 * date is the registration with the latest `effective_from` on or before it;
 * of several with the same date, the one recorded last (a correction). No
 * authorization or audit here (CreatorPricingSettings does that). A failed
 * read or write throws a DatabaseException, never an empty result.
 */
final class CreatorVatHistoryRepository {

	private const COLUMNS = 'id, creator_id, vat_registered, effective_from, checked_on, documentation_reference, reason, recorded_by, recorded_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Locks the creator's row until the caller's transaction ends, so
	 * registrations of one creator are serialised.
	 *
	 * @param int $creator_id Creator.
	 * @return bool Whether the creator exists.
	 * @throws DatabaseException When the read fails.
	 */
	public function lock_creator( int $creator_id ): bool {
		$wpdb  = $this->wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', Tables::name( $wpdb, Tables::CREATORS ), $creator_id ) );

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not lock the creator.' );
		}

		return null !== $value;
	}

	/**
	 * Records a registration.
	 *
	 * @param int               $creator_id     Creator.
	 * @param bool              $vat_registered VAT registered.
	 * @param string            $effective_from Date the status applies from (Y-m-d).
	 * @param string            $checked_on     Date the status was checked (Y-m-d).
	 * @param string            $reference      Documentation reference.
	 * @param string            $reason         Reason.
	 * @param int|null          $user           WordPress user.
	 * @param DateTimeImmutable $now            Time of recording.
	 * @return int The registration's id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( int $creator_id, bool $vat_registered, string $effective_from, string $checked_on, string $reference, string $reason, ?int $user, DateTimeImmutable $now ): int {
		$wpdb     = $this->wpdb;
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'creator_id'              => $creator_id,
				'vat_registered'          => $vat_registered ? 1 : 0,
				'effective_from'          => $effective_from,
				'checked_on'              => $checked_on,
				'documentation_reference' => $reference,
				'reason'                  => $reason,
				'recorded_by'             => $user,
				'recorded_at'             => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			),
			// A null user is written as NULL by wpdb.
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( 1 !== $inserted || $wpdb->insert_id < 1 ) {
			throw new DatabaseException( 'Could not store the VAT status registration.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * The registrations of a creator, most recently recorded first.
	 *
	 * @param int $creator_id Creator.
	 * @param int $limit      Most registrations, 1–500.
	 * @return list<CreatorVatEntry>
	 * @throws DatabaseException When the read fails.
	 */
	public function for_creator( int $creator_id, int $limit = 100 ): array {
		$wpdb = $this->wpdb;

		return $this->entries( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d ORDER BY id DESC LIMIT %d', $this->table(), $creator_id, max( 1, min( 500, $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The registration recorded last, or null when there is none.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the read fails.
	 */
	public function latest( int $creator_id ): ?CreatorVatEntry {
		return $this->for_creator( $creator_id, 1 )[0] ?? null;
	}

	/**
	 * The registration that applies on a date: the latest `effective_from`
	 * on or before it, and of those the one recorded last. Null when no
	 * registration applies yet (the history starts later or is empty).
	 *
	 * @param int    $creator_id Creator.
	 * @param string $date       Date (Y-m-d).
	 * @throws DatabaseException When the read fails.
	 */
	public function at( int $creator_id, string $date ): ?CreatorVatEntry {
		$wpdb = $this->wpdb;

		return $this->entries( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d AND effective_from <= %s ORDER BY effective_from DESC, id DESC LIMIT 1', $this->table(), $creator_id, $date ) )[0] ?? null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * Rows of a prepared query as registrations.
	 *
	 * @param string|null $sql Prepared query.
	 * @return list<CreatorVatEntry>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	private function entries( ?string $sql ): array {
		$wpdb = $this->wpdb;
		$rows = null === $sql ? null : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; the history is never cached.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the VAT status history.' );
		}

		$entries = array();

		foreach ( $rows as $row ) {
			if ( ! in_array( (string) ( $row['vat_registered'] ?? '' ), array( '0', '1' ), true ) ) {
				throw new DatabaseException( 'Invalid VAT status registration.' );
			}

			try {
				$recorded = new DateTimeImmutable( (string) $row['recorded_at'], new DateTimeZone( 'UTC' ) );
			} catch ( Exception ) {
				throw new DatabaseException( 'Invalid VAT status registration.' );
			}

			$entries[] = new CreatorVatEntry(
				(int) $row['id'],
				(int) $row['creator_id'],
				'1' === (string) $row['vat_registered'],
				(string) $row['effective_from'],
				(string) $row['checked_on'],
				(string) $row['documentation_reference'],
				(string) $row['reason'],
				null === $row['recorded_by'] ? null : (int) $row['recorded_by'],
				$recorded
			);
		}

		return $entries;
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::CREATOR_VAT_HISTORY );
	}
}
