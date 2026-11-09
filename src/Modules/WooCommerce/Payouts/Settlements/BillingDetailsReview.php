<?php
/**
 * The creator's own master data, required before the first payout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\WooCommerce\Payouts\CprNumber;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's own master data for settlement documents (ADR-104, status
 * note 2026-11-09: no confirmation):
 *
 * - The creator saves them; they apply at once (`billing_details`, ADR-062
 *   K09). An administrator can still change them on the creator's page.
 * - A registered business gives its CVR/SE number; a private person gives
 *   the CPR number once. It is stored encrypted (PayoutCipher,
 *   `billing_cpr`), never shown back, and copied into each payout's encrypted
 *   details for the report.
 * - The first payout (no settlement document yet) needs master data.
 *
 * Values are never audited, logged or sent in notifications: only the
 * names of changed fields.
 */
final class BillingDetailsReview {

	/**
	 * No master data yet.
	 */
	public const MISSING = 'missing';

	/**
	 * Master data are saved.
	 */
	public const CONFIRMED = 'confirmed';

	/**
	 * Fields the creator must fill in.
	 */
	public const REQUIRED = array( 'legal_name', 'address_1', 'postcode', 'city', 'country' );

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param CreatorBillingDetailsStore $billing     Master data and the CPR number.
	 * @param PayoutRepository           $payouts     Row lock, settlement documents and pending payouts.
	 * @param AuditTrail                 $audit       Audit trail.
	 * @param Transaction                $transaction Transactions.
	 * @param Closure                    $clock       Current time (`Closure(): DateTimeImmutable`).
	 * @param PayoutCipher|null          $cipher      Encryption of the CPR number; null = none (private persons cannot save).
	 */
	public function __construct(
		private CreatorBillingDetailsStore $billing,
		private PayoutRepository $payouts,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Closure $clock,
		private ?PayoutCipher $cipher = null
	) {
	}

	/**
	 * Validated master data from the creator's form, with the CPR number
	 * when one was typed: every field is read, the required ones must be
	 * filled in, texts are single lines within their length, and the type is
	 * a registered business (then the CVR/SE number is required: eight
	 * digits, spaces removed) or a private person (then no CVR/SE number is
	 * kept, and the CPR number is required unless one is stored).
	 *
	 * @param array<mixed> $values    Field => submitted value (also `type` and `cpr`).
	 * @param bool         $has_cpr   Whether a CPR number is stored already.
	 * @return array{0: CreatorBillingDetails, 1: CprNumber|null}
	 * @throws BillingDetailsInvalid With a code per invalid field.
	 */
	public static function validate( array $values, bool $has_cpr = false ): array {
		$clean  = array();
		$errors = array();

		foreach ( CreatorBillingDetails::FIELDS as $field => $max ) {
			$value = $values[ $field ] ?? '';

			if ( ! is_string( $value ) ) {
				$errors[ $field ] = 'invalid';
				continue;
			}

			$value = trim( $value );

			if ( 'cvr' === $field ) {
				$value = preg_replace( '/\s+/', '', $value ) ?? '';

				if ( '' !== $value && 1 !== preg_match( '/^\d{8}$/', $value ) ) {
					$errors[ $field ] = 'invalid_cvr';
				}
			} elseif ( 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				$errors[ $field ] = 'invalid';
			} elseif ( mb_strlen( $value ) > $max ) {
				$errors[ $field ] = 'too_long';
			} elseif ( '' === $value && in_array( $field, self::REQUIRED, true ) ) {
				$errors[ $field ] = 'required';
			}

			$clean[ $field ] = $value;
		}

		$type = $values['type'] ?? '';
		$cpr  = null;

		if ( ! in_array( $type, array( CreatorBillingDetails::BUSINESS, CreatorBillingDetails::PRIVATE ), true ) ) {
			$errors['type'] = 'required';
		} elseif ( CreatorBillingDetails::PRIVATE === $type ) {
			unset( $errors['cvr'] );
			$clean['cvr'] = '';
			$input        = is_string( $values['cpr'] ?? null ) ? trim( $values['cpr'] ) : '';

			if ( '' !== $input ) {
				try {
					$cpr = CprNumber::from_input( $input );
				} catch ( PayoutException ) {
					$errors['cpr'] = 'invalid_cpr';
				}
			} elseif ( ! $has_cpr ) {
				$errors['cpr'] = 'required';
			}
		} elseif ( '' === ( $clean['cvr'] ?? '' ) && ! isset( $errors['cvr'] ) ) {
			$errors['cvr'] = 'required';
		}

		if ( array() !== $errors ) {
			throw new BillingDetailsInvalid( $errors );
		}

		$clean['type'] = (string) $type;

		return array( CreatorBillingDetails::from_input( $clean ), $cpr );
	}

