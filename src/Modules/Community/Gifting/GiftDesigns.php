<?php
/**
 * The designs of a collection that can be given as a gift.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use Closure;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 6: on a collection's page the buyer says which design is
 * given. Nothing is preselected unless the buyer chose that design on the
 * page: the collection plugin's own first design is no choice.
 */
final class GiftDesigns {

	/**
	 * A design's creator and name when it can be given now, or null.
	 *
	 * @var Closure(int): ?array{creator: int, name: string}
	 */
	private Closure $gift;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?array{creator: int, name: string} $gift
	 *
	 * @param DesignCollections $collections The collections.
	 * @param Closure           $gift        A design's creator and product name when it can be given now, or null.
	 */
	public function __construct( private DesignCollections $collections, Closure $gift ) {
		$this->gift = $gift;
	}

	/**
	 * The choice on a collection's page: the designs, the one the buyer chose
	 * (0 for none) and their creator (null when several creators share the
	 * collection); null when no design can be given.
	 *
	 * @param int $collection_id Collection.
	 * @param int $asked         The design in the page's address, 0 without.
	 * @return array{designs: list<array{id: int, name: string}>, chosen: int, creator: int|null}|null
	 */
	public function choice( int $collection_id, int $asked ): ?array {
		$designs  = array();
		$creators = array();
		$chosen   = 0;

		foreach ( $this->collections->members( $collection_id ) as $member ) {
			$gift = ( $this->gift )( $member['id'] );

			if ( null === $gift ) {
				continue;
			}

			$label     = trim( $member['label'] );
			$designs[] = array(
				'id'   => $member['id'],
				'name' => '' === $label ? $gift['name'] : $label,
			);

			$creators[ $gift['creator'] ] = $gift['creator'];

			if ( $member['id'] === $asked ) {
				$chosen = $asked;
			}
		}

		if ( array() === $designs ) {
			return null;
		}

		return array(
			'designs' => $designs,
			'chosen'  => $chosen,
			'creator' => 1 === count( $creators ) ? array_values( $creators )[0] : null,
		);
	}
}
