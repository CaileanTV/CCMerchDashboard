<?php
/**
 * "Alert sound limits" section on a creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\Sounds\CreatorSoundLimits;
use MerchBoard\Modules\Alerts\Sounds\SoundFiles;
use MerchBoard\Modules\Creators\Creator;

defined( 'ABSPATH' ) || exit;

/**
 * A creator's own limits of uploaded alert sounds (step 25.9.2), on the
 * creator's edit page (`merchboard_creators_edit_sections`) for users with
 * `merchboard_manage_alerts`. Empty fields show the site-wide limits. Its own
 * form, nonce and notices; every change goes through
 * CreatorSoundLimitsActions. Everything is escaped at output.
 */
final class CreatorSoundLimitsSection {

	/**
	 * Constructor.
	 *
	 * @param CreatorSoundLimits $limits The creators' own limits.
	 */
	public function __construct( private CreatorSoundLimits $limits ) {
	}

	/**
	 * Registers the section on the creator's edit page.
	 */
	public function register(): void {
		add_action( 'merchboard_creators_edit_sections', array( $this, 'render' ), 12 );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                                      => array( __( 'The creator\'s sound limits were saved. They apply to the creator\'s new uploads.', 'merchboard' ), 'success' ),
			'no_changes'                                 => array( __( 'No changes were made to the creator\'s sound limits.', 'merchboard' ), 'info' ),
			AlertSettingsException::INVALID_SOUND_SIZE   => array( __( 'The largest file must be empty or a whole number between 100 KB and 500 MB. Nothing was saved.', 'merchboard' ), 'error' ),
			AlertSettingsException::INVALID_SOUND_LENGTH => array( __( 'The longest sound must be empty or a whole number between 1 second and 60 minutes. Nothing was saved.', 'merchboard' ), 'error' ),
			AlertSettingsException::CREATOR_NOT_FOUND    => array( __( 'The creator does not exist.', 'merchboard' ), 'error' ),
			AlertSettingsException::NOT_AUTHORIZED       => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'error'                                      => array( __( 'A technical error occurred. The creator\'s sound limits were not changed.', 'merchboard' ), 'error' ),
			default                                      => null,
		};
	}

	/**
	 * Renders the section.
	 *
	 * @param Creator $creator Creator being edited.
	 */
	public function render( Creator $creator ): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ CreatorSoundLimitsActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ CreatorSoundLimitsActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		echo '<hr><h2 id="merchboard-creator-sound-limits">' . esc_html__( 'Alert sound limits', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: the site-wide largest file, e.g. "10 MB", 2: the site-wide longest sound in seconds. */
				__( 'Other limits of the sounds this creator uploads for their alerts, e.g. for special needs. Empty fields use the site-wide limits from Settings → Alerts (now %1$s and %2$d seconds). The limits apply to new uploads; sounds already uploaded stay. The sound library always uses the site-wide limits.', 'merchboard' ),
				size_format( SoundFiles::max_bytes() ),
				SoundFiles::max_seconds()
			)
		) . '</p>';

		$own     = $this->limits->own( $creator->id );
		$bytes   = $own['max_bytes'];
		$seconds = $own['max_seconds'];
		$in_mb   = null === $bytes || 0 === $bytes % AlertsSettingsActions::SIZE_UNITS['MB'];
		$in_min  = null !== $seconds && $seconds >= 60 && 0 === $seconds % 60;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CreatorSoundLimitsActions::ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		wp_nonce_field( CreatorSoundLimitsActions::nonce_action( $creator->id ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="merchboard-creator-sound-size">%1$s</label></th><td><input type="number" id="merchboard-creator-sound-size" name="%2$s" value="%3$s" min="1" step="1" class="small-text"> <select name="%4$s" aria-label="%5$s"><option value="KB"%6$s>KB</option><option value="MB"%7$s>MB</option></select><p class="description">%8$s</p></td></tr>',
			esc_html__( 'Largest file', 'merchboard' ),
			esc_attr( AlertsSettingsActions::FIELD_SOUND_SIZE ),
			esc_attr( null === $bytes ? '' : (string) ( $in_mb ? intdiv( $bytes, AlertsSettingsActions::SIZE_UNITS['MB'] ) : intdiv( $bytes, AlertsSettingsActions::SIZE_UNITS['KB'] ) ) ),
			esc_attr( AlertsSettingsActions::FIELD_SOUND_SIZE_UNIT ),
			esc_attr__( 'Unit of the largest file', 'merchboard' ),
			selected( $in_mb, false, false ),
			selected( $in_mb, true, false ),
			esc_html__( 'Empty for the site-wide limit; else 100 KB to 500 MB. The site\'s upload limit still applies.', 'merchboard' )
		);
		printf(
			'<tr><th scope="row"><label for="merchboard-creator-sound-length">%1$s</label></th><td><input type="number" id="merchboard-creator-sound-length" name="%2$s" value="%3$s" min="1" step="1" class="small-text"> <select name="%4$s" aria-label="%5$s"><option value="seconds"%6$s>%7$s</option><option value="minutes"%8$s>%9$s</option></select><p class="description">%10$s</p></td></tr>',
			esc_html__( 'Longest sound', 'merchboard' ),
			esc_attr( AlertsSettingsActions::FIELD_SOUND_LENGTH ),
			esc_attr( null === $seconds ? '' : (string) ( $in_min ? intdiv( $seconds, 60 ) : $seconds ) ),
			esc_attr( AlertsSettingsActions::FIELD_SOUND_LENGTH_UNIT ),
			esc_attr__( 'Unit of the longest sound', 'merchboard' ),
			selected( $in_min, false, false ),
			esc_html__( 'seconds', 'merchboard' ),
			selected( $in_min, true, false ),
			esc_html__( 'minutes', 'merchboard' ),
			esc_html__( 'Empty for the site-wide limit; else 1 second to 60 minutes.', 'merchboard' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save sound limits', 'merchboard' ), 'secondary' );
		echo '</form>';
	}
}
