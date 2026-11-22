<?php
/**
 * Which collections can be given with free choice, and what may be chosen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use Closure;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120, status note 2026-11-22: FreeChoice's rule applied to a collection.
 * All its published designs count. One whose prices cannot be read means no
 * free choice, as it could not be said to cost the same.
 */
class FreeChoiceDesigns {

	/**
	 * A design with its prices now, and the price its gift would pay (its
	 * cheapest variant in stock; null with none); null when it is not sold or
	 * cannot be priced.
	 *
	 * @var Closure(int): ?array{design: array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}, pays: int|null}
	 */
	private Closure $read;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?array{design: array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}, pays: int|null} $read
	 *
	 * @param DesignCollections $collections The collections.
	 * @param Closure           $read        A design with its prices now (FreeChoicePrices::read()).
	 */
	public function __construct( private DesignCollections $collections, Closure $read ) {
		$this->read = $read;
	}

	/**
	 * A published collection that can be given with free choice: what is
	 * locked, and the design whose product the purchase is booked on (the one
	 * that pays least now, the first of equals); null when it cannot.
	 *
	 * @param int $collection_id Collection.
	 * @return array{lock: FreeChoiceLock, creator: int, product_id: int}|null
	 */
	public function offer( int $collection_id ): ?array {
		if ( ! $this->collections->is_collection( $collection_id ) || 'publish' !== get_post_status( $collection_id ) ) {
			return null;
		}

		$designs = array();
		$booked  = null;
		$least   = null;
		$creator = null;

		foreach ( $this->collections->members( $collection_id ) as $member ) {
			// The page shows only the published ones.
			if ( 'publish' !== get_post_status( $member['id'] ) ) {
				continue;
			}

			$read = ( $this->read )( $member['id'] );

			if ( null === $read ) {
				return null;
			}

			$designs[ $member['id'] ] = $read['design'];

			$creator ??= $read['design']['creator'];

			if ( null !== $read['pays'] && ( null === $least || $read['pays'] < $least ) ) {
				$least  = $read['pays'];
				$booked = $member['id'];
			}
		}

		if ( null === $creator || null === $booked || ! FreeChoice::offered( $designs ) ) {
			return null;
		}

		$name = html_entity_decode( wp_strip_all_tags( (string) get_post_field( 'post_title', $collection_id, 'raw' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return array(
			'lock'       => new FreeChoiceLock( $collection_id, $name, $designs ),
			'creator'    => $creator,
			'product_id' => $booked,
		);
	}

	/**
	 * The designs a winner may choose now.
	 *
	 * @param FreeChoiceLock $lock What was locked at the purchase.
	 * @return list<int>
	 */
	public function allowed( FreeChoiceLock $lock ): array {
		$current = array();

		foreach ( $lock->product_ids() as $id ) {
			$read = 'publish' === get_post_status( $id ) ? ( $this->read )( $id ) : null;

			if ( null !== $read ) {
				$current[ $id ] = $read['design'];
			}
		}

		return FreeChoice::allowed( $lock->designs, $current );
	}
}
