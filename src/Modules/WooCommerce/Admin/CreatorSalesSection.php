<?php
/**
 * "Sales" section on the creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReports;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesInterval;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered sales of the creator being edited (ADR-040 pkt. 13, with the
 * project owner's decision of 2026-10-07): the default period of `/me/sales`
 * (the last 30 days including today, by day, in the site's time zone) with
 * net sales, units and orders per currency and the daily series as a table.
 * No product breakdown, no admin REST and no HTTP request: it calls the same
 * SalesReports service as `GET /me/sales` (in production CachedSalesReports),
 * so the definitions, the cache entry and its invalidation are the ones of
 * the creator's dashboard. The creator is the one the page loaded, never a
 * request parameter of this section. Only for `merchboard_manage_creators`;
 * active and inactive creators alike. No customer data.
 */
final class CreatorSalesSection {

	/**
	 * Current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param SalesReports                        $reports Sales of a creator (the `/me/sales` service).
	 * @param Logger                              $logger  Technical log.
	 * @param (Closure(): DateTimeImmutable)|null $clock   Current time (default: now).
	 */
	public function __construct( private SalesReports $reports, private Logger $logger, ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now' );
	}

	/**
	 * Registers the section on the creator's edit page, after the product
	 * category and product sections.
	 */
	public function register(): void {
		add_action( 'merchboard_creators_edit_sections', array( $this, 'render' ), 20 );
	}

	/**
	 * Renders the section below the creator form.
	 *
	 * @param Creator $creator Creator being edited.
	 */
	public function render( Creator $creator ): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return;
		}

		echo '<hr><h2 id="merchboard-sales">' . esc_html__( 'Sales', 'merchboard' ) . '</h2>';

		try {
			$query  = SalesQuery::resolve( null, null, SalesInterval::Day, wp_timezone(), ( $this->clock )() );
			$report = $this->reports->build( $creator->id, $query );
		} catch ( DatabaseException | InvalidArgumentException $e ) {
			$this->logger->error(
				'Could not read the sales of a creator.',
				array(
					'creator_id' => $creator->id,
					'reason'     => $e->getMessage(),
				)
			);
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The sales could not be loaded. Try again later; details are in the log.', 'merchboard' ) . '</p></div>';

			return;
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: first day, 2: last day (both included). */
				__( 'Last 30 days: %1$s – %2$s', 'merchboard' ),
				self::day( $query->from ),
				self::day( $query->to )
			)
		) . '</p>';
		echo '<p class="description">' . esc_html__( "The shop's net sales of this creator's products after discounts, excluding VAT, shipping and fees. It is not a payout. The figures and their definitions are the same as in the creator's dashboard; dates are in the site's time zone.", 'merchboard' ) . '</p>';

		$currencies = is_array( $report['currencies'] ?? null ) ? array_values( array_filter( $report['currencies'], 'is_array' ) ) : array();

		if ( array() === $currencies ) {
			echo '<p><em>' . esc_html__( 'No net sales in the last 30 days.', 'merchboard' ) . '</em></p>';

			return;
		}

		if ( count( $currencies ) > 1 ) {
			echo '<p>' . esc_html__( 'The figures are shown per currency and never added together.', 'merchboard' ) . '</p>';
		}

		foreach ( $currencies as $block ) {
			$this->render_currency( $block );
		}
	}

	/**
	 * Totals and daily series of one currency.
	 *
	 * @param array<mixed> $block One entry of the report's `currencies`.
	 */
	private function render_currency( array $block ): void {
		$currency = is_string( $block['currency'] ?? null ) ? $block['currency'] : '';
		$totals   = is_array( $block['totals'] ?? null ) ? $block['totals'] : array();
		$series   = is_array( $block['series'] ?? null ) ? array_values( array_filter( $block['series'], 'is_array' ) ) : array();

		echo '<h3>' . esc_html( $currency ) . '</h3>';
		echo '<table class="widefat striped merchboard-sales-totals" style="max-width:40rem"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Net sales (excl. VAT)', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Units', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Orders', 'merchboard' ) . '</th>';
		echo '</tr></thead><tbody><tr>';
		echo '<td>' . esc_html( self::money( $totals['net_sales'] ?? null, $currency ) ) . '</td>';
		echo '<td>' . esc_html( self::count( $totals['units'] ?? null ) ) . '</td>';
		echo '<td>' . esc_html( self::count( $totals['orders'] ?? null ) ) . '</td>';
		echo '</tr></tbody></table>';

		echo '<details class="merchboard-sales-series"><summary>' . esc_html__( 'Net sales per day', 'merchboard' ) . '</summary>';
		echo '<table class="widefat striped" style="max-width:40rem"><caption class="screen-reader-text">' . esc_html(
			sprintf(
				/* translators: %s: currency code (e.g. DKK). */
				__( 'Net sales, units and orders per period (%s)', 'merchboard' ),
				$currency
			)
		) . '</caption><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Day', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Net sales (excl. VAT)', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Units', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Orders', 'merchboard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $series as $point ) {
			echo '<tr>';
			echo '<th scope="row">' . esc_html( is_string( $point['start'] ?? null ) ? self::day( $point['start'] ) : '' ) . '</th>';
			echo '<td>' . esc_html( self::money( $point['net_sales'] ?? null, $currency ) ) . '</td>';
			echo '<td>' . esc_html( self::count( $point['units'] ?? null ) ) . '</td>';
			echo '<td>' . esc_html( self::count( $point['orders'] ?? null ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></details>';
	}

	/**
	 * A four-decimal amount as money with two decimals and the currency code,
	 * e.g. "1234.5000" DKK → "1.234,50 DKK" in Danish. Integer arithmetic only
	 * (rounded half away from zero, like SalesAmount); no float in between.
	 *
	 * @param mixed  $amount   Decimal string from the report.
	 * @param string $currency Currency code.
	 */
	public static function money( mixed $amount, string $currency ): string {
		global $wp_locale;

		try {
			$units = SalesAmount::parse( is_string( $amount ) ? $amount : '' );
		} catch ( InvalidArgumentException | OverflowException ) {
			return '';
		}

		$negative = $units < 0;
		$cents    = intdiv( abs( $units ) + 50, 100 );
		$decimal  = is_object( $wp_locale ) && isset( $wp_locale->number_format['decimal_point'] ) ? (string) $wp_locale->number_format['decimal_point'] : '.';
		$text     = number_format_i18n( intdiv( $cents, 100 ) ) . $decimal . str_pad( (string) ( $cents % 100 ), 2, '0', STR_PAD_LEFT );

		return ( $negative && 0 !== $cents ? '-' : '' ) . $text . ' ' . $currency;
	}

	/**
	 * A count in the site's number format.
	 *
	 * @param mixed $value Integer from the report.
	 */
	private static function count( mixed $value ): string {
		return is_int( $value ) ? ( $value < 0 ? '-' : '' ) . number_format_i18n( abs( $value ) ) : '';
	}

	/**
	 * A `YYYY-MM-DD` date of the site's calendar in the site's date format.
	 *
	 * @param string $date Date.
	 */
	private static function day( string $date ): string {
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );

		if ( false === $time ) {
			return $date;
		}

		$text = wp_date( (string) get_option( 'date_format' ), $time->getTimestamp(), new DateTimeZone( 'UTC' ) );

		return false === $text ? $date : $text;
	}
}
