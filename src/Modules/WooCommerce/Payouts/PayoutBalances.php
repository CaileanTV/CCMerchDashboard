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
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;

defined( 'ABSPATH' ) || exit;

/**
 * Balances per currency (ADR-054): earned payout from the ledger with the
 * creator's settlement start and the holding period from the settings,
 * minus completed and pending payouts. Server-side only; never cached.
 * Requests and completions read it under the creator's payout lock, so two
 * requests can never reserve the same amount.
 */
final class PayoutBalances {

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param LedgerCreatorPayouts $earned  Earned payout.
	 * @param PayoutRepository     $payouts Payouts and settlement start.
	 * @param Closure              $clock   Current time (`Closure(): DateTimeImmutable`).
	 */
	public function __construct( private LedgerCreatorPayouts $earned, private PayoutRepository $payouts, private Closure $clock ) {
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
		$result = array();

		foreach ( array_unique( array_merge( array_keys( $earned ), array_keys( $totals ) ) ) as $currency ) {
			$currency = (string) $currency;
			$summary  = $earned[ $currency ] ?? null;
			// No row in the window is a known 0; any unknown row makes it unknown.
			$result[ $currency ] = new PayoutBalance(
				$currency,
				null === $summary ? 0 : $summary->amount_units(),
				null === $summary ? 0 : $summary->unknown_rows,
				$totals[ $currency ]['completed'] ?? 0,
				$totals[ $currency ]['reserved'] ?? 0
			);
		}

		ksort( $result );

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
