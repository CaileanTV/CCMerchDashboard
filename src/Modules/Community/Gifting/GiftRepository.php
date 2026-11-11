<?php
/**
 * The gifts table.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and changes `merchboard_gifts` (ADR-107 pkt. 4). Every change of a
 * gift is one conditional UPDATE (`WHERE status = …`), so two requests (two
 * webhook deliveries, a job and a click) can never move a gift twice; entrants
 * are added under a row lock.
 */
final class GiftRepository {

	/**
	 * Constructor.
	 *
	 * @param wpdb        $wpdb        Database.
	 * @param Transaction $transaction Transactions.
	 */
	public function __construct( private wpdb $wpdb, private Transaction $transaction ) {
	}

	/**
	 * Records a paid gift order (once per order).
	 *
	 * @param int         $creator_id      Creator.
	 * @param int         $order_id        Gift order.
	 * @param int         $product_id      Parent product.
	 * @param string      $unit_price      Paid unit price incl. VAT.
	 * @param string      $shipping_credit Shipping covered.
	 * @param string      $currency        Currency.
	 * @param string|null $buyer_name      Name to announce, null for anonymous.
	 * @return Gift|null The new gift; null when the order's gift existed already.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( int $creator_id, int $order_id, int $product_id, string $unit_price, string $shipping_credit, string $currency, ?string $buyer_name ): ?Gift {
		$now = self::now();
		$sql = $this->wpdb->prepare(
			"INSERT IGNORE INTO %i (creator_id, order_id, product_id, unit_price, shipping_credit, currency, buyer_name, status, draws, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %s, NULLIF(%s, ''), %s, 0, %s, %s)",
			$this->table(),
			$creator_id,
			$order_id,
			$product_id,
			$unit_price,
			$shipping_credit,
			$currency,
			$buyer_name ?? '',
			Gift::WAITING,
			$now,
			$now
		);

		$inserted = $this->wpdb->query( (string) $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; own table.

		if ( false === $inserted ) {
			throw new DatabaseException( 'Could not store the gift.', array( 'order_id' => $order_id ) );
		}

		// Two requests marking the order paid at once: only the one that inserted goes on.
		return 1 === (int) $inserted ? $this->find_by( 'order_id', $order_id ) : null;
	}

	/**
	 * The gift of a giveaway (ADR-110): waiting for a draw (`bot`) or with an
	 * open link (`manual`, won without a Twitch winner until the deadline).
	 * Once per giveaway.
	 *
	 * @param int         $creator_id      Creator.
	 * @param int         $giveaway_id     Giveaway.
	 * @param int         $product_id      Parent product.
	 * @param string      $unit_price      The price the code covers, incl. VAT.
	 * @param string      $shipping_credit Shipping covered.
	 * @param string      $currency        Currency.
	 * @param string|null $link_deadline   UTC end of an open link; null to wait for a draw.
	 * @return Gift The giveaway's gift (also when it existed already).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create_for_giveaway( int $creator_id, int $giveaway_id, int $product_id, string $unit_price, string $shipping_credit, string $currency, ?string $link_deadline ): Gift {
		$now  = self::now();
		$link = null !== $link_deadline;
		$sql  = $this->wpdb->prepare(
			"INSERT IGNORE INTO %i (creator_id, order_id, giveaway_id, product_id, unit_price, shipping_credit, currency, buyer_name, status, draws, claim_deadline, claim_token, created_at, updated_at) VALUES (%d, NULL, %d, %d, %s, %s, %s, NULL, %s, 0, NULLIF(%s, ''), NULLIF(%s, ''), %s, %s)",
			$this->table(),
			$creator_id,
			$giveaway_id,
			$product_id,
			$unit_price,
			$shipping_credit,
			$currency,
			$link ? Gift::WON : Gift::WAITING,
			$link_deadline ?? '',
			$link ? bin2hex( random_bytes( 16 ) ) : '',
			$now,
			$now
		);

		if ( false === $this->wpdb->query( (string) $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; own table.
			throw new DatabaseException( 'Could not store the giveaway\'s gift.', array( 'giveaway_id' => $giveaway_id ) );
		}

		$gift = $this->find_by( 'giveaway_id', $giveaway_id );

		if ( null === $gift ) {
			throw new DatabaseException( 'Could not read the giveaway\'s gift.', array( 'giveaway_id' => $giveaway_id ) );
		}

		return $gift;
	}

	/**
	 * Gives a giveaway's gift to the creator as an open link (ADR-110): won
	 * without a Twitch winner, so whoever has the link can claim it until the
	 * deadline (after the last draw).
	 *
	 * @param int    $id       Gift.
	 * @param string $from     `won` or `waiting`.
	 * @param string $deadline UTC end of the link.
	 * @return bool Whether this request did it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function open_link( int $id, string $from, string $deadline ): bool {
		return $this->change( $id, $from, 'status = %s, entrants = NULL, window_ends = NULL, winner_twitch_id = NULL, winner_login = NULL, claim_deadline = %s, claim_token = %s', array( Gift::WON, $deadline, bin2hex( random_bytes( 16 ) ) ) );
	}

	/**
	 * Ends a giveaway's open link that nobody claimed in time.
	 *
	 * @param int $id Gift.
	 * @return bool Whether this request ended it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function expire_link( int $id ): bool {
		return $this->change( $id, Gift::WON, 'status = %s, claim_token = NULL', array( Gift::CANCELLED ) );
	}

	/**
	 * The gift of a giveaway.
	 *
	 * @param int $giveaway_id Giveaway.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function find_by_giveaway( int $giveaway_id ): ?Gift {
		return $this->find_by( 'giveaway_id', $giveaway_id );
	}

	/**
	 * A gift by id.
	 *
	 * @param int $id Gift.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function find( int $id ): ?Gift {
		return $this->find_by( 'id', $id );
	}

	/**
	 * The gift of an order.
	 *
	 * @param int $order_id Gift order.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function find_by_order( int $order_id ): ?Gift {
		return $this->find_by( 'order_id', $order_id );
	}

	/**
	 * The gift of a claim link (only while won).
	 *
	 * @param string $token Claim token.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function find_by_token( string $token ): ?Gift {
		return 1 === preg_match( '/^[0-9a-f]{32}$/', $token ) ? $this->find_by( 'claim_token', $token ) : null;
	}

	/**
	 * A creator's gifts in some statuses, oldest first.
	 *
	 * @phpstan-param list<string> $statuses
	 *
	 * @param int       $creator_id Creator.
	 * @param string[]  $statuses   Statuses.
	 * @param int       $limit      Most rows.
	 * @param bool|null $giveaways  Only giveaways' gifts (true), only bought gifts (false) or both (null).
	 * @return list<Gift>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function for_creator( int $creator_id, array $statuses, int $limit = 100, ?bool $giveaways = null ): array {
		$wpdb  = $this->wpdb;
		$marks = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$kind  = null === $giveaways ? '' : ( $giveaways ? ' AND giveaway_id IS NOT NULL' : ' AND giveaway_id IS NULL' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE creator_id = %d AND status IN ({$marks}){$kind} ORDER BY id ASC LIMIT %d", $this->table(), $creator_id, ...array_merge( $statuses, array( $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders and a constant clause only; own table.

		return $this->gifts( $rows );
	}

	/**
	 * A creator's latest bought gifts (any status), newest first; the gifts
	 * of giveaways are not among them.
	 *
	 * @param int $creator_id Creator.
	 * @param int $limit      Most rows.
	 * @return list<Gift>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function latest( int $creator_id, int $limit = 50 ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE creator_id = %d AND giveaway_id IS NULL ORDER BY id DESC LIMIT %d', $this->table(), $creator_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.

		return $this->gifts( $rows );
	}

	/**
	 * Gifts in a status whose time ran out: open windows past their end, or
	 * wins past their claim deadline.
	 *
	 * @param string $status `entering` or `won`.
	 * @return list<Gift>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function due( string $status ): array {
		$wpdb   = $this->wpdb;
		$column = Gift::ENTERING === $status ? 'window_ends' : 'claim_deadline';
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s AND %i <= %s ORDER BY id ASC LIMIT 50', $this->table(), $status, $column, self::now() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.

		return $this->gifts( $rows );
	}

	/**
	 * Opens the `!enter` window of a waiting gift.
	 *
	 * @param int    $id          Gift.
	 * @param string $window_ends UTC end.
	 * @return bool Whether this request opened it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function open_window( int $id, string $window_ends ): bool {
		return $this->change( $id, Gift::WAITING, 'status = %s, entrants = NULL, window_ends = %s', array( Gift::ENTERING, $window_ends ) );
	}

	/**
	 * Takes the database's named lock of a gift's chat confirmations, so two
	 * webhook deliveries never confirm the same entrants twice. Released with
	 * unlock(), or when the connection ends.
	 *
	 * @param int $id      Gift.
	 * @param int $timeout Seconds to wait.
	 * @return bool Whether this request holds the lock.
	 */
	public function lock( int $id, int $timeout ): bool {
		$wpdb = $this->wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name( $id ), $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock.
	}

