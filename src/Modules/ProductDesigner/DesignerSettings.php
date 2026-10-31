<?php
/**
 * The product designer's switches.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The kill switches of the product designer (ADR-080), Printful (ADR-081),
 * Gelato (ADR-082) and Prodigi (ADR-084). All are off by default. Only users with
 * `merchboard_manage_designer` change them, and every changed switch is
 * audited (`settings_updated`) in the same transaction as the option.
 */
class DesignerSettings {

	/**
	 * The switches, in the order shown.
	 */
	public const SWITCHES = array( Settings::DESIGNER_ENABLED, Settings::PRINTFUL_ENABLED, Settings::GELATO_ENABLED, Settings::PRODIGI_ENABLED );

	/**
	 * Constructor.
	 *
	 * @param AuditTrail  $audit       Audit trail.
	 * @param Transaction $transaction Transaction.
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * Saves the switches.
	 *
	 * @param array<string, bool> $values Switch => on, for keys of SWITCHES (others are ignored; missing ones are off).
	 * @return list<string> The switches that changed.
	 * @throws DesignerException When the current user may not manage the designer.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function save( array $values ): array {
		self::authorize();

		$settings = Settings::all();
		$changed  = array();

		foreach ( self::SWITCHES as $key ) {
			$value = true === ( $values[ $key ] ?? false );

			if ( $settings[ $key ] !== $value ) {
				$changed[ $key ]  = array( $settings[ $key ], $value );
				$settings[ $key ] = $value;
			}
		}

		if ( array() === $changed ) {
			return array();
		}

		$this->transaction->run(
			function () use ( $settings, $changed ): void {
				foreach ( $changed as $key => [ $previous, $value ] ) {
					$this->audit->record(
						AuditAction::SettingsUpdated,
						null,
						array(
							'setting'        => $key,
							'previous_value' => $previous,
							'value'          => $value,
						)
					);
				}

				if ( ! Settings::save( $settings ) ) {
					throw new DatabaseException( 'Could not save settings.' );
				}
			}
		);

		return array_keys( $changed );
	}

	/**
	 * Requires `merchboard_manage_designer`.
	 *
	 * @throws DesignerException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			throw new DesignerException( DesignerException::NOT_AUTHORIZED );
		}
	}
}
