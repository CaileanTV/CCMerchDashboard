<?php
/**
 * The creator's own master data, confirmed before the first payout.
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
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\Payouts\CreatorRowLock;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The creator submits master data for settlement documents; an
 * administrator confirms them or asks for corrections (ADR-104).
 *
 * - `billing_details` stays the confirmed master data that documents copy
 *   (ADR-062 K09); the submission waits in `billing_submission`.
 * - Status: missing, submitted, changes_requested or confirmed (possibly
 *   with a newer submission waiting). Master data an administrator entered
 *   count as confirmed.
 * - The first payout (no settlement document yet) can be requested once
 *   master data are submitted and completed only once they are confirmed;
 *   later payouts are never held back.
 *
 * Values are never audited, logged or sent in notifications: only the
 * names of changed fields. Confirming takes the creator's payout lock and row
 * lock like a completion (ADR-055), so the two never cross.
 */
final class BillingDetailsReview {

	/**
	 * Nothing confirmed and nothing submitted.
	 */
	public const MISSING = 'missing';

	/**
	 * Submitted, nothing confirmed yet: waits for an administrator.
	 */
	public const SUBMITTED = 'submitted';

	/**
	 * A submission waits for an administrator (submission status).
	 */
	public const PENDING = 'pending';

	/**
	 * An administrator asked for corrections.
	 */
	public const CHANGES_REQUESTED = 'changes_requested';

	/**
	 * Confirmed master data exist.
	 */
	public const CONFIRMED = 'confirmed';

	/**
	 * Fields the creator must fill in.
	 */
	public const REQUIRED = array( 'legal_name', 'address_1', 'postcode', 'city', 'country' );

	/**
	 * Longest note when asking for corrections.
	 */
	public const MAX_NOTE_LENGTH = 500;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param CreatorBillingDetailsStore $billing     Master data and submissions.
	 * @param PayoutRepository           $payouts     Locks, settlement documents and pending payouts.
	 * @param AuditTrail                 $audit       Audit trail.
	 * @param Transaction                $transaction Transactions.
	 * @param Closure                    $clock       Current time (`Closure(): DateTimeImmutable`).
	 */
	public function __construct(
		private CreatorBillingDetailsStore $billing,
		private PayoutRepository $payouts,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Closure $clock
	) {
	}

	/**
	 * Validated master data from the creator's form: every field is read,
	 * the required ones must be filled in, texts are single lines within
	 * their length, and the type is a registered business (then the CVR/SE
	 * number is required: eight digits, spaces removed) or a private person
	 * (then no CVR/SE number is kept; the CPR number goes with each payout).
	 *
	 * @param array<mixed> $values Field => submitted value.
	 * @throws BillingDetailsInvalid With a code per invalid field.
	 */
	public static function validate( array $values ): CreatorBillingDetails {
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

		if ( ! in_array( $type, array( CreatorBillingDetails::BUSINESS, CreatorBillingDetails::PRIVATE ), true ) ) {
			$errors['type'] = 'required';
		} elseif ( CreatorBillingDetails::PRIVATE === $type ) {
			unset( $errors['cvr'] );
			$clean['cvr'] = '';
		} elseif ( '' === ( $clean['cvr'] ?? '' ) && ! isset( $errors['cvr'] ) ) {
			$errors['cvr'] = 'required';
		}

		if ( array() !== $errors ) {
			throw new BillingDetailsInvalid( $errors );
		}

		$clean['type'] = (string) $type;

		return CreatorBillingDetails::from_input( $clean );
	}

	/**
	 * The creator's master data and where they stand.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When a read fails.
	 */
	public function state( int $creator_id ): BillingDetailsState {
		$confirmed  = $this->billing->find( $creator_id );
		$submission = $this->billing->submission( $creator_id );
		$has        = array() !== $confirmed->to_stored();
		$first      = ! $this->payouts->has_settlement_documents( $creator_id );
		$status     = match ( true ) {
			null !== $submission && self::CHANGES_REQUESTED === $submission['status'] => self::CHANGES_REQUESTED,
			$has => self::CONFIRMED,
			null !== $submission => self::SUBMITTED,
			default => self::MISSING,
		};

		return new BillingDetailsState(
			$status,
			$has ? $confirmed : null,
			$has ? ( $this->billing->confirmation( $creator_id )['at'] ?? null ) : null,
			$submission,
			null === $submission ? '' : self::version( $submission ),
			$first && ! $has,
			$first && ! $has && $this->payouts->count_pending( $creator_id ) > 0,
			self::is_private( $confirmed, $submission )
		);
	}

