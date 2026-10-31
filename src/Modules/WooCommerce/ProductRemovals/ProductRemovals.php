<?php
/**
 * Requests to remove a legacy product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\ProductRemovals;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-083: a creator asks to have a legacy product removed, and an
 * administrator handles it by hand. A legacy product is any product of the
 * creator's that is not a designer product (DesignerProductGuard).
 *
 * Nothing here reads or writes a product beyond its name: it stays in the
 * shop while the request is pending, and the administrator removes it in
 * WooCommerce, so nothing can be lost by a request or a click here. Closing a
 * request says that the administrator handled it, not that the product is
 * gone. A product has at most one pending request; a declined product can be
 * requested again.
 */
final class ProductRemovals {

	/**
	 * Longest note.
	 */
	private const NOTE_MAX_LENGTH = 500;

	/**
	 * Longest stored product name.
	 */
	private const NAME_MAX_LENGTH = 191;

	/**
	 * Clock.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Whether a product is a designer product.
	 *
	 * @var Closure(int): bool
	 */
	private Closure $is_designer;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): bool $is_designer
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 *
	 * @param ProductRemovalRepository $requests    Requests.
	 * @param CreatorProducts          $products    The creators' products.
	 * @param Transaction              $transaction The project's transaction helper.
	 * @param AuditTrail               $audit       Audit trail.
	 * @param Closure                  $is_designer Whether a product is a designer product; it throws when that cannot be read.
	 * @param Closure|null             $clock       Time; UTC now by default.
	 */
	public function __construct(
		private ProductRemovalRepository $requests,
		private CreatorProducts $products,
		private Transaction $transaction,
		private AuditTrail $audit,
		Closure $is_designer,
		?Closure $clock = null
	) {
		$this->is_designer = $is_designer;
		$this->clock       = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Requests the removal of one of the creator's legacy products. A request
	 * already pending is the answer to a second one.
	 *
	 * @param Creator $creator    Creator from CreatorContext.
	 * @param int     $product_id WooCommerce parent product.
	 * @throws DatabaseException When it cannot be read or stored (nothing is kept).
	 */
	public function request( Creator $creator, int $product_id ): ProductRemovalRequest {
		if ( ! $this->products->owns( $creator->id, $product_id ) ) {
			self::refuse( ProductRemovalException::NOT_FOUND );
		}

		if ( ( $this->is_designer )( $product_id ) ) {
			self::refuse( ProductRemovalException::DESIGNER_PRODUCT );
		}

		$pending = $this->requests->pending_for_product( $product_id );

		if ( null !== $pending ) {
			return $pending;
		}

		$name = mb_substr( sanitize_text_field( (string) get_post_field( 'post_title', $product_id, 'raw' ) ), 0, self::NAME_MAX_LENGTH );

		try {
			$id = $this->transaction->run(
				function () use ( $creator, $product_id, $name ): int {
					$id = $this->requests->insert( $creator->id, $product_id, $name, self::user(), ( $this->clock )() );

					$this->audit->record(
						AuditAction::ProductRemovalRequested,
						$creator->id,
						array(
							'removal_id' => $id,
							'product_id' => $product_id,
						)
					);

					return $id;
				}
			);
		} catch ( DatabaseException $e ) {
			// A parallel request may just have stored it; the UNIQUE key refused this one.
			return $this->requests->pending_for_product( $product_id ) ?? throw $e;
		}

		do_action( 'merchboard_notify', 'admin_product_removal_requested', $creator->id, array( 'product' => $name ), 'removal:' . $id );

		return $this->read( $id );
	}

	/**
	 * Closes a request as handled: the administrator has dealt with the
	 * product in WooCommerce.
	 *
	 * @param int $id Request.
	 * @throws ProductRemovalException When it is refused.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function complete( int $id ): ProductRemovalRequest {
		$request = $this->close( $id, ProductRemovalStatus::Completed, null, AuditAction::ProductRemovalCompleted );

		do_action( 'merchboard_notify', 'product_removal_completed', $request->creator_id, array( 'product' => $request->product_name ), 'removal:' . $id );

		return $request;
	}

	/**
	 * Declines a request with a note to the creator. The product stays as it is.
	 *
	 * @param int    $id   Request.
	 * @param string $note Why.
	 * @throws ProductRemovalException When it is refused.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function decline( int $id, string $note ): ProductRemovalRequest {
		$note = mb_substr( trim( sanitize_text_field( $note ) ), 0, self::NOTE_MAX_LENGTH );

		if ( '' === $note ) {
			self::authorize();
			self::refuse( ProductRemovalException::NOTE_REQUIRED );
		}

		$request = $this->close( $id, ProductRemovalStatus::Declined, $note, AuditAction::ProductRemovalDeclined );

		do_action(
			'merchboard_notify',
			'product_removal_declined',
			$request->creator_id,
			array(
				'product' => $request->product_name,
				'note'    => $note,
			),
			'removal:' . $id
		);

		return $request;
	}

	/**
	 * Closes a pending request and audits it.
	 *
	 * @param int                  $id     Request.
	 * @param ProductRemovalStatus $status Completed or Declined.
	 * @param string|null          $note   Note to the creator.
	 * @param AuditAction          $action What to audit.
	 * @throws ProductRemovalException When there is no such request.
	 */
	private function close( int $id, ProductRemovalStatus $status, ?string $note, AuditAction $action ): ProductRemovalRequest {
		self::authorize();

		$request = $this->requests->find( $id ) ?? throw new ProductRemovalException( ProductRemovalException::NOT_FOUND );

		$this->transaction->run(
			function () use ( $request, $status, $note, $action ): void {
				if ( ! $this->requests->close( $request->id, $status, $note, self::user(), ( $this->clock )() ) ) {
					self::refuse( ProductRemovalException::NOT_PENDING );
				}

				$context = array(
					'removal_id' => $request->id,
					'product_id' => $request->product_id,
				);

				if ( null !== $note ) {
					$context['decision_note'] = $note;
				}

				$this->audit->record( $action, $request->creator_id, $context );
			}
		);

		return $this->read( $id );
	}

	/**
	 * A stored request.
	 *
	 * @param int $id Request.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function read( int $id ): ProductRemovalRequest {
		return $this->requests->find( $id ) ?? throw new DatabaseException( 'The product removal request could not be read back.' );
	}

	/**
	 * The current WordPress user, null when none.
	 */
	private static function user(): ?int {
		$user = get_current_user_id();

		return $user > 0 ? $user : null;
	}

	/**
	 * Requires the capability of the creators' pages.
	 *
	 * @throws ProductRemovalException When it is missing.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			self::refuse( ProductRemovalException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Refuses the request.
	 *
	 * @param string $reason One of ProductRemovalException's constants.
	 * @throws ProductRemovalException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new ProductRemovalException( $reason );
	}
}
