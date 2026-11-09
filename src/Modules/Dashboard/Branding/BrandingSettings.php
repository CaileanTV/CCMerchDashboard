<?php
/**
 * The "Branding" tab of Merch Dashboard → Settings: login screen, logos and icons.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard\Branding;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-103 pkt. 4: the administrator's choices for MitCCMerch's login screen,
 * its logos and its icons, on the tab "Branding" (with the main logo of
 * ADR-042, rendered by SettingsPage). Its own form and nonce;
 * `merchboard_manage_settings` before the nonce; BrandingDesign validates;
 * the option and the audit entry (`settings_updated`, setting `branding`)
 * are written in one transaction. Everything is escaped at output.
 */
final class BrandingSettings {

	public const TAB = 'branding';

	public const ACTION = 'merchboard_branding';

	public const RESULT_ARG = 'merchboard_branding';

	/**
	 * The form's field (an array).
	 */
	public const FIELD = 'merchboard_branding';

	/**
	 * Constructor.
	 *
	 * @param AuditTrail     $audit       Audit trail.
	 * @param Transaction    $transaction Transaction.
	 * @param LogoAttachment $images      Image checks.
	 * @param BrandLogo      $logo        Images of the branding.
	 * @param DashboardRoute $route       The dashboard route (the preview link).
	 * @param Logger         $logger      Technical log.
	 */
	public function __construct(
		private AuditTrail $audit,
		private Transaction $transaction,
		private LogoAttachment $images,
		private BrandLogo $logo,
		private DashboardRoute $route,
		private Logger $logger
	) {
	}

	/**
	 * The tab's URL with an outcome code.
	 *
	 * @param string $result Outcome code ('' for none).
	 */
	public static function url( string $result = '' ): string {
		$url = SettingsPage::url( '', self::TAB );

		return '' === $result ? $url : add_query_arg( array( self::RESULT_ARG => $result ), $url );
	}

