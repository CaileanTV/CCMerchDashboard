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
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetails;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetailsStore;

defined( 'ABSPATH' ) || exit;

/**
 * Admin changes that move what a creator has earned, each audited in the
 * same transaction as the change and requiring `merchboard_manage_payouts`
 * (ADR-058):
 *
 * - the holding period in days (planning decision 2, default 14);
 * - the payout gift card provider (ADR-057, default none);
 * - the master data of settlement documents (ADR-062 K09): the issuing
 *   business' CVR number and each creator's legal name, address and CVR/SE
 *   number. They move nothing earned; issued documents keep their snapshot;
 * - a creator's settlement start (D3, D6, ADR-054): set, changed or removed
 *   under the creator's payout lock and row lock (CreatorRowLock), so no
 *   request runs against the old window meanwhile. It only bounds the
 *   window; no ledger row is changed or filled in. D3 locks it once the
 *   creator has a settlement document (ADR-060). A start in the future is
 *   refused.
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
	 * @param PayoutRepository                $payouts     Settlement start storage and lock.
	 * @param AuditTrail                      $audit       Audit trail.
	 * @param Transaction                     $transaction Transactions.
	 * @param Closure                         $clock       Current time (`Closure(): DateTimeImmutable`).
	 * @param CreatorBillingDetailsStore|null $billing     Creators' master data (ADR-062 K09); null = none.
	 */
	public function __construct( private PayoutRepository $payouts, private AuditTrail $audit, private Transaction $transaction, private Closure $clock, private ?CreatorBillingDetailsStore $billing = null ) {
	}

	/**
	 * Sets the CVR number of the business that issues the settlement
	 * documents (ADR-062 K09): eight digits (spaces removed) or '' for none.
	 * New documents copy it when they are issued; issued documents keep theirs.
	 *
	 * @param string $cvr CVR number.
	 * @return bool Whether it changed.
	 * @throws PayoutException When not allowed or not eight digits.
	 */
	public function set_company_cvr( string $cvr ): bool {
		self::authorize();
		$cvr = preg_replace( '/\s+/', '', $cvr ) ?? '';

		if ( ! Settings::valid_cvr( $cvr ) ) {
			throw new PayoutException( PayoutException::INVALID_CVR );
		}

		$settings = Settings::all();
		$previous = $settings[ Settings::COMPANY_CVR ];

		if ( $previous === $cvr ) {
			return false;
		}

		$settings[ Settings::COMPANY_CVR ] = $cvr;

		$this->transaction->run(
			function () use ( $settings, $previous, $cvr ): void {
				$this->audit->record(
					AuditAction::PayoutSettingsChanged,
					null,
					array(
						'setting'        => Settings::COMPANY_CVR,
						'previous_value' => $previous,
						'value'          => $cvr,
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
	 * The creator's master data for settlement documents (ADR-062 K09).
	 *
	 * @param int $creator_id Creator.
	 */
	public function billing_details( int $creator_id ): CreatorBillingDetails {
		return null === $this->billing ? new CreatorBillingDetails() : $this->billing->find( $creator_id );
	}

	/**
	 * Sets the creator's master data for settlement documents (ADR-062 K09):
	 * legal name, address and CVR/SE number, each optional. Under the
	 * creator's row lock in one transaction with the audit record (names of
	 * the changed fields, never values). New documents copy it when they are
	 * issued; issued documents keep theirs.
	 *
	 * @param int                   $creator_id Creator.
	 * @param array<string, string> $values     Field => submitted value (CreatorBillingDetails::FIELDS).
	 * @return bool Whether it changed.
	 * @throws PayoutException When not allowed, the creator does not exist or a value is invalid.
	 */
	public function set_billing_details( int $creator_id, array $values ): bool {
		self::authorize();
		$details = CreatorBillingDetails::from_input( $values );
		$billing = $this->billing ?? throw new PayoutException( PayoutException::NOT_FOUND );

		$changed = $this->transaction->run(
			function () use ( $billing, $creator_id, $details ): bool {
				// Locks the creator's row (and refuses an unknown creator) until the commit.
				$this->payouts->settlement_start( $creator_id, true );
				$changed = $details->changed_fields( $billing->find( $creator_id ) );

				if ( array() === $changed ) {
					return false;
				}

				$billing->save( $creator_id, $details );
				$this->audit->record( AuditAction::CreatorBillingDetailsChanged, $creator_id, array( 'changed_fields' => $changed ) );

				return true;
			}
		);

		// Only that they changed: never the values.
		if ( $changed ) {
			do_action( 'merchboard_notify', 'billing_details_changed', $creator_id, array(), '' );
		}

		return $changed;
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
					$previous = CreatorRowLock::acquire( $this->payouts, $creator_id );

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
