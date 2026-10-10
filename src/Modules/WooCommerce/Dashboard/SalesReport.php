<?php
/**
 * The creator's sales report of `GET /me/sales`.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfits;
use MerchBoard\Modules\WooCommerce\Sales\SalesPeriod;
use MerchBoard\Modules\WooCommerce\Sales\SalesProductTotals;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;
use MerchBoard\Modules\WooCommerce\Sales\SalesSeriesPoint;
use MerchBoard\Modules\WooCommerce\Sales\SalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesTotals;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the response body of `GET /me/sales` (ADR-040 pkt. 11) from
 * SalesStatistics: one block per currency with the totals, a dense series
 * (every period of the query, periods without rows as zeros) and at most
 * MAX_PRODUCTS products with `truncated`. Only the shown products' names are
 * looked up (SalesProductNames, once per distinct product id, never id 0);
 * a product without a current name gets the ledger's item name, and an empty
 * item name becomes "Unknown product". The sums are never changed here.
 *
 * Each currency also carries `profit` (ADR-050): the creator's realized
 * profit (ADR-049) of the same rows in the period (CreatorProfits, one query),
 * with `complete`, `incomplete` or `unknown`; only a complete profit has an
 * amount. It is separate from the sales figures, which are unchanged.
 */
final class SalesReport implements SalesReports {

	/**
	 * Most products per currency.
	 */
	public const MAX_PRODUCTS = 100;

	/**
	 * Zero amount.
	 */
	private const ZERO = '0.0000';

	/**
	 * Constructor.
	 *
	 * @param SalesStatistics   $statistics Read model of the sales ledger.
	 * @param SalesProductNames $names      Current product names.
	 * @param CreatorProfits    $profits    Realized profit of the same rows (ADR-050).
	 */
	public function __construct( private SalesStatistics $statistics, private SalesProductNames $names, private CreatorProfits $profits ) {
	}

	/**
	 * The report of a creator for a query.
	 *
	 * @param int        $creator_id Creator id (from CreatorContext).
	 * @param SalesQuery $query      Validated period.
	 * @return array{from: string, to: string, interval: string, timezone: string, currencies: list<array<string, mixed>>}
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	public function build( int $creator_id, SalesQuery $query ): array {
		$report = array(
			'from'       => $query->from,
			'to'         => $query->to,
			'interval'   => $query->interval->value,
			'timezone'   => $query->timezone->getName(),
			'currencies' => array(),
		);
		$totals = $this->statistics->totals( $creator_id, $query->start, $query->end );

		if ( array() === $totals ) {
			return $report;
		}

		$points   = array();
		$products = array();

		foreach ( $this->statistics->series( $creator_id, $query->periods ) as $point ) {
			$points[ $point->currency ][ $point->index ] = $point;
		}

		foreach ( $this->statistics->products( $creator_id, $query->start, $query->end ) as $product ) {
			$products[ $product->currency ][] = $product;
		}

		$shown = array();
		$ids   = array();

		foreach ( $products as $currency => $list ) {
			$shown[ $currency ] = array_slice( $list, 0, self::MAX_PRODUCTS );

			foreach ( $shown[ $currency ] as $product ) {
				if ( $product->product_id > 0 ) {
					$ids[ $product->product_id ] = $product->product_id;
				}
			}
		}

		$names   = array() === $ids ? array() : $this->names->current( array_values( $ids ) );
		$profits = $this->profits->totals( $creator_id, $query->start, $query->end );

		foreach ( $totals as $total ) {
			$currency               = $total->currency;
			$report['currencies'][] = array(
				'currency'  => $currency,
				'totals'    => self::totals( $total ),
				// Whether every row of the period has its stored tax (ADR-053): only then are the amounts incl. VAT known.
				'vat'       => array(
					'status'       => null === $total->net_sales_incl_vat ? 'incomplete' : 'complete',
					'missing_rows' => $total->missing_vat_rows,
				),
				// Same creator, period and rows as the totals, so every currency has a profit.
				'profit'    => isset( $profits[ $currency ] ) ? $profits[ $currency ]->to_array() : null,
				'series'    => self::series( $query->periods, $points[ $currency ] ?? array(), null !== $total->net_sales_incl_vat ),
				'products'  => array_map( static fn( SalesProductTotals $product ): array => self::product( $product, $names ), $shown[ $currency ] ?? array() ),
				'truncated' => count( $products[ $currency ] ?? array() ) > self::MAX_PRODUCTS,
			);
		}

		return $report;
	}

	/**
	 * The totals of a currency.
	 *
	 * @param SalesTotals $totals Totals.
	 * @return array{net_sales: string, sales: string, adjustments: string, net_sales_incl_vat: string|null, sales_incl_vat: string|null, adjustments_incl_vat: string|null, units: int, orders: int}
	 */
	private static function totals( SalesTotals $totals ): array {
		return array(
			'net_sales'            => $totals->net_sales,
			'sales'                => $totals->sales,
			'adjustments'          => $totals->adjustments,
			'net_sales_incl_vat'   => $totals->net_sales_incl_vat,
			'sales_incl_vat'       => $totals->sales_incl_vat,
			'adjustments_incl_vat' => $totals->adjustments_incl_vat,
			'units'                => $totals->units,
			'orders'               => $totals->orders,
		);
	}

	/**
	 * Every period, with the currency's sums or zeros.
	 *
	 * @phpstan-param list<SalesPeriod> $periods
	 *
	 * @param SalesPeriod[]                $periods Periods of the query.
	 * @param array<int, SalesSeriesPoint> $points  The currency's points by period index.
	 * @param bool                         $vat     Whether the currency's amounts incl. VAT are known (every row has its tax).
	 * @return list<array{start: string, end: string, net_sales: string, net_sales_incl_vat: string|null, units: int, orders: int}>
	 */
	private static function series( array $periods, array $points, bool $vat ): array {
		return array_map(
			static function ( SalesPeriod $period ) use ( $points, $vat ): array {
				$point = $points[ $period->index ] ?? null;

				return array(
					'start'              => $period->first_day,
					'end'                => $period->last_day,
					'net_sales'          => null === $point ? self::ZERO : $point->net_sales,
					'net_sales_incl_vat' => $vat ? ( null === $point ? self::ZERO : $point->net_sales_incl_vat ) : null,
					'units'              => null === $point ? 0 : $point->units,
					'orders'             => null === $point ? 0 : $point->orders,
				);
			},
			$periods
		);
	}

	/**
	 * A product with its current name or the ledger's item name.
	 *
	 * @param SalesProductTotals $product Product sums.
	 * @param array<int, string> $names   Current names by product id.
	 * @return array{product_id: int, name: string, net_sales: string, net_sales_incl_vat: string|null, units: int}
	 */
	private static function product( SalesProductTotals $product, array $names ): array {
		$name = ( $product->product_id > 0 ? $names[ $product->product_id ] ?? null : null ) ?? PlainText::of( $product->item_name );

		return array(
			'product_id'         => $product->product_id,
			'name'               => '' === $name ? __( 'Unknown product', 'merchboard' ) : $name,
			'net_sales'          => $product->net_sales,
			'net_sales_incl_vat' => $product->net_sales_incl_vat,
			'units'              => $product->units,
		);
	}
}
