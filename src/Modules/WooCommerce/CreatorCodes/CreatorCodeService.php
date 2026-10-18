<?php
/**
 * Registering and ending a creator's own discount code.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's own discount code (ADR-068): orders that use it do not count
 * in the creator's sales, profit or payouts (OrderExclusionAssessor).
 *
 * - K4: registering and ending need `merchboard_manage_creator_pricing`,
 *   checked here again (the admin action checks it and the nonce first).
 * - K5 (as changed by the owner on 2026-10-18): the coupon must exist and
 *   must not be a gift card code; when the gift card check cannot be done,
 *   the registration is refused. The coupon need not be restricted to an
 *   e-mail address, so every order with the code is excluded, whoever
 *   placed it.
 * - K1: a code applies from its registration on; there is no backdating and
 *   no future start. At most one active code per creator and one active
 *   creator per code. Registering a new code for a creator ends the old
 *   registration and creates a new one in the same transaction.
 *   Registrations of one creator and of one code are serialised by named
 *   locks taken in a fixed order (creator, then code; separate from the
 *   payout lock protocol, since a registration changes no balance), checked
 *   again inside the transaction, and the table's UNIQUE keys are the last
 *   safety net.
 * - Every registration and ending is audited in the same transaction
 *   (registration id, coupon id, validation result).
 */
final class CreatorCodeService {

	/**
	 * Clock.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 *
	 * @param CreatorRepository     $creators    Creators.
	 * @param CreatorCodeRepository $codes       Registrations.
	 * @param CouponLookup          $coupons     WooCommerce coupons and gift cards.
	 * @param Transaction           $transaction The project's transaction helper.
	 * @param AuditTrail            $audit       Audit trail.
	 * @param Closure|null          $clock       Time of the registration; UTC now by default.
	 */
	public function __construct(
		private CreatorRepository $creators,
		private CreatorCodeRepository $codes,
		private CouponLookup $coupons,
		private Transaction $transaction,
		private AuditTrail $audit,
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Registers a code for the creator from now on.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $raw_code   Code as entered.
	 * @return string `code_registered`, or `code_changed` when an earlier code was ended.
	 * @throws CreatorCodeException When the registration is refused.
	 */
	public function register( int $creator_id, string $raw_code ): string {
		self::authorize();

		$creator = $this->creator( $creator_id );
		$code    = CouponCode::normalize( $raw_code );

		if ( '' === $code || mb_strlen( $code ) > CouponCode::MAX_LENGTH ) {
			throw new CreatorCodeException( CreatorCodeException::INVALID_CODE );
		}

		$coupon = $this->validated_coupon( $code );

		return $this->codes->with_locks(
			$creator->id,
			$code,
			fn(): string => $this->transaction->run(
				function () use ( $creator, $code, $coupon ): string {
					$current = $this->codes->active_for_creator( $creator->id, true );
					$owner   = $this->codes->active_for_code( $code, true );

					if ( null !== $owner && $owner->creator_id !== $creator->id ) {
						throw new CreatorCodeException( CreatorCodeException::CODE_TAKEN );
					}

					if ( null !== $current && $current->code === $code && $current->coupon_id === $coupon->id ) {
						throw new CreatorCodeException( CreatorCodeException::UNCHANGED );
					}

					$now  = ( $this->clock )();
					$user = self::user();

					if ( null !== $current ) {
						$this->end_registration( $current, $user, $now );
					}

					$id = $this->codes->insert( $creator->id, $code, $coupon->id, $user, $now );

					$this->audit->record(
						AuditAction::CreatorCodeRegistered,
						$creator->id,
						array(
							'creator_code_id'          => $id,
							'coupon_id'                => $coupon->id,
							'previous_creator_code_id' => null === $current ? null : $current->id,
							'validation'               => 'passed',
						)
					);

					return null === $current ? 'code_registered' : 'code_changed';
				}
			)
		);
	}

	/**
	 * Ends the creator's active code from now on. Orders created before
	 * stay covered (their period does not change).
	 *
	 * @param int $creator_id Creator.
	 * @throws CreatorCodeException When there is no active code or the creator is unknown.
	 */
	public function end( int $creator_id ): void {
		self::authorize();

		$creator = $this->creator( $creator_id );
		$current = $this->codes->active_for_creator( $creator->id ) ?? throw new CreatorCodeException( CreatorCodeException::NO_ACTIVE_CODE );

		$this->codes->with_locks(
			$creator->id,
			$current->code,
			fn() => $this->transaction->run(
				function () use ( $creator ): void {
					// Again under the locks: it may have been ended or changed meanwhile.
					$active = $this->codes->active_for_creator( $creator->id, true ) ?? throw new CreatorCodeException( CreatorCodeException::NO_ACTIVE_CODE );

					$this->end_registration( $active, self::user(), ( $this->clock )() );
				}
			)
		);
	}

	/**
	 * The coupon of a code, checked against K5.
	 *
	 * @param string $code Normalized code.
	 * @throws CreatorCodeException When the coupon does not meet K5.
	 */
	private function validated_coupon( string $code ): CouponRecord {
		$gift_card = $this->coupons->is_gift_card( $code );

		if ( null === $gift_card ) {
			throw new CreatorCodeException( CreatorCodeException::GIFT_CARD_UNVERIFIED );
		}

		if ( $gift_card ) {
			throw new CreatorCodeException( CreatorCodeException::GIFT_CARD_CODE );
		}

		$coupon = $this->coupons->find( $code );

		if ( null === $coupon || $coupon->code !== $code ) {
			throw new CreatorCodeException( CreatorCodeException::COUPON_NOT_FOUND );
		}

		return $coupon;
	}

	/**
	 * Ends a registration and audits it, inside the caller's transaction.
	 *
	 * @param CreatorCode       $registration Active registration.
	 * @param int|null          $user         WordPress user.
	 * @param DateTimeImmutable $now          End.
	 * @throws CreatorCodeException When it was ended meanwhile.
	 */
	private function end_registration( CreatorCode $registration, ?int $user, DateTimeImmutable $now ): void {
		if ( ! $this->codes->end( $registration->id, $user, $now ) ) {
			throw new CreatorCodeException( CreatorCodeException::NO_ACTIVE_CODE );
		}

		$this->audit->record(
			AuditAction::CreatorCodeEnded,
			$registration->creator_id,
			array(
				'creator_code_id' => $registration->id,
				'coupon_id'       => $registration->coupon_id,
			)
		);
	}

	/**
	 * The creator.
	 *
	 * @param int $creator_id Creator.
	 * @throws CreatorCodeException When it does not exist.
	 */
	private function creator( int $creator_id ): Creator {
		$creator = $creator_id > 0 ? $this->creators->find( $creator_id ) : null;

		return $creator ?? throw new CreatorCodeException( CreatorCodeException::CREATOR_NOT_FOUND );
	}

	/**
	 * The current WordPress user, null when none.
	 */
	private static function user(): ?int {
		$user = get_current_user_id();

		return $user > 0 ? $user : null;
	}

	/**
	 * Requires the pricing capability (K4).
	 *
	 * @throws CreatorCodeException When it is missing.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) ) {
			throw new CreatorCodeException( CreatorCodeException::NOT_AUTHORIZED );
		}
	}
}
