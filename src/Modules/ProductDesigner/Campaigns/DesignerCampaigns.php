<?php
/**
 * Which campaign covers each designer line of an order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Campaigns;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerCampaignSource;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerRowAmounts;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricing;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\OrderSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * DesignerCampaignSource on the designer's own tables (ADR-080 pkt. 9). A
 * designer line is covered by a campaign that ran when the line was bought
 * (its snapshot's time) and that names one of the order's coupon codes, or
 * that covers sale prices while the line was sold below its list price. A
 * line is sold below its list price when its subtotal before coupons incl.
 * VAT is at least one store step below quantity × list price: only the
 * company sets a sale price or changes a line's price (creators cannot), so
 * either is the company's own discount. With several campaigns, the oldest
 * (lowest id) wins. Legacy lines are never covered.
 */
final class DesignerCampaigns implements DesignerCampaignSource {

	/**
	 * Constructor.
	 *
	 * @param CampaignRepository         $campaigns Campaigns.
	 * @param SalesLinePricingRepository $snapshots Order line snapshots.
	 */
	public function __construct( private CampaignRepository $campaigns, private SalesLinePricingRepository $snapshots ) {
	}

	/**
	 * The campaign that covers each designer line of the order.
	 *
	 * @param OrderSnapshot $order Order as read now.
	 * @return array<int, int> Campaign id by order item.
	 * @throws InvalidArgumentException When the order's coupon data is unknown while a campaign could cover a designer line.
	 */
	public function campaigns( OrderSnapshot $order ): array {
		$designer = array();

		foreach ( $order->lines as $line ) {
			$snapshot = null === $line->creator_id ? null : $this->snapshots->find( $line->item_id );

			if ( null !== $snapshot && $snapshot->is_designer() && $snapshot->order_id === $order->order_id ) {
				$designer[] = array( $line, $snapshot );
			}
		}

		if ( array() === $designer ) {
			return array();
		}

		$campaigns = $this->campaigns->all();

		if ( array() === $campaigns ) {
			return array();
		}

		if ( null === $order->coupon_codes ) {
			throw new InvalidArgumentException( 'The order\'s coupon data is unknown.' );
		}

		usort( $campaigns, static fn( Campaign $a, Campaign $b ): int => $a->id <=> $b->id );

		$result = array();

		foreach ( $designer as [ $line, $snapshot ] ) {
			foreach ( $campaigns as $campaign ) {
				if ( null !== $campaign->id && $campaign->covers( $snapshot->captured_at, $order->coupon_codes, self::on_sale( $line, $snapshot ) ) ) {
					$result[ $line->item_id ] = $campaign->id;
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * Whether a designer line was sold below its list price before coupons.
	 * A line sold without VAT is compared with the list price without VAT
	 * (review 30.15), so leaving out the VAT is never a sale price.
	 *
	 * @param OrderLineSnapshot $line     The order line.
	 * @param SalesLinePricing  $snapshot Its designer snapshot.
	 */
	public static function on_sale( OrderLineSnapshot $line, SalesLinePricing $snapshot ): bool {
		if ( null === $line->subtotal || $line->quantity < 1 || ! $snapshot->is_designer() ) {
			return false;
		}

		$price = null === $line->exempt_vat_bps ? $snapshot->price_units : DesignerRowAmounts::without_vat( $snapshot->price_units, $line->exempt_vat_bps, false, $snapshot->price_decimals );
		$list  = PricingMath::mul_div_round( $line->quantity, $price, 1 );

		return $list - $line->subtotal >= PricingMath::step( $snapshot->price_decimals );
	}
}
