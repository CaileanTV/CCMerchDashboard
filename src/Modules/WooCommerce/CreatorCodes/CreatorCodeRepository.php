<?php
/**
 * Storage of the creators' discount code registrations.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_creator_codes` (ADR-068, K1). A registration is inserted once
 * and can only be ended (valid_to, ended_by, and clearing the `active_*`
 * columns); there is no method to change or delete one. The UNIQUE keys on
 * the `active_*` columns are the last safety net against two active
 * registrations of one creator or one code. No authorization or audit here
 * (CreatorCodeService does that). A failed read or write throws a
 * DatabaseException, never an empty result.
 */
final class CreatorCodeRepository {

	/**
	 * Seconds to wait for a registration lock.
	 */
	public const LOCK_TIMEOUT = 5;

	private const COLUMNS = 'id, creator_id, code, coupon_id, valid_from, valid_to, created_by, ended_by';

	/**
	 * Most codes matched per query (an order has few coupons).
	 */
	private const MAX_CODES = 100;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Runs $work while holding the named registration locks of the creator
	 * and of the code, always in this order (K1: registrations are serialised
	 * per creator and per normalized code), and releases both afterwards.
	 *
	 * @template T
	 * @param int           $creator_id Creator.
	 * @param string|null   $code       Normalized code, null for the creator lock only.
	 * @param callable(): T $work       Work under the locks.
	 * @return T
	 * @throws CreatorCodeException When a lock is held elsewhere beyond the timeout (busy).
	 * @throws DatabaseException When a lock cannot be taken.
	 */
	public function with_locks( int $creator_id, ?string $code, callable $work ): mixed {
		$names = array( 'merchboard_code_creator:' . $creator_id );

		if ( null !== $code ) {
			// The code's hash keeps the name short and free of user input.
			$names[] = 'merchboard_code:' . md5( $code );
		}

		$wpdb  = $this->wpdb;
		$taken = array();

		try {
			foreach ( $names as $name ) {
				$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT ) );

				if ( '' !== $wpdb->last_error || ! is_numeric( $acquired ) ) {
					throw new DatabaseException( 'Could not take the discount code lock.' );
				}

				if ( 1 !== (int) $acquired ) {
					throw new CreatorCodeException( CreatorCodeException::BUSY );
				}

				$taken[] = $name;
			}

