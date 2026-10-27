<?php
/**
 * Issuing and ending a creator's own discount code.
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
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's own discount code (ADR-068): orders that use it do not count
 * in the creator's sales, profit or payouts (OrderExclusionAssessor).
 *
 * - ADR-078: the plugin issues the code. A new code (CreatorCodeGenerator)
 *   gets its own WooCommerce coupon (CouponIssuer) whose discount is the
 *   creator's profit on their own products (CreatorProfitCoupon). Issuing a
 *   code for a creator with a code retires the old coupon (trash) in the
 *   same transaction, so the old code stops working at once.
 * - An administrator issues with `merchboard_manage_creator_pricing` (K4);
 *   the creator resets their own code (only when they have one), also while
 *   an administrator views as the creator (ADR-075), at most RESETS_PER_DAY
 *   times a day.
 * - K5: a code that is a gift card code is never issued; when the gift card
 *   check cannot be done, nothing is issued. The coupon is not restricted to
 *   an e-mail address, so every order with the code is excluded, whoever
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
 *   (registration id, coupon id, source).
 */
final class CreatorCodeService {

	public const SOURCE_ADMIN   = 'admin';
	public const SOURCE_CREATOR = 'creator';

	/**
	 * Codes a creator may issue (reset) within a day.
	 */
	public const RESETS_PER_DAY = 3;

	/**
	 * Candidates tried before giving up on a free code.
	 */
	private const ATTEMPTS = 5;

	/**
	 * Clock.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * A new code candidate for a creator.
	 *
	 * @var Closure(Creator): string
	 */
	private Closure $generate;

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 * @phpstan-param (Closure(Creator): string)|null $generate
	 *
	 * @param CreatorRepository     $creators    Creators.
	 * @param CreatorCodeRepository $codes       Registrations.
	 * @param CouponLookup          $coupons     WooCommerce coupons and gift cards.
	 * @param CouponIssuer          $issuer      Creates and retires the coupons.
	 * @param Transaction           $transaction The project's transaction helper.
	 * @param AuditTrail            $audit       Audit trail.
	 * @param Closure|null          $clock       Time of the registration; UTC now by default.
	 * @param Closure|null          $generate    A code candidate; CreatorCodeGenerator by default.
	 */
	public function __construct(
		private CreatorRepository $creators,
		private CreatorCodeRepository $codes,
		private CouponLookup $coupons,
		private CouponIssuer $issuer,
		private Transaction $transaction,
		private AuditTrail $audit,
		?Closure $clock = null,
		?Closure $generate = null
	) {
		$this->clock    = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$this->generate = $generate ?? static fn( Creator $creator ): string => CreatorCodeGenerator::candidate( $creator->display_name );
	}