	/**
	 * Handles the form and redirects back.
	 */
	public function handle_request(): void {
		// The capability and the nonce are checked in save().
		wp_safe_redirect( self::url( $this->save( wp_unslash( $_POST ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field validated in save() (BrandingDesign).
		exit;
	}

	/**
	 * Validates and stores the form; the outcome code.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 */
	public function save( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Merch Dashboard settings.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$nonce = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 1 !== wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$stored = BrandingDesign::read();

		if ( array_key_exists( 'reset', $input ) ) {
			$design   = BrandingDesign::defaults();
			$problems = array();
		} else {
			$fields                = is_array( $input[ self::FIELD ] ?? null ) ? $input[ self::FIELD ] : array();
			[ $design, $problems ] = BrandingDesign::validate( $fields, $stored, $this->images );
		}

		if ( $design === $stored ) {
			return array() === $problems ? 'no_changes' : $problems[0];
		}

		try {
			$this->transaction->run(
				function () use ( $design ): void {
					$this->audit->record(
						AuditAction::SettingsUpdated,
						null,
						array(
							'setting' => 'branding',
							'value'   => 'changed',
						)
					);
					update_option( BrandingDesign::OPTION, $design, true );
				}
			);
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The branding could not be saved.', $e->context() );

			return 'error';
		}

		return array() === $problems ? 'saved' : $problems[0];
	}

	/**
	 * Renders the section on its tab (`merchboard_settings_sections`).
	 *
	 * @param mixed $tab The open tab.
	 */
	public function render( mixed $tab ): void {
		if ( self::TAB !== $tab || ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::message( $code );
		$design = BrandingDesign::read();
		$field  = static fn( string $name ): string => self::FIELD . '[' . $name . ']';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );

		echo '<h2>' . esc_html__( 'Logos and icons in MitCCMerch', 'merchboard' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="merchboard-logo-size">' . esc_html__( 'Logo size', 'merchboard' ) . '</label></th><td><select id="merchboard-logo-size" name="' . esc_attr( $field( 'logo_size' ) ) . '">';

		foreach ( array(
			'small'  => __( 'Small (32 px)', 'merchboard' ),
			'medium' => __( 'Medium (48 px)', 'merchboard' ),
			'large'  => __( 'Large (64 px)', 'merchboard' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $design['logo_size'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select><p class="description">' . esc_html__( 'The height of the logo at the top of MitCCMerch. On small screens it is at most 40 px.', 'merchboard' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Light plate', 'merchboard' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $field( 'logo_plate' ) ) . '" value="1"' . checked( $design['logo_plate'], true, false ) . '> ' . esc_html__( 'Show a light plate behind the logo on dark themes', 'merchboard' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Without the plate the logo must be readable on a dark background, or choose a logo for dark themes below; that one is always shown without a plate.', 'merchboard' ) . '</p></td></tr>';
		$this->image_row( $field( 'dark_logo_id' ), 'dark_logo', __( 'Logo for dark themes', 'merchboard' ), (int) $design['dark_logo_id'], LogoAttachment::KIND_LOGO, __( 'A light version of the logo, shown on the dark themes instead of the plate. Same rules as the logo.', 'merchboard' ) );
		$this->image_row( $field( 'favicon_id' ), 'favicon', __( 'Favicon', 'merchboard' ), (int) $design['favicon_id'], LogoAttachment::KIND_FAVICON, __( 'The icon in the browser tab of MitCCMerch and its login. Square PNG, JPEG or WebP, at least 48 px (512 × 512 px recommended). Without one, the site icon of WordPress is used.', 'merchboard' ) );
		$this->image_row( $field( 'app_icon_id' ), 'app_icon', __( 'App icon', 'merchboard' ), (int) $design['app_icon_id'], LogoAttachment::KIND_APP_ICON, __( 'The icon when a creator adds MitCCMerch to the home screen of a phone. Square, at least 180 px (512 × 512 px recommended). Without one, the favicon is used.', 'merchboard' ) );
		echo '<tr><th scope="row"><label for="merchboard-app-name">' . esc_html__( 'App name', 'merchboard' ) . '</label></th><td><input type="text" class="regular-text" id="merchboard-app-name" name="' . esc_attr( $field( 'app_name' ) ) . '" value="' . esc_attr( $design['app_name'] ) . '" maxlength="' . esc_attr( (string) BrandingDesign::APP_NAME_MAX ) . '" placeholder="' . esc_attr( (string) get_bloginfo( 'name' ) ) . '"><p class="description">' . esc_html__( 'The name under the app icon on the home screen. Empty: the site name.', 'merchboard' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Login screen', 'merchboard' ) . '</h2>';
		echo '<p>' . esc_html__( 'MitCCMerch\'s own login screen on WordPress\' login page: login, lost password, new password and logged out, when creators log in to their dashboard. Other logins are not changed, and WordPress keeps the whole login.', 'merchboard' ) . ' <a href="' . esc_url( wp_login_url( $this->route->url() ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See the login screen', 'merchboard' ) . '</a></p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Layout', 'merchboard' ) . '</th><td>';

		foreach ( array(
			'split'    => __( 'Split screen: image or colour beside the form', 'merchboard' ),
			'centered' => __( 'Centred card on the image or colour', 'merchboard' ),
		) as $value => $label ) {
			echo '<label style="display:block;margin-bottom:.4em"><input type="radio" name="' . esc_attr( $field( 'layout' ) ) . '" value="' . esc_attr( $value ) . '"' . checked( $design['layout'], $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}

		echo '</td></tr>';
		$this->image_row( $field( 'background_id' ), 'background', __( 'Background image', 'merchboard' ), (int) $design['background_id'], LogoAttachment::KIND_BACKGROUND, __( 'PNG, JPEG or WebP, at least 400 px high, at most 3000 px, between three times as high and three times as wide. Without one, the background colour is used.', 'merchboard' ) );
		$this->color_row( $field( 'background_color' ), 'background-color', __( 'Background colour', 'merchboard' ), $design['background_color'], __( 'Behind the image, and alone without one.', 'merchboard' ) );
		$this->color_row( $field( 'panel_text' ), 'panel-text', __( 'Text on the background', 'merchboard' ), $design['panel_text'], __( 'Must be readable on the background colour (contrast at least 4.5:1).', 'merchboard' ) );
		$this->color_row( $field( 'accent' ), 'accent', __( 'Button colour', 'merchboard' ), $design['accent'], __( 'The login button; its white text must be readable (contrast at least 4.5:1).', 'merchboard' ) );
		$this->image_row( $field( 'login_logo_id' ), 'login_logo', __( 'Login logo', 'merchboard' ), (int) $design['login_logo_id'], LogoAttachment::KIND_LOGO, __( 'A large logo above the form. Without one, the logo of MitCCMerch is used. Same rules as the logo.', 'merchboard' ) );

		foreach ( array(
			'login'        => __( 'Login', 'merchboard' ),
			'lostpassword' => __( 'Lost password', 'merchboard' ),
			'resetpass'    => __( 'New password', 'merchboard' ),
			'loggedout'    => __( 'Logged out', 'merchboard' ),
		) as $screen => $label ) {
			$texts = $design['texts'][ $screen ];
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			echo '<p><label>' . esc_html__( 'Heading', 'merchboard' ) . '<br><input type="text" class="regular-text" name="' . esc_attr( self::FIELD . '[texts][' . $screen . '][title]' ) . '" value="' . esc_attr( $texts['title'] ) . '" maxlength="' . esc_attr( (string) BrandingDesign::TITLE_MAX ) . '"></label></p>';
			echo '<p><label>' . esc_html__( 'Welcome text', 'merchboard' ) . '<br><textarea class="large-text" rows="2" name="' . esc_attr( self::FIELD . '[texts][' . $screen . '][text]' ) . '" maxlength="' . esc_attr( (string) BrandingDesign::TEXT_MAX ) . '">' . esc_textarea( $texts['text'] ) . '</textarea></label></p>';
			echo '</td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'Links', 'merchboard' ) . '</th><td>';
		echo '<p><label><input type="checkbox" name="' . esc_attr( $field( 'signup_link' ) ) . '" value="1"' . checked( $design['signup_link'], true, false ) . '> ' . esc_html__( 'Show "Become a creator" while signing up is open', 'merchboard' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="' . esc_attr( $field( 'shop_link' ) ) . '" value="1"' . checked( $design['shop_link'], true, false ) . '> ' . esc_html__( 'Show the link back to the shop', 'merchboard' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Support link (https or mailto)', 'merchboard' ) . '<br><input type="text" class="regular-text" name="' . esc_attr( $field( 'support_url' ) ) . '" value="' . esc_attr( $design['support_url'] ) . '" placeholder="https://"></label></p>';
		echo '<p><label>' . esc_html__( 'Its label', 'merchboard' ) . '<br><input type="text" class="regular-text" name="' . esc_attr( $field( 'support_label' ) ) . '" value="' . esc_attr( $design['support_label'] ) . '" maxlength="' . esc_attr( (string) BrandingDesign::LABEL_MAX ) . '" placeholder="' . esc_attr__( 'Help and support', 'merchboard' ) . '"></label></p>';
		echo '</td></tr></tbody></table>';
		echo '<p class="description">' . esc_html__( 'Empty headings and texts use the standard texts. Leave an image empty to use the default.', 'merchboard' ) . '</p>';
		echo '<p class="submit">';
		submit_button( __( 'Save branding', 'merchboard' ), 'primary', 'submit', false );
		echo ' ';
		submit_button( __( 'Reset to standard', 'merchboard' ), 'secondary', 'reset', false );
		echo '</p></form>';
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function message( string $code ): ?array {
		return match ( $code ) {
			'saved'                          => array( __( 'The branding was saved.', 'merchboard' ), 'success' ),
			'no_changes'                     => array( __( 'No changes were made to the branding.', 'merchboard' ), 'info' ),
			BrandingDesign::BAD_IMAGE        => array( __( 'An image was not changed: it must be a PNG, JPEG or WebP on this site within the sizes of its kind. The other changes were saved.', 'merchboard' ), 'error' ),
			BrandingDesign::BAD_COLOR        => array( __( 'A colour was not changed: use a colour like #4f46e5. The other changes were saved.', 'merchboard' ), 'error' ),
			BrandingDesign::LOW_CONTRAST     => array( __( 'The colours were not changed: the text on the background and the white text on the button must have a contrast of at least 4.5:1. The other changes were saved.', 'merchboard' ), 'error' ),
			BrandingDesign::BAD_LINK         => array( __( 'The support link was not changed: use an https address or mailto:. The other changes were saved.', 'merchboard' ), 'error' ),
			'error'                          => array( __( 'A technical error occurred. The branding was not changed.', 'merchboard' ), 'error' ),
			default                          => null,
		};
	}

	/**
	 * A row with an image from the Media Library (the picker needs
	 * JavaScript; "Remove" works without).
	 *
	 * @param string $name        Field name.
	 * @param string $id          Element id suffix.
	 * @param string $label       Label.
	 * @param int    $value       Attachment id.
	 * @param string $kind        LogoAttachment::KIND_*.
	 * @param string $description Help text.
	 */
	private function image_row( string $name, string $id, string $label, int $value, string $kind, string $description ): void {
		$image = $this->logo->image( $value, $kind );
		$input = 'merchboard-image-' . $id;

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<input type="hidden" id="' . esc_attr( $input ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		echo '<p><img id="' . esc_attr( $input ) . '-preview" src="' . esc_url( null === $image ? '' : $image['url'] ) . '" alt="" style="max-height:64px;max-width:240px' . ( null === $image ? ';display:none' : '' ) . '"></p>';

		if ( $value > 0 && null === $image ) {
			echo '<p class="description" style="color:#b32d2e">' . esc_html__( 'The chosen image can no longer be used, so the default is shown.', 'merchboard' ) . '</p>';
		}

		echo '<p class="hide-if-no-js"><button type="button" class="button" data-merchboard-image="' . esc_attr( $input ) . '">' . esc_html__( 'Choose image', 'merchboard' ) . '</button> ';
		echo '<button type="button" class="button-link" data-merchboard-image-clear="' . esc_attr( $input ) . '">' . esc_html__( 'Remove', 'merchboard' ) . '</button></p>';
		echo '<p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	/**
	 * A row with a colour field.
	 *
	 * @param string $name        Field name.
	 * @param string $id          Element id suffix.
	 * @param string $label       Label.
	 * @param string $value       #rrggbb.
	 * @param string $description Help text.
	 */
	private function color_row( string $name, string $id, string $label, string $value, string $description ): void {
		echo '<tr><th scope="row"><label for="merchboard-color-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="color" id="merchboard-color-' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}
}
