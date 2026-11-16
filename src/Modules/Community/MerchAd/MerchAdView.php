<?php
/**
 * What a creator's merch ad shows.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchAd;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * The ad's content (ADR-113): the chosen products (or the newest) with name
 * and shop image, and the look. A shop image without background (ADR-114)
 * stands free in the overlay; another lies in a tile. Never a price, a
 * supplier or a link.
 */
final class MerchAdView {

	/**
	 * A creator's newest published products (creator, limit).
	 *
	 * @var Closure(int, int): list<int>
	 */
	private Closure $newest;

	/**
	 * A product's name and shop image, or null when it is not published.
	 *
	 * @var Closure(int): (array{name: string, image: string|null, transparent?: bool}|null)
	 */
	private Closure $product;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int, int): list<int> $newest
	 * @phpstan-param Closure(int): (array{name: string, image: string|null, transparent?: bool}|null) $product
	 *
	 * @param MerchAdRepository $settings The creators' ads.
	 * @param Closure           $newest   A creator's newest products.
	 * @param Closure           $product  A product as shown.
	 */
	public function __construct( private MerchAdRepository $settings, Closure $newest, Closure $product ) {
		$this->newest  = $newest;
		$this->product = $product;
	}

	/**
	 * The products of a creator's ad, in order.
	 *
	 * @param int $creator_id Creator.
	 * @return list<array{id: int, name: string, image: string|null, transparent: bool}>
	 * @throws \MerchBoard\Core\Database\DatabaseException When the creator's products cannot be read.
	 */
	public function products( int $creator_id ): array {
		$settings = $this->settings->find( $creator_id );
		$ids      = array() === $settings->product_ids ? ( $this->newest )( $creator_id, MerchAdSettings::MAX_PRODUCTS ) : $settings->product_ids;
		$products = array();

		foreach ( $ids as $id ) {
			$product = ( $this->product )( $id );

			if ( null === $product ) {
				continue;
			}

			$products[] = array(
				'id'          => $id,
				'name'        => $product['name'],
				'image'       => $product['image'],
				// The shop image is without background (ADR-114).
				'transparent' => $product['transparent'] ?? false,
			);

			if ( count( $products ) >= MerchAdSettings::MAX_PRODUCTS ) {
				break;
			}
		}

		return $products;
	}

	/**
	 * What the overlay draws.
	 *
	 * @param int $creator_id Creator.
	 * @return array{enabled: bool, design: string, note: string, every: int, seconds: int, enter: string, leave: string, accent: string, theme: string, pill_position: string, pill_size: string, show_names: bool, stack_spread: int, products: list<array{name: string, image: string, cutout: bool}>}
	 * @throws \MerchBoard\Core\Database\DatabaseException When the creator's products cannot be read.
	 */
	public function overlay( int $creator_id ): array {
		$settings = $this->settings->find( $creator_id );
		$products = array();

		foreach ( $this->products( $creator_id ) as $product ) {
			if ( null !== $product['image'] ) {
				$products[] = array(
					'name'   => $product['name'],
					'image'  => $product['image'],
					'cutout' => $product['transparent'],
				);
			}
		}

		return array(
			'enabled'       => $settings->enabled,
			'design'        => $settings->design,
			'note'          => $settings->note,
			'every'         => $settings->every,
			'seconds'       => $settings->seconds,
			'enter'         => $settings->enter,
			'leave'         => $settings->leave,
			'accent'        => $settings->accent,
			'theme'         => $settings->theme,
			'pill_position' => $settings->pill_position,
			'pill_size'     => $settings->pill_size,
			'show_names'    => $settings->show_names,
			'stack_spread'  => $settings->stack_spread,
			'products'      => $products,
		);
	}

	/**
	 * A fingerprint of what the overlay draws; it reloads when it changes.
	 *
	 * @param int $creator_id Creator.
	 * @throws \MerchBoard\Core\Database\DatabaseException When the creator's products cannot be read.
	 */
	public function version( int $creator_id ): string {
		return substr( md5( (string) wp_json_encode( $this->overlay( $creator_id ) ) ), 0, 16 );
	}
}
