<?php
/**
 * "Community" tab on the settings screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Admin;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\CommunityLink;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;
use MerchBoard\Modules\Community\CommunitySettings;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionApp;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionLegal;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The shop-wide switches of the community features (ADR-108) on "Merch
 * Dashboard → Settings → Community" for users with
 * `merchboard_manage_settings`: one checkbox per feature, whether Twitch is
 * set up, and the form's handler (admin-post, nonce, Post/Redirect/Get).
 * Everything is escaped at output.
 */
final class CommunitySettingsSection {

	/**
	 * The settings tab.
	 */
	public const TAB = 'community';

	/**
	 * The admin-post action and the nonce action.
	 */
	public const SAVE_ACTION = 'merchboard_save_community_settings';

	/**
	 * Query argument with the outcome code.
	 */
	public const RESULT_ARG = 'merchboard_community_result';

	/**
	 * Whether Twitch is set up on the site (the app and the bot).
	 *
	 * @var Closure(): bool
	 */
	private Closure $twitch;

	/**
	 * The form fields of the Twitch Extension's setup (ADR-112 pkt. 5).
	 */
	public const EXTENSION_CLIENT_ID = 'twitch_extension_client_id';

	/**
	 * The new secret ('' keeps the stored one).
	 */
	public const EXTENSION_SECRET = 'twitch_extension_secret';

