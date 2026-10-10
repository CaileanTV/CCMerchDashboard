<?php
/**
 * Storage of the creators' default markup.
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
 * `merchboard_creators.default_markup_bps` (ADR-049): the creator's share of
 * the WooCommerce sale price in basis points 0–10 000,
 * 0 by default. Creator pricing is business data on the creator entity, not
 * WordPress user meta. No authorization or audit here;
 * CreatorPricingSettings does that.
 */
final class CreatorMarkupRepository {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * A creator's default markup, or null when the creator does not exist.
	 *
	 * @param int $creator_id Creator.
	 * @throws InvalidArgumentException When the id is not positive.
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function find( int $creator_id ): ?MarkupBps {
		if ( $creator_id < 1 ) {
			throw new InvalidArgumentException( 'Creator id must be positive.' );
		}

		$wpdb  = $this->wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT default_markup_bps FROM %i WHERE id = %d', $this->table(), $creator_id ) );

		if ( null === $value ) {
			if ( '' !== $wpdb->last_error ) {
				throw new DatabaseException( 'Could not read the creator markup.' );
			}

			return null;
		}

		if ( ! is_numeric( $value ) ) {
			throw new DatabaseException( 'Invalid creator markup.' );
		}

		try {
			return new MarkupBps( (int) $value );
		} catch ( InvalidArgumentException ) {
			throw new DatabaseException( 'Invalid creator markup.' );
		}
	}

	/**
	 * Stores a creator's default markup and verifies it by reading it back.
	 *
	 * @param int       $creator_id Creator.
	 * @param MarkupBps $markup     Markup (0–10 000, checked by MarkupBps).
	 * @throws DatabaseException When the creator does not exist or the value cannot be stored.
	 */
	public function save( int $creator_id, MarkupBps $markup ): void {
		if ( null === $this->find( $creator_id ) ) {
			throw new DatabaseException( 'Creator not found.' );
		}

		$updated = $this->wpdb->update(
			$this->table(),
			array( 'default_markup_bps' => $markup->bps ),
			array( 'id' => $creator_id ),
			array( '%d' ),
			array( '%d' )
		);

		// 0 rows when the value is unchanged; the read-back decides.
		$stored = $this->find( $creator_id );

		if ( false === $updated || null === $stored || $stored->bps !== $markup->bps ) {
			throw new DatabaseException( 'Could not store the creator markup.' );
		}
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::CREATORS );
	}
}
