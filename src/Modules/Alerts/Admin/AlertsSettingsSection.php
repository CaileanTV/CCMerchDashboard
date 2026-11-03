<?php
/**
 * "Alerts" section on the settings screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Tts\TtsSettings;
use MerchBoard\Modules\Alerts\Checkout\CheckoutUsername;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The kill switch of purchase alerts (ADR-065 pkt. 6), rendered on "Merch
 * Dashboard → Settings" via `merchboard_settings_sections` for users with
 * `merchboard_manage_alerts`. Shows whether alerts are sent now and warns
 * while MerchAlert is active, because then no alerts are sent. Read-only
 * rendering with its own form, nonce and notices; every change goes through
 * AlertsSettingsActions. Everything is escaped at output.
 */
final class AlertsSettingsSection {

	/**
	 * The settings tab of the section.
	 */
	public const TAB = 'alerts';

	/**
	 * Constructor.
	 *
	 * @param AlertsAvailability $availability Kill switch and MerchAlert guard.
	 * @param TtsSettings|null   $tts          The read-out (ADR-089); null for none.
	 */
	public function __construct( private AlertsAvailability $availability, private ?TtsSettings $tts = null ) {
	}

	/**
	 * Registers the section on the settings screen.
	 */
	public function register(): void {
		SettingsPage::add_tab( self::TAB, static fn(): string => __( 'Alerts', 'merchboard' ) );
		add_action( 'merchboard_settings_sections', array( $this, 'render' ) );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'enabled'                                => array( __( 'Purchase alerts are switched on.', 'merchboard' ), 'success' ),
			'disabled'                               => array( __( 'Purchase alerts are switched off. No new alerts are recorded.', 'merchboard' ), 'success' ),
			'no_changes'                             => array( __( 'No changes were made to the alert settings.', 'merchboard' ), 'info' ),
			AlertSettingsException::NOT_AUTHORIZED   => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'error'                                  => array( __( 'A technical error occurred. The alert settings were not changed.', 'merchboard' ), 'error' ),
			default                                  => null,
		};
	}

	/**
	 * Renders the section.
	 *
	 * @param mixed $tab The open settings tab (only its own renders).
	 */
	public function render( mixed $tab = self::TAB ): void {
		if ( self::TAB !== $tab ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code    = isset( $_GET[ AlertsSettingsActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AlertsSettingsActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice  = self::result_message( $code );
		$enabled = $this->availability->enabled();
		$blocked = $this->availability->merchalert_active();

		echo '<h2 id="merchboard-alerts">' . esc_html__( 'Alerts', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		if ( $blocked ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'MerchAlert is active on this site, so Merch Dashboard sends no alerts. Deactivate MerchAlert before you switch alerts on.', 'merchboard' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'When an order is paid (processing or completed), the creator each product is attributed to gets an alert. Mapping a product category to a creator attributes its products, so the category turns the alerts on. Only active creators get alerts.', 'merchboard' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Status:', 'merchboard' ) . '</strong> ' . esc_html( self::status( $enabled, $blocked ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsSettingsActions::SAVE_ACTION ) . '">';
		wp_nonce_field( AlertsSettingsActions::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Purchase alerts', 'merchboard' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( AlertsSettingsActions::FIELD_ENABLED ) . '" value="1"' . checked( $enabled, true, false ) . '> ';
		echo esc_html__( 'Record purchase alerts for creators', 'merchboard' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. Orders paid while alerts are off get no alerts later.', 'merchboard' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save alert settings', 'merchboard' ), 'secondary' );
		echo '</form>';
		self::render_checkout_username();
		self::render_sound_limits();
		$this->render_tts();
	}

	/**
	 * Message and type for an outcome code of the read-out; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function tts_result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                                   => array( __( 'The read-out settings were saved.', 'merchboard' ), 'success' ),
			'key_removed'                             => array( __( 'The API key was removed. Alerts are not read out.', 'merchboard' ), 'success' ),
			'no_changes'                              => array( __( 'No changes were made to the read-out settings.', 'merchboard' ), 'info' ),
			AlertSettingsException::INVALID_TTS_KEY   => array( __( 'That is not a valid Google API key. Nothing was saved.', 'merchboard' ), 'error' ),
			AlertSettingsException::INVALID_TTS_LIMIT => array( __( 'The monthly limit must be a whole number of characters. Nothing was saved.', 'merchboard' ), 'error' ),
			AlertSettingsException::NOT_AUTHORIZED    => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'error'                                   => array( __( 'A technical error occurred. The read-out settings were not changed.', 'merchboard' ), 'error' ),
			default                                   => null,
		};
	}

	/**
	 * The read-out of alerts with Google Cloud Text-to-Speech (ADR-089): the
	 * switch, the API key (never shown again), the monthly limit and the
	 * characters read out this month.
	 */
	private function render_tts(): void {
		if ( null === $this->tts ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ AlertsSettingsActions::TTS_RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AlertsSettingsActions::TTS_RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::tts_result_message( $code );
		$has    = null !== $this->tts->key();

		echo '<h3 id="merchboard-tts">' . esc_html__( 'Read-out (TTS)', 'merchboard' ) . '</h3>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p>' . esc_html__( 'Creators can have their alerts read out. The text is sent to Google Cloud Text-to-Speech, which turns it into speech with a standard voice; it contains the username the customer chose and the product. Mention this in your privacy policy before you switch it on.', 'merchboard' ) . '</p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: characters read out this month, 2: monthly limit. */
				__( 'Read out this month: %1$s of %2$s characters. At the limit, alerts are not read out until next month.', 'merchboard' ),
				number_format_i18n( $this->tts->used() ),
				number_format_i18n( $this->tts->limit() )
			)
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsSettingsActions::TTS_ACTION ) . '">';
		wp_nonce_field( AlertsSettingsActions::TTS_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Read-out', 'merchboard' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( AlertsSettingsActions::FIELD_TTS_ENABLED ) . '" value="1"' . checked( $this->tts->enabled(), true, false ) . '> ' . esc_html__( 'Let creators have their alerts read out', 'merchboard' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-tts-key">' . esc_html__( 'Google API key', 'merchboard' ) . '</label></th><td><input type="password" id="merchboard-tts-key" class="regular-text" name="' . esc_attr( AlertsSettingsActions::FIELD_TTS_KEY ) . '" value="" autocomplete="off">';
		echo '<p class="description">' . esc_html( $has ? __( 'A key is stored. Leave the field empty to keep it.', 'merchboard' ) : __( 'No key is stored.', 'merchboard' ) ) . ' ' . esc_html__( 'Create the key in Google Cloud with only the Text-to-Speech API allowed.', 'merchboard' ) . '</p>';

		if ( $has ) {
			echo '<label><input type="checkbox" name="' . esc_attr( AlertsSettingsActions::FIELD_TTS_REMOVE_KEY ) . '" value="1"> ' . esc_html__( 'Remove the stored key', 'merchboard' ) . '</label>';
		}

		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-tts-limit">' . esc_html__( 'Monthly limit', 'merchboard' ) . '</label></th><td><input type="number" id="merchboard-tts-limit" class="regular-text" min="0" max="' . esc_attr( (string) TtsSettings::MAX_LIMIT ) . '" step="1" name="' . esc_attr( AlertsSettingsActions::FIELD_TTS_LIMIT ) . '" value="' . esc_attr( (string) $this->tts->limit() ) . '"> ' . esc_html__( 'characters', 'merchboard' );
		echo '<p class="description">' . esc_html__( 'Standard voices are free up to about 4 million characters a month; above that Google charges.', 'merchboard' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save read-out settings', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Message and type for an outcome code of the sound limits; null for
	 * unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function sounds_result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                                      => array( __( 'The sound limits were saved. They apply to new uploads.', 'merchboard' ), 'success' ),
			'no_changes'                                 => array( __( 'No changes were made to the sound limits.', 'merchboard' ), 'info' ),
			AlertSettingsException::INVALID_SOUND_SIZE   => array( __( 'The largest file must be a whole number between 100 KB and 500 MB. Nothing was saved.', 'merchboard' ), 'error' ),
			AlertSettingsException::INVALID_SOUND_LENGTH => array( __( 'The longest sound must be a whole number between 1 second and 60 minutes. Nothing was saved.', 'merchboard' ), 'error' ),
			AlertSettingsException::NOT_AUTHORIZED       => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'error'                                      => array( __( 'A technical error occurred. The sound limits were not changed.', 'merchboard' ), 'error' ),
			default                                      => null,
		};
	}

	/**
	 * The limits of uploaded alert sounds (step 25.9.2): the largest file in
	 * KB or MB and the longest sound in seconds or minutes.
	 */
	private static function render_sound_limits(): void {
		// Read-only outcome code; nothing is changed by this request.
		$code    = isset( $_GET[ AlertsSettingsActions::SOUNDS_RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AlertsSettingsActions::SOUNDS_RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice  = self::sounds_result_message( $code );
		$bytes   = Settings::sound_max_bytes();
		$seconds = Settings::sound_max_seconds();
		$in_mb   = 0 === $bytes % AlertsSettingsActions::SIZE_UNITS['MB'];
		$in_min  = $seconds >= 60 && 0 === $seconds % 60;
		$site    = (int) wp_max_upload_size();

		echo '<h3 id="merchboard-sound-limits">' . esc_html__( 'Sound file limits', 'merchboard' ) . '</h3>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p>' . esc_html__( 'The largest file and the longest sound creators and the sound library can upload (MP3, WAV or OGG). The limits apply to new uploads; sounds already uploaded stay.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsSettingsActions::SOUNDS_ACTION ) . '">';
		wp_nonce_field( AlertsSettingsActions::SOUNDS_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="merchboard-sound-size">%1$s</label></th><td><input type="number" id="merchboard-sound-size" name="%2$s" value="%3$d" min="1" step="1" class="small-text" required> <select name="%4$s" aria-label="%5$s"><option value="KB"%6$s>KB</option><option value="MB"%7$s>MB</option></select><p class="description">%8$s</p></td></tr>',
			esc_html__( 'Largest file', 'merchboard' ),
			esc_attr( AlertsSettingsActions::FIELD_SOUND_SIZE ),
			(int) ( $in_mb ? $bytes / AlertsSettingsActions::SIZE_UNITS['MB'] : intdiv( $bytes, AlertsSettingsActions::SIZE_UNITS['KB'] ) ),
			esc_attr( AlertsSettingsActions::FIELD_SOUND_SIZE_UNIT ),
			esc_attr__( 'Unit of the largest file', 'merchboard' ),
			selected( $in_mb, false, false ),
			selected( $in_mb, true, false ),
			esc_html(
				$site > 0 && $site < $bytes
					/* translators: %s: the site's upload limit, e.g. "8 MB". */
					? sprintf( __( '100 KB to 500 MB; default 10 MB. This site accepts uploads of at most %s, so that is the limit now.', 'merchboard' ), size_format( $site ) )
					: __( '100 KB to 500 MB; default 10 MB.', 'merchboard' )
			)
		);
		printf(
			'<tr><th scope="row"><label for="merchboard-sound-length">%1$s</label></th><td><input type="number" id="merchboard-sound-length" name="%2$s" value="%3$d" min="1" step="1" class="small-text" required> <select name="%4$s" aria-label="%5$s"><option value="seconds"%6$s>%7$s</option><option value="minutes"%8$s>%9$s</option></select><p class="description">%10$s</p></td></tr>',
			esc_html__( 'Longest sound', 'merchboard' ),
			esc_attr( AlertsSettingsActions::FIELD_SOUND_LENGTH ),
			(int) ( $in_min ? intdiv( $seconds, 60 ) : $seconds ),
			esc_attr( AlertsSettingsActions::FIELD_SOUND_LENGTH_UNIT ),
			esc_attr__( 'Unit of the longest sound', 'merchboard' ),
			selected( $in_min, false, false ),
			esc_html__( 'seconds', 'merchboard' ),
			selected( $in_min, true, false ),
			esc_html__( 'minutes', 'merchboard' ),
			esc_html__( '1 second to 60 minutes; default 30 seconds.', 'merchboard' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save sound limits', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Message and type for an outcome code of the checkout username field;
	 * null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function checkout_result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                                        => array( __( 'The checkout username field was saved.', 'merchboard' ), 'success' ),
			'no_changes'                                   => array( __( 'No changes were made to the checkout username field.', 'merchboard' ), 'info' ),
			AlertSettingsException::INVALID_CHECKOUT_LABEL => array( __( 'The label must be one line of at most 100 characters. Nothing was saved.', 'merchboard' ), 'error' ),
			AlertSettingsException::INVALID_CHECKOUT_HELP  => array( __( 'The checkbox text must be one line of at most 300 characters. Nothing was saved.', 'merchboard' ), 'error' ),
			AlertSettingsException::NOT_AUTHORIZED         => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'error'                                        => array( __( 'A technical error occurred. The checkout username field was not changed.', 'merchboard' ), 'error' ),
			default                                        => null,
		};
	}

	/**
	 * The checkout username field (step 25.9.1): shown or not, label and
	 * the text of the checkbox that shows it.
	 */
	private static function render_checkout_username(): void {
		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ AlertsSettingsActions::CHECKOUT_RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AlertsSettingsActions::CHECKOUT_RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::checkout_result_message( $code );

		echo '<h3 id="merchboard-checkout-username">' . esc_html__( 'Username in the checkout', 'merchboard' ) . '</h3>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p>' . esc_html__( 'An optional field in the classic and the block checkout, where customers can give the username shown in the creator\'s alert and chat ($UserName). The customer first ticks a checkbox that says the name is shown publicly; then the field appears. Both are shown only while purchase alerts are switched on and the cart holds a creator\'s product. Without a username, alerts show "Anonymous".', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsSettingsActions::CHECKOUT_ACTION ) . '">';
		wp_nonce_field( AlertsSettingsActions::CHECKOUT_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Username field', 'merchboard' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( AlertsSettingsActions::FIELD_CHECKOUT_ENABLED ) . '" value="1"' . checked( Settings::checkout_username_enabled(), true, false ) . '> ';
		echo esc_html__( 'Show the field in the checkout', 'merchboard' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default.', 'merchboard' ) . '</p></td></tr>';
		printf(
			'<tr><th scope="row"><label for="merchboard-checkout-label">%1$s</label></th><td><input type="text" id="merchboard-checkout-label" name="%2$s" value="%3$s" placeholder="%4$s" maxlength="%5$d" class="regular-text"><p class="description">%6$s</p></td></tr>',
			esc_html__( 'Label', 'merchboard' ),
			esc_attr( AlertsSettingsActions::FIELD_CHECKOUT_LABEL ),
			esc_attr( Settings::checkout_username_label() ),
			esc_attr( CheckoutUsername::default_label() ),
			(int) Settings::CHECKOUT_USERNAME_LABEL_MAX_LENGTH,
			esc_html__( 'Empty for the default, e.g. "Twitch username".', 'merchboard' )
		);
		printf(
			'<tr><th scope="row"><label for="merchboard-checkout-help">%1$s</label></th><td><input type="text" id="merchboard-checkout-help" name="%2$s" value="%3$s" placeholder="%4$s" maxlength="%5$d" class="large-text"><p class="description">%6$s</p></td></tr>',
			esc_html__( 'Checkbox text', 'merchboard' ),
			esc_attr( AlertsSettingsActions::FIELD_CHECKOUT_HELP ),
			esc_attr( Settings::checkout_username_help() ),
			esc_attr( CheckoutUsername::default_help() ),
			(int) Settings::CHECKOUT_USERNAME_HELP_MAX_LENGTH,
			esc_html__( 'The text of the checkbox that shows the field. Empty for the default. Tell customers that the name is shown publicly.', 'merchboard' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save checkout field', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * The current status as a sentence.
	 *
	 * @param bool $enabled Whether alerts are switched on.
	 * @param bool $blocked Whether MerchAlert is active.
	 */
	public static function status( bool $enabled, bool $blocked ): string {
		if ( ! $enabled ) {
			return __( 'Off. No alerts are recorded.', 'merchboard' );
		}

		return $blocked
			? __( 'Switched on, but paused while MerchAlert is active.', 'merchboard' )
			: __( 'On. Alerts are recorded when orders are paid.', 'merchboard' );
	}
}
