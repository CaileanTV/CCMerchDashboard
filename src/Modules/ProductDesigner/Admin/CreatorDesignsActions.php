<?php
/**
 * Form handler of the "Designer products" section on a creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerAdminProducts;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=merchboard_creator_designs` (ADR-124): `do` =
 * `rename` (with `postfix`, and `same_name` to accept a name another
 * product has) or `delete` for one `product` of one `creator_id`.
 * Capability `merchboard_manage_designer` first, then the nonce bound to
 * the creator and the product; Post/Redirect/Get to the creator's edit
 * page with a result code.
 */
final class CreatorDesignsActions {

	public const ACTION = 'merchboard_creator_designs';

	public const RESULT_ARG = 'merchboard_designs_result';

	/**
	 * Constructor.
	 *
	 * @param DesignerAdminProducts $products The creator's products.
	 * @param CreatorRepository     $creators Creators.
	 * @param Logger                $logger   Technical log.
	 */
	public function __construct( private DesignerAdminProducts $products, private CreatorRepository $creators, private Logger $logger ) {
	}

	/**
	 * Nonce action of one product of one creator.
	 *
	 * @param int $creator_id Creator.
	 * @param int $product    Designer product.
	 */
	public static function nonce_action( int $creator_id, int $product ): string {
		return self::ACTION . '_' . $creator_id . '_' . $product;
	}

	/**
	 * Hooks the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Handles the request and redirects.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle(); the name is sanitized by DesignerAdminProducts.
		exit;
	}

	/**
	 * Handles the form.
	 *
	 * @param array<mixed> $input Unslashed POST data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$text    = static fn( string $key ): string => is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';
		$id      = static fn( string $key ): int => 1 === preg_match( '/^[1-9]\d{0,18}$/', $text( $key ) ) ? (int) $text( $key ) : 0;
		$creator = $id( 'creator_id' );
		$product = $id( 'product' );

		if ( 0 === $creator || 0 === $product || 1 !== wp_verify_nonce( $text( '_wpnonce' ), self::nonce_action( $creator, $product ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			$found = $this->creators->find( $creator );

			if ( null === $found || $found->is_deleted() ) {
				return self::url( $creator, DesignerException::DESIGN_UNKNOWN );
			}

			switch ( $text( 'do' ) ) {
				case 'rename':
					return self::url( $creator, $this->products->rename( $found, $product, $text( 'postfix' ), '1' === $text( 'same_name' ) ) ? 'renamed' : 'unchanged' );
				case 'delete':
					return self::url( $creator, $this->products->delete_draft( $found, $product ) );
				default:
					return self::url( $creator, 'error' );
			}
		} catch ( DesignerException $e ) {
			return self::url( $creator, $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'An administrator\'s change of a designer product failed.',
				array_merge(
					$e->context(),
					array(
						'creator_id'        => $creator,
						'design_product_id' => $product,
					)
				)
			);

			return self::url( $creator, 'error' );
		}
	}

	/**
	 * The creator's edit page at the section, with an outcome code.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $result     Outcome code.
	 */
	private static function url( int $creator_id, string $result ): string {
		return add_query_arg( self::RESULT_ARG, $result, CreatorsAdminPage::edit_url( $creator_id ) ) . '#' . CreatorDesignsSection::ANCHOR;
	}
}