	/**
	 * Releases the lock of lock().
	 *
	 * @param int $id Gift.
	 */
	public function unlock( int $id ): void {
		$wpdb = $this->wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock.
	}

	/**
	 * Adds a viewer to an open window (once per Twitch account).
	 *
	 * @param int    $id        Gift.
	 * @param string $twitch_id Viewer's Twitch id.
	 * @param string $login     Viewer's login.
	 * @return bool Whether the viewer is in now.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function add_entrant( int $id, string $twitch_id, string $login ): bool {
		$wpdb = $this->wpdb;
		return (bool) $this->transaction->run(
			function () use ( $wpdb, $id, $twitch_id, $login ): bool {
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Row lock in the transaction.

				if ( ! is_array( $row ) ) {
					return false;
				}

				$gift = Gift::from_row( $row );

				if ( Gift::ENTERING !== $gift->status || ( null !== $gift->window_ends && $gift->window_ends < self::now() ) ) {
					return false;
				}

				$entrants               = $gift->entrants;
				$entrants[ $twitch_id ] = $login;

				return false !== $wpdb->update(
					$this->table(),
					array(
						'entrants'   => (string) wp_json_encode( $entrants ),
						'updated_at' => self::now(),
					),
					array( 'id' => $id )
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			}
		);
	}

	/**
	 * Records the winner of an open window.
	 *
	 * @param int    $id        Gift.
	 * @param string $twitch_id Winner's Twitch id.
	 * @param string $login     Winner's login.
	 * @param string $deadline  UTC claim deadline.
	 * @param string $token     Claim token (32 hex).
	 * @return bool Whether this request decided it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function win( int $id, string $twitch_id, string $login, string $deadline, string $token ): bool {
		return $this->change( $id, Gift::ENTERING, 'status = %s, entrants = NULL, window_ends = NULL, winner_twitch_id = %s, winner_login = %s, won_at = %s, claim_deadline = %s, claim_token = %s, draws = draws + 1', array( Gift::WON, $twitch_id, $login, self::now(), $deadline, $token ) );
	}

	/**
	 * Back to waiting: a window without entrants, or a win nobody claimed.
	 *
	 * @param int    $id   Gift.
	 * @param string $from `entering` or `won`.
	 * @return bool Whether this request moved it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function wait_again( int $id, string $from ): bool {
		return $this->change( $id, $from, 'status = %s, entrants = NULL, window_ends = NULL, winner_twitch_id = NULL, winner_login = NULL, claim_deadline = NULL, claim_token = NULL', array( Gift::WAITING ) );
	}

	/**
	 * The winner got the gift code. The claim token stays, so the winner's
	 * link shows the code again.
	 *
	 * @param int $id        Gift.
	 * @param int $coupon_id The code's coupon.
	 * @return bool Whether this request claimed it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function claim( int $id, int $coupon_id ): bool {
		return $this->change( $id, Gift::WON, 'status = %s, coupon_id = %d', array( Gift::CLAIMED, $coupon_id ) );
	}

	/**
	 * The buyer gets the gift code after the last draw.
	 *
	 * @param int    $id        Gift.
	 * @param string $from      `won` or `waiting`.
	 * @param int    $coupon_id The code's coupon.
	 * @return bool Whether this request returned it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function return_to_buyer( int $id, string $from, int $coupon_id ): bool {
		return $this->change( $id, $from, 'status = %s, coupon_id = %d, entrants = NULL, window_ends = NULL, claim_token = NULL, claim_deadline = NULL', array( Gift::RETURNED, $coupon_id ) );
	}

	/**
	 * Cancels a gift that has no winner yet (also one whose window is open:
	 * its draw then finds nothing to draw).
	 *
	 * @param int $id Gift.
	 * @return bool Whether this request cancelled it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function cancel( int $id ): bool {
		return $this->change( $id, Gift::WAITING, 'status = %s', array( Gift::CANCELLED ) )
			|| $this->change( $id, Gift::ENTERING, 'status = %s, entrants = NULL, window_ends = NULL', array( Gift::CANCELLED ) );
	}

	/**
	 * Records the order the winner used the code in.
	 *
	 * @param int $id       Gift.
	 * @param int $order_id The winner's order.
	 * @return bool Whether this request recorded it (the first use).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function used_in( int $id, int $order_id ): bool {
		$wpdb    = $this->wpdb;
		$sql     = $wpdb->prepare( 'UPDATE %i SET claimed_order_id = %d, updated_at = %s WHERE id = %d AND status IN (%s, %s) AND claimed_order_id IS NULL', $this->table(), $order_id, self::now(), $id, Gift::CLAIMED, Gift::RETURNED );
		$changed = $wpdb->query( (string) $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; own table.

		if ( false === $changed ) {
			throw new DatabaseException( 'Could not record the use of a gift code.', array( 'gift_id' => $id ) );
		}

		return 1 === (int) $changed;
	}

	/**
	 * Deletes a creator's finished gifts (creator deletion): cancelled ones
	 * and claimed ones whose code was used. A code not used yet keeps its
	 * gift, so it still works.
	 *
	 * @param int $creator_id Creator.
	 * @return int Deleted rows.
	 * @throws DatabaseException When it fails.
	 */
	public function delete_for_creator( int $creator_id ): int {
		$wpdb    = $this->wpdb;
		$sql     = $wpdb->prepare( 'DELETE FROM %i WHERE creator_id = %d AND ( status = %s OR ( status = %s AND claimed_order_id IS NOT NULL ) )', $this->table(), $creator_id, Gift::CANCELLED, Gift::CLAIMED );
		$deleted = null === $sql ? false : $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; own table.

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not delete the creator\'s gifts.' );
		}

		return (int) $deleted;
	}

	/**
	 * Forgets the personal data of gifts (privacy erasure): the buyer's name
	 * and the winner's login, by gift order.
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int[] $order_ids Gift orders.
	 * @return int Changed rows.
	 * @throws DatabaseException When it fails.
	 */
	public function anonymize( array $order_ids ): int {
		$wpdb = $this->wpdb;
		if ( array() === $order_ids ) {
			return 0;
		}

		$marks   = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );
		$sql     = $wpdb->prepare( "UPDATE %i SET buyer_name = NULL, winner_login = NULL, entrants = NULL WHERE order_id IN ({$marks})", $this->table(), ...$order_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders only.
		$changed = null === $sql ? false : $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; own table.

		if ( false === $changed ) {
			throw new DatabaseException( 'Could not anonymize the gifts.' );
		}

		return (int) $changed;
	}

	/**
	 * Moves a gift from one status with an UPDATE that only applies while it
	 * is still in that status.
	 *
	 * @phpstan-param literal-string $set
	 *
	 * @param int          $id     Gift.
	 * @param string       $from   Expected status.
	 * @param string       $set    SET clause with placeholders.
	 * @param array<mixed> $values Its values.
	 * @return bool Whether a row changed.
	 * @throws DatabaseException When the statement fails.
	 */
	private function change( int $id, string $from, string $set, array $values ): bool {
		$wpdb    = $this->wpdb;
		$sql     = $wpdb->prepare( "UPDATE %i SET {$set}, updated_at = %s WHERE id = %d AND status = %s", $this->table(), ...array_merge( $values, array( self::now(), $id, $from ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $set is a constant clause of this class.
		$changed = $wpdb->query( (string) $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; own table.

		if ( false === $changed ) {
			throw new DatabaseException( 'Could not change the gift.', array( 'gift_id' => $id ) );
		}

		return 1 === (int) $changed;
	}

	/**
	 * A gift by a unique column.
	 *
	 * @param string     $column `id`, `order_id`, `giveaway_id` or `claim_token`.
	 * @param int|string $value  Value.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function find_by( string $column, int|string $value ): ?Gift {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s LIMIT 1', $this->table(), $column, (string) $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the gift.' );
		}

		return is_array( $row ) ? Gift::from_row( $row ) : null;
	}

	/**
	 * Gifts from rows.
	 *
	 * @param mixed $rows Rows.
	 * @return list<Gift>
	 * @throws DatabaseException When the read failed.
	 */
	private function gifts( mixed $rows ): array {
		$wpdb = $this->wpdb;
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the gifts.' );
		}

		return array_values( array_map( static fn( array $row ): Gift => Gift::from_row( $row ), array_filter( $rows, 'is_array' ) ) );
	}

	/**
	 * Now in UTC.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * The name of a gift's confirmation lock.
	 *
	 * @param int $id Gift.
	 */
	private function lock_name( int $id ): string {
		return $this->table() . '_confirm_' . $id;
	}

	/**
	 * The full table name.
	 */
	private function table(): string {
		$wpdb = $this->wpdb;
		return Tables::name( $wpdb, Tables::GIFTS );
	}
}
