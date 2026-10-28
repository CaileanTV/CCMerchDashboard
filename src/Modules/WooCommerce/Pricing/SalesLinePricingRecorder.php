<?php
/**
 * Records the creator share snapshot of a new order line.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Core\Logging\Logger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Takes the snapshot of ADR-047/ADR-049 when an order line is created: the
 * product's owner, the share that applies to it at that moment (the
 * product's own share for this owner, otherwise the owner's default
 * markup), the store's price decimals and the owner's VAT status. Stored by
 * the append-only SalesLinePricingRepository. Nothing here reads or writes
 * a WooCommerce price: the sale price the share applies to comes from the
 * sales ledger row later (RealizedProfit).
 *
 * System work in the customer's request (checkout, wp-admin, REST): no
 * capability check, as for the category reconciliation (ADR-033).
 *
 * Failure behaviour (as the sales ledger, ADR-038): it never throws, so
 * order creation is never interrupted. A failure is logged and leaves the
 * line without a snapshot, i.e. with unknown profit and payout; a snapshot
 * is never guessed or written later. The sales ledger does not depend on it.
 */
final class SalesLinePricingRecorder {

	/**
	 * Constructor.
	 *
	 * @param PricingProductStore        $products  WooCommerce access (read only).
	 * @param MarkupResolver             $markups   The share that applies now.
	 * @param SalesLinePricingRepository $snapshots Append-only storage.
	 * @param Logger                     $logger    Logger.
	 * @param CreatorVatRepository|null  $vat       Creators' VAT status (ADR-048); null leaves it unknown.
	 * @param DesignerPriceSource|null   $designer  Designer products' prices (ADR-080); null: none.
	 */
	public function __construct(
		private PricingProductStore $products,
		private MarkupResolver $markups,
		private SalesLinePricingRepository $snapshots,
		private Logger $logger,
		private ?CreatorVatRepository $vat = null,
		private ?DesignerPriceSource $designer = null
	) {
	}

	/**
	 * Records the snapshot of a new product line, once. A second call for the
	 * same order line keeps the first snapshot.
	 *
	 * @param int    $order_item_id  Order line.
	 * @param int    $order_id       Order.
	 * @param int    $product_id     Parent product of the line.
	 * @param int    $variation_id   Variation of the line, 0 for a simple product.
	 * @param string $order_currency Order currency.
	 * @return SalesLinePricing|null The stored snapshot; null when none was stored (no owner, already recorded, or a failure).
	 */
	public function record( int $order_item_id, int $order_id, int $product_id, int $variation_id, string $order_currency ): ?SalesLinePricing {
		try {
			if ( $order_item_id < 1 || $order_id < 1 || $product_id < 1 || $variation_id < 0 ) {
				return null;
			}

			$unit = $this->products->find( $variation_id > 0 ? $variation_id : $product_id );

			if ( null === $unit || $unit->product_id() !== $product_id ) {
				// Unknown product, or a line whose variation does not belong to its product.
				return null;
			}

			// A designer product (ADR-080 pkt. 8): base price, profit and list price; never the share model.
			$designer = $this->designer?->price( $product_id );

			if ( null !== $designer ) {
				if ( $designer->currency !== $order_currency ) {
					// Amounts in another currency than the order's are never converted: the profit stays unknown.
					$this->logger->warning(
						'A designer product was sold in another currency than its base price; its profit stays unknown.',
						array(
							'order_id'      => $order_id,
							'order_item_id' => $order_item_id,
						)
					);

					return null;
				}

				$snapshot = SalesLinePricing::capture_designer(
					$order_item_id,
					$order_id,
					$product_id,
					$variation_id,
					$order_currency,
					$designer,
					$this->products->decimals(),
					null === $this->vat ? null : $this->vat->find( $designer->creator_id ),
					PricingRows::now()
				);

				return $this->snapshots->insert( $snapshot ) ? $snapshot : null;
			}

			$markup = $this->markups->resolve( $product_id );

			if ( null === $markup ) {
				// No creator: no snapshot.
				return null;
			}

			$snapshot = SalesLinePricing::capture(
				$order_item_id,
				$order_id,
				$product_id,
				$variation_id,
				$order_currency,
				$markup,
				$this->products->decimals(),
				null === $this->vat ? null : $this->vat->find( $markup->creator_id ),
				PricingRows::now()
			);

			return $this->snapshots->insert( $snapshot ) ? $snapshot : null;
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The pricing snapshot of an order line could not be recorded; its profit stays unknown.',
				array(
					'order_id'      => $order_id,
					'order_item_id' => $order_item_id,
					'error'         => $e::class,
				)
			);

			return null;
		}
	}
}
