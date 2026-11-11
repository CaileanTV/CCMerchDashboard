<?php
/**
 * The global switches of the community features.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The shop-wide switches of the Twitch subscriber discount and Twitch gifting
 * (ADR-108). Both are off by default. Only users with
 * `merchboard_manage_settings` change them, and every changed switch is
 * audited (`settings_updated`) in the same transaction as the option.
 */
class CommunitySettings {

	/**
	 * The switches, in the order shown.
	 */
	public const SWITCHES = array( Settings::COMMUNITY_TWITCH_SUB_DISCOUNT_ENABLED, Settings::COMMUNITY_TWITCH_GIFTING_ENABLED, Settings::COMMUNITY_GIVEAWAYS_ENABLED );

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
	 * @return list<string>|null The switches that changed; null when the current user may not change them.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function save( array $values ): ?array {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return null;
		}

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
}