	/**
	 * Whether the creator is paid as a private person and gives the CPR
	 * number with each payout request (ADR-104): the waiting submission's
	 * type when it has one, else the confirmed one.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When a read fails.
	 */
	public function cpr_required( int $creator_id ): bool {
		return self::is_private( $this->billing->find( $creator_id ), $this->billing->submission( $creator_id ) );
	}

	/**
	 * Whether the creator may request a payout: always after the first
	 * settlement document, else once master data are submitted or confirmed.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When a read fails.
	 */
	public function allows_request( int $creator_id ): bool {
		return $this->payouts->has_settlement_documents( $creator_id ) || array() !== $this->billing->find( $creator_id )->to_stored() || null !== $this->billing->submission( $creator_id );
	}

	/**
	 * Whether a pending payout of the creator may be completed: always after
	 * the first settlement document, else only with confirmed master data.
	 * Called inside the completion's transaction under the creator's locks.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When a read fails.
	 */
	public function allows_completion( int $creator_id ): bool {
		return $this->payouts->has_settlement_documents( $creator_id ) || array() !== $this->billing->find( $creator_id )->to_stored();
	}

	/**
	 * The creator submits master data (the caller resolved the creator with
	 * CreatorContext). Unchanged data change nothing; data equal to the
	 * confirmed ones withdraw a waiting submission.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $values     Field => submitted value.
	 * @return bool Whether a submission now waits for an administrator.
	 * @throws BillingDetailsInvalid With a code per invalid field.
	 * @throws PayoutException When the creator does not exist.
	 * @throws DatabaseException When a read or write fails.
	 */
	public function submit( int $creator_id, array $values ): bool {
		$details = self::validate( $values );
		$now     = $this->now();

		$submitted = $this->transaction->run(
			function () use ( $creator_id, $details, $now ): bool {
				// Locks the creator's row (and refuses an unknown creator), as a confirmation does.
				$this->payouts->settlement_start( $creator_id, true );
				$changed = $details->changed_fields( $this->billing->find( $creator_id ) );
				$waiting = $this->billing->submission( $creator_id );

				if ( array() === $changed ) {
					if ( null !== $waiting ) {
						$this->billing->delete_submission( $creator_id );
					}

					return false;
				}

				if ( null !== $waiting && self::PENDING === $waiting['status'] && array() === $details->changed_fields( $waiting['values'] ) ) {
					return false;
				}

				$this->billing->save_submission( $creator_id, $details, self::PENDING, $now, '' );
				$this->audit->record( AuditAction::BillingDetailsSubmitted, $creator_id, array( 'changed_fields' => $changed ) );

				return true;
			}
		);

		// Only that something waits: never the values.
		if ( $submitted ) {
			do_action( 'merchboard_notify', 'admin_billing_details_submitted', $creator_id, array(), '' );
		}

		return $submitted;
	}

	/**
	 * An administrator confirms the submission that was shown ($version):
	 * it becomes the creator's master data. Under the creator's payout lock
	 * and row lock, like a completion.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $version    The shown submission's version (BillingDetailsState::$version).
	 * @throws PayoutException When not allowed, nothing waits, it changed meanwhile, or another payout action runs (`busy`).
	 * @throws DatabaseException When a read or write fails.
	 */
	public function confirm( int $creator_id, string $version ): void {
		self::authorize();
		$now  = $this->now();
		$user = get_current_user_id();

		$this->payouts->with_creator_lock(
			$creator_id,
			fn() => $this->transaction->run(
				function () use ( $creator_id, $version, $now, $user ): void {
					CreatorRowLock::acquire( $this->payouts, $creator_id );
					$submission = $this->decidable( $creator_id, $version );
					$changed    = $submission['values']->changed_fields( $this->billing->find( $creator_id ) );

					$this->billing->save( $creator_id, $submission['values'] );
					$this->billing->save_confirmation( $creator_id, $now, $user );
					$this->billing->delete_submission( $creator_id );
					$this->audit->record( AuditAction::BillingDetailsConfirmed, $creator_id, array( 'changed_fields' => $changed ) );
				}
			)
		);

		do_action( 'merchboard_notify', 'billing_details_confirmed', $creator_id, array(), '' );
	}