	/**
	 * Issues a new code for the creator from now on: a new coupon, and the
	 * old code (if any) ends and its coupon goes to the trash.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $source     SOURCE_ADMIN (an administrator on the creator's page) or SOURCE_CREATOR (a reset in the dashboard).
	 * @return string `code_registered`, or `code_changed` when an earlier code was ended.
	 * @throws CreatorCodeException When it is refused (nothing is kept).
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function issue( int $creator_id, string $source ): string {
		$creator = $this->creator( $creator_id );

		if ( self::SOURCE_CREATOR === $source ) {
			self::authorize_creator( $creator );

			if ( null === $this->codes->active_for_creator( $creator->id ) ) {
				self::refuse( CreatorCodeException::NO_ACTIVE_CODE );
			}

			$this->assert_below_limit( $creator->id );
		} else {
			self::authorize();
		}

		$code   = $this->free_code( $creator );
		$coupon = $this->issuer->create( $code, $creator );
		$issued = false;
		$result = '';

		try {
			$result = $this->codes->with_locks(
				$creator->id,
				$code,
				fn(): string => $this->transaction->run(
					function () use ( $creator, $code, $coupon, $source ): string {
						$current = $this->codes->active_for_creator( $creator->id, true );

						// Again under the locks: an admin may have ended the code, or a parallel reset used the limit.
						if ( self::SOURCE_CREATOR === $source ) {
							if ( null === $current ) {
								self::refuse( CreatorCodeException::NO_ACTIVE_CODE );
							}

							$this->assert_below_limit( $creator->id );
						}

						if ( null !== $this->codes->active_for_code( $code, true ) ) {
							self::refuse( CreatorCodeException::CODE_TAKEN );
						}

						$now  = ( $this->clock )();
						$user = self::user();

						if ( null !== $current ) {
							$this->end_registration( $current, $user, $now );

							// The old code stops working at once (owner's choice 2026-10-26).
							if ( ! $this->issuer->retire( $current->coupon_id ) ) {
								self::refuse( CreatorCodeException::COUPON_NOT_RETIRED );
							}
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
								'source'                   => self::SOURCE_CREATOR === $source ? self::SOURCE_CREATOR : self::SOURCE_ADMIN,
							)
						);

						return null === $current ? 'code_registered' : 'code_changed';
					}
				)
			);
			$issued = true;
		} finally {
			if ( ! $issued ) {
				$this->issuer->discard( $coupon->id );
			}
		}

		// Never the code: the creator reads it in the dashboard.
		do_action( 'merchboard_notify', 'code_registered' === $result ? 'code_issued' : 'code_reset', $creator->id, array(), '' );

		return $result;
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

		do_action( 'merchboard_notify', 'code_ended', $creator->id, array(), '' );
	}

	/**
	 * A new code no coupon, registration or gift card uses (K5).
	 *
	 * @param Creator $creator Creator.
	 * @throws CreatorCodeException When the gift card check cannot be done or no free code is found.
	 * @throws DatabaseException When the registrations cannot be read.
	 */
	private function free_code( Creator $creator ): string {
		for ( $attempt = 0; $attempt < self::ATTEMPTS; $attempt++ ) {
			$code      = CouponCode::normalize( ( $this->generate )( $creator ) );
			$gift_card = $this->coupons->is_gift_card( $code );

			if ( null === $gift_card ) {
				self::refuse( CreatorCodeException::GIFT_CARD_UNVERIFIED );
			}

			if ( '' !== $code && ! $gift_card && null === $this->coupons->find( $code ) && array() === $this->codes->matching( array( $code ) ) ) {
				return $code;
			}
		}

		self::refuse( CreatorCodeException::CODE_TAKEN );
	}

	/**
	 * A creator issues at most RESETS_PER_DAY codes within a day.
	 *
	 * @param int $creator_id Creator.
	 * @throws CreatorCodeException When the limit is reached.
	 * @throws DatabaseException When the registrations cannot be read.
	 */
	private function assert_below_limit( int $creator_id ): void {
		$since  = ( $this->clock )()->modify( '-1 day' );
		$recent = array_filter( $this->codes->for_creator( $creator_id, self::RESETS_PER_DAY ), static fn( CreatorCode $code ): bool => $code->valid_from > $since );

		if ( count( $recent ) >= self::RESETS_PER_DAY ) {
			self::refuse( CreatorCodeException::RESET_LIMIT );
		}
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
			self::refuse( CreatorCodeException::NOT_AUTHORIZED );
		}
	}

	/**
	 * The creator must be the current user's active creator, or the one an
	 * administrator views and acts as (ADR-075; allowed by the owner
	 * 2026-10-26).
	 *
	 * @param Creator $creator Creator.
	 * @throws CreatorCodeException When it is not.
	 */
	private static function authorize_creator( Creator $creator ): void {
		$user_id = get_current_user_id();
		$own     = $user_id > 0 && $creator->user_id === $user_id;

		if ( ( ! $own && ImpersonationSession::creator_id() !== $creator->id ) || ! $creator->is_active() || $creator->is_deleted() ) {
			self::refuse( CreatorCodeException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Refuses the request.
	 *
	 * @param string $reason One of CreatorCodeException's constants.
	 * @throws CreatorCodeException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new CreatorCodeException( $reason );
	}
}
