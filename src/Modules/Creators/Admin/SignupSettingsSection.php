<?php
/**
 * Settings → Agreement: whether creators may sign themselves up.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Agreement\Agreement;
use MerchBoard\Modules\Creators\Signup\SignupPage;
use MerchBoard\Modules\Creators\Signup\SignupSettings;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Above the agreement on its tab (ADR-091, ADR-093): the switch (off by
 * default; it can only be switched on once a version of the agreement is
 * published), the parent category of the creators' categories, and the
 * address of the sign-up page. Saving goes through admin-post with a nonce;
 * requires `merchboard_manage_creators`.
 */
final class SignupSettingsSection {

	public const ACTION = 'merchboard_signup_settings';

	/**
	 * Constructor.
	 *
	 * @param Agreement  $agreement The agreement.
	 * @param SignupPage $page      The sign-up page.
	 * @param Logger     $logger    Technical log.
	 */
	public function __construct( private Agreement $agreement, private SignupPage $page, private Logger $logger ) {
	}

	/**
	 * Registers the section and the form handler (before the agreement).
	 */
	public function register(): void {
		add_action( 'merchboard_settings_sections', array( $this, 'render' ), 5 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Handles the form and redirects back.
	 */
	public function handle_request(): void {
		check_admin_referer( self::ACTION );
		$enabled     = isset( $_POST['enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$parent_term = isset( $_POST['parent_category'] ) ? absint( wp_unslash( $_POST['parent_category'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		wp_safe_redirect( AgreementSettingsSection::url( $this->handle( $enabled, $parent_term ) ) );
		exit;
	}

	/**
	 * Saves the settings.
	 *
	 * @param bool $enabled     Whether sign-up is switched on.
	 * @param int  $parent_term Parent `product_cat` (0 for none).
	 * @return string Outcome code.
	 */
	public function handle( bool $enabled, int $parent_term ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return 'not_authorized';
		}

		if ( $parent_term > 0 && ! ( get_term( $parent_term, 'product_cat' ) instanceof WP_Term ) ) {
			return 'signup_parent_unknown';
		}

		try {
			if ( $enabled && null === $this->agreement->current() ) {
				return 'signup_needs_agreement';
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The agreement could not be read.', $e->context() );

			return 'error';
		}

		SignupSettings::save( $enabled, $parent_term );

		return 'signup_saved';
	}

	/**
	 * Renders the section.
	 *
	 * @param mixed $tab The open settings tab (only the agreement's renders it).
	 */
	public function render( mixed $tab = AgreementSettingsSection::TAB ): void {
		if ( AgreementSettingsSection::TAB !== $tab || ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code    = isset( $_GET[ AgreementSettingsSection::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AgreementSettingsSection::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$message = match ( $code ) {
			'signup_saved'           => array( __( 'The sign-up settings were saved.', 'merchboard' ), 'success' ),
			'signup_needs_agreement' => array( __( 'Not switched on: publish a version of the agreement first.', 'merchboard' ), 'error' ),
			'signup_parent_unknown'  => array( __( 'Not saved: the parent category does not exist.', 'merchboard' ), 'error' ),
			default                  => null,
		};

		echo '<h2 id="merchboard-signup">' . esc_html__( 'Creators who sign up themselves', 'merchboard' ) . '</h2>';

		if ( null !== $message ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $message[1] ), esc_html( $message[0] ) );
		}

		echo '<p>' . esc_html__( 'When this is on, anyone can sign up as a creator on the page below. They confirm their e-mail, accept the agreement and can prepare their shop, but nothing of theirs is shown in the shop until you approve their request to go live. Then their own product category is created, at the top level unless you choose a parent category below.', 'merchboard' ) . '</p>';
		echo '<p>' . esc_html__( 'Sign-up page:', 'merchboard' ) . ' <code>' . esc_html( $this->page->url() ) . '</code></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Sign-up', 'merchboard' ) . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( SignupSettings::enabled(), true, false ) . '> ' . esc_html__( 'Creators can sign themselves up', 'merchboard' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-signup-parent">' . esc_html__( 'Parent category for creators', 'merchboard' ) . '</label></th><td>';

		if ( taxonomy_exists( 'product_cat' ) ) {
			wp_dropdown_categories(
				array(
					'taxonomy'          => 'product_cat',
					'name'              => 'parent_category',
					'id'                => 'merchboard-signup-parent',
					'hide_empty'        => false,
					'hierarchical'      => true,
					'show_option_none'  => __( '— None (top level) —', 'merchboard' ),
					'option_none_value' => '0',
					'selected'          => SignupSettings::parent_category(),
				)
			);
			echo '<p class="description">' . esc_html__( 'Optional. Without one, a creator\'s product category is created at the top level when you approve that they go live; with one, under it.', 'merchboard' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Product categories need WooCommerce.', 'merchboard' ) . '</p>';
		}

		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save sign-up settings', 'merchboard' ), 'secondary' );
		echo '</form>';
	}
}
