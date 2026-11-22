<?php
/**
 * Open gifts and giveaways that point at a collection itself.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Community\Gifting\Gift;
use MerchBoard\Modules\Community\Giveaways\Giveaway;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 3: a collection can no longer be given, and what already
 * points at one is never repaired by itself. The administrator is shown it.
 * Read-only.
 */
final class CollectionGiftWarnings {

	public const GIFT = 'gift';

	public const GIVEAWAY = 'giveaway';

	/**
	 * Constructor.
	 *
	 * @param wpdb              $wpdb        Database.
	 * @param DesignCollections $collections The shop's collections.
	 */
	public function __construct( private wpdb $wpdb, private DesignCollections $collections ) {
	}

	/**
	 * The affected gifts and giveaways that are still open.
	 *
	 * @return list<array{kind: string, id: int, product_id: int}>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function affected(): array {
		$wpdb = $this->wpdb;

		$gifts     = $this->rows( $wpdb->prepare( 'SELECT id, product_id FROM %i WHERE status IN ( %s, %s, %s, %s ) AND claimed_order_id IS NULL', Tables::name( $wpdb, Tables::GIFTS ), Gift::WAITING, Gift::ENTERING, Gift::WON, Gift::CLAIMED ) );
		$giveaways = $this->rows( $wpdb->prepare( 'SELECT id, product_id FROM %i WHERE status = %s', Tables::name( $wpdb, Tables::GIVEAWAYS ), Giveaway::AWAITING_PAYMENT ) );

		$affected = array();
		$known    = array();

		// A paid giveaway is its gift, so only the unpaid ones are read as giveaways.
		foreach ( array(
			self::GIFT     => $gifts,
			self::GIVEAWAY => $giveaways,
		) as $kind => $rows ) {
			foreach ( $rows as $row ) {
				$product_id = (int) $row['product_id'];

				$known[ $product_id ] ??= $this->collections->is_collection( $product_id );

				if ( $known[ $product_id ] ) {
					$affected[] = array(
						'kind'       => $kind,
						'id'         => (int) $row['id'],
						'product_id' => $product_id,
					);
				}
			}
		}

		return $affected;
	}
	/**
	 * The rows of a prepared query.
	 *
	 * @param string|null $sql Prepared query.
	 * @return array<array<string, mixed>>
	 * @throws DatabaseException When it cannot be read.
	 */
	private function rows( ?string $sql ): array {
		$wpdb = $this->wpdb;
		$rows = null === $sql ? null : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; the plugin's own tables, current state.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the gifts of collections.' );
		}

		return $rows;
	}
}
