<?php
/**
 * The digitization fee of new embroidery files.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Fees;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerOrderFiles;
use MerchBoard\Modules\WooCommerce\JobQueue;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutAdjustmentRepository;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-086: a new embroidery file costs the company a digitization fee at the
 * partner, and the creator pays it.
 *
 * 1. The amount is set under Product designer → Fees. A version with an
 *    embroidered side keeps the amount the creator saw when it was sent
 *    (`fee_quote`).
 * 2. On approval each embroidered side's print file gets a fee, one per
 *    creator and file (its SHA-256), so a file used before costs nothing.
 * 3. The first order in a counted status of a version with the file charges
 *    it, whichever variant was bought and also on the creator's own order: a
 *    negative payout adjustment under the creator's payout lock, in the
 *    balance at once and on the next settlement document. The balance can go
 *    below zero and is offset against coming earnings (ADR-054).
 * 4. An administrator can cancel a charged fee: a positive adjustment undoes
 *    it, and the next sale charges it again. Adjustments are never changed.
 *
 * An order is looked at by a job, because a WooCommerce order save must never
 * sit around a transaction of ours. A failed job leaves the fee waiting, so
 * the next order charges it.
 */
class DesignerFees {

	public const HOOK = 'merchboard_designer_fee_charge';

	/**
	 * The most fees the administrator sees.
	 */
	public const LIST_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param DesignerFeeRepository      $fees        Fees.
	 * @param PayoutRepository           $payouts     The creator's payout lock.
	 * @param PayoutAdjustmentRepository $adjustments Adjustments.
	 * @param AuditTrail                 $audit       Audit.
	 * @param Transaction                $transaction Transaction.
	 * @param DesignerFiles              $files       Files.
	 * @param JobQueue                   $queue       Job queue.
	 * @param CountedOrderStatuses       $statuses    The statuses that count as a sale.
	 * @param Logger                     $logger      Technical log.
	 * @param Closure                    $clock       Current time (`Closure(): DateTimeImmutable`).
	 */
	public function __construct(
		private DesignerFeeRepository $fees,
		private PayoutRepository $payouts,
		private PayoutAdjustmentRepository $adjustments,
		private AuditTrail $audit,
		private Transaction $transaction,
		private DesignerFiles $files,
		private JobQueue $queue,
		private CountedOrderStatuses $statuses,
		private Logger $logger,
		private Closure $clock
	) {
	}

	/**
	 * The fee a version keeps when it is sent, or null without one.
	 *
	 * @param BaseProduct $base The base product.
	 * @param string[]    $used The used sides.
	 */
	public function quote( BaseProduct $base, array $used ): ?string {
		$units = Settings::digitization_fee_units();

		return $units > 0 && array() !== self::embroidered( $base, $used ) ? SalesAmount::format( $units ) : null;
	}

