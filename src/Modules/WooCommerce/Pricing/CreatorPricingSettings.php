<?php
/**
 * Pricing configuration: creator markup, product markup and VAT status.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;

defined( 'ABSPATH' ) || exit;

/**
 * The only write path of the pricing configuration (ADR-048, ADR-049): a
 * creator's default markup (share of the WooCommerce sale price) and VAT
 * status (through its history, ADR-062 K06), and a parent product's own share. Nothing here reads or writes a
 * WooCommerce price, and sales snapshots are never touched: a change applies
 * to order lines created afterwards only.
 *
 * - Authorization: `merchboard_manage_creator_pricing`; a product share also
 *   needs `edit_product` for the parent product (product configuration, as
 *   for the product link, ADR-030).
 * - Ownership: a product share is always bound to the parent product's
 *   current owner (ProductCreatorLink). The caller's creator id is only the
 *   expectation of the page: a product of another creator is rejected
 *   (`not_owned`), never re-bound.
 * - Values: a percentage with at most two decimals (MarkupBps, 0–100 %;
 *   "." or ","). No floats.
 * - Each change is stored and audited in one transaction; an unchanged
 *   value writes nothing and records nothing.
 */
final class CreatorPricingSettings {

	/**
	 * Most characters of a documentation reference in the VAT status history.
	 */
	public const MAX_REFERENCE = 191;

	/**
	 * Most characters of a reason in the VAT status history.
	 */
	public const MAX_REASON = 500;

	/**
	 * The earliest date of the VAT status history.
	 */
	private const EARLIEST_DATE = '2000-01-01';

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 *
	 * @param CreatorRepository                $creators    Creators.
	 * @param CreatorMarkupRepository          $markups     Default markups.
	 * @param ProductMarkupRepository          $products    Product shares.
	 * @param ProductCreatorLink               $links       Product ownership (read only).
	 * @param PricingProductStore              $store       WooCommerce access (read only).
	 * @param AuditTrail                       $audit       Audit trail.
	 * @param Transaction                      $transaction Database transaction.
	 * @param CreatorVatRepository|null        $vat         Creators' current VAT status.
	 * @param CreatorVatHistoryRepository|null $history     History of the VAT status (ADR-062 K06).
	 * @param Closure|null                     $clock       Current time; null = now.
	 */
	public function __construct(
		private CreatorRepository $creators,
		private CreatorMarkupRepository $markups,
		private ProductMarkupRepository $products,
		private ProductCreatorLink $links,
		private PricingProductStore $store,
		private AuditTrail $audit,
		private Transaction $transaction,
		private ?CreatorVatRepository $vat = null,
		private ?CreatorVatHistoryRepository $history = null,
		private ?Closure $clock = null
	) {
	}

	/**
	 * Records a registration of a creator's VAT status in its history
	 * (ADR-062 K06; ADR-048, ADR-049): the status, the date it applies from,
	 * the date it was checked, a documentation reference and a reason. The
	 * registration is never changed afterwards; a correction is a new one.
	 *
	 * The creator's current status (`merchboard_creators.vat_registered`,
	 * which new order lines snapshot) becomes the status the history gives
	 * for today. A registration with an earlier date may therefore leave it
	 * unchanged. Nothing earlier is recalculated: order lines, settlement
	 * documents (also their VAT on the commission), payouts and adjustments
	 * keep the status they were made with. A date in the future is refused.
	 *
	 * In one transaction under a lock of the creator: the registration, the
	 * current status and the audit records. A registration identical to the
	 * last one writes nothing. A storage failure throws a DatabaseException;
	 * nothing is stored then.
	 *
	 * @param int    $creator_id     Creator.
	 * @param bool   $vat_registered VAT registered.
	 * @param string $effective_from Date the status applies from (Y-m-d).
	 * @param string $checked_on     Date the status was checked (Y-m-d).
	 * @param string $reference      Documentation reference (1–191 characters).
	 * @param string $reason         Reason (1–500 characters).
	 * @return string `vat_saved` (current status changed), `vat_recorded` (recorded, current status unchanged) or `vat_unchanged` (nothing recorded).
	 * @throws PricingException When not authorized, the creator is unknown or an input is invalid.
	 */
	public function record_vat_status( int $creator_id, bool $vat_registered, string $effective_from, string $checked_on, string $reference, string $reason ): string {
		self::authorize();

		$vat     = $this->vat;
		$history = $this->history;

		if ( $creator_id < 1 || null === $vat || null === $history || null === $vat->find( $creator_id ) ) {
			throw new PricingException( PricingException::CREATOR_NOT_FOUND );
		}

		$now            = null === $this->clock ? new DateTimeImmutable( 'now', wp_timezone() ) : ( $this->clock )();
		$today          = $now->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		$effective_from = self::history_date( $effective_from, $today, PricingException::INVALID_EFFECTIVE_DATE );
		$checked_on     = self::history_date( $checked_on, $today, PricingException::INVALID_CHECK_DATE );
		$reference      = self::history_text( $reference, self::MAX_REFERENCE, false, PricingException::INVALID_REFERENCE );
		$reason         = self::history_text( $reason, self::MAX_REASON, true, PricingException::INVALID_REASON );

		$result = $this->transaction->run(
			function () use ( $vat, $history, $creator_id, $vat_registered, $effective_from, $checked_on, $reference, $reason, $now, $today ): string {
				if ( ! $history->lock_creator( $creator_id ) ) {
					throw new PricingException( PricingException::CREATOR_NOT_FOUND );
				}

				$previous = $vat->find( $creator_id ) ?? throw new PricingException( PricingException::CREATOR_NOT_FOUND );
				$latest   = $history->latest( $creator_id );

				if ( null !== $latest && $latest->records( $vat_registered, $effective_from, $checked_on, $reference, $reason ) ) {
					return 'vat_unchanged';
				}

				$user    = get_current_user_id();
				$id      = $history->insert( $creator_id, $vat_registered, $effective_from, $checked_on, $reference, $reason, $user > 0 ? $user : null, $now );
				$current = $history->at( $creator_id, $today )->vat_registered ?? $previous;

				if ( $current !== $previous ) {
					$vat->save( $creator_id, $current );
					$this->audit->record(
						AuditAction::CreatorVatStatusChanged,
						$creator_id,
						array(
							'previous_vat_registered' => $previous,
							'vat_registered'          => $current,
						)
					);
				}

				$this->audit->record(
					AuditAction::CreatorVatHistoryRecorded,
					$creator_id,
					array(
						'entry_id'               => $id,
						'vat_registered'         => $vat_registered,
						'effective_from'         => $effective_from,
						'checked_on'             => $checked_on,
						'has_reference'          => true,
						'has_reason'             => true,
						'current_vat_registered' => $current,
						'current_changed'        => $current !== $previous,
					)
				);

				return $current !== $previous ? 'vat_saved' : 'vat_recorded';
			}
		);

		// Only a change of the current status (the reference and reason stay internal); it is
		// committed, so a failed read only skips the notification.
		if ( 'vat_saved' === $result ) {
			try {
				do_action( 'merchboard_notify', 'vat_changed', $creator_id, array( 'registered' => true === $vat->find( $creator_id ) ), '' );
			} catch ( DatabaseException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- See above.
			}
		}

		return $result;
	}

