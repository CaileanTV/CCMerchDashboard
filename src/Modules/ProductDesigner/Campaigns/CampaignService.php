<?php
/**
 * Creating, changing and deleting the company's campaigns.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Campaigns;

use DateTimeImmutable;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponCode;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\Pricing\PricingRows;

defined( 'ABSPATH' ) || exit;

/**
 * The only write path of the campaigns (ADR-080 pkt. 9), for
 * `merchboard_manage_designer`, each change audited in the same transaction.
 *
 * - Only the company creates campaigns. A creator's own discount code can
 *   never be a campaign code (any registration, active or ended): it would
 *   let the creator's code take the company below its base price.
 * - A change applies to order lines that reach the ledger afterwards; rows
 *   already in the ledger keep the amounts stored with them.
 * - A campaign used by a ledger row cannot be deleted (it is ended by
 *   moving its end instead), so every stored campaign id stays readable.
 */
class CampaignService {

	/**
	 * Constructor.
	 *
	 * @param CampaignRepository    $campaigns   Campaigns.
	 * @param CreatorCodeRepository $codes       The creators' own discount codes.
	 * @param AuditTrail            $audit       Audit trail.
	 * @param Transaction           $transaction Transaction.
	 */
	public function __construct(
		private CampaignRepository $campaigns,
		private CreatorCodeRepository $codes,
		private AuditTrail $audit,
		private Transaction $transaction
	) {
	}

	/**
	 * Creates a campaign.
	 *
	 * @param string            $name        Name.
	 * @param DateTimeImmutable $starts_at   Start (UTC).
	 * @param DateTimeImmutable $ends_at     End (UTC, exclusive).
	 * @param string            $codes       Coupon codes, separated by commas, spaces or new lines.
	 * @param bool              $sale_prices Whether lines sold at a sale price are covered.
	 * @return int The new id.
	 * @throws DesignerException When the user may not, or a value is refused.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function create( string $name, DateTimeImmutable $starts_at, DateTimeImmutable $ends_at, string $codes, bool $sale_prices ): int {
		self::authorize();

		$campaign = $this->campaign( null, $name, $starts_at, $ends_at, $codes, $sale_prices );

		return $this->transaction->run(
			function () use ( $campaign ): int {
				$id = $this->campaigns->insert( $campaign );
				$this->audit->record( AuditAction::DesignerCampaignCreated, null, array_merge( array( 'campaign_id' => $id ), self::context( $campaign ) ) );

				return $id;
			}
		);
	}

	/**
	 * Changes a campaign.
	 *
	 * @param int               $id          Id.
	 * @param string            $name        Name.
	 * @param DateTimeImmutable $starts_at   Start (UTC).
	 * @param DateTimeImmutable $ends_at     End (UTC, exclusive).
	 * @param string            $codes       Coupon codes, separated by commas, spaces or new lines.
	 * @param bool              $sale_prices Whether lines sold at a sale price are covered.
	 * @throws DesignerException When the user may not, the campaign is unknown or a value is refused.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function update( int $id, string $name, DateTimeImmutable $starts_at, DateTimeImmutable $ends_at, string $codes, bool $sale_prices ): void {
		self::authorize();

		$previous = $this->existing( $id );
		$campaign = $this->campaign( $id, $name, $starts_at, $ends_at, $codes, $sale_prices );

		$this->transaction->run(
			function () use ( $id, $campaign, $previous ): void {
				$this->campaigns->update( $id, $campaign );

				$before = self::context( $previous );
				$after  = self::context( $campaign );
				$this->audit->record(
					AuditAction::DesignerCampaignChanged,
					null,
					array_merge(
						array( 'campaign_id' => $id ),
						$after,
						// Only what changed, prefixed `previous_`.
						array_combine(
							array_map( static fn( string $key ): string => 'previous_' . $key, array_keys( array_diff_assoc( $before, $after ) ) ),
							array_values( array_diff_assoc( $before, $after ) )
						)
					)
				);
			}
		);
	}

	/**
	 * Deletes a campaign that no ledger row uses.
	 *
	 * @param int $id Id.
	 * @throws DesignerException When the user may not, the campaign is unknown or in use.
	 * @throws DatabaseException When it cannot be deleted (nothing is kept).
	 */
	public function delete( int $id ): void {
		self::authorize();

		$campaign = $this->existing( $id );

		$this->transaction->run(
			function () use ( $id, $campaign ): void {
				if ( $this->campaigns->rows( $id ) > 0 ) {
					throw new DesignerException( DesignerException::CAMPAIGN_IN_USE );
				}

				$this->campaigns->delete( $id );
				$this->audit->record(
					AuditAction::DesignerCampaignDeleted,
					null,
					array(
						'campaign_id'   => $id,
						'campaign_name' => $campaign->name,
					)
				);
			}
		);
	}

