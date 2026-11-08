<?php
/**
 * The product tags of designer products after their base product's tags
 * changed.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;
use MerchBoard\Modules\WooCommerce\JobQueue;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-100: a change of a base product's product tags reaches every designer
 * product of the base product that is in the shop, one Action Scheduler job
 * (`merchboard_designer_tags`) per product under the same named lock as the
 * repricing and the apply pipeline. The tags are written only through
 * WooCommerceDesignerWriter on the guard's proof; tags set by hand stay.
 * Legacy products are never touched (the guard gives them no proof).
 *
 * The base product's change is audited when it is saved; the job itself
 * writes no audit. A failure is logged and tried again twice after ten
 * minutes. It never throws.
 */
class DesignerTagSync {

	public const HOOK = 'merchboard_designer_tags';

	private const LOCK = 'merchboard_designer_product_';

	private const ATTEMPTS = 3;

	private const RETRY_DELAY = 600;

	/**
	 * Products to update at the end of this request (no queue).
	 *
	 * @var list<int>
	 */
	private array $at_shutdown = array();

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb     Database (the named lock).
	 * @param DesignerProductRepository $products Designer products.
	 * @param BaseProductRepository     $bases    Base products.
	 * @param DesignerProductGuard      $guard    The legacy guard.
	 * @param WooCommerceDesignerWriter $writer   The only writer of designer products.
	 * @param JobQueue                  $queue    Job queue.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct(
		private wpdb $wpdb,
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private DesignerProductGuard $guard,
		private WooCommerceDesignerWriter $writer,
		private JobQueue $queue,
		private Logger $logger
	) {
	}

	/**
	 * Queues a job for every product in the shop of a base product
	 * (`merchboard_designer_base_tags_changed`); never throws.
	 *
	 * @param mixed $base_id Base product.
	 */
	public function base_changed( mixed $base_id ): void {
		try {
			$ids = is_numeric( $base_id ) ? $this->products->in_shop_of_base( (int) $base_id, true ) : array();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The designer products of changed product tags could not be read.', $e->context() );

			return;
		}

		foreach ( $ids as $id ) {
			$this->schedule( $id );
		}
	}

	/**
	 * Queues the job of one product, or updates it at the end of the request
	 * without a queue.
	 *
	 * @param int $product_id Designer product.
	 * @param int $attempt    Attempt (1 for the first).
	 * @param int $delay      Seconds to wait.
	 */
	public function schedule( int $product_id, int $attempt = 1, int $delay = 0 ): void {
		if ( $this->queue->available() && $this->queue->enqueue( self::HOOK, array( $product_id, $attempt ), $delay ) ) {
			return;
		}

		if ( array() === $this->at_shutdown ) {
			add_action( 'shutdown', array( $this, 'run_pending' ) );
		}

		$this->at_shutdown[] = $product_id;
	}

	/**
	 * Updates the products of this request that had no queue.
	 */
	public function run_pending(): void {
		$ids               = $this->at_shutdown;
		$this->at_shutdown = array();

		foreach ( $ids as $id ) {
			$this->run( $id, self::ATTEMPTS );
		}
	}

	/**
	 * Gives one product its base product's tags (the job); never throws.
	 *
	 * @param mixed $product_id Designer product.
	 * @param mixed $attempt    Attempt.
	 */
	public function run( mixed $product_id, mixed $attempt = 1 ): void {
		$id      = is_numeric( $product_id ) ? (int) $product_id : 0;
		$attempt = is_numeric( $attempt ) ? max( 1, (int) $attempt ) : 1;
		$wpdb    = $this->wpdb;

		if ( $id < 1 ) {
			return;
		}

		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK . $id, 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		if ( ! is_numeric( $acquired ) || 1 !== (int) $acquired ) {
			// Another job holds the product: try again a little later.
			$this->schedule( $id, $attempt, 30 );

			return;
		}

		try {
			$this->sync( $id );
		} catch ( Throwable $e ) {
			$this->failed( $id, $attempt, $e );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
		}
	}

	/**
	 * Writes the base product's tags on a product in the shop.
	 *
	 * @param int $id Designer product.
	 * @throws DatabaseException When it cannot be read or saved.
	 */
	private function sync( int $id ): void {
		$product = $this->products->product( $id );

		if ( null === $product || DesignerProductRepository::LIVE !== $product['status'] ) {
			return;
		}

		$base  = $this->bases->find( (int) $product['base_id'] );
		$proof = $this->guard->for_row( $id );

		if ( null === $base || BaseProduct::RETIRED === $base->status || null === $proof ) {
			return;
		}

		$this->writer->set_tags( $proof, $base->tag_ids );
	}

	/**
	 * Logs a failure and tries again later.
	 *
	 * @param int       $id      Designer product.
	 * @param int       $attempt Attempt.
	 * @param Throwable $e       Cause.
	 */
	private function failed( int $id, int $attempt, Throwable $e ): void {
		$this->logger->error(
			'A designer product could not get its product tags.',
			array_merge(
				$e instanceof DatabaseException ? $e->context() : array(),
				array(
					'design_product_id' => $id,
					'attempt'           => $attempt,
					'exception'         => $e::class,
					'reason'            => $e instanceof DesignerException ? $e->reason() : 'error',
				)
			)
		);

		if ( $attempt < self::ATTEMPTS ) {
			$this->schedule( $id, $attempt + 1, self::RETRY_DELAY );
		}
	}
}
