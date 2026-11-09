<?php
/**
 * Designer products stay out of Meta's catalog (Facebook and Instagram).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Shop;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\WooCommerce\JobQueue;
use Throwable;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The owner's decision 2026-11-09 (1.7.7): products made in the product
 * designer are never synced to Meta by "Facebook for WooCommerce" (Meta for
 * WooCommerce).
 *
 * - **Filter:** `wc_facebook_should_sync_product` (the plugin's own filter,
 *   ProductValidator::validate_product_sync_field()) answers false for a
 *   designer product and its variations. A designer product is only what
 *   DesignerProductGuard proves; every other product is left to the plugin.
 *   Should the designer's data be unreadable, the answer is false (closed),
 *   so a designer product never slips through.
 * - **Once after the update:** the job `merchboard_designer_meta_catalog`
 *   goes through the products the designer made, 50 at a time, and switches
 *   their sync off with the plugin's own
 *   `\WooCommerce\Facebook\Products::disable_sync_for_products()`, which
 *   also deletes them from Meta's catalog. Without the plugin there is
 *   nothing to remove, and the job only counts as done.
 *
 * Nothing is sent to Meta by Merch Dashboard itself, and no other product is
 * touched. Never throws.
 */
class MetaCatalogExclusion {

	public const FILTER = 'wc_facebook_should_sync_product';

	public const HOOK = 'merchboard_designer_meta_catalog';

	/**
	 * Whether the products already at Meta were removed (an autoloaded option).
	 */
	public const OPTION = 'merchboard_designer_meta_catalog';

	/**
	 * The plugin's class that switches a product's sync off.
	 */
	public const PLUGIN_PRODUCTS = 'WooCommerce\Facebook\Products';

	private const BATCH = 50;

	private const RETRY_DELAY = 300;

	/**
	 * Whether a parent product is a designer product, by id (this request).
	 *
	 * @var array<int, bool>
	 */
	private array $known = array();

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products Designer products.
	 * @param DesignerProductGuard      $guard    The legacy guard.
	 * @param JobQueue                  $queue    Job queue.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct(
		private DesignerProductRepository $products,
		private DesignerProductGuard $guard,
		private JobQueue $queue,
		private Logger $logger
	) {
	}

	/**
	 * `wc_facebook_should_sync_product`: false for a designer product.
	 *
	 * @param mixed $sync    Whether to sync, as decided so far.
	 * @param mixed $product The product or variation.
	 * @return mixed
	 */
	public function should_sync( mixed $sync, mixed $product ): mixed {
		if ( false === $sync || ! $product instanceof WC_Product ) {
			return $sync;
		}

		$parent = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();

		return $this->is_designer( $parent ) ? false : $sync;
	}

	/**
	 * Whether the products at Meta still have to be removed.
	 */
	public static function due(): bool {
		return '1' !== (string) get_option( self::OPTION, '' );
	}

	/**
	 * Queues the removal once (`admin_init` after the update); never throws.
	 */
	public function start(): void {
		if ( ! self::due() || ! $this->queue->available() || $this->queue->has_any( self::HOOK ) ) {
			return;
		}

		$this->queue->enqueue( self::HOOK, array( 0 ) );
	}

	/**
	 * Switches the sync off for the designer's products after an id and
	 * queues the next ones (the job); never throws.
	 *
	 * @param mixed $after The last designer product done.
	 */
	public function run( mixed $after = 0 ): void {
		$after = is_numeric( $after ) ? max( 0, (int) $after ) : 0;

		if ( ! class_exists( self::PLUGIN_PRODUCTS ) ) {
			// No Meta plugin: nothing was synced, and the filter keeps it so.
			update_option( self::OPTION, '1', true );

			return;
		}

		try {
			$rows = $this->products->shop_batch( $after, self::BATCH );
		} catch ( Throwable $e ) {
			$this->logger->error( 'The designer products could not be read for Meta\'s catalog.', array( 'exception' => $e::class ) );
			$this->queue->enqueue( self::HOOK, array( $after ), self::RETRY_DELAY );

			return;
		}

		foreach ( $rows as $row ) {
			$this->remove( $row['wc_product_id'] );
		}

		if ( count( $rows ) === self::BATCH ) {
			$this->queue->enqueue( self::HOOK, array( $rows[ self::BATCH - 1 ]['id'] ) );

			return;
		}

		update_option( self::OPTION, '1', true );
	}

	/**
	 * Switches one designer product's sync off with the plugin, which also
	 * deletes it from Meta's catalog; a product that is not a designer
	 * product (by the guard) is left alone.
	 *
	 * @param int $wc_product_id WooCommerce product.
	 */
	private function remove( int $wc_product_id ): void {
		try {
			$product = $this->is_designer( $wc_product_id ) ? wc_get_product( $wc_product_id ) : null;

			// A plugin version without the method throws a TypeError, caught below.
			if ( $product instanceof WC_Product ) {
				call_user_func( array( self::PLUGIN_PRODUCTS, 'disable_sync_for_products' ), array( $product ) );
			}
		} catch ( Throwable $e ) {
			$this->logger->warning(
				'A designer product could not be taken out of Meta\'s catalog.',
				array(
					'product_id' => $wc_product_id,
					'exception'  => $e::class,
				)
			);
		}
	}

	/**
	 * Whether a parent product is a designer product; true when it cannot be
	 * told (closed).
	 *
	 * @param int $wc_product_id WooCommerce parent product.
	 */
	private function is_designer( int $wc_product_id ): bool {
		if ( isset( $this->known[ $wc_product_id ] ) ) {
			return $this->known[ $wc_product_id ];
		}

		try {
			$designer = null !== $this->guard->guard( $wc_product_id );
		} catch ( Throwable $e ) {
			$this->logger->warning(
				'It could not be told whether a product is a designer product; it is kept out of Meta\'s catalog.',
				array(
					'product_id' => $wc_product_id,
					'exception'  => $e::class,
				)
			);

			return true;
		}

		$this->known[ $wc_product_id ] = $designer;

		return $designer;
	}
}
