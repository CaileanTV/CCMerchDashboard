<?php
/**
 * Payout settings: the holding period and a creator's settlement start.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin changes that move what a creator has earned, each audited in the
 * same transaction as the change and requiring `merchboard_manage_payouts`
 * (ADR-058):
 *
 * - the holding period in days (planning decision 2, default 14);
 * - the payout gift card provider (ADR-057, default none);
 * - a creator's settlement start (D3, D6, ADR-054): set, changed or removed
 *   under the creator's payout lock, so no request runs against the old
 *   window meanwhile. It only bounds the window; no ledger row is changed or
 *   filled in. D3 locks it once the creator has a settlement document
 *   (ADR-060). A start in the future is refused.
 *
 * A failed read or write is a DatabaseException; the transaction is rolled
 * back.
 */
final class PayoutSettings {

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param PayoutRepository $payouts     Settlement start storage and lock.
	 * @param AuditTrail       $audit       Audit trail.
	 * @param Transaction      $transaction Transactions.
	 * @param Closure          $clock       Current time (`Closure(): DateTimeImmutable`).
	 */
	public function __construct( private PayoutRepository $payouts, private AuditTrail $audit, private Transaction $transaction, private Closure $clock ) {
	}

	/**
	 * Sets the holding period.
	 *
	 * @param int $days Days (0 … Settings::MAX_PAYOUT_HOLDING_DAYS).
	 * @return bool Whether it changed.
	 * @throws PayoutException When not allowed or out of range.
	 */
	public function set_holding_days( int $days ): bool {
		self::authorize();

		if ( ! Settings::valid_holding_days( $days ) ) {
			throw new PayoutException( PayoutException::INVALID_HOLDING_DAYS );
		}

		$settings = Settings::all();
		$previous = $settings[ Settings::PAYOUT_HOLDING_DAYS ];

		if ( $previous === $days ) {
			return false;
		}

		$settings[ Settings::PAYOUT_HOLDING_DAYS ] = $days;

		$this->transaction->run(
			function () use ( $settings, $previous, $days ): void {
				$this->audit->record(
					AuditAction::PayoutSettingsChanged,
					null,
					array(
						'setting'        => Settings::PAYOUT_HOLDING_DAYS,
						'previous_value' => $previous,
						'value'          => $days,
					)
				);

				if ( ! Settings::save( $settings ) ) {
					throw new DatabaseException( 'Could not save the payout settings.' );
				}
			}
		);

		return true;
	}

	/**
	 * Chooses the payout gift card provider ('' = none, ADR-057). Only a
	 * registered adapter can be chosen; choosing one changes no plugin.
	 *
	 * @phpstan-param list<string> $known
	 *
	 * @param string   $provider Provider id or ''.
	 * @param string[] $known    Ids of the registered adapters.
	 * @return bool Whether it changed.
	 * @throws PayoutException When not allowed or the id is no registered adapter.
	 */
	public function set_gift_card_provider( string $provider, array $known ): bool {
		self::authorize();

		if ( ( '' !== $provider && ! in_array( $provider, $known, true ) ) || ! Settings::valid_provider_id( $provider ) ) {
			throw new PayoutException( PayoutException::INVALID_GIFT_CARD_PROVIDER );
		}

		$settings = Settings::all();
		$previous = $settings[ Settings::PAYOUT_GIFT_CARD_PROVIDER ];

		if ( $previous === $provider ) {
			return false;
		}

		$settings[ Settings::PAYOUT_GIFT_CARD_PROVIDER ] = $provider;

		$this->transaction->run(
			function () use ( $settings, $previous, $provider ): void {
				$this->audit->record(
					AuditAction::PayoutSettingsChanged,
					null,
					array(
						'setting'        => Settings::PAYOUT_GIFT_CARD_PROVIDER,
						'previous_value' => $previous,
						'value'          => $provider,
					)
				);

				if ( ! Settings::save( $settings ) ) {
					throw new DatabaseException( 'Could not save the payout settings.' );
				}
			}
		);

		return true;
	}

	/**
	 * The creator's settlement start (null = the whole history).
	 *
	 * @param int $creator_id Creator.
	 * @throws PayoutException When the creator does not exist.
	 * @throws DatabaseException When the read fails.
	 */
	public function settlement_start( int $creator_id ): ?DateTimeImmutable {
		return $this->payouts->settlement_start( $creator_id );
	}

	/**
	 * Sets, changes or removes the creator's settlement start.
	 *
	 * @param int                    $creator_id Creator.
	 * @param DateTimeImmutable|null $start      Start, null for the whole history.
	 * @return bool Whether it changed.
	 * @throws PayoutException When not allowed, the creator does not exist or the start is in the future.
	 */
	public function set_settlement_start( int $creator_id, ?DateTimeImmutable $start ): bool {
		self::authorize();
		$start = $start?->setTimezone( new DateTimeZone( 'UTC' ) );
		$now   = ( $this->clock )();

		if ( null !== $start && $start > $now ) {
			throw new PayoutException( PayoutException::INVALID_START );
		}

		return $this->payouts->with_creator_lock(
			$creator_id,
			fn(): bool => $this->transaction->run(
				function () use ( $creator_id, $start, $now ): bool {
					$previous = $this->payouts->settlement_start( $creator_id, true );

					if ( self::stored( $previous ) === self::stored( $start ) ) {
						return false;
					}

					// D3: locked once the creator has a settlement document (ADR-060).
					if ( $this->payouts->has_settlement_documents( $creator_id ) ) {
						throw new PayoutException( PayoutException::START_LOCKED );
					}

					$this->payouts->set_settlement_start( $creator_id, $start, $now );
					$this->payouts->assert_same_connection( $creator_id );
					$this->audit->record(
						AuditAction::PayoutStartChanged,
						$creator_id,
						array(
							'previous_start' => self::stored( $previous ),
							'start'          => self::stored( $start ),
						)
					);
					$this->payouts->assert_same_connection( $creator_id );

					return true;
				}
			)
		);
	}

	/**
	 * A start as stored (UTC, whole seconds), or null.
	 *
	 * @param DateTimeImmutable|null $start Start.
	 */
	private static function stored( ?DateTimeImmutable $start ): ?string {
		return $start?->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Requires `merchboard_manage_payouts`.
	 *
	 * @throws PayoutException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			throw new PayoutException( PayoutException::NOT_AUTHORIZED );
		}
	}
}
