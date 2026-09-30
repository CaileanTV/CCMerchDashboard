<?php
/**
 * Validates and saves the counted order statuses.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use Closure;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce side of the setting `counted_order_statuses` (ADR-038,
 * status note "ROADMAP trin 6", S1-S14). Core Settings only checks the
 * format; this service decides what may be chosen:
 *
 * - Authorization: `merchboard_manage_settings`, independent of the UI (S2).
 * - Choices: every status `wc_get_order_statuses()` registers now (also
 *   from other plugins) under a `wc-` key whose slug is valid
 *   (Settings::valid_status_slug(): `sanitize_key()` characters, at most 17
 *   so it fits both order stores), without the prefix and without the fixed
 *   deny list (S3, S14, status note 2026-09-30), plus statuses that already
 *   count but are no longer registered: those can be kept or removed, never
 *   added (S7, S10).
 * - Registered statuses that cannot be chosen (no `wc-` prefix, invalid
 *   slug, too long) are reported by unsupported(), never silently dropped.
 * - The submitted list is deduplicated and sorted, so the same statuses in
 *   another order are no change; an empty list is rejected before anything
 *   is written (S4). An unchanged list writes nothing, not even an audit
 *   record.
 * - A change writes a `settings_updated` audit record first and the option
 *   last in one transaction, as SettingsService does: if the option cannot be
 *   saved, the audit record is rolled back (S5, ADR-024).
 *
 * A change applies from the next synchronization of an order on; nothing is
 * recalculated (S6).
 */
final class CountedOrderStatusSettings {

	/**
	 * Unsupported registration: the key has no `wc-` prefix.
	 */
	public const UNSUPPORTED_NO_PREFIX = 'no_prefix';

	/**
	 * Unsupported registration: the slug is empty, starts with `wc-` again or
	 * has characters outside `a-z`, `0-9`, `_` and `-` (e.g. capitals).
	 */
	public const UNSUPPORTED_FORMAT = 'invalid_format';

	/**
	 * Unsupported registration: the slug is longer than
	 * Settings::STATUS_SLUG_MAX_LENGTH.
	 */
	public const UNSUPPORTED_TOO_LONG = 'too_long';

	/**
	 * Source of WooCommerce' registered order statuses (`wc_get_order_statuses()`
	 * format); anything but an array means WooCommerce is unavailable.
	 *
	 * @var Closure(): mixed
	 */
	private Closure $source;

	/**
	 * Constructor.
	 *
	 * @param AuditTrail              $audit       Audit trail.
	 * @param Transaction             $transaction Database transaction.
	 * @param CountedOrderStatuses    $counted     The statuses that count now.
	 * @param (Closure(): mixed)|null $source      Registered statuses; defaults to `wc_get_order_statuses()`.
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction, private CountedOrderStatuses $counted, ?Closure $source = null ) {
		$this->source = $source ?? static fn(): ?array => function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : null;
	}

	/**
	 * The statuses an administrator can choose from: registered, allowed
	 * statuses in WooCommerce' order, then counted statuses that are no longer
	 * registered (in stored order).
	 *
	 * @return list<array{status: string, label: string, registered: bool, selected: bool}>
	 * @throws CountedOrderStatusException When WooCommerce' order statuses are unavailable.
	 */
	public function choices(): array {
		$registered = $this->registered();
		$counted    = $this->counted->statuses();
		$choices    = array();

		foreach ( $registered as $status => $label ) {
			$status = (string) $status; // A slug of digits only is an int key.

			if ( ! CountedOrderStatuses::denied( $status ) ) {
				$choices[] = array(
					'status'     => $status,
					'label'      => $label,
					'registered' => true,
					'selected'   => in_array( $status, $counted, true ),
				);
			}
		}

		foreach ( $this->unregistered( $registered, $counted ) as $status ) {
			$choices[] = array(
				'status'     => $status,
				'label'      => $status,
				'registered' => false,
				'selected'   => true,
			);
		}

		return $choices;
	}

	/**
	 * Registered statuses that cannot be chosen, in WooCommerce' order, with
	 * the reason (one of the UNSUPPORTED_* constants). `status` is the key as
	 * registered.
	 *
	 * @return list<array{status: string, label: string, reason: string}>
	 * @throws CountedOrderStatusException When WooCommerce' order statuses are unavailable.
	 */
	public function unsupported(): array {
		return $this->registrations()['unsupported'];
	}

