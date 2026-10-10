<?php
/**
 * Deletion of an order's stored history.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\Sales\SalesLockTimeoutException;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Permanently deletes Merch Dashboard's stored history of one order, e.g. a
 * test order (ADR-052), and records it in the audit trail in the same
 * transaction:
 *
 * - only with `merchboard_delete_order_history`;
 * - only when the WooCommerce order is in the trash or deleted, checked under
 *   the order's ledger lock, so no synchronization runs at the same time and
 *   none registers it again afterwards (ADR-038 Q8) unless the order is
 *   restored from the trash;
 * - only Merch Dashboard's rows of that order (ledger, cycles, stored tax,
 *   profit snapshots). The WooCommerce order, its items, refunds, products
 *   and prices are never read for writing or changed.
 */
final class OrderHistoryEraser {

	/**
	 * Constructor.
	 *
	 * @param SalesRepository   $ledger Ledger (lock, transaction, deletion).
	 * @param OrderStatusLookup $orders WooCommerce state of the order.
	 * @param AuditTrail        $audit  Audit trail.
	 * @param Logger            $logger Technical log (ids only).
	 */
	public function __construct( private SalesRepository $ledger, private OrderStatusLookup $orders, private AuditTrail $audit, private Logger $logger ) {
	}

	/**
	 * Deletes the order's stored history.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return array{ledger_rows: int, cycle_events: int, tax_rows: int, snapshots: int, creator_ids: list<int>, woocommerce_order: string}
	 * @throws OrderHistoryEraseException When the deletion is not allowed or failed (nothing was deleted).
	 */
	public function erase( int $order_id ): array {
		if ( ! current_user_can( Capabilities::DELETE_ORDER_HISTORY ) ) {
			throw new OrderHistoryEraseException( OrderHistoryEraseException::NOT_AUTHORIZED );
		}

		if ( $order_id < 1 ) {
			throw new OrderHistoryEraseException( OrderHistoryEraseException::NO_HISTORY );
		}

		try {
			return $this->ledger->with_order_lock(
				$order_id,
				function () use ( $order_id ): array {
					$state = $this->orders->erasable_state( $order_id );

					if ( null === $state ) {
						throw new OrderHistoryEraseException( OrderHistoryEraseException::ORDER_ACTIVE );
					}

					$result = $this->ledger->erase_order(
						$order_id,
						function ( array $counts, array $creator_ids ) use ( $order_id, $state ): void {
							if ( 0 === array_sum( $counts ) ) {
								// Rolls back the (empty) transaction; nothing to record.
								throw new OrderHistoryEraseException( OrderHistoryEraseException::NO_HISTORY );
							}

							$this->audit->record(
								AuditAction::OrderHistoryDeleted,
								null,
								array(
									'order_id'          => $order_id,
									'woocommerce_order' => $state,
									'ledger_rows'       => $counts['ledger_rows'],
									'cycle_events'      => $counts['cycle_events'],
									'tax_rows'          => $counts['tax_rows'],
									'snapshots'         => $counts['snapshots'],
									'creator_ids'       => $creator_ids,
								)
							);
						}
					);

					return $result + array( 'woocommerce_order' => $state );
				}
			);
		} catch ( SalesLockTimeoutException ) {
			throw new OrderHistoryEraseException( OrderHistoryEraseException::BUSY );
		} catch ( Throwable $e ) {
			// Thrown by the work above (and rolled back): passed on as it is.
			if ( $e instanceof OrderHistoryEraseException ) {
				throw new OrderHistoryEraseException( $e->reason() );
			}

			$this->logger->error(
				'The order history could not be deleted.',
				array(
					'order_id' => $order_id,
					'error'    => $e::class,
				)
			);

			throw new OrderHistoryEraseException( OrderHistoryEraseException::FAILED );
		}
	}
}