	/**
	 * An administrator asks the creator to correct the submission that was
	 * shown, with a note the creator sees.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $version    The shown submission's version.
	 * @param string $note       What to correct (1–500 characters).
	 * @throws PayoutException When not allowed, the note is invalid, nothing waits or it changed meanwhile.
	 */
	public function request_changes( int $creator_id, string $version, string $note ): void {
		self::authorize();
		$note = trim( $note );

		if ( '' === $note || mb_strlen( $note ) > self::MAX_NOTE_LENGTH || 1 !== preg_match( '//u', $note ) || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note ) ) {
			throw new PayoutException( PayoutException::INVALID_NOTE );
		}

		$this->transaction->run(
			function () use ( $creator_id, $version, $note ): void {
				$this->payouts->settlement_start( $creator_id, true );
				$submission = $this->decidable( $creator_id, $version );

				$this->billing->save_submission( $creator_id, $submission['values'], self::CHANGES_REQUESTED, $submission['submitted_at'], $note );
				$this->audit->record( AuditAction::BillingDetailsChangesRequested, $creator_id, array( 'decision_note' => $note ) );
			}
		);

		do_action( 'merchboard_notify', 'billing_details_changes_requested', $creator_id, array( 'note' => $note ), '' );
	}

	/**
	 * The creators whose submission waits for an administrator (not those
	 * asked for corrections), in ascending id order.
	 *
	 * @return list<int>
	 * @throws DatabaseException When a read fails.
	 */
	public function waiting(): array {
		return array_values(
			array_filter(
				$this->billing->submitted_creator_ids(),
				fn( int $creator_id ): bool => self::PENDING === ( $this->billing->submission( $creator_id )['status'] ?? null )
			)
		);
	}

	/**
	 * A stable token of a submission: it changes when the creator submits
	 * again, so a decision never applies to values that were not shown.
	 *
	 * @param array{values: CreatorBillingDetails, status: string, submitted_at: string, note: string} $submission Submission.
	 */
	public static function version( array $submission ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $submission['values']->values(), $submission['submitted_at'] ) ) );
	}

	/**
	 * Whether the type in force is a private person.
	 *
	 * @param CreatorBillingDetails                                                                         $confirmed  Confirmed master data.
	 * @param array{values: CreatorBillingDetails, status: string, submitted_at: string, note: string}|null $submission Waiting submission.
	 */
	private static function is_private( CreatorBillingDetails $confirmed, ?array $submission ): bool {
		$type = null !== $submission && '' !== $submission['values']->type ? $submission['values']->type : $confirmed->type;

		return CreatorBillingDetails::PRIVATE === $type;
	}

	/**
	 * The waiting submission the administrator saw.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $version    Shown version.
	 * @return array{values: CreatorBillingDetails, status: string, submitted_at: string, note: string}
	 * @throws PayoutException When none waits or it changed.
	 */
	private function decidable( int $creator_id, string $version ): array {
		$submission = $this->billing->submission( $creator_id ) ?? throw new PayoutException( PayoutException::BILLING_SUBMISSION_MISSING );

		if ( ! hash_equals( self::version( $submission ), $version ) ) {
			throw new PayoutException( PayoutException::BILLING_SUBMISSION_STALE );
		}

		return $submission;
	}

	/**
	 * The current time as stored (UTC).
	 */
	private function now(): string {
		return ( $this->clock )()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Only administrators who manage payouts decide.
	 *
	 * @throws PayoutException When not allowed.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			throw new PayoutException( PayoutException::NOT_AUTHORIZED );
		}
	}
}