	/**
	 * Replaces the counted statuses.
	 *
	 * @param string[] $statuses Chosen statuses, with or without `wc-`.
	 * @return bool Whether the setting changed.
	 * @throws CountedOrderStatusException When not authorized, WooCommerce is unavailable, or the list is empty or contains a status that cannot be chosen.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function save( array $statuses ): bool {
		$this->authorize();

		$value    = $this->normalize( $statuses );
		$previous = Settings::counted_order_statuses();
		$compare  = $previous;
		sort( $compare, SORT_STRING );

		if ( $compare === $value ) {
			return false;
		}

		$settings = Settings::all();

		$settings[ Settings::COUNTED_ORDER_STATUSES ] = $value;

		$this->transaction->run(
			function () use ( $settings, $previous, $value ): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting'        => Settings::COUNTED_ORDER_STATUSES,
						'previous_value' => $previous,
						'value'          => $value,
					)
				);

				if ( ! Settings::save( $settings ) ) {
					throw new DatabaseException( 'Could not save settings.' );
				}
			}
		);

		return true;
	}

	/**
	 * Requires the settings capability (independent of the UI).
	 *
	 * @throws CountedOrderStatusException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			throw new CountedOrderStatusException( CountedOrderStatusException::NOT_AUTHORIZED );
		}
	}

	/**
	 * The chosen statuses as they are stored: without `wc-`, without
	 * duplicates, sorted; every one must be a valid choice.
	 *
	 * @param string[] $statuses Chosen statuses.
	 * @return list<string>
	 * @throws CountedOrderStatusException When WooCommerce is unavailable, a status cannot be chosen or none is left.
	 */
	private function normalize( array $statuses ): array {
		$registered = $this->registered();
		$allowed    = array_merge(
			array_values( array_filter( array_map( 'strval', array_keys( $registered ) ), static fn( string $status ): bool => ! CountedOrderStatuses::denied( $status ) ) ),
			$this->unregistered( $registered, $this->counted->statuses() )
		);
		$value      = array();

		foreach ( $statuses as $status ) {
			$status = self::slug( $status );

			if ( ! in_array( $status, $allowed, true ) ) {
				throw new CountedOrderStatusException( CountedOrderStatusException::NOT_ALLOWED );
			}

			$value[] = $status;
		}

		$value = array_values( array_unique( $value ) );

		if ( array() === $value ) {
			throw new CountedOrderStatusException( CountedOrderStatusException::EMPTY_LIST );
		}

		sort( $value, SORT_STRING );

		return $value;
	}

	/**
	 * Counted statuses that WooCommerce no longer registers (never denied
	 * ones: CountedOrderStatuses already drops those).
	 *
	 * @param array<string, string> $registered Registered statuses.
	 * @param string[]              $counted    Counted statuses.
	 * @return list<string>
	 */
	private function unregistered( array $registered, array $counted ): array {
		return array_values( array_filter( $counted, static fn( string $status ): bool => ! isset( $registered[ $status ] ) ) );
	}

	/**
	 * The order statuses WooCommerce registers now that can be chosen, keyed
	 * by slug without `wc-`, in WooCommerce' order.
	 *
	 * @return array<string, string> Slug => label.
	 * @throws CountedOrderStatusException When WooCommerce is unavailable.
	 */
	private function registered(): array {
		return $this->registrations()['supported'];
	}

	/**
	 * WooCommerce' registered statuses, split into supported ones (a `wc-`
	 * key with a valid slug) and unsupported ones with the reason.
	 *
	 * @return array{supported: array<string, string>, unsupported: list<array{status: string, label: string, reason: string}>}
	 * @throws CountedOrderStatusException When WooCommerce is unavailable.
	 */
	private function registrations(): array {
		$statuses = ( $this->source )();

		if ( ! is_array( $statuses ) ) {
			throw new CountedOrderStatusException( CountedOrderStatusException::UNAVAILABLE );
		}

		$supported   = array();
		$unsupported = array();

		foreach ( $statuses as $key => $label ) {
			$key    = (string) $key;
			$status = self::slug( $key );
			$label  = is_string( $label ) && '' !== $label ? $label : $key;
			$reason = self::unsupported_reason( $key, $status );

			if ( null === $reason ) {
				$supported[ $status ] = $label;
				continue;
			}

			$unsupported[] = array(
				'status' => $key,
				'label'  => $label,
				'reason' => $reason,
			);
		}

		return array(
			'supported'   => $supported,
			'unsupported' => $unsupported,
		);
	}

	/**
	 * Why a registered key cannot be chosen; null when it can.
	 *
	 * @param string $key    Registered key.
	 * @param string $status Key without `wc-`.
	 */
	private static function unsupported_reason( string $key, string $status ): ?string {
		if ( ! str_starts_with( $key, 'wc-' ) ) {
			return self::UNSUPPORTED_NO_PREFIX;
		}

		if ( Settings::valid_status_slug( $status ) ) {
			return null;
		}

		// Only the length is wrong when the first STATUS_SLUG_MAX_LENGTH characters are valid.
		return Settings::valid_status_slug( substr( $status, 0, Settings::STATUS_SLUG_MAX_LENGTH ) ) && 1 === preg_match( '/^[a-z0-9_-]+$/', $status )
			? self::UNSUPPORTED_TOO_LONG
			: self::UNSUPPORTED_FORMAT;
	}

	/**
	 * A status slug without the `wc-` prefix.
	 *
	 * @param string $status Status, with or without `wc-`.
	 */
	private static function slug( string $status ): string {
		return str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}
}