	/**
	 * Gives an approved version's embroidery files their fees. A file that
	 * cannot be read is left out and logged: it must not stop the approval.
	 *
	 * @param array<string, mixed> $version Version.
	 * @param BaseProduct          $base    The base product.
	 * @throws DatabaseException When a fee cannot be stored.
	 */
	public function approved( array $version, BaseProduct $base ): void {
		$amount = $version['fee_quote'] ?? null;

		if ( ! is_string( $amount ) || SalesAmount::parse( $amount ) < 1 ) {
			return;
		}

		// Every design's files: a colour design's new embroidery is a file of its own (ADR-096).
		foreach ( SubmissionFiles::prints( SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null ) ) as $prints ) {
			foreach ( self::embroidered( $base, array_map( 'strval', array_keys( $prints ) ) ) as $side ) {
				$name = $prints[ $side ]['file'] ?? null;
				$path = is_string( $name ) ? $this->files->path( $name ) : null;
				$hash = null === $path ? false : hash_file( 'sha256', $path );

				if ( false === $hash ) {
					$this->logger->warning( 'An embroidery file could not be read for its digitization fee.', array( 'version_id' => (int) $version['id'] ) );

					continue;
				}

				$this->fees->register( (int) $version['creator_id'], $hash, $amount, $base->currency, $side, (string) $version['title'], (int) $version['id'] );
			}
		}
	}

	/**
	 * Queues the job of an order that reached a counted status.
	 *
	 * @param mixed $order_id Order.
	 * @param mixed $from     Old status.
	 * @param mixed $to       New status.
	 */
	public function status_changed( mixed $order_id, mixed $from, mixed $to ): void {
		if ( is_numeric( $order_id ) && is_string( $to ) && in_array( $to, $this->statuses->statuses(), true ) ) {
			$this->enqueue( (int) $order_id );
		}
	}

	/**
	 * Queues the job of an order made in a counted status (no status change
	 * follows). The job checks the status.
	 *
	 * @param mixed $order_id Order.
	 */
	public function order_created( mixed $order_id ): void {
		if ( is_numeric( $order_id ) ) {
			$this->enqueue( (int) $order_id );
		}
	}

	/**
	 * Charges the waiting fees of an order's versions (the job); never throws.
	 *
	 * @param mixed $order_id Order.
	 */
	public function run( mixed $order_id ): void {
		$id    = is_numeric( $order_id ) ? (int) $order_id : 0;
		$order = $id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;

		if ( ! is_object( $order ) || ! in_array( $order->get_status(), $this->statuses->statuses(), true ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$version = (int) $item->get_meta( DesignerOrderFiles::ITEM_META, true );

			if ( $version > 0 ) {
				$this->charge_version( $version, $id );
			}
		}
	}

	/**
	 * Charges the waiting fees of a sold version; never throws.
	 *
	 * @param int $version_id Version.
	 * @param int $order_id   The order.
	 */
	public function charge_version( int $version_id, int $order_id ): void {
		try {
			foreach ( $this->fees->waiting_of_version( $version_id ) as $fee ) {
				$this->charge( $fee, $order_id );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'A digitization fee could not be charged; it waits for the next order.',
				array(
					'version_id' => $version_id,
					'order_id'   => $order_id,
					'exception'  => $e::class,
				)
			);
		}
	}

	/**
	 * Cancels a charged fee: it is paid back and waits for the next sale.
	 *
	 * @param int $id Fee.
	 * @throws DesignerException When the user may not, or it is not charged.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function cancel( int $id ): void {
		self::authorize();

		$fee = $this->fees->find( $id ) ?? self::refuse();

		$this->payouts->with_creator_lock(
			(int) $fee['creator_id'],
			fn() => $this->transaction->run(
				function () use ( $id, $fee ): void {
					$creator    = (int) $fee['creator_id'];
					$connection = $this->payouts->lock_connection( $creator );
					$now        = ( $this->clock )();

					if ( ! $this->fees->mark_waiting( $id, $connection ) ) {
						self::refuse();
					}

					$adjustment = $this->adjustments->insert_fee( $creator, (string) $fee['currency'], SalesAmount::parse( (string) $fee['amount'] ), $id, get_current_user_id(), $now, $connection );

					$this->audit->record(
						AuditAction::DesignerFeeCancelled,
						$creator,
						array(
							'fee_id'        => $id,
							'adjustment_id' => $adjustment,
							'amount'        => (string) $fee['amount'],
							'currency'      => (string) $fee['currency'],
						)
					);
				}
			)
		);
	}

	/**
	 * Sets the fee of new embroidery files.
	 *
	 * @param string $amount The amount, with a comma or a point; empty for none.
	 * @throws DesignerException When the user may not, or it is no amount.
	 */
	public function set_amount( string $amount ): void {
		self::authorize();

		$text = str_replace( ',', '.', trim( $amount ) );
		$text = '' === $text ? '0' : $text;

		if ( 1 !== preg_match( '/^\d{1,7}(\.\d{1,4})?$/', $text ) ) {
			self::refuse();
		}

		$before = Settings::digitization_fee_units();
		$units  = SalesAmount::parse( $text );

		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_DIGITIZATION_FEE => SalesAmount::format( $units ) ) ) );
		$this->audit->record(
			AuditAction::DesignerFeeChanged,
			null,
			array(
				'before' => SalesAmount::format( $before ),
				'after'  => SalesAmount::format( $units ),
			)
		);
	}

	/**
	 * Charges one fee under the creator's payout lock.
	 *
	 * @param int $id       Fee.
	 * @param int $order_id The order.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function charge( int $id, int $order_id ): void {
		$fee = $this->fees->find( $id );

		if ( null === $fee ) {
			return;
		}

		$this->payouts->with_creator_lock(
			(int) $fee['creator_id'],
			fn() => $this->transaction->run(
				function () use ( $id, $fee, $order_id ): void {
					$creator    = (int) $fee['creator_id'];
					$connection = $this->payouts->lock_connection( $creator );
					$now        = ( $this->clock )();
					$current    = $this->fees->find( $id );

					// Two orders at once: only the first charges.
					if ( DesignerFeeRepository::WAITING !== ( $current['status'] ?? '' ) ) {
						return;
					}

					// A cancelled fee keeps the order it was charged on: only a later order charges it
					// again (ADR-086, "the next sale"), never that order changing status (30.15 review).
					if ( is_numeric( $current['order_id'] ?? null ) && $order_id <= (int) $current['order_id'] ) {
						return;
					}

					$adjustment = $this->adjustments->insert_fee( $creator, (string) $fee['currency'], -SalesAmount::parse( (string) $fee['amount'] ), $id, null, $now, $connection );

					if ( ! $this->fees->mark_charged( $id, $adjustment, $order_id, $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $connection ) ) {
						throw new DatabaseException( 'The digitization fee changed while it was charged.' );
					}

					$this->audit->record(
						AuditAction::DesignerFeeCharged,
						$creator,
						array(
							'fee_id'        => $id,
							'adjustment_id' => $adjustment,
							'order_id'      => $order_id,
							'amount'        => (string) $fee['amount'],
							'currency'      => (string) $fee['currency'],
						)
					);
				}
			)
		);
	}

	/**
	 * Queues an order's job; a refused job is logged, and the next order
	 * charges the fee.
	 *
	 * @param int $order_id Order.
	 */
	private function enqueue( int $order_id ): void {
		if ( ! $this->queue->enqueue( self::HOOK, array( $order_id ) ) ) {
			$this->logger->warning( 'The digitization fees of an order could not be queued.', array( 'order_id' => $order_id ) );
		}
	}

	/**
	 * The embroidered ones among some sides.
	 *
	 * @param BaseProduct $base  The base product.
	 * @param string[]    $sides Sides.
	 * @return list<string>
	 */
	private static function embroidered( BaseProduct $base, array $sides ): array {
		$found = array();

		foreach ( $base->views as $view ) {
			if ( '' !== $view->thread_pack && in_array( $view->key, $sides, true ) ) {
				$found[] = $view->key;
			}
		}

		return $found;
	}

	/**
	 * Only who may handle payouts changes what creators pay.
	 *
	 * @throws DesignerException When the user may not.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			throw new DesignerException( DesignerException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Refuses a value or a state.
	 *
	 * @throws DesignerException Always.
	 */
	private static function refuse(): never {
		throw new DesignerException( DesignerException::FEE_INVALID );
	}
}
