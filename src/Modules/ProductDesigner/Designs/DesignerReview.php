<?php
/**
 * The administrator's decision on a submitted design.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\DesignerPrices;
use MerchBoard\Modules\WooCommerce\JobQueue;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * Approval (step 30.10, ADR-080 pkt. 3), `merchboard_manage_designer`:
 *
 * - approve(): the pending version is approved with the price it gives now
 *   (recorded; the base product must be unchanged since the submission and
 *   not retired; the shop gets the price of the base price when it is
 *   applied, step 30.11) and queued for DesignerApply (Action Scheduler;
 *   without a queue at the end of the request).
 * - reject(): with a note to the creator; a new draft with the same content
 *   and files continues it, so the creator can change it and send again.
 * - retry(): an approved version that failed is queued again.
 *
 * Each in one transaction with its audit record; the notification and the
 * job follow after the commit. The creator's change of a live product
 * starts with change() as a new draft (without the live version's files,
 * which orders point at).
 */
class DesignerReview {

	/**
	 * Longest note.
	 */
	public const MAX_NOTE = 2000;

	/**
	 * Seconds after which a running apply counts as stuck and may be tried again.
	 */
	public const STALE = 600;

	/**
	 * Seconds after which a provider's mockups that never finished no longer hold up an approval.
	 */
	public const STALE_MOCKUPS = 1800;

	/**
	 * Versions to apply at the end of this request (no queue).
	 *
	 * @var list<int>
	 */
	private array $at_shutdown = array();

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products    Designer products.
	 * @param BaseProductRepository     $bases       Base products.
	 * @param AuditTrail                $audit       Audit trail.
	 * @param Transaction               $transaction Transaction.
	 * @param JobQueue                  $queue       Job queue.
	 * @param int                       $decimals    Store price decimals.
	 * @param callable(int): void       $apply       Applies a version now (no queue).
	 */
	public function __construct(
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private AuditTrail $audit,
		private Transaction $transaction,
		private JobQueue $queue,
		private int $decimals,
		private $apply
	) {
	}