	/**
	 * The creator's master data and where they stand.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When a read fails.
	 */
	public function state( int $creator_id ): BillingDetailsState {
		$details = $this->billing->find( $creator_id );
		$has     = self::has( $details );
		$first   = ! $this->payouts->has_settlement_documents( $creator_id );

		return new BillingDetailsState(
			$has ? self::CONFIRMED : self::MISSING,
			$has ? $details : null,
			$has ? ( $this->billing->confirmation( $creator_id )['at'] ?? null ) : null,
			$first && ! $has,
			$first && ! $has && $this->payouts->count_pending( $creator_id ) > 0,
			CreatorBillingDetails::PRIVATE === $details->type,
			null !== $this->billing->cpr( $creator_id )
		);
	}

	/**
	 * Whether the creator may request a payout: always after the first
	 * settlement document, else once master data are saved.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When a read fails.
	 */
	public function allows_request( int $creator_id ): bool {
		return $this->allows_completion( $creator_id );
	}

	/**
	 * Whether a pending payout of the creator may be completed: always after
	 * the first settlement document, else only with master data.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When a read fails.
	 */
	public function allows_completion( int $creator_id ): bool {
		return $this->payouts->has_settlement_documents( $creator_id ) || self::has( $this->billing->find( $creator_id ) );
	}

	/**
	 * Whether the creator is paid as a private person, so each payout needs
	 * the CPR number.
	 *
	 * @param int $creator_id Creator.
	 */
	public function cpr_required( int $creator_id ): bool {
		return CreatorBillingDetails::PRIVATE === $this->billing->find( $creator_id )->type;
	}

	/**
	 * The CPR number to copy into a payout: null for a business (or master
	 * data without a type).
	 *
	 * @param int $creator_id Creator.
	 * @throws PayoutException `cpr_missing` for a private person without a readable CPR number.
	 */
	public function cpr( int $creator_id ): ?CprNumber {
		if ( ! $this->cpr_required( $creator_id ) ) {
			return null;
		}

		$stored = $this->billing->cpr( $creator_id );

		if ( null === $stored || null === $this->cipher ) {
			throw new PayoutException( PayoutException::CPR_MISSING );
		}

		try {
			return CprNumber::from_input( $this->cipher->decrypt( $stored['ciphertext'], $stored['key_id'] ) );
		} catch ( PayoutException ) {
			throw new PayoutException( PayoutException::CPR_MISSING );
		}
	}

	/**
	 * The creator saves master data (the caller resolved the creator with
	 * CreatorContext); they apply at once. A typed CPR number replaces the
	 * stored one; a business keeps none.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $values     Field => submitted value (also `type` and `cpr`).
	 * @return bool Whether anything changed.
	 * @throws BillingDetailsInvalid With a code per invalid field (`cpr` `unavailable` without an encryption key).
	 * @throws PayoutException When the creator does not exist.
	 * @throws Throwable When a read or write fails (DatabaseException); the meta cache is dropped first.
	 */
	public function submit( int $creator_id, array $values ): bool {
		$has_cpr           = null !== $this->billing->cpr( $creator_id );
		[ $details, $cpr ] = self::validate( $values, $has_cpr );
		$ciphertext        = null;
		$key_id            = '';

		if ( null !== $cpr ) {
			if ( null === $this->cipher || ! $this->cipher->available() ) {
				throw new BillingDetailsInvalid( array( 'cpr' => 'unavailable' ) );
			}

			$ciphertext = $this->cipher->encrypt( $cpr->value );
			$key_id     = $this->cipher->key_id();
		}

		$now  = ( $this->clock )()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$user = get_current_user_id();

		try {
			$changed = $this->transaction->run(
				function () use ( $creator_id, $details, $ciphertext, $key_id, $has_cpr, $now, $user ): array {
					// Locks the creator's row (and refuses an unknown creator) like a payout does.
					$this->payouts->settlement_start( $creator_id, true );
					$changed = $details->changed_fields( $this->billing->find( $creator_id ) );

					if ( null !== $ciphertext ) {
						$this->billing->save_cpr( $creator_id, $ciphertext, $key_id );
						$changed[] = 'cpr';
					} elseif ( CreatorBillingDetails::BUSINESS === $details->type && $has_cpr ) {
						$this->billing->delete_cpr( $creator_id );
						$changed[] = 'cpr';
					}

					$this->billing->delete_submission( $creator_id );

					if ( array() === $changed ) {
						return array();
					}

					$this->billing->save( $creator_id, $details );
					$this->billing->save_confirmation( $creator_id, $now, $user );
					$this->audit->record( AuditAction::BillingDetailsSubmitted, $creator_id, array( 'changed_fields' => $changed ) );

					return $changed;
				}
			);
		} catch ( PayoutException $e ) {
			$this->billing->forget( $creator_id );
			throw $e;
		} catch ( Throwable $e ) {
			$this->billing->forget( $creator_id );
			throw $e;
		}

		// Only that they changed: never the values.
		if ( array() !== $changed ) {
			do_action( 'merchboard_notify', 'admin_billing_details_submitted', $creator_id, array(), '' );
		}

		return array() !== $changed;
	}

	/**
	 * Whether master data hold more than a type.
	 *
	 * @param CreatorBillingDetails $details Master data.
	 */
	private static function has( CreatorBillingDetails $details ): bool {
		return array() !== array_diff_key( $details->to_stored(), array( 'type' => true ) );
	}
}
