<?php
/**
 * What the Twitch Extension panel shows on a channel.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchExtension;

use Closure;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The panel's content for a Twitch channel (ADR-112 pkt. 2–3): the creator's
 * name and up to 12 products with name and image, never a price, a link or a
 * supplier. Empty when the channel is not a creator's, the creator has not
 * switched the panel on, or the shop has switched it off. Cached 5 minutes
 * per creator; the creator's save clears it.
 */
final class TwitchExtensionPanel {

	/**
	 * How long the panel is cached.
	 */
	private const CACHE_SECONDS = 300;

	/**
	 * The creator of a connected Twitch channel, by broadcaster id.
	 *
	 * @var Closure(string): ?int
	 */
	private Closure $creator_of;

	/**
	 * A creator's display name.
	 *
	 * @var Closure(int): string
	 */
	private Closure $name;

	/**
	 * A creator's newest published products (creator, limit).
	 *
	 * @var Closure(int, int): list<int>
	 */
	private Closure $newest;

	/**
	 * A product as shown, or null when it is not published.
	 *
	 * @var Closure(int): (array{name: string, image: string|null}|null)
	 */
	private Closure $product;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(string): ?int $creator_of
	 * @phpstan-param Closure(int): string $name
	 * @phpstan-param Closure(int, int): list<int> $newest
	 * @phpstan-param Closure(int): (array{name: string, image: string|null}|null) $product
	 *
	 * @param TwitchExtensionRepository $settings   The creators' panels.
	 * @param Closure                   $creator_of The creator of a channel.
	 * @param Closure                   $name       A creator's name.
	 * @param Closure                   $newest     A creator's newest products.
	 * @param Closure                   $product    A product as shown.
	 */
	public function __construct( private TwitchExtensionRepository $settings, Closure $creator_of, Closure $name, Closure $newest, Closure $product ) {
		$this->creator_of = $creator_of;
		$this->name       = $name;
		$this->newest     = $newest;
		$this->product    = $product;
	}

	/**
	 * The panel for a channel.
	 *
	 * @phpstan-impure It reads and fills the cache.
	 *
	 * @param string $channel_id Twitch channel (broadcaster) id.
	 * @return array{enabled: bool, creator: string, products: list<array{id: int, name: string, image: string|null}>}
	 * @throws \MerchBoard\Core\Database\DatabaseException When the creator's data cannot be read.
	 */
	public function for_channel( string $channel_id ): array {
		$creator_id = Settings::twitch_extension_enabled() ? ( $this->creator_of )( $channel_id ) : null;

		if ( null === $creator_id || ! $this->settings->find( $creator_id )->enabled ) {
			return array(
				'enabled'  => false,
				'creator'  => '',
				'products' => array(),
			);
		}

		$cached = get_transient( TwitchExtensionRepository::CACHE_PREFIX . $creator_id );

		if ( is_array( $cached ) && isset( $cached['products'], $cached['creator'] ) && is_array( $cached['products'] ) && is_string( $cached['creator'] ) ) {
			/**
			 * The panel as stored below.
			 *
			 * @var array{enabled: bool, creator: string, products: list<array{id: int, name: string, image: string|null}>} $cached
			 */
			return $cached;
		}

		$panel = array(
			'enabled'  => true,
			'creator'  => ( $this->name )( $creator_id ),
			'products' => $this->products( $creator_id ),
		);
		set_transient( TwitchExtensionRepository::CACHE_PREFIX . $creator_id, $panel, self::CACHE_SECONDS );

		return $panel;
	}

	/**
	 * The products a creator's panel shows (also the dashboard's preview):
	 * the chosen ones in order, or the newest when none are chosen.
	 *
	 * @param int $creator_id Creator.
	 * @return list<array{id: int, name: string, image: string|null}>
	 * @throws \MerchBoard\Core\Database\DatabaseException When the creator's products cannot be read.
	 */
	public function products( int $creator_id ): array {
		$ids = $this->settings->find( $creator_id )->product_ids;
		$ids = array() === $ids ? ( $this->newest )( $creator_id, TwitchExtensionSettings::MAX_PRODUCTS ) : $ids;

		$products = array();

		foreach ( $ids as $id ) {
			$product = ( $this->product )( $id );

			if ( null !== $product ) {
				$products[] = array(
					'id'    => $id,
					'name'  => $product['name'],
					'image' => $product['image'],
				);
			}

			if ( count( $products ) >= TwitchExtensionSettings::MAX_PRODUCTS ) {
				break;
			}
		}

		return $products;
	}
}
