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
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;
use MerchBoard\Modules\Community\CommunitySettings;
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
	 * Constructor.
	 *
	 * @phpstan-param Closure(): bool $twitch
	 *
	 * @param CommunitySettings $settings The switches.
	 * @param Closure           $twitch   Whether Twitch is set up on the site.
	 * @param Logger            $logger   Technical log.
	 */
	public function __construct( private CommunitySettings $settings, Closure $twitch, private Logger $logger ) {
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
			Settings::COMMUNITY_TWITCH_GIFTING_ENABLED => array(
				__( 'Twitch gifting', 'merchboard' ),
				__( 'Viewers can buy gifts that are drawn in the creator\'s Twitch chat', 'merchboard' ),
				__( 'Off by default. Off pauses gifting: the page disappears from the creators\' dashboards, the gift button disappears and no new draws start. A draw already open ends as usual, winners can still claim their gift, and waiting gifts wait until gifting is switched on again.', 'merchboard' ),
				Settings::twitch_gifting_enabled(),
			),
			Settings::COMMUNITY_GIVEAWAYS_ENABLED      => array(
				__( 'Giveaways', 'merchboard' ),
				__( 'Creators can give their own products away to their viewers', 'merchboard' ),
				__( 'Off by default. The creator pays the base price and 50 kr. shipping: from the balance when the code is used, or in the shop. The bot draws the winner in the Twitch chat, or the creator passes a link on from their own giveaway. Off: the page disappears from the creators\' dashboards and no new giveaway starts; links and codes already given out still work.', 'merchboard' ),
				Settings::giveaways_enabled(),
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

		echo '</tbody></table>';
		submit_button( __( 'Save community settings', 'merchboard' ), 'secondary' );
		echo '</form>';
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

		try {
			$changed = $this->settings->save( $values );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving the community settings failed.', array_merge( $e->context(), array( 'reason' => $e->getMessage() ) ) );

			return self::url( 'error' );
		}

		if ( null === $changed ) {
			return self::url( 'not_authorized' );
		}

		return self::url( array() === $changed ? 'no_changes' : 'saved' );
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
