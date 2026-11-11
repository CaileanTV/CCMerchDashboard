<?php
/**
 * "Twitch gifting test mode" section on a creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Settings\Admin\SettingsPage;
use MerchBoard\Modules\Community\Gifting\GiftingSettingsRepository;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The test mode of a creator's Twitch gifting (ADR-107, statusnote
 * 2026-11-11), on the creator's edit page (`merchboard_creators_edit_sections`)
 * for users with `merchboard_manage_creators`. In the test mode the creator
 * counts as live, so gifts can be bought and drawn without a stream. Order of
 * checks when saving: capability → nonce bound to the action and the creator
 * id → the creator exists → GiftingSettingsRepository (writes and audits).
 * Post/Redirect/Get back to the edit page with a whitelisted outcome code.
 */
final class GiftingTestModeSection {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'merchboard_save_gifting_test_mode';

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_gifting_test_result';

	/**
	 * The checkbox.
	 */
	public const FIELD = 'merchboard_gifting_test_mode';

	/**
	 * Constructor.
	 *
	 * @param GiftingSettingsRepository $settings Gifting settings.
	 * @param CreatorRepository         $creators Creators.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct( private GiftingSettingsRepository $settings, private CreatorRepository $creators, private Logger $logger ) {
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
	 * Registers the section and the admin-post handler.
	 */
	public function register(): void {
		add_action( 'merchboard_creators_edit_sections', array( $this, 'render' ), 14 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'on'         => array( __( 'The test mode is on: the creator counts as live on Twitch.', 'merchboard' ), 'success' ),
			'off'        => array( __( 'The test mode is off: gifts need the creator to be live on Twitch again.', 'merchboard' ), 'success' ),
			'no_changes' => array( __( 'No changes were made to the test mode.', 'merchboard' ), 'info' ),
			'not_found'  => array( __( 'The creator does not exist.', 'merchboard' ), 'error' ),
			'error'      => array( __( 'A technical error occurred. The test mode was not changed.', 'merchboard' ), 'error' ),
			default      => null,
		};
	}

	/**
	 * Renders the section.
	 *
	 * @param Creator $creator Creator being edited.
	 */
	public function render( Creator $creator ): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code     = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice   = self::result_message( $code );
		$settings = $this->settings->find( $creator->id );

		echo '<hr><h2 id="merchboard-gifting-test-mode">' . esc_html__( 'Twitch gifting test mode', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		if ( $settings->test_mode ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The test mode is on. Customers can buy gifts from this creator while the creator is offline.', 'merchboard' ) . '</p></div>';
		}

		if ( ! Settings::twitch_gifting_enabled() ) {
			printf(
				'<div class="notice notice-info inline"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Twitch gifting is switched off for the shop, so no gifts can be bought or drawn, also in the test mode.', 'merchboard' ),
				esc_url( SettingsPage::url( '', 'community' ) ),
				esc_html__( 'Switch it on under Settings → Community', 'merchboard' )
			);
		}

		echo '<p class="description">' . esc_html__( 'In the test mode the creator counts as live on Twitch: the gift button shows on the creator\'s products, and gifts are drawn in the chat without a stream. Use it only for tests, e.g. on a staging site, and turn it off afterwards. The creator\'s Twitch channel must be connected and gifting turned on in the creator\'s dashboard.', 'merchboard' ) . '</p>';

		$state = array(
			$this->settings->connected( $creator->id ) ? __( 'Twitch channel connected.', 'merchboard' ) : __( 'No Twitch channel connected.', 'merchboard' ),
			$settings->open() ? __( 'Gifting is on.', 'merchboard' ) : __( 'Gifting is off or paused.', 'merchboard' ),
		);
		echo '<p>' . esc_html( implode( ' ', $state ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		wp_nonce_field( self::nonce_action( $creator->id ) );
		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label></p>',
			esc_attr( self::FIELD ),
			checked( $settings->test_mode, true, false ),
			esc_html__( 'Treat the creator as live on Twitch (test mode)', 'merchboard' )
		);
		submit_button( __( 'Save test mode', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and the fields validated in handle().
		exit;
	}

	/**
	 * Processes the form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$creator_id = absint( is_scalar( $input['creator_id'] ?? null ) ? (string) $input['creator_id'] : '' );
		$nonce      = is_scalar( $input['_wpnonce'] ?? null ) ? sanitize_text_field( (string) $input['_wpnonce'] ) : '';

		if ( 1 !== wp_verify_nonce( $nonce, self::nonce_action( $creator_id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			if ( null === $this->creators->find( $creator_id ) ) {
				$code = 'not_found';
			} else {
				$on   = '1' === ( $input[ self::FIELD ] ?? null );
				$code = $this->settings->set_test_mode( $creator_id, $on ) ? ( $on ? 'on' : 'off' ) : 'no_changes';
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving the Twitch gifting test mode failed.', array_merge( $e->context(), array( 'creator_id' => $creator_id ) ) );
			$code = 'error';
		}

		return add_query_arg( array( self::RESULT_ARG => $code ), CreatorsAdminPage::edit_url( $creator_id ) ) . '#merchboard-gifting-test-mode';
	}
}
