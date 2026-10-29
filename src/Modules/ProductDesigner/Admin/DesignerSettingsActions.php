<?php
/**
 * State-changing request of the "Product designer" settings section.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerSettings;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulToken;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Saves the product designer's switches (ADR-080–082). Order of checks:
 * capability `merchboard_manage_designer` → nonce → input (three
 * checkboxes) → DesignerSettings (checks the capability again, audits).
 * Post/Redirect/Get to the settings screen with a whitelisted outcome code.
 */
final class DesignerSettingsActions {

	/**
	 * The admin-post action and nonce action.
	 */
	public const SAVE_ACTION = 'merchboard_save_designer_settings';

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_designer_result';

	/**
	 * Constructor.
	 *
	 * @param DesignerSettings   $settings Switches.
	 * @param Logger             $logger   Technical log.
	 * @param PrintfulToken|null $token  The Printful token (step 30.12).
	 */
	public function __construct( private DesignerSettings $settings, private Logger $logger, private ?PrintfulToken $token = null ) {
	}

	/**
	 * URL of the settings screen with an outcome code, at the section.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result ): string {
		return add_query_arg( array( self::RESULT_ARG => $result ), SettingsPage::url() ) . '#merchboard-designer';
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_request' ) );
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
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$nonce = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 1 !== wp_verify_nonce( $nonce, self::SAVE_ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$values = array();

		foreach ( DesignerSettings::SWITCHES as $key ) {
			$values[ $key ] = '1' === ( is_scalar( $input[ $key ] ?? null ) ? (string) $input[ $key ] : '' );
		}

		$token  = is_string( $input['printful_token'] ?? null ) ? trim( $input['printful_token'] ) : '';
		$remove = '1' === ( is_scalar( $input['printful_token_remove'] ?? null ) ? (string) $input['printful_token_remove'] : '' );

		try {
			$changed = $this->settings->save( $values );

			if ( null !== $this->token && '' !== $token ) {
				$this->token->set( $token );
				$changed[] = 'printful_token';
			} elseif ( null !== $this->token && $remove ) {
				$this->token->clear();
				$changed[] = 'printful_token';
			}
		} catch ( DesignerException $e ) {
			return self::url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving the product designer settings failed.', $e->context() );

			return self::url( 'error' );
		}

		return self::url( array() === $changed ? 'no_changes' : 'saved' );
	}
}