	/**
	 * The install link.
	 */
	public const EXTENSION_INSTALL_URL = 'twitch_extension_install_url';

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): bool $twitch
	 *
	 * @param CommunitySettings       $settings  The switches.
	 * @param Closure                 $twitch    Whether Twitch is set up on the site.
	 * @param Logger                  $logger    Technical log.
	 * @param TwitchExtensionApp|null $extension The Twitch Extension's setup.
	 */
	public function __construct( private CommunitySettings $settings, Closure $twitch, private Logger $logger, private ?TwitchExtensionApp $extension = null ) {
		$this->twitch = $twitch;
	}

	/**
	 * Registers the tab, the section and the form's handler.
	 */
	public function register(): void {
		SettingsPage::add_tab( self::TAB, static fn(): string => __( 'Community', 'merchboard' ) );
		add_action( 'merchboard_settings_sections', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_request' ) );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'          => array( __( 'The community settings were saved.', 'merchboard' ), 'success' ),
			'no_changes'     => array( __( 'No changes were made to the community settings.', 'merchboard' ), 'info' ),
			'not_authorized' => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'error'          => array( __( 'A technical error occurred. The community settings were not changed.', 'merchboard' ), 'error' ),
			'invalid_extension' => array( __( 'The Twitch Extension\'s Client ID, secret or install link is invalid. Nothing was changed.', 'merchboard' ), 'error' ),
			'invalid_link'   => array( __( 'The link name must be 2–40 lowercase letters, digits, - or _, start with a letter and not be a name WordPress or the shop uses. Nothing was changed.', 'merchboard' ), 'error' ),
			default          => null,
		};
	}

	/**
	 * Renders the section.
	 *
	 * @param mixed $tab The open settings tab (only its own renders).
	 */
	public function render( mixed $tab = self::TAB ): void {
		if ( self::TAB !== $tab || ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );
		$fields = array(
			Settings::COMMUNITY_TWITCH_SUB_DISCOUNT_ENABLED => array(
				__( 'Twitch subscriber discount', 'merchboard' ),
				__( 'Creators can give their Twitch subscribers a discount', 'merchboard' ),
				__( 'Off by default. Customers log in with Twitch in the cart and the checkout, and the discount is taken from the creator\'s profit. Off: the page disappears from the creators\' dashboards and no cart gets the discount; the creators\' campaigns are kept.', 'merchboard' ),
				Settings::twitch_sub_discount_enabled(),
			),
			Settings::COMMUNITY_TWITCH_GIFTING_ENABLED   => array(
				__( 'Twitch gifting', 'merchboard' ),
				__( 'Viewers can buy gifts that are drawn in the creator\'s Twitch chat', 'merchboard' ),
				__( 'Off by default. Off pauses gifting: the page disappears from the creators\' dashboards, the gift button disappears and no new draws start. A draw already open ends as usual, winners can still claim their gift, and waiting gifts wait until gifting is switched on again.', 'merchboard' ),
				Settings::twitch_gifting_enabled(),
			),
			Settings::COMMUNITY_GIVEAWAYS_ENABLED        => array(
				__( 'Giveaways', 'merchboard' ),
				__( 'Creators can give their own products away to their viewers', 'merchboard' ),
				__( 'Off by default. The creator pays the base price and 50 kr. shipping: from the balance when the code is used, or in the shop. The bot draws the winner in the Twitch chat, or the creator passes a link on from their own giveaway. Off: the page disappears from the creators\' dashboards and no new giveaway starts; links and codes already given out still work.', 'merchboard' ),
				Settings::giveaways_enabled(),
			),
			Settings::COMMUNITY_TWITCH_EXTENSION_ENABLED => array(
				__( 'Twitch Extension', 'merchboard' ),
				__( 'Creators can show their products in the shop\'s Twitch Extension panel', 'merchboard' ),
				__( 'Off by default. The panel shows the creator\'s products with image and name only: Twitch does not allow prices, links to the shop or buying in an extension. Creators link to the shop from an ordinary Twitch panel. Off: the page disappears from the creators\' dashboards and the panel shows nothing.', 'merchboard' ),
				Settings::twitch_extension_enabled(),
			),
			Settings::COMMUNITY_MERCH_AD_ENABLED         => array(
				__( 'Merch ad', 'merchboard' ),
				__( 'Creators can show an ad overlay with their products in the stream', 'merchboard' ),
				__( 'Off by default. Creators design an ad with their products (images without background) and an optional pill, choose how often and how long it shows and add it to OBS as a browser source. Off: the page disappears from the creators\' dashboards and the overlay shows nothing.', 'merchboard' ),
				Settings::merch_ad_enabled(),
			),
		);

		echo '<h2 id="merchboard-community">' . esc_html__( 'Community Features', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p>' . esc_html__( 'Choose which community features creators can use. Each creator then switches a feature on for themselves and connects their Twitch channel on the feature\'s page in the dashboard.', 'merchboard' ) . '</p>';

		if ( ! ( $this->twitch )() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Twitch is not set up yet: the Twitch features and giveaways in the chat need the Twitch app and the bot account.', 'merchboard' ),
				esc_url( AlertsAdminPage::url( 'chatbot' ) ),
				esc_html__( 'Set up Twitch under Alerts → Chatbot', 'merchboard' )
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $fields as $key => [ $title, $label, $help, $on ] ) {
			echo '<tr><th scope="row">' . esc_html( $title ) . '</th><td>';
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( $on, true, false ) . '> ' . esc_html( $label ) . '</label>';
			echo '<p class="description">' . esc_html( $help ) . '</p>';
			echo '</td></tr>';
		}

		$link = CommunityLink::name();
		echo '<tr><th scope="row"><label for="merchboard-community-link">' . esc_html__( 'Link name', 'merchboard' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text code" id="merchboard-community-link" name="' . esc_attr( Settings::COMMUNITY_LINK_PARAM ) . '" value="' . esc_attr( $link ) . '" pattern="[a-z][a-z0-9_\-]{1,39}" maxlength="40" required>';
		/* translators: %s: an example link. */
		echo '<p class="description">' . esc_html( sprintf( __( 'The name in the public links of the community features, such as the gift and giveaway links, and later links of the same kind: %s', 'merchboard' ), home_url( '/' ) . '?' . $link . '=…' ) ) . '</p>';
		echo '<p class="description">' . esc_html__( '2–40 lowercase letters, digits, - or _, starting with a letter. Links already passed on keep working after a change: the earlier names are still read.', 'merchboard' ) . '</p>';
		echo '</td></tr>';

		if ( null !== $this->extension ) {
			$this->render_extension( $this->extension );
		}

		echo '</tbody></table>';
		submit_button( __( 'Save community settings', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * The Twitch Extension's setup: Client ID, secret and install link.
	 *
	 * @param TwitchExtensionApp $extension The setup.
	 */
	private function render_extension( TwitchExtensionApp $extension ): void {
		echo '<tr><th scope="row"><label for="merchboard-twitch-extension-client">' . esc_html__( 'Twitch Extension: Client ID', 'merchboard' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text code" id="merchboard-twitch-extension-client" name="' . esc_attr( self::EXTENSION_CLIENT_ID ) . '" value="' . esc_attr( $extension->client_id() ) . '" pattern="[a-z0-9]{20,40}" autocomplete="off">';
		echo '<p class="description">' . esc_html__( 'From the extension in the Twitch developer console (dev.twitch.tv/console/extensions). The shop owns one extension that all creators install.', 'merchboard' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="merchboard-twitch-extension-secret">' . esc_html__( 'Twitch Extension: secret', 'merchboard' ) . '</label></th><td>';

		if ( TwitchExtensionApp::secret_in_config() ) {
			echo '<p>' . esc_html__( 'Set in wp-config.php (MERCHBOARD_TWITCH_EXTENSION_SECRET).', 'merchboard' ) . '</p>';
		} else {
			echo '<input type="password" class="regular-text code" id="merchboard-twitch-extension-secret" name="' . esc_attr( self::EXTENSION_SECRET ) . '" value="" autocomplete="new-password">';
			echo '<p class="description">' . esc_html( null !== $extension->key() ? __( 'A secret is stored (encrypted). Leave empty to keep it.', 'merchboard' ) : __( 'The extension\'s secret under "Extension Client Configuration" in the developer console. It is stored encrypted and never shown again.', 'merchboard' ) ) . '</p>';
		}

		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="merchboard-twitch-extension-url">' . esc_html__( 'Twitch Extension: install link', 'merchboard' ) . '</label></th><td>';
		echo '<input type="url" class="regular-text code" id="merchboard-twitch-extension-url" name="' . esc_attr( self::EXTENSION_INSTALL_URL ) . '" value="' . esc_attr( $extension->install_url() ) . '" placeholder="https://dashboard.twitch.tv/extensions/…">';
		echo '<p class="description">' . esc_html__( 'The extension\'s page on Twitch, where creators install it. Shown in the creators\' guide.', 'merchboard' ) . '</p>';
		/* translators: %s: the REST URL of the panel. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Allowlist this site in the developer console (URL fetching and image domains). The panel reads: %s', 'merchboard' ), rest_url( 'merchboard/v1/twitch-extension/panel' ) ) ) . '</p>';
		/* translators: 1: the terms URL, 2: the privacy policy URL. */
		echo '<p class="description">' . esc_html( sprintf( __( 'For the developer console: EULA/terms of service URL %1$s and privacy policy URL %2$s (served by this plugin; the company details come from Settings → Company details).', 'merchboard' ), TwitchExtensionLegal::url( 'terms' ), TwitchExtensionLegal::url( 'privacy' ) ) ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_save_request(): void {
		// The nonce is verified in save() before any input is used.
		wp_safe_redirect( $this->save( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and the fields read as flags in save().
		exit;
	}

	/**
	 * Processes the form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$nonce = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 1 !== wp_verify_nonce( $nonce, self::SAVE_ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$values = array();

		foreach ( CommunitySettings::SWITCHES as $key ) {
			$values[ $key ] = '1' === ( is_scalar( $input[ $key ] ?? null ) ? (string) $input[ $key ] : '' );
		}

		$link = is_scalar( $input[ Settings::COMMUNITY_LINK_PARAM ] ?? null ) ? strtolower( trim( (string) $input[ Settings::COMMUNITY_LINK_PARAM ] ) ) : '';

		if ( ! CommunityLink::valid( $link ) ) {
			return self::url( 'invalid_link' );
		}

		$values[ Settings::COMMUNITY_LINK_PARAM ] = $link;

		try {
			$extension = null === $this->extension || ! array_key_exists( self::EXTENSION_CLIENT_ID, $input ) ? array() : $this->extension->save(
				is_scalar( $input[ self::EXTENSION_CLIENT_ID ] ?? null ) ? (string) $input[ self::EXTENSION_CLIENT_ID ] : '',
				is_scalar( $input[ self::EXTENSION_SECRET ] ?? null ) ? (string) $input[ self::EXTENSION_SECRET ] : '',
				is_scalar( $input[ self::EXTENSION_INSTALL_URL ] ?? null ) ? (string) $input[ self::EXTENSION_INSTALL_URL ] : ''
			);

			if ( null === $extension ) {
				return self::url( 'invalid_extension' );
			}

			$changed = $this->settings->save( $values );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving the community settings failed.', array_merge( $e->context(), array( 'reason' => $e->getMessage() ) ) );

			return self::url( 'error' );
		}

		if ( null === $changed ) {
			return self::url( 'not_authorized' );
		}

		return self::url( array() === $changed && array() === $extension ? 'no_changes' : 'saved' );
	}

	/**
	 * The tab's URL with an outcome code.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result ): string {
		return add_query_arg( array( self::RESULT_ARG => $result ), SettingsPage::url( '', self::TAB ) ) . '#merchboard-community';
	}
}