	/**
	 * Approves a pending version.
	 *
	 * @param int $version_id Version.
	 * @throws DesignerException When it may not or cannot be approved.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function approve( int $version_id ): void {
		self::authorize();

		$approved = $this->transaction->run(
			function () use ( $version_id ): array {
				$version = $this->pending( $version_id );
				$base    = $this->bases->find( (int) $version['base_id'] );

				if ( null !== $base && BaseProduct::RETIRED === $base->status ) {
					self::refuse( DesignerException::BASE_RETIRED );
				}

				// A provider's mockups (steps 30.12–30.13) are approved only once they are in, unless their job
				// stopped (no task touched for STALE_MOCKUPS seconds): then the creator's mockups are used (review 30.15).
				if ( 'running' === ( $version['mockup_state'] ?? '' ) && ! $this->products->mockups_stale( $version_id, self::STALE_MOCKUPS ) ) {
					self::refuse( DesignerException::MOCKUPS_PENDING );
				}

				if ( null === $base || $base->version !== (int) $version['base_version'] ) {
					self::refuse( DesignerException::DESIGN_STALE );
				}

				try {
					$price = SalesAmount::format( DesignerPrices::compute( 1, SalesAmount::format( $base->base_units ), (string) $version['profit_kind'], SalesAmount::format( SalesAmount::parse( (string) $version['profit_value'] ) ), $base->currency, $this->decimals )->price_units() );
				} catch ( InvalidArgumentException | OverflowException ) {
					self::refuse( DesignerException::DESIGN_PROFIT );
				}

				$this->products->decide( $version_id, DesignerProductRepository::APPROVED, null, get_current_user_id(), $price );
				$this->audit->record(
					AuditAction::DesignerVersionApproved,
					(int) $version['creator_id'],
					array(
						'design_product_id' => (int) $version['design_product_id'],
						'version_id'        => $version_id,
						'number'            => (int) $version['number'],
						'price'             => $price,
					)
				);

				return $version;
			}
		);

		$this->schedule( (int) $approved['id'] );
	}

	/**
	 * Rejects a pending version with a note to the creator.
	 *
	 * @param int    $version_id Version.
	 * @param string $note       Note (required).
	 * @throws DesignerException When it may not or cannot be rejected.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function reject( int $version_id, string $note ): void {
		self::authorize();
		$note = trim( sanitize_textarea_field( $note ) );

		if ( '' === $note || mb_strlen( $note ) > self::MAX_NOTE ) {
			self::refuse( DesignerException::REVIEW_NOTE );
		}

		$rejected = $this->transaction->run(
			function () use ( $version_id, $note ): array {
				$version = $this->pending( $version_id );

				$this->products->decide( $version_id, DesignerProductRepository::REJECTED, $note, get_current_user_id(), null );
				$this->products->copy_as_draft( $version, true );
				$this->audit->record(
					AuditAction::DesignerVersionRejected,
					(int) $version['creator_id'],
					array(
						'design_product_id' => (int) $version['design_product_id'],
						'version_id'        => $version_id,
						'number'            => (int) $version['number'],
					)
				);

				return $version;
			}
		);

		do_action(
			'merchboard_notify',
			DesignerNotifications::REJECTED,
			(int) $rejected['creator_id'],
			array(
				'title' => (string) $rejected['title'],
				'note'  => $note,
			),
			'designer_version:' . $version_id
		);
	}

	/**
	 * Queues a failed approved version again.
	 *
	 * @param int $version_id Version.
	 * @throws DesignerException When it may not or is not waiting to be applied.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function retry( int $version_id ): void {
		self::authorize();
		$version = $this->products->version( $version_id );

		if ( null === $version || DesignerProductRepository::APPROVED !== $version['status'] || ! self::retryable( $version ) ) {
			self::refuse( DesignerException::NOT_PENDING );
		}

		$this->products->set_apply( $version_id, DesignerProductRepository::APPLY_QUEUED, $version['apply_step'] ?? null, null );
		$this->audit->record(
			AuditAction::DesignerApplyRetried,
			(int) $version['creator_id'],
			array(
				'design_product_id' => (int) $version['design_product_id'],
				'version_id'        => $version_id,
			)
		);
		$this->schedule( $version_id );
	}

	/**
	 * Whether an approved version may be tried again: it failed, or it has
	 * been running for longer than STALE seconds (the job died, e.g. on a
	 * fatal error or a timeout; review 30.15).
	 *
	 * @param array<string, mixed> $version Version (apply_state, updated_at).
	 */
	public static function retryable( array $version ): bool {
		if ( DesignerProductRepository::APPLY_FAILED === ( $version['apply_state'] ?? null ) ) {
			return true;
		}

		$since = is_string( $version['updated_at'] ?? null ) ? strtotime( $version['updated_at'] . ' UTC' ) : false;

		return DesignerProductRepository::APPLY_RUNNING === ( $version['apply_state'] ?? null ) && false !== $since && time() - $since > self::STALE;
	}

	/**
	 * Applies the versions of this request that had no queue.
	 */
	public function apply_pending(): void {
		$ids               = $this->at_shutdown;
		$this->at_shutdown = array();

		foreach ( $ids as $id ) {
			( $this->apply )( $id );
		}
	}

	/**
	 * The pending version, locked.
	 *
	 * @param int $version_id Version.
	 * @return array<string, mixed>
	 * @throws DesignerException When it is not pending.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function pending( int $version_id ): array {
		$version = $this->products->version( $version_id, true );

		if ( null === $version || DesignerProductRepository::PENDING !== $version['status'] ) {
			self::refuse( DesignerException::NOT_PENDING );
		}

		return $version;
	}

	/**
	 * Queues the apply job, or runs it at the end of the request without a queue.
	 *
	 * @param int $version_id Version.
	 */
	private function schedule( int $version_id ): void {
		if ( $this->queue->available() && $this->queue->enqueue( DesignerApply::HOOK, array( $version_id ) ) ) {
			return;
		}

		if ( array() === $this->at_shutdown ) {
			add_action( 'shutdown', array( $this, 'apply_pending' ) );
		}

		$this->at_shutdown[] = $version_id;
	}

	/**
	 * Requires the designer capability.
	 *
	 * @throws DesignerException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			self::refuse( DesignerException::NOT_AUTHORIZED );
		}
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
