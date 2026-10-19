<?php
/**
 * State-changing requests of the "Alerts" admin page (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Fonts\AlertFontsException;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundException;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * The font library (ADR-067): save or remove the Google Fonts API key,
 * fetch the catalogue, approve and remove fonts. The sound library (step
 * 6.3): upload and remove sounds. Order of checks: capability
 * `merchboard_manage_alerts` → nonce bound to the action (and the family or
 * the sound) → input → AlertFonts or AlertSoundLibrary (checks the
 * capability again, audits). Post/Redirect/Get to the page's tab with a
 * whitelisted outcome code.
 */
final class AlertsAdminActions {

	public const KEY_ACTION     = 'merchboard_alert_fonts_key';
	public const SYNC_ACTION    = 'merchboard_alert_fonts_sync';
	public const APPROVE_ACTION = 'merchboard_alert_font_approve';
	public const REMOVE_ACTION  = 'merchboard_alert_font_remove';

	public const SOUND_ADD_ACTION    = 'merchboard_alert_sound_library_add';
	public const SOUND_REMOVE_ACTION = 'merchboard_alert_sound_library_remove';

	/**
	 * Every handled action.
	 */
	private const ACTIONS = array( self::KEY_ACTION, self::SYNC_ACTION, self::APPROVE_ACTION, self::REMOVE_ACTION, self::SOUND_ADD_ACTION, self::SOUND_REMOVE_ACTION );

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_alerts_admin_result';

	/**
	 * Constructor.
	 *
	 * @param AlertFonts             $fonts   Font library.
	 * @param Logger                 $logger  Technical log.
	 * @param AlertSoundLibrary|null $library Sound library; null for none.
	 */
	public function __construct( private AlertFonts $fonts, private Logger $logger, private ?AlertSoundLibrary $library = null ) {
	}

	/**
	 * Registers the admin-post handlers.
	 */
	public function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'handle_request' ) );
		}
	}

	/**
	 * The nonce action of a request.
	 *
	 * @param string $action  Admin-post action.
	 * @param string $subject Font family or sound id ('' when the action has none).
	 */
	public static function nonce_action( string $action, string $subject = '' ): string {
		return '' === $subject ? $action : $action . ':' . $subject;
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in process() before any input is used.
		wp_safe_redirect( $this->process( wp_unslash( $_POST ), $_FILES ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified and every field validated in process(); the upload is validated by SoundFiles.
		exit;
	}

	/**
	 * Processes a request.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @param array<mixed> $files Uploaded files ($_FILES).
	 * @return string Redirect URL.
	 */
	public function process( array $input, array $files = array() ): string {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$action  = self::scalar( $input, 'action' );
		$family  = self::scalar( $input, 'family' );
		$sound   = self::scalar( $input, 'sound' );
		$nonce   = self::scalar( $input, '_wpnonce' );
		$subject = match ( $action ) {
			self::APPROVE_ACTION, self::REMOVE_ACTION => $family,
			self::SOUND_REMOVE_ACTION => $sound,
			default => '',
		};
		$tab = in_array( $action, array( self::SOUND_ADD_ACTION, self::SOUND_REMOVE_ACTION ), true ) ? 'sounds' : 'fonts';

		if ( ! in_array( $action, self::ACTIONS, true ) || 1 !== wp_verify_nonce( $nonce, self::nonce_action( $action, $subject ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			return AlertsAdminPage::url( $tab, 'sounds' === $tab ? $this->run_sounds( $action, $files, $sound ) : $this->run( $action, $input, $family ) );
		} catch ( AlertFontsException $e ) {
			return AlertsAdminPage::url( $tab, $e->reason() );
		} catch ( AlertSoundException $e ) {
			return AlertsAdminPage::url( $tab, $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'Changing the alert font or sound library failed.',
				array_merge(
					$e->context(),
					array( 'reason' => $e->getMessage() )
				)
			);

			return AlertsAdminPage::url( $tab, 'error' );
		}
	}

	/**
	 * Runs a sound library action; returns the outcome code.
	 *
	 * @param string       $action Admin-post action.
	 * @param array<mixed> $files  Uploaded files.
	 * @param string       $sound  Sound id (remove).
	 * @throws AlertSoundException When the library rejects the change.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function run_sounds( string $action, array $files, string $sound ): string {
		if ( null === $this->library ) {
			return 'error';
		}

		if ( self::SOUND_ADD_ACTION === $action ) {
			$this->library->add( is_array( $files['file'] ?? null ) ? $files['file'] : array() );

			return 'sound_added';
		}

		return $this->library->remove( absint( $sound ) ) ? 'sound_removed' : 'no_changes';
	}

	/**
	 * Runs an action; returns the outcome code.
	 *
	 * @param string       $action Admin-post action.
	 * @param array<mixed> $input  Request data.
	 * @param string       $family Font family.
	 * @throws AlertFontsException When the library rejects the change.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function run( string $action, array $input, string $family ): string {
		switch ( $action ) {
			case self::KEY_ACTION:
				$remove = '1' === self::scalar( $input, 'remove' );
				$this->fonts->set_key( $remove ? '' : self::scalar( $input, 'key' ) );

				return $remove ? 'key_removed' : 'key_saved';
			case self::SYNC_ACTION:
				$this->fonts->sync();

				return 'synced';
			case self::APPROVE_ACTION:
				return $this->fonts->approve( $family ) ? 'approved' : 'no_changes';
			default:
				return $this->fonts->remove( $family ) ? 'removed' : 'no_changes';
		}
	}

	/**
	 * A scalar input field as a trimmed string.
	 *
	 * @param array<mixed> $input Request data.
	 * @param string       $key   Field.
	 */
	private static function scalar( array $input, string $key ): string {
		return is_scalar( $input[ $key ] ?? null ) ? trim( sanitize_text_field( (string) $input[ $key ] ) ) : '';
	}
}