	/**
	 * A stored campaign.
	 *
	 * @param int $id Id.
	 * @throws DesignerException When it does not exist.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function existing( int $id ): Campaign {
		$campaign = $this->campaigns->find( $id );

		if ( null === $campaign ) {
			self::reject( DesignerException::CAMPAIGN_UNKNOWN );
		}

		return $campaign;
	}

	/**
	 * The normalized, unique codes of a text.
	 *
	 * @param string $codes Codes separated by commas, spaces or new lines.
	 * @return list<string>
	 */
	public static function parse_codes( string $codes ): array {
		$parts = preg_split( '/[\s,;]+/u', $codes );
		$found = array();

		foreach ( false === $parts ? array() : $parts as $part ) {
			$code = CouponCode::normalize( $part );

			if ( '' !== $code ) {
				$found[ $code ] = $code;
			}
		}

		return array_values( $found );
	}

	/**
	 * A validated campaign.
	 *
	 * @param int|null          $id          Id.
	 * @param string            $name        Name.
	 * @param DateTimeImmutable $starts_at   Start.
	 * @param DateTimeImmutable $ends_at     End.
	 * @param string            $codes       Coupon codes as typed.
	 * @param bool              $sale_prices Sale prices.
	 * @throws DesignerException When a value is refused.
	 * @throws DatabaseException When the creators' codes cannot be read.
	 */
	private function campaign( ?int $id, string $name, DateTimeImmutable $starts_at, DateTimeImmutable $ends_at, string $codes, bool $sale_prices ): Campaign {
		$name = trim( sanitize_text_field( $name ) );

		if ( '' === $name || mb_strlen( $name ) > 191 ) {
			self::reject( DesignerException::CAMPAIGN_NAME );
		}

		if ( $starts_at >= $ends_at ) {
			self::reject( DesignerException::CAMPAIGN_PERIOD );
		}

		$list = self::parse_codes( $codes );

		if ( count( $list ) > Campaign::MAX_CODES || array() !== array_filter( $list, static fn( string $code ): bool => mb_strlen( $code ) > CouponCode::MAX_LENGTH ) ) {
			self::reject( DesignerException::CAMPAIGN_CODES );
		}

		if ( array() === $list && ! $sale_prices ) {
			self::reject( DesignerException::CAMPAIGN_SCOPE );
		}

		if ( array() !== $list && array() !== $this->codes->matching( $list ) ) {
			self::reject( DesignerException::CAMPAIGN_CREATOR_CODE );
		}

		try {
			return new Campaign( $id, $name, $starts_at, $ends_at, $list, $sale_prices );
		} catch ( InvalidArgumentException ) {
			self::reject( DesignerException::CAMPAIGN_CODES );
		}
	}

	/**
	 * The audit context of a campaign (scalars only).
	 *
	 * @param Campaign $campaign Campaign.
	 * @return array{campaign_name: string, period: string, coupon_codes: string, sale_prices: bool}
	 */
	private static function context( Campaign $campaign ): array {
		return array(
			'campaign_name' => $campaign->name,
			'period'        => PricingRows::datetime( $campaign->starts_at ) . ' – ' . PricingRows::datetime( $campaign->ends_at ),
			'coupon_codes'  => implode( ', ', $campaign->coupon_codes ),
			'sale_prices'   => $campaign->sale_prices,
		);
	}

	/**
	 * Refuses with a reason.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function reject( string $reason ): never {
		throw new DesignerException( $reason );
	}

	/**
	 * Requires `merchboard_manage_designer`.
	 *
	 * @throws DesignerException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			throw new DesignerException( DesignerException::NOT_AUTHORIZED );
		}
	}
}