			return $work();
		} finally {
			foreach ( array_reverse( $taken ) as $name ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
		}
	}

	/**
	 * The creator's active registration, or null.
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $lock       Read FOR UPDATE (inside a transaction).
	 * @throws DatabaseException When the read fails.
	 */
	public function active_for_creator( int $creator_id, bool $lock = false ): ?CreatorCode {
		$wpdb = $this->wpdb;

		return $this->codes( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE active_creator_id = %d' . ( $lock ? ' FOR UPDATE' : '' ), $this->table(), $creator_id ) )[0] ?? null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The active registration of a normalized code, or null.
	 *
	 * @param string $code Normalized code.
	 * @param bool   $lock Read FOR UPDATE (inside a transaction).
	 * @throws DatabaseException When the read fails.
	 */
	public function active_for_code( string $code, bool $lock = false ): ?CreatorCode {
		$wpdb = $this->wpdb;

		return $this->codes( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE active_code = %s' . ( $lock ? ' FOR UPDATE' : '' ), $this->table(), $code ) )[0] ?? null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The creator's registrations, newest first.
	 *
	 * @param int $creator_id Creator.
	 * @param int $limit      Most registrations, 1–500.
	 * @return list<CreatorCode>
	 * @throws DatabaseException When the read fails.
	 */
	public function for_creator( int $creator_id, int $limit = 100 ): array {
		$wpdb = $this->wpdb;

		return $this->codes( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d ORDER BY id DESC LIMIT %d', $this->table(), $creator_id, max( 1, min( 500, $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * Every registration, active or ended, of the given normalized codes,
	 * oldest first.
	 *
	 * @phpstan-param list<string> $codes
	 *
	 * @param string[] $codes Normalized codes.
	 * @return list<CreatorCode>
	 * @throws DatabaseException When the read fails.
	 */
	public function matching( array $codes ): array {
		$codes = array_slice( array_values( array_unique( array_filter( $codes, static fn( string $code ): bool => '' !== $code ) ) ), 0, self::MAX_CODES );

		if ( array() === $codes ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$marks = implode( ', ', array_fill( 0, count( $codes ), '%s' ) );

		return $this->codes( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE code IN ( {$marks} ) ORDER BY id ASC", $this->table(), ...$codes ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant columns; $marks holds one %s per code.
	}

	/**
	 * Inserts an active registration.
	 *
	 * @param int               $creator_id Creator.
	 * @param string            $code       Normalized code.
	 * @param int               $coupon_id  WooCommerce coupon.
	 * @param int|null          $user       WordPress user.
	 * @param DateTimeImmutable $now        Start of the registration.
	 * @return int The registration's id.
	 * @throws DatabaseException When it cannot be stored (also when a UNIQUE key refuses it).
	 */
	public function insert( int $creator_id, string $code, int $coupon_id, ?int $user, DateTimeImmutable $now ): int {
		$wpdb     = $this->wpdb;
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'creator_id'        => $creator_id,
				'code'              => $code,
				'coupon_id'         => $coupon_id,
				'valid_from'        => self::utc( $now ),
				'active_creator_id' => $creator_id,
				'active_code'       => $code,
				'created_by'        => $user,
			),
			// A null user is written as NULL by wpdb.
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%d' )
		);

		if ( 1 !== $inserted || $wpdb->insert_id < 1 ) {
			throw new DatabaseException( 'Could not store the discount code registration.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Ends an active registration.
	 *
	 * @param int               $id   Registration.
	 * @param int|null          $user WordPress user.
	 * @param DateTimeImmutable $now  End of the registration.
	 * @return bool Whether an active registration was ended.
	 * @throws DatabaseException When the write fails.
	 */
	public function end( int $id, ?int $user, DateTimeImmutable $now ): bool {
		$wpdb = $this->wpdb;
		$sql  = null === $user
			? $wpdb->prepare( 'UPDATE %i SET valid_to = %s, ended_by = NULL, active_creator_id = NULL, active_code = NULL WHERE id = %d AND valid_to IS NULL', $this->table(), self::utc( $now ), $id )
			: $wpdb->prepare( 'UPDATE %i SET valid_to = %s, ended_by = %d, active_creator_id = NULL, active_code = NULL WHERE id = %d AND valid_to IS NULL', $this->table(), self::utc( $now ), $user, $id );

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not end the discount code registration.' );
		}

		$updated = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; registrations are never cached.

		if ( '' !== $wpdb->last_error || false === $updated ) {
			throw new DatabaseException( 'Could not end the discount code registration.' );
		}

		return 1 === (int) $updated;
	}

	/**
	 * Rows of a prepared query as registrations.
	 *
	 * @param string|null $sql Prepared query.
	 * @return list<CreatorCode>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	private function codes( ?string $sql ): array {
		$wpdb = $this->wpdb;
		$rows = null === $sql ? null : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; registrations are never cached.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the discount code registrations.' );
		}

		$codes = array();
		$utc   = new DateTimeZone( 'UTC' );

		foreach ( $rows as $row ) {
			try {
				$codes[] = new CreatorCode(
					(int) $row['id'],
					(int) $row['creator_id'],
					(string) $row['code'],
					(int) $row['coupon_id'],
					new DateTimeImmutable( (string) $row['valid_from'], $utc ),
					null === $row['valid_to'] ? null : new DateTimeImmutable( (string) $row['valid_to'], $utc ),
					null === $row['created_by'] ? null : (int) $row['created_by'],
					null === $row['ended_by'] ? null : (int) $row['ended_by']
				);
			} catch ( Exception ) {
				throw new DatabaseException( 'Invalid discount code registration.' );
			}
		}

		return $codes;
	}

	/**
	 * A time as stored (UTC).
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::CREATOR_CODES );
	}
}
