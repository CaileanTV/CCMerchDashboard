<?php
/**
 * Storage of product-specific creator shares.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_pricing_overrides` (schema version 8, ADR-049): at most one
 * share per parent product (primary key), bound to the owner. Current state:
 * setting it again replaces it; "use creator markup" deletes it. No
 * authorization or audit here; CreatorPricingSettings does that, in its
 * transactions.
 */
final class ProductMarkupRepository {

	private const COLUMNS = 'product_id, creator_id, markup_bps, created_at, updated_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The share of a parent product, or null.
	 *
	 * @param int $product_id Parent product.
	 * @throws InvalidArgumentException When the id is not positive.
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function find( int $product_id ): ?ProductMarkup {
		if ( $product_id < 1 ) {
			throw new InvalidArgumentException( 'Product id must be positive.' );
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE product_id = %d', $this->table(), $product_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant column list; values are prepared.

		if ( ! is_array( $row ) ) {
			if ( '' !== $wpdb->last_error ) {
				throw new DatabaseException( 'Could not read the product markup.' );
			}

			return null;
		}

		return self::hydrate( $row );
	}

	/**
	 * Shares set for a creator, by product id.
	 *
	 * @param int $creator_id Creator.
	 * @return list<ProductMarkup>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function for_creator( int $creator_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d ORDER BY product_id ASC', $this->table(), $creator_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant column list; values are prepared.

		if ( ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the product markups.' );
		}

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Sets (inserts or replaces) the share of a product and returns it as
	 * stored. The creation time is kept when it is replaced.
	 *
	 * @param ProductMarkup $markup Share.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save( ProductMarkup $markup ): ProductMarkup {
		$now      = PricingRows::datetime( PricingRows::now() );
		$existing = $this->find( $markup->product_id );
		$data     = array(
			'creator_id' => $markup->creator_id,
			'markup_bps' => $markup->markup->bps,
			'updated_at' => $now,
		);

		if ( null === $existing ) {
			$written = $this->wpdb->insert(
				$this->table(),
				array_merge(
					array( 'product_id' => $markup->product_id ),
					$data,
					array( 'created_at' => $now )
				),
				array( '%d', '%d', '%d', '%s', '%s' )
			);
		} else {
			$written = $this->wpdb->update( $this->table(), $data, array( 'product_id' => $markup->product_id ), array( '%d', '%d', '%s' ), array( '%d' ) );
		}

		$stored = $this->find( $markup->product_id );

		if ( false === $written || null === $stored
			|| $stored->creator_id !== $markup->creator_id
			|| $stored->markup->bps !== $markup->markup->bps ) {
			throw new DatabaseException( 'Could not store the product markup.' );
		}

		return $stored;
	}

	/**
	 * Removes the share of a product (none is fine).
	 *
	 * @param int $product_id Parent product.
	 * @return bool Whether a share was removed.
	 * @throws InvalidArgumentException When the id is not positive.
	 * @throws DatabaseException When it cannot be removed.
	 */
	public function delete( int $product_id ): bool {
		if ( $product_id < 1 ) {
			throw new InvalidArgumentException( 'Product id must be positive.' );
		}

		$deleted = $this->wpdb->delete( $this->table(), array( 'product_id' => $product_id ), array( '%d' ) );

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not remove the product markup.' );
		}

		return $deleted > 0;
	}

	/**
	 * A share from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): ProductMarkup {
		try {
			if ( ! is_numeric( $row['markup_bps'] ?? null ) ) {
				throw new DatabaseException( 'Invalid product markup row.' );
			}

			return new ProductMarkup(
				PricingRows::id( $row['product_id'] ?? null ),
				PricingRows::id( $row['creator_id'] ?? null ),
				new MarkupBps( (int) $row['markup_bps'] ),
				PricingRows::time( $row['created_at'] ?? null ),
				PricingRows::time( $row['updated_at'] ?? null )
			);
		} catch ( InvalidArgumentException ) {
			throw new DatabaseException( 'Invalid product markup row.' );
		}
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::PRICING_OVERRIDES );
	}
}
