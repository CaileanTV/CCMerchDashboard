<?php
/**
 * The admin-post handler of base products and cliparts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoException;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulException;
use MerchBoard\Modules\ProductDesigner\Files\ClipartLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Saves, activates, deactivates, retires (step 30.11, with a confirmation)
 * and deletes base products, imports one from Printful (step 30.12) and
 * adds and deletes cliparts (step 30.6). Order of checks: capability
 * `merchboard_manage_designer` → nonce → the services (which check again
 * and validate every value and file). Post/Redirect/Get with an outcome.
 */
final class BaseProductActions {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'merchboard_base_product';

	/**
	 * Constructor.
	 *
	 * @param BaseProductService   $bases    Base products.
	 * @param ClipartLibrary       $cliparts Cliparts.
	 * @param Logger               $logger   Technical log.
	 * @param PrintfulCatalog|null $printful Printful import (step 30.12).
	 * @param GelatoCatalog|null   $gelato   Gelato import (step 30.13).
	 */
	public function __construct( private BaseProductService $bases, private ClipartLibrary $cliparts, private Logger $logger, private ?PrintfulCatalog $printful = null, private ?GelatoCatalog $gelato = null ) {
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ), $_FILES ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle(); the services validate and sanitize.
		exit;
	}

	/**
	 * Processes a request.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @param array<mixed> $files Uploads ($_FILES).
	 * @return string Redirect URL.
	 */
	public function handle( array $input, array $files ): string {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$text  = static fn( string $key ): string => is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';
		$id    = absint( $text( 'base_id' ) );
		$do    = sanitize_key( $text( 'do' ) );
		$nonce = $text( '_wpnonce' );

		if ( 1 !== wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			switch ( $do ) {
				case 'save':
					$fields = array();

					foreach ( array( 'name', 'description', 'base_price', 'category_id', 'colors', 'sizes' ) as $key ) {
						$fields[ $key ] = $text( $key );
					}

					return BaseProductsPage::edit_url( $this->bases->save( $id > 0 ? $id : null, $fields, self::views( $input, $files ) ), 'saved' );
				case 'activate':
				case 'deactivate':
					$this->bases->set_active( $id, 'activate' === $do );

					return BaseProductsPage::edit_url( $id, 'activate' === $do ? 'activated' : 'deactivated' );
				case 'printful_import':
					if ( null === $this->printful ) {
						return BaseProductsPage::url( PrintfulException::NOT_CONFIGURED );
					}

					return BaseProductsPage::edit_url( $this->printful->import( absint( $text( 'printful_product' ) ), $text( 'base_price' ) ), 'imported' );
				case 'gelato_import':
					if ( null === $this->gelato ) {
						return BaseProductsPage::url( GelatoException::NOT_CONFIGURED );
					}

					return BaseProductsPage::edit_url( $this->gelato->import( $text( 'gelato_template' ), $text( 'base_price' ) ), 'gelato_imported' );
				case 'retire':
					if ( '1' !== $text( 'confirm_retire' ) ) {
						return BaseProductsPage::edit_url( $id, 'retire_unconfirmed' );
					}

					$this->bases->retire( $id );

					return BaseProductsPage::edit_url( $id, 'retired' );
				case 'delete':
					$this->bases->delete( $id );

					return BaseProductsPage::url( 'deleted' );
				case 'clipart_add':
					$this->cliparts->add( $text( 'clipart_name' ), is_array( $files['clipart'] ?? null ) ? $files['clipart'] : array() );

					return BaseProductsPage::cliparts_url( 'clipart_added' );
				case 'clipart_delete':
					$this->cliparts->delete( absint( $text( 'asset_id' ) ) );

					return BaseProductsPage::cliparts_url( 'clipart_deleted' );
				default:
					return BaseProductsPage::url( 'error' );
			}
		} catch ( PrintfulException | GelatoException $e ) {
			return BaseProductsPage::url( $e->reason() );
		} catch ( DesignerException $e ) {
			if ( 'printful_import' === $do || 'gelato_import' === $do ) {
				return BaseProductsPage::url( $e->reason() );
			}

			if ( str_starts_with( $do, 'clipart_' ) ) {
				return BaseProductsPage::cliparts_url( $e->reason() );
			}

			return DesignerException::BASE_UNKNOWN === $e->reason() ? BaseProductsPage::url( $e->reason() ) : BaseProductsPage::edit_url( $id, $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Changing a base product or clipart failed.', array_merge( $e->context(), array( 'base_id' => $id ) ) );

			return BaseProductsPage::url( 'error' );
		}
	}

	/**
	 * The sides of the form with their uploads. The empty "new side" is
	 * left out.
	 *
	 * @param array<mixed> $input Request data.
	 * @param array<mixed> $files Uploads.
	 * @return list<array<string, mixed>>
	 */
	private static function views( array $input, array $files ): array {
		$views = array();

		foreach ( is_array( $input['views'] ?? null ) ? $input['views'] : array() as $index => $view ) {
			if ( ! is_array( $view ) || ! is_int( $index ) ) {
				continue;
			}

			$value = static fn( string $key ): string => is_string( $view[ $key ] ?? null ) ? $view[ $key ] : '';

			if ( '' === $value( 'key' ) && '' === trim( $value( 'name' ) ) ) {
				continue;
			}

			$uploads = array();

			foreach ( BaseView::LAYERS as $layer ) {
				$upload = $files[ 'layer_' . $index . '_' . $layer ] ?? null;

				if ( is_array( $upload ) ) {
					$uploads[ $layer ] = $upload;
				}
			}

			$views[] = array(
				'key'       => $value( 'key' ),
				'name'      => $value( 'name' ),
				'x'         => $value( 'x' ),
				'y'         => $value( 'y' ),
				'width'     => $value( 'width' ),
				'height'    => $value( 'height' ),
				'width_mm'  => $value( 'width_mm' ),
				'height_mm' => $value( 'height_mm' ),
				'dpi'       => $value( 'dpi' ),
				'remove'    => '1' === $value( 'remove' ),
				'clear'     => array_values( array_filter( is_array( $view['clear'] ?? null ) ? $view['clear'] : array(), static fn( mixed $layer ): bool => in_array( $layer, BaseView::LAYERS, true ) ) ),
				'uploads'   => $uploads,
			);
		}

		return $views;
	}
}