	/**
	 * A date of the VAT status history: Y-m-d, from 2000-01-01 to today.
	 *
	 * @param string $value Submitted date.
	 * @param string $today Today (Y-m-d, site calendar).
	 * @param string $code  Exception code when invalid.
	 * @throws PricingException When the date is invalid.
	 */
	private static function history_date( string $value, string $today, string $code ): string {
		$value  = trim( $value );
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );

		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $value || $value < self::EARLIEST_DATE || $value > $today ) {
			throw new PricingException( $code );
		}

		return $value;
	}

	/**
	 * A text of the VAT status history: trimmed, 1 to $max characters,
	 * without control characters (line breaks only where allowed).
	 *
	 * @param string $value       Submitted text.
	 * @param int    $max         Most characters.
	 * @param bool   $line_breaks Whether line breaks are allowed.
	 * @param string $code        Exception code when invalid.
	 * @throws PricingException When the text is invalid.
	 */
	private static function history_text( string $value, int $max, bool $line_breaks, string $code ): string {
		$value    = trim( str_replace( "\r\n", "\n", $value ) );
		$controls = $line_breaks ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';

		if ( '' === $value || mb_strlen( $value ) > $max || 1 === preg_match( $controls, $value ) || 1 !== preg_match( '//u', $value ) ) {
			throw new PricingException( $code );
		}

		return $value;
	}

	/**
	 * Sets a creator's default markup (share of the sale price). A storage
	 * failure throws a DatabaseException; nothing is stored then.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $percent    Percentage, e.g. "19" or "17.5".
	 * @return bool Whether the markup changed.
	 * @throws PricingException When not authorized, the creator is unknown or the percentage is invalid.
	 */
	public function set_markup( int $creator_id, string $percent ): bool {
		self::authorize();

		if ( $creator_id < 1 || null === $this->creators->find( $creator_id ) ) {
			throw new PricingException( PricingException::CREATOR_NOT_FOUND );
		}

		$markup   = self::markup( $percent );
		$previous = $this->markups->find( $creator_id );

		if ( null !== $previous && $previous->bps === $markup->bps ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $creator_id, $markup, $previous ): void {
				$this->markups->save( $creator_id, $markup );
				$this->audit->record(
					AuditAction::CreatorMarkupChanged,
					$creator_id,
					array(
						'previous_markup_bps' => $previous?->bps,
						'markup_bps'          => $markup->bps,
					)
				);
			}
		);

		self::markup_changed( $creator_id, '', $previous, $markup );

		return true;
	}

	/**
	 * Sets (or replaces) a parent product's own share for its owner. A
	 * storage failure throws a DatabaseException; nothing is stored then.
	 *
	 * @param int    $product_id Parent product.
	 * @param int    $creator_id The creator the admin expects to own it.
	 * @param string $percent    Percentage, e.g. "25" or "12,5".
	 * @return bool Whether the share changed.
	 * @throws PricingException When not authorized or a value or the product is invalid.
	 */
	public function set_product_markup( int $product_id, int $creator_id, string $percent ): bool {
		$owner    = $this->owned_parent( $product_id, $creator_id );
		$markup   = self::markup( $percent );
		$previous = $this->products->find( $product_id );

		if ( null !== $previous && $previous->creator_id === $owner && $previous->markup->bps === $markup->bps ) {
			return false;
		}

		$product = new ProductMarkup( $product_id, $owner, $markup );
		$default = $this->markups->find( $owner );

		$this->transaction->run(
			function () use ( $product, $previous, $owner, $product_id ): void {
				$this->products->save( $product );
				$this->audit->record(
					AuditAction::ProductMarkupSet,
					$owner,
					array(
						'product_id'          => $product_id,
						'markup_bps'          => $product->markup->bps,
						'previous_markup_bps' => null === $previous || ! $previous->belongs_to( $owner ) ? null : $previous->markup->bps,
					)
				);
			}
		);

		self::markup_changed( $owner, (string) get_the_title( $product_id ), null !== $previous && $previous->belongs_to( $owner ) ? $previous->markup : $default, $product->markup );

		return true;
	}

	/**
	 * Removes a parent product's own share, so the owner's default markup
	 * applies again ("Use creator markup").
	 *
	 * @param int $product_id Parent product.
	 * @param int $creator_id The creator the admin expects to own it.
	 * @return bool Whether a share was removed.
	 * @throws PricingException When not authorized or the product is invalid.
	 * @throws DatabaseException When it cannot be removed (nothing is removed then).
	 */
	public function remove_product_markup( int $product_id, int $creator_id ): bool {
		$owner    = $this->owned_parent( $product_id, $creator_id );
		$previous = $this->products->find( $product_id );

		if ( null === $previous ) {
			return false;
		}

		$default = $this->markups->find( $owner );

		$this->transaction->run(
			function () use ( $previous, $owner, $product_id ): void {
				$this->products->delete( $product_id );
				$this->audit->record(
					AuditAction::ProductMarkupRemoved,
					$owner,
					array(
						'product_id'          => $product_id,
						'previous_markup_bps' => $previous->markup->bps,
					)
				);
			}
		);

		self::markup_changed( $owner, (string) get_the_title( $product_id ), $previous->markup, $default );

		return true;
	}

	/**
	 * Tells the creator that their profit changed (ADR-079), after the commit.
	 *
	 * @param int            $creator_id Creator.
	 * @param string         $product    Product name ('' for the default markup).
	 * @param MarkupBps|null $previous   The markup before.
	 * @param MarkupBps|null $current    The markup now.
	 */
	private static function markup_changed( int $creator_id, string $product, ?MarkupBps $previous, ?MarkupBps $current ): void {
		$text = static fn( ?MarkupBps $markup ): string => null === $markup ? '–' : number_format_i18n( $markup->bps / 100, 0 === $markup->bps % 100 ? 0 : 2 ) . ' %';

		do_action(
			'merchboard_notify',
			'pricing_changed',
			$creator_id,
			array(
				'product'  => $product,
				'previous' => $text( $previous ),
				'new'      => $text( $current ),
			),
			''
		);
	}

	/**
	 * Checks a parent product the current user may configure and returns its
	 * owner, which must be the expected creator.
	 *
	 * @param int $product_id Parent product.
	 * @param int $creator_id Expected owner.
	 * @throws PricingException When not authorized, the product is missing, a variation or unsupported, or owned by nobody or another creator.
	 */
	private function owned_parent( int $product_id, int $creator_id ): int {
		self::authorize();
		$unit = $product_id > 0 ? $this->store->find( $product_id ) : null;

		if ( null === $unit ) {
			throw new PricingException( PricingException::PRODUCT_NOT_FOUND );
		}

		// The share belongs to the parent product (ADR-049): no variation shares.
		if ( ! in_array( $unit->type, array( 'simple', 'variable' ), true ) ) {
			throw new PricingException( PricingException::UNSUPPORTED_PRODUCT );
		}

		if ( ! current_user_can( Capabilities::EDIT_PRODUCT, $product_id ) ) {
			throw new PricingException( PricingException::NOT_AUTHORIZED );
		}

		$owner = $this->links->creator_id( $product_id );

		if ( null === $owner ) {
			throw new PricingException( PricingException::NO_OWNER );
		}

		if ( $owner !== $creator_id ) {
			throw new PricingException( PricingException::NOT_OWNED );
		}

		return $owner;
	}

	/**
	 * A percentage as basis points.
	 *
	 * @param string $percent Percentage with at most two decimals ("." or ",").
	 * @throws PricingException When it is not a percentage between 0 and 100.
	 */
	private static function markup( string $percent ): MarkupBps {
		try {
			return MarkupBps::from_percent( str_replace( ',', '.', $percent ) );
		} catch ( InvalidArgumentException ) {
			throw new PricingException( PricingException::INVALID_MARKUP );
		}
	}

	/**
	 * Requires the creator pricing capability.
	 *
	 * @throws PricingException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) ) {
			throw new PricingException( PricingException::NOT_AUTHORIZED );
		}
	}
}
