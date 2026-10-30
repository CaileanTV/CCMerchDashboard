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
use MerchBoard\Modules\ProductDesigner\Providers\GelatoAccount;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulToken;
use MerchBoard\Modules\ProductDesigner\ThreadPacks;
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
	 * The admin-post action and nonce action of an embroidery pack.
	 */
	public const PACK_ACTION = 'merchboard_save_thread_pack';

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
	 * @param GelatoAccount|null $gelato The Gelato key and store (step 30.13).
	 * @param ThreadPacks|null   $packs  The embroidery packs.
	 */
	public function __construct( private DesignerSettings $settings, private Logger $logger, private ?PrintfulToken $token = null, private ?GelatoAccount $gelato = null, private ?ThreadPacks $packs = null ) {
	}

	/**
	 * URL of the settings screen with an outcome code, at the section.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result ): string {
		return add_query_arg( array( self::RESULT_ARG => $result ), SettingsPage::url( '', DesignerSettingsSection::TAB ) ) . '#merchboard-designer';
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_request' ) );
		add_action( 'admin_post_' . self::PACK_ACTION, array( $this, 'handle_pack_request' ) );
	}

	/**
	 * WordPress entry point of an embroidery pack.
	 */
	public function handle_pack_request(): void {
		// The nonce is verified in save_pack() before any input is used.
		wp_safe_redirect( $this->save_pack( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and the fields validated in save_pack() and ThreadPacks.
		exit;
	}

	/**
	 * Saves or deletes an embroidery pack.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save_pack( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$text = static fn( string $key ): string => is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';

		if ( 1 !== wp_verify_nonce( $text( '_wpnonce' ), self::PACK_ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		if ( null === $this->packs ) {
			return self::url( 'error' );
		}

		$rows = array();

		foreach ( is_array( $input['threads'] ?? null ) ? $input['threads'] : array() as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = array(
					'code'   => is_string( $row['code'] ?? null ) ? $row['code'] : '',
					'name'   => is_string( $row['name'] ?? null ) ? $row['name'] : '',
					'hex'    => is_string( $row['hex'] ?? null ) ? $row['hex'] : '',
					'remove' => '1' === ( $row['remove'] ?? '' ),
				);
			}
		}

		try {
			if ( 'delete' === $text( 'do' ) ) {
				$this->packs->delete( $text( 'pack_id' ) );

				return self::url( 'pack_deleted' );
			}

			return self::url( $this->packs->save( $text( 'pack_id' ), $text( 'pack_name' ), $text( 'pack_max' ), $rows ) ? 'saved' : 'no_changes' );
		} catch ( DesignerException $e ) {
			return self::url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving an embroidery pack failed.', $e->context() );

			return self::url( 'error' );
		}
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
		$key    = is_string( $input['gelato_key'] ?? null ) ? $input['gelato_key'] : '';
		$store  = is_string( $input['gelato_store'] ?? null ) ? $input['gelato_store'] : '';
		$drop   = '1' === ( is_scalar( $input['gelato_key_remove'] ?? null ) ? (string) $input['gelato_key_remove'] : '' );

		// The keys first: a refused key changes nothing, not even the switches (review 30.15).
		try {
			$changed = array();

			if ( null !== $this->token && '' !== $token ) {
				$this->token->set( $token );
				$changed[] = 'printful_token';
			} elseif ( null !== $this->token && $remove ) {
				$this->token->clear();
				$changed[] = 'printful_token';
			}

			if ( null !== $this->gelato && $this->gelato->set( $key, $store ) ) {
				$changed[] = 'gelato';
			}

			if ( null !== $this->gelato && $drop && '' === trim( $key ) ) {
				$this->gelato->clear();
				$changed[] = 'gelato_key';
			}

			$changed = array_merge( $changed, $this->settings->save( $values ) );
		} catch ( DesignerException $e ) {
			return self::url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving the product designer settings failed.', $e->context() );

			return self::url( 'error' );
		}

		return self::url( array() === $changed ? 'no_changes' : 'saved' );
	}
}
