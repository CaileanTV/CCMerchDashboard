<?php
/**
 * New prices of designer products after a new base price.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\DesignerPrices;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;
use MerchBoard\Modules\WooCommerce\JobQueue;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-080 pkt. 10 (step 30.11): a new base price reaches every designer
 * product of the base product that is in the shop, one Action Scheduler job
 * (`merchboard_designer_reprice`) per product under a named lock per
 * product. The creator's percent or amount of the live version stays; the
 * customer's price is computed from the base price now (DesignerPrices),
 * written only through WooCommerceDesignerWriter on the guard's proof and
 * stored with its base price (`shop_price`, `shop_base_price`), which the
 * settlement of a sale uses. Orders placed before keep their snapshot.
 *
 * Unchanged prices write nothing. A changed price is audited (system) and
 * notified to the creator. A failure is logged, tried again twice after ten
 * minutes and then notified to the administrator. It never throws.
 */
class DesignerRepricing {

	public const HOOK = 'merchboard_designer_reprice';

	private const LOCK = 'merchboard_designer_product_';

	private const ATTEMPTS = 3;

	private const RETRY_DELAY = 600;

	/**
	 * Products to reprice at the end of this request (no queue).
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
	 * @param WooCommerceDesignerWriter $writer   The only writer of prices.
	 * @param JobQueue                  $queue    Job queue.
	 * @param AuditTrail                $audit    Audit trail.
	 * @param Logger                    $logger   Technical log.
	 * @param int                       $decimals Store price decimals.
	 */
	public function __construct(
		private wpdb $wpdb,
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private DesignerProductGuard $guard,
		private WooCommerceDesignerWriter $writer,
		private JobQueue $queue,
		private AuditTrail $audit,
		private Logger $logger,
		private int $decimals
	) {
	}

	/**
	 * Queues a job for every live product of a base product
	 * (`merchboard_designer_base_price_changed`); never throws.
	 *
	 * @param mixed $base_id Base product.
	 */
	public function base_changed( mixed $base_id ): void {
		try {
			$ids = is_numeric( $base_id ) ? $this->products->in_shop_of_base( (int) $base_id, true ) : array();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The designer products of a new base price could not be read.', $e->context() );

			return;
		}

		foreach ( $ids as $id ) {
			$this->schedule( $id );
		}
	}

	/**
	 * Queues the job of one product, or reprices it at the end of the
	 * request without a queue.
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
	 * Reprices the products of this request that had no queue.
	 */
	public function run_pending(): void {
		$ids               = $this->at_shutdown;
		$this->at_shutdown = array();

		foreach ( $ids as $id ) {
			$this->run( $id, self::ATTEMPTS );
		}
	}

	/**
	 * Reprices one product (the job); never throws.
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
			// Another job holds the product (it may have read the base price already): try again a little later (review 30.15).
			$this->schedule( $id, $attempt, 30 );

			return;
		}

		try {
			$this->reprice( $id );
		} catch ( Throwable $e ) {
			$this->failed( $id, $attempt, $e );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
		}
	}

	/**
	 * Writes the price of the base price now when it differs.
	 *
	 * @param int $id Designer product.
	 * @throws DesignerException When it cannot be priced or written.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	private function reprice( int $id ): void {
		$product = $this->products->product( $id );

		if ( null === $product || DesignerProductRepository::LIVE !== $product['status'] ) {
			return;
		}

		$live  = $this->products->live( $id );
		$base  = $this->bases->find( (int) $product['base_id'] );
		$proof = $this->guard->for_row( $id );

		if ( null === $live || null === $base || BaseProduct::RETIRED === $base->status || null === $proof ) {
			return;
		}

		try {
			$price = DesignerPrices::compute( (int) $product['creator_id'], SalesAmount::format( $base->base_units ), (string) $live['profit_kind'], SalesAmount::format( SalesAmount::parse( (string) $live['profit_value'] ) ), $base->currency, $this->decimals );
		} catch ( InvalidArgumentException | OverflowException ) {
			self::refuse( DesignerException::DESIGN_PROFIT );
		}

		$units    = $price->price_units();
		$previous = null === $product['shop_price'] ? null : SalesAmount::parse( (string) $product['shop_price'] );
		$stored   = null === $product['shop_base_price'] ? null : SalesAmount::parse( (string) $product['shop_base_price'] );

		if ( $previous === $units && $stored === $price->base_units ) {
			return;
		}

		$this->writer->set_prices( $proof, $units, $this->decimals );
		$this->products->set_shop_price( $id, SalesAmount::format( $units ), SalesAmount::format( $price->base_units ) );

		if ( $previous === $units ) {
			return;
		}

		$this->audit->record_system(
			AuditAction::DesignerProductRepriced,
			(int) $product['creator_id'],
			array(
				'design_product_id' => $id,
				'product_id'        => $proof->wc_product_id,
				'previous_price'    => null === $previous ? null : SalesAmount::format( $previous ),
				'price'             => SalesAmount::format( $units ),
				'base_price'        => SalesAmount::format( $price->base_units ),
			)
		);

		if ( null !== $previous ) {
			do_action(
				'merchboard_notify',
				DesignerNotifications::PRICE_CHANGED,
				(int) $product['creator_id'],
				array(
					'title'          => (string) $live['title'],
					'price'          => SalesAmount::format( $units ),
					'previous_price' => SalesAmount::format( $previous ),
					'currency'       => $base->currency,
				),
				'designer_reprice:' . $id . ':' . SalesAmount::format( $units )
			);
		}
	}

	/**
	 * Logs a failure, tries again later and finally tells the administrator.
	 *
	 * @param int       $id      Designer product.
	 * @param int       $attempt Attempt.
	 * @param Throwable $e       Cause.
	 */
	private function failed( int $id, int $attempt, Throwable $e ): void {
		$this->logger->error(
			'A designer product could not get its new price.',
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

			return;
		}

		try {
			$live = $this->products->live( $id );
		} catch ( Throwable ) {
			$live = null;
		}

		do_action(
			'merchboard_notify',
			DesignerNotifications::APPLY_FAILED,
			null === $live ? null : (int) $live['creator_id'],
			array(
				'title'  => null === $live ? '#' . $id : (string) $live['title'],
				'reason' => $e instanceof DesignerException && DesignerException::PRICES_EXCL_TAX === $e->reason() ? $e->reason() : 'reprice',
			),
			'designer_reprice_failed:' . $id
		);
	}

	/**
	 * Refuses with a reason.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
