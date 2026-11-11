<?php
/**
 * The giveaways table.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Giveaways;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_giveaways` (ADR-110). Status changes are conditional UPDATEs
 * from an expected status, so two requests never move a giveaway twice.
 */
final class GiveawayRepository {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Stores a new giveaway.
	 *
	 * @param int    $creator_id      Creator.
	 * @param int    $product_id      Parent product.
	 * @param string $mode            `bot` or `manual`.
	 * @param string $funding         `balance` or `shop`.
	 * @param string $unit_price      The price the code covers (decimal).
	 * @param string $shipping_credit Shipping covered (decimal).
	 * @param string $cost            What the creator pays (decimal).
	 * @param string $currency        Currency.
	 * @param int    $window_seconds  Window of a bot giveaway.
	 * @return Giveaway The new giveaway.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( int $creator_id, int $product_id, string $mode, string $funding, string $unit_price, string $shipping_credit, string $cost, string $currency, int $window_seconds ): Giveaway {
		$now      = self::now();
		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'creator_id'      => $creator_id,
				'product_id'      => $product_id,
				'mode'            => $mode,
				'funding'         => $funding,
				'status'          => Giveaway::SHOP === $funding ? Giveaway::AWAITING_PAYMENT : Giveaway::READY,
				'unit_price'      => $unit_price,
				'shipping_credit' => $shipping_credit,
				'cost'            => $cost,
				'currency'        => $currency,
				'window_seconds'  => $window_seconds,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$id       = (int) $this->wpdb->insert_id;

		if ( false === $inserted || $id < 1 ) {
			throw new DatabaseException( 'Could not store the giveaway.', array( 'creator_id' => $creator_id ) );
		}

		return $this->find( $id ) ?? throw new DatabaseException( 'Could not read the new giveaway.' );
	}

	/**
	 * A giveaway by id.
	 *
	 * @param int $id Giveaway.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function find( int $id ): ?Giveaway {
		return $this->find_by( 'id', $id );
	}

	/**
	 * The giveaway a shop order paid.
	 *
	 * @param int $order_id Order.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function find_by_order( int $order_id ): ?Giveaway {
		return $this->find_by( 'order_id', $order_id );
	}

	/**
	 * A creator's latest giveaways, newest first.
	 *
	 * @param int $creator_id Creator.
	 * @param int $limit      Most rows.
	 * @return list<Giveaway>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function latest( int $creator_id, int $limit = 50 ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE creator_id = %d ORDER BY id DESC LIMIT %d', $this->table(), $creator_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the giveaways.' );
		}

		return array_values( array_map( static fn( array $row ): Giveaway => Giveaway::from_row( $row ), array_filter( $rows, 'is_array' ) ) );
	}

	/**
	 * How many giveaways a creator has going (awaiting payment or ready).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function count_open( int $creator_id ): int {
		$wpdb  = $this->wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE creator_id = %d AND status IN (%s, %s)', $this->table(), $creator_id, Giveaway::AWAITING_PAYMENT, Giveaway::READY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not count the giveaways.' );
		}

		return (int) $count;
	}

	/**
	 * What a creator's ready giveaways paid from the balance hold back, per
	 * currency (units): reserved until the code is used or the giveaway ends.
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, int>
	 * @throws DatabaseException When it cannot be read.
	 */
	public function held( int $creator_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT currency, SUM(cost) AS total FROM %i WHERE creator_id = %d AND funding = %s AND status = %s GROUP BY currency', $this->table(), $creator_id, Giveaway::BALANCE, Giveaway::READY ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Balances are never cached.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the reserved giveaways.' );
		}

		$held = array();

		try {
			foreach ( $rows as $row ) {
				$held[ (string) $row['currency'] ] = SalesAmount::parse( (string) $row['total'] );
			}
		} catch ( Throwable ) {
			throw new DatabaseException( 'A giveaway does not match the schema.' );
		}

		return $held;
	}

	/**
	 * Moves a giveaway from one status to another.
	 *
	 * @param int                $id    Giveaway.
	 * @param string             $from  Expected status.
	 * @param string             $to    New status.
	 * @param array<string, int> $set   Other columns to set (`gift_id`, `order_id`, `adjustment_id`).
	 * @return bool Whether this request moved it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function move( int $id, string $from, string $to, array $set = array() ): bool {
		$values = array(
			'status'     => $to,
			'updated_at' => self::now(),
		);

		foreach ( array( 'gift_id', 'order_id', 'adjustment_id' ) as $column ) {
			if ( isset( $set[ $column ] ) ) {
				$values[ $column ] = $set[ $column ];
			}
		}

		$changed = $this->wpdb->update( $this->table(), $values, array( 'id' => $id, 'status' => $from ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact where clause.

		if ( false === $changed ) {
			throw new DatabaseException( 'Could not change the giveaway.', array( 'giveaway_id' => $id ) );
		}

		return 1 === (int) $changed;
	}

	/**
	 * Remembers a giveaway's gift or shop order without changing its status.
	 *
	 * @param int                $id  Giveaway.
	 * @param array<string, int> $set `gift_id` and/or `order_id`.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function attach( int $id, array $set ): void {
		$values = array_intersect_key( $set, array_flip( array( 'gift_id', 'order_id' ) ) );

		if ( array() === $values ) {
			return;
		}

		$values['updated_at'] = self::now();

		if ( false === $this->wpdb->update( $this->table(), $values, array( 'id' => $id ) ) ) {
			throw new DatabaseException( 'Could not change the giveaway.', array( 'giveaway_id' => $id ) );
		}
	}

	/**
	 * Deletes a creator's finished giveaways (creator deletion).
	 *
	 * @param int $creator_id Creator.
	 * @return int Deleted rows.
	 * @throws DatabaseException When it fails.
	 */
	public function delete_finished( int $creator_id ): int {
		$wpdb    = $this->wpdb;
		$sql     = $wpdb->prepare( 'DELETE FROM %i WHERE creator_id = %d AND status IN (%s, %s, %s)', $this->table(), $creator_id, Giveaway::USED, Giveaway::EXPIRED, Giveaway::CANCELLED );
		$deleted = null === $sql ? false : $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; own table.

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not delete the creator\'s giveaways.' );
		}

		return (int) $deleted;
	}

	/**
	 * A giveaway by a unique column.
	 *
	 * @param string $column `id` or `order_id`.
	 * @param int    $value  Value.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function find_by( string $column, int $value ): ?Giveaway {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %d LIMIT 1', $this->table(), $column, $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the giveaway.' );
		}

		return is_array( $row ) ? Giveaway::from_row( $row ) : null;
	}

	/**
	 * Now in UTC.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * The full table name.
	 */
	private function table(): string {
		$wpdb = $this->wpdb;

		return Tables::name( $wpdb, Tables::GIVEAWAYS );
	}
}
