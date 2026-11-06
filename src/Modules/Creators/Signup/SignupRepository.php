<?php
/**
 * Storage of the creators' sign-ups until their e-mail is verified.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Signup;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_creator_signups` (ADR-091): one row per creator who signed up.
 * `created_user` marks a WordPress user the plugin created; only such a user
 * is deleted with an unverified sign-up.
 */
class SignupRepository {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Stores a sign-up.
	 *
	 * @param int                    $creator_id   Creator.
	 * @param int                    $user_id      WordPress user.
	 * @param string                 $channel_url  The channel link.
	 * @param bool                   $created_user Whether the plugin created the user.
	 * @param DateTimeImmutable      $now          Now (UTC).
	 * @param DateTimeImmutable|null $expires_at   When an unverified sign-up is removed; null when verified already.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( int $creator_id, int $user_id, string $channel_url, bool $created_user, DateTimeImmutable $now, ?DateTimeImmutable $expires_at ): void {
		$stored = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			$this->table(),
			array(
				'creator_id'   => $creator_id,
				'user_id'      => $user_id,
				'channel_url'  => $channel_url,
				'created_user' => $created_user ? 1 : 0,
				'created_at'   => self::utc( $now ),
				'expires_at'   => null === $expires_at ? null : self::utc( $expires_at ),
				'verified_at'  => null === $expires_at ? self::utc( $now ) : null,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( 1 !== $stored ) {
			throw new DatabaseException( 'Could not store the sign-up.' );
		}
	}

	/**
	 * The sign-up of a user, or null.
	 *
	 * @param int $user_id WordPress user.
	 * @return array{creator_id: int, user_id: int, channel_url: string, created_user: bool, verified: bool}|null
	 * @throws DatabaseException When it cannot be read.
	 */
	public function for_user( int $user_id ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT creator_id, user_id, channel_url, created_user, verified_at FROM %i WHERE user_id = %d', $this->table(), $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; a live check.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the sign-up.' );
		}

		return is_array( $row ) ? self::row( $row ) : null;
	}

	/**
	 * The channel link of a creator's sign-up ('' for none).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function channel( int $creator_id ): string {
		$wpdb = $this->wpdb;
		$url  = $wpdb->get_var( $wpdb->prepare( 'SELECT channel_url FROM %i WHERE creator_id = %d', $this->table(), $creator_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the sign-up.' );
		}

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Marks a user's sign-up as verified.
	 *
	 * @param int               $user_id WordPress user.
	 * @param DateTimeImmutable $now     Now (UTC).
	 * @return bool Whether it was verified now.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function verify( int $user_id, DateTimeImmutable $now ): bool {
		$updated = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$this->table(),
			array(
				'verified_at' => self::utc( $now ),
				'expires_at'  => null,
			),
			array(
				'user_id'     => $user_id,
				'verified_at' => null,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $updated ) {
			throw new DatabaseException( 'Could not verify the sign-up.' );
		}

		return 1 === $updated;
	}

	/**
	 * Unverified sign-ups past their expiry whose user the plugin created.
	 *
	 * @param DateTimeImmutable $now Now (UTC).
	 * @return list<array{creator_id: int, user_id: int, channel_url: string, created_user: bool, verified: bool}>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function expired( DateTimeImmutable $now ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT creator_id, user_id, channel_url, created_user, verified_at FROM %i WHERE verified_at IS NULL AND created_user = 1 AND expires_at < %s ORDER BY creator_id LIMIT 100', $this->table(), self::utc( $now ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the daily clean-up.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the expired sign-ups.' );
		}

		return array_map( self::row( ... ), $rows );
	}

	/**
	 * Deletes a creator's sign-up.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $creator_id ): void {
		if ( false === $this->wpdb->delete( $this->table(), array( 'creator_id' => $creator_id ), array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			throw new DatabaseException( 'Could not delete the sign-up.' );
		}
	}

	/**
	 * Deletes a creator's sign-up only while it is unverified, so a sign-up
	 * verified meanwhile is kept (review 32.7).
	 *
	 * @param int $creator_id Creator.
	 * @return bool Whether it was deleted.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete_unverified( int $creator_id ): bool {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE creator_id = %d AND verified_at IS NULL', $this->table(), $creator_id );

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not delete the sign-up.' );
		}

		$deleted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the result of $wpdb->prepare(); own table.

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not delete the sign-up.' );
		}

		return 1 === $deleted;
	}

	/**
	 * A sign-up from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array{creator_id: int, user_id: int, channel_url: string, created_user: bool, verified: bool}
	 */
	private static function row( array $row ): array {
		return array(
			'creator_id'   => is_numeric( $row['creator_id'] ?? null ) ? (int) $row['creator_id'] : 0,
			'user_id'      => is_numeric( $row['user_id'] ?? null ) ? (int) $row['user_id'] : 0,
			'channel_url'  => is_string( $row['channel_url'] ?? null ) ? $row['channel_url'] : '',
			'created_user' => '1' === ( is_scalar( $row['created_user'] ?? null ) ? (string) $row['created_user'] : '0' ),
			'verified'     => null !== ( $row['verified_at'] ?? null ),
		);
	}

	/**
	 * A UTC DATETIME value.
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
		return Tables::name( $this->wpdb, Tables::CREATOR_SIGNUPS );
	}
}
