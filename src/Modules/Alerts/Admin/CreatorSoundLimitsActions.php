<?php
/**
 * State-changing request of a creator's own alert sound limits.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\Sounds\CreatorSoundLimits;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * Saves a creator's own limits of uploaded alert sounds from their edit
 * page (step 25.9.2). Order of checks: capability
 * `merchboard_manage_alerts` → nonce bound to the action and the creator id
 * → input (an empty field = the site-wide limit; else a whole number and a
 * unit, as in Settings → Alerts) → CreatorSoundLimits (checks the
 * capability again, the creator and the ranges; writes and audits).
 * Post/Redirect/Get back to the creator's edit page with a whitelisted
 * outcome code.
 */
final class CreatorSoundLimitsActions {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'merchboard_save_creator_sound_limits';

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_creator_sounds_result';

	/**
	 * Constructor.
	 *
	 * @param CreatorSoundLimits $limits The creators' own limits.
	 * @param Logger             $logger Technical log.
	 */
	public function __construct( private CreatorSoundLimits $limits, private Logger $logger ) {
	}

	/**
	 * Nonce action for one creator.
	 *
	 * @param int $creator_id Creator id.
	 */
	public static function nonce_action( int $creator_id ): string {
		return self::ACTION . '_' . $creator_id;
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and the fields validated in handle() and CreatorSoundLimits.
		exit;
	}

	/**
	 * Processes the form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$field      = static fn( string $key ): string => is_scalar( $input[ $key ] ?? null ) ? trim( sanitize_text_field( (string) $input[ $key ] ) ) : '';
		$creator_id = absint( $field( 'creator_id' ) );

		if ( 1 !== wp_verify_nonce( $field( '_wpnonce' ), self::nonce_action( $creator_id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$size   = $field( AlertsSettingsActions::FIELD_SOUND_SIZE );
		$length = $field( AlertsSettingsActions::FIELD_SOUND_LENGTH );

		try {
			$changed = $this->limits->set(
				$creator_id,
				'' === $size ? null : AlertsSettingsActions::amount( $size, AlertsSettingsActions::SIZE_UNITS[ $field( AlertsSettingsActions::FIELD_SOUND_SIZE_UNIT ) ] ?? 0 ),
				'' === $length ? null : AlertsSettingsActions::amount( $length, AlertsSettingsActions::LENGTH_UNITS[ $field( AlertsSettingsActions::FIELD_SOUND_LENGTH_UNIT ) ] ?? 0 )
			);
			$code    = $changed ? 'saved' : 'no_changes';
		} catch ( AlertSettingsException $e ) {
			$code = $e->reason();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving a creator\'s sound limits failed.', array_merge( $e->context(), array( 'creator_id' => $creator_id ) ) );
			$code = 'error';
		}

		return add_query_arg( array( self::RESULT_ARG => $code ), CreatorsAdminPage::edit_url( $creator_id ) ) . '#merchboard-creator-sound-limits';
	}
}
