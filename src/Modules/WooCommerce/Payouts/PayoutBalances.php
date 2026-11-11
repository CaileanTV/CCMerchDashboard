<?php
/**
 * A creator's payout balances.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use Closure;
use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutAdjustmentRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * Balances per currency (ADR-054): earned payout from the ledger with the
 * creator's settlement start and the holding period from the settings,
 * plus executed payout adjustments (ADR-063; a negative one lowers the
 * balance and is offset like any outstanding amount), minus completed and
 * pending payouts. Server-side only; never cached.
 * Requests and completions read it under the creator's payout lock, so two
 * requests can never reserve the same amount.
 */
final class PayoutBalances {

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param LedgerCreatorPayouts            $earned      Earned payout.
	 * @param PayoutRepository                $payouts     Payouts and settlement start.
	 * @param Closure                         $clock       Current time (`Closure(): DateTimeImmutable`).
	 * @param PayoutAdjustmentRepository|null $adjustments Executed adjustments (ADR-063); null = none.
	 */
	public function __construct( private LedgerCreatorPayouts $earned, private PayoutRepository $payouts, private Closure $clock, private ?PayoutAdjustmentRepository $adjustments = null ) {
	}

	/**
	 * Balances of every currency with earnings or payouts.
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, PayoutBalance>
	 * @throws PayoutException When the creator does not exist.
	 * @throws DatabaseException When a read fails.
	 */
	public function for_creator( int $creator_id ): array {
		$start  = $this->payouts->settlement_start( $creator_id );
		$earned = $this->earned->earned( $creator_id, $start, ( $this->clock )(), Settings::payout_holding_days() );
		$totals = $this->payouts->totals( $creator_id );
		$adjust = null === $this->adjustments ? array() : $this->adjustments->totals( $creator_id );
		$held   = self::held( $creator_id );
		$result = array();

		foreach ( array_unique( array_merge( array_keys( $earned ), array_keys( $totals ), array_keys( $adjust ), array_keys( $held ) ) ) as $currency ) {
			$currency = (string) $currency;
			$summary  = $earned[ $currency ] ?? null;
			// No row in the window is a known 0; any unknown row makes it unknown.
			$units = null === $summary ? 0 : $summary->amount_units();
			// Executed adjustments count as earned (ADR-063); an unknown stays unknown.
			$units               = null === $units ? null : SalesAmount::add( $units, $adjust[ $currency ] ?? 0 );
			$result[ $currency ] = new PayoutBalance(
				$currency,
				$units,
				null === $summary ? 0 : $summary->unknown_rows,
				$totals[ $currency ]['completed'] ?? 0,
				$totals[ $currency ]['reserved'] ?? 0,
				$held[ $currency ] ?? 0
			);
		}

		ksort( $result );

		return $result;
	}

	/**
	 * What other modules set aside from a creator's balance until it is
	 * charged or released, per currency in units (open giveaways, ADR-110).
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, int>
	 */
	private static function held( int $creator_id ): array {
		/**
		 * Filters what is set aside from a creator's balance, per currency in units.
		 *
		 * @param mixed $held       Units per currency (array<string, int>).
		 * @param int   $creator_id Creator.
		 */
		$held   = apply_filters( 'merchboard_payout_held', array(), $creator_id );
		$result = array();

		foreach ( is_array( $held ) ? $held : array() as $currency => $units ) {
			if ( is_string( $currency ) && is_int( $units ) && $units > 0 ) {
				$result[ $currency ] = $units;
			}
		}

		return $result;
	}

	/**
	 * The balance of one currency (a currency without earnings or payouts has
	 * a known balance of 0).
	 *
	 * @param int    $creator_id Creator.
	 * @param string $currency   Currency.
	 * @throws PayoutException When the creator does not exist.
	 * @throws DatabaseException When a read fails.
	 */
	public function in_currency( int $creator_id, string $currency ): PayoutBalance {
		return $this->for_creator( $creator_id )[ $currency ] ?? new PayoutBalance( $currency, 0, 0, 0, 0 );
	}
}
