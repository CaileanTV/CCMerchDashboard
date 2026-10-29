<?php
/**
 * The designer products of a retired base product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;
use MerchBoard\Modules\WooCommerce\JobQueue;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-080 pkt. 10 (step 30.11): when an administrator retires a base
 * product (`merchboard_designer_base_retired`),
 *
 * - its designs waiting for approval are rejected with a note (the creator
 *   is told; nothing of a retired base product can be approved);
 * - each of its products with a WooCommerce product is taken out of the
 *   shop by an Action Scheduler job (`merchboard_designer_retire`) under the
 *   product's lock: a draft again through WooCommerceDesignerWriter on the
 *   guard's proof, never deleted (orders point at it), the row `retired`,
 *   audited (system) and the creator told when it was in the shop.
 *
 * Never throws; a failed job is logged and tried again after 5 minutes, at
 * most ATTEMPTS times (review 30.15).
 */
class DesignerRetirement {

	public const HOOK = 'merchboard_designer_retire';

	private const LOCK = 'merchboard_designer_product_';

	/**
	 * Most runs of a product's job.
	 */
	private const ATTEMPTS = 5;

	/**
	 * Products to take out at the end of this request (no queue).
	 *
	 * @var list<int>
	 */
	private array $at_shutdown = array();

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb        Database (the named lock).
	 * @param DesignerProductRepository $products    Designer products.
	 * @param DesignerProductGuard      $guard       The legacy guard.
	 * @param WooCommerceDesignerWriter $writer      The only writer of designer products.
	 * @param JobQueue                  $queue       Job queue.
	 * @param AuditTrail                $audit       Audit trail.
	 * @param Transaction               $transaction Transaction.
	 * @param Logger                    $logger      Technical log.
	 */
	public function __construct(
		private wpdb $wpdb,
		private DesignerProductRepository $products,
		private DesignerProductGuard $guard,
		private WooCommerceDesignerWriter $writer,
		private JobQueue $queue,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Logger $logger
	) {
	}

	/**
	 * Rejects the waiting designs and queues the products of a retired base
	 * product; never throws.
	 *
	 * @param mixed $base_id Base product.
	 */
	public function base_retired( mixed $base_id ): void {
		if ( ! is_numeric( $base_id ) ) {
			return;
		}

		try {
			$pending = $this->products->pending_of_base( (int) $base_id );
			$shop    = $this->products->in_shop_of_base( (int) $base_id, false );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The designs of a retired base product could not be read.', $e->context() );

			return;
		}

		foreach ( $pending as $version ) {
			try {
				$this->reject( $version );
			} catch ( Throwable $e ) {
				$this->logger->error(
					'A waiting design of a retired base product could not be rejected.',
					array(
						'version_id' => $version,
						'exception'  => $e::class,
					)
				);
			}
		}

		foreach ( $shop as $id ) {
			if ( $this->queue->available() && $this->queue->enqueue( self::HOOK, array( $id ) ) ) {
				continue;
			}

			if ( array() === $this->at_shutdown ) {
				add_action( 'shutdown', array( $this, 'run_pending' ) );
			}

			$this->at_shutdown[] = $id;
		}
	}

	/**
	 * Takes the products of this request that had no queue out of the shop.
	 */
	public function run_pending(): void {
		$ids               = $this->at_shutdown;
		$this->at_shutdown = array();

		foreach ( $ids as $id ) {
			$this->run( $id );
		}
	}

	/**
	 * Takes one product out of the shop (the job); never throws.
	 *
	 * @param mixed $product_id Designer product.
	 * @param mixed $attempt    Attempt (1 for the first).
	 */
	public function run( mixed $product_id, mixed $attempt = 1 ): void {
		$id      = is_numeric( $product_id ) ? (int) $product_id : 0;
		$attempt = is_numeric( $attempt ) ? max( 1, (int) $attempt ) : 1;
		$wpdb    = $this->wpdb;

		if ( $id < 1 ) {
			return;
		}

		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK . $id, 10 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		if ( ! is_numeric( $acquired ) || 1 !== (int) $acquired ) {
			$this->logger->warning( 'A designer product of a retired base product was busy; it is taken out of the shop later.', array( 'design_product_id' => $id ) );
				$this->queue->enqueue( self::HOOK, array( $id, $attempt ), 60 );

			return;
		}

		try {
			$this->unpublish( $id );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'A designer product of a retired base product could not be taken out of the shop.',
				array_merge(
					$e instanceof DatabaseException ? $e->context() : array(),
					array(
						'design_product_id' => $id,
						'exception'         => $e::class,
						'attempt'           => $attempt,
					)
				)
			);

			if ( $attempt < self::ATTEMPTS ) {
				$this->queue->enqueue( self::HOOK, array( $id, $attempt + 1 ), 300 );
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
		}
	}

	/**
	 * Takes a product out of the shop and records it.
	 *
	 * @param int $id Designer product.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function unpublish( int $id ): void {
		$product = $this->products->product( $id );

		if ( null === $product || DesignerProductRepository::RETIRED === $product['status'] ) {
			return;
		}

		$proof = $this->guard->for_row( $id );

		if ( null !== $proof ) {
			$this->writer->unpublish( $proof );
		}

		$was_live = DesignerProductRepository::LIVE === $product['status'];
		$live     = $this->products->live( $id );

		$this->transaction->run(
			function () use ( $id, $product, $proof ): void {
				$this->products->retire( $id );
				$this->audit->record_system(
					AuditAction::DesignerProductUnpublished,
					(int) $product['creator_id'],
					array(
						'design_product_id' => $id,
						'product_id'        => $proof?->wc_product_id,
						'reason'            => 'base_retired',
					)
				);
			}
		);

		if ( $was_live && null !== $live ) {
			do_action( 'merchboard_notify', DesignerNotifications::UNPUBLISHED, (int) $product['creator_id'], array( 'title' => (string) $live['title'] ), 'designer_retired:' . $id );
		}
	}

	/**
	 * Rejects a waiting design of a retired base product.
	 *
	 * @param int $version_id Version.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function reject( int $version_id ): void {
		$note    = __( 'We no longer offer this base product, so the design cannot be approved. You are welcome to design on another product.', 'merchboard' );
		$version = $this->transaction->run(
			function () use ( $version_id, $note ): ?array {
				$version = $this->products->version( $version_id, true );

				if ( null === $version || DesignerProductRepository::PENDING !== $version['status'] ) {
					return null;
				}

				$this->products->decide( $version_id, DesignerProductRepository::REJECTED, $note, get_current_user_id(), null );
				$this->audit->record(
					AuditAction::DesignerVersionRejected,
					(int) $version['creator_id'],
					array(
						'design_product_id' => (int) $version['design_product_id'],
						'version_id'        => $version_id,
						'number'            => (int) $version['number'],
						'reason'            => 'base_retired',
					)
				);

				return $version;
			}
		);

		if ( null !== $version ) {
			do_action(
				'merchboard_notify',
				DesignerNotifications::REJECTED,
				(int) $version['creator_id'],
				array(
					'title' => (string) $version['title'],
					'note'  => $note,
				),
				'designer_version:' . $version_id
			);
		}
	}
}
