<?php
/**
 * "Designer products" section on a creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerAdminProducts;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerNames;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's designer products and drafts (ADR-124), on the creator's
 * edit page (`merchboard_creators_edit_sections`) for users with
 * `merchboard_manage_designer`: name, base product and where each is, a
 * form per product to rename its addition, and "Delete draft" or "Discard
 * change" where it has a draft. Every change goes through
 * CreatorDesignsActions. Everything is escaped at output.
 */
final class CreatorDesignsSection {

	public const ANCHOR = 'merchboard-creator-designs';

	/**
	 * Constructor.
	 *
	 * @param DesignerAdminProducts $products The creator's products.
	 * @param Logger                $logger   Technical log.
	 */
	public function __construct( private DesignerAdminProducts $products, private Logger $logger ) {
	}

	/**
	 * Registers the section on the creator's edit page.
	 */
	public function register(): void {
		add_action( 'merchboard_creators_edit_sections', array( $this, 'render' ), 6 );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'renamed'                                => array( __( 'The product was renamed, in the shop too if it is there.', 'merchboard' ), 'success' ),
			'unchanged'                              => array( __( 'The product already has that name. Nothing was changed.', 'merchboard' ), 'info' ),
			'deleted'                                => array( __( 'The draft was deleted for good.', 'merchboard' ), 'success' ),
			'discarded'                              => array( __( 'The change was discarded. The shop keeps the product as it is.', 'merchboard' ), 'success' ),
			DesignerException::DESIGN_TITLE          => array( __( 'The name may be at most 120 characters. Nothing was changed.', 'merchboard' ), 'error' ),
			DesignerException::DESIGN_NAME_TAKEN     => array( __( 'Another product already has that name. Nothing was changed; tick "Allow the same name as another product" to use it anyway.', 'merchboard' ), 'error' ),
			DesignerException::DESIGN_BUSY           => array( __( 'The product is being updated in the shop right now. Try again in a minute.', 'merchboard' ), 'error' ),
			DesignerException::DESIGN_NOT_DRAFT      => array( __( 'The product has no draft that can be changed here (it may have been sent for approval). Nothing was changed.', 'merchboard' ), 'error' ),
			DesignerException::NOT_LIVE              => array( __( 'The product in the shop could not be found. Nothing was changed.', 'merchboard' ), 'error' ),
			DesignerException::DESIGN_UNKNOWN        => array( __( 'The product does not exist or belongs to another creator.', 'merchboard' ), 'error' ),
			DesignerException::BASE_UNKNOWN          => array( __( 'The product\'s base product no longer exists. Nothing was changed.', 'merchboard' ), 'error' ),
			DesignerException::NOT_AUTHORIZED        => array( __( 'You are not allowed to manage the product designer.', 'merchboard' ), 'error' ),
			'error'                                  => array( __( 'A technical error occurred. Nothing was changed; try again or check the log.', 'merchboard' ), 'error' ),
			default                                  => null,
		};
	}

	/**
	 * Where a product is, for people.
	 *
	 * @param string $state DesignerAdminProducts' state.
	 */
	public static function state_label( string $state ): string {
		return match ( $state ) {
			'in_shop'         => __( 'In the shop', 'merchboard' ),
			'in_shop_change'  => __( 'In the shop, with a change in draft', 'merchboard' ),
			'in_shop_waiting' => __( 'In the shop, with a change waiting for approval', 'merchboard' ),
			'waiting'         => __( 'Waiting for approval', 'merchboard' ),
			'approved'        => __( 'On the way to the shop', 'merchboard' ),
			'retired'         => __( 'Base product retired', 'merchboard' ),
			default           => __( 'Draft', 'merchboard' ),
		};
	}

	/**
	 * Renders the section.
	 *
	 * @param Creator $creator Creator being edited.
	 */
	public function render( Creator $creator ): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ CreatorDesignsActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ CreatorDesignsActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		echo '<hr><h2 id="' . esc_attr( self::ANCHOR ) . '">' . esc_html__( 'Designer products', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		try {
			$products = $this->products->for_creator( $creator );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The creator\'s designer products could not be read.', array_merge( $e->context(), array( 'creator_id' => $creator->id ) ) );
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The creator\'s designer products could not be read. Details are in the log.', 'merchboard' ) . '</p></div>';

			return;
		}

		if ( array() === $products ) {
			echo '<p>' . esc_html__( 'The creator has no designer products.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<p class="description">' . esc_html__( 'The creator\'s products and drafts from the product designer. You change only the part of the name after the creator\'s and the base product\'s name; a product in the shop gets the new name there at once. A draft the creator has open keeps the new name unless they change the name themselves. Designs waiting for approval are decided under Approvals.', 'merchboard' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Name', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Base product', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Draft', 'merchboard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $products as $product ) {
			echo '<tr><td>';
			$this->rename_form( $creator, $product['id'], $product['prefix'], $product['postfix'] );

			if ( null !== $product['wc_product_id'] && current_user_can( 'edit_post', $product['wc_product_id'] ) ) {
				$edit = get_edit_post_link( $product['wc_product_id'], 'url' );

				if ( is_string( $edit ) ) {
					echo '<p><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Open the product in WooCommerce', 'merchboard' ) . '</a></p>';
				}
			}

			echo '</td><td>' . esc_html( $product['base'] ) . '</td>';
			echo '<td>' . esc_html( self::state_label( $product['state'] ) ) . '</td><td>';

			if ( $product['deletable'] ) {
				$this->delete_form( $creator, $product['id'], __( 'Delete draft', 'merchboard' ), __( 'Delete this draft for good? The creator cannot get it back.', 'merchboard' ) );
			} elseif ( $product['discardable'] ) {
				$this->delete_form( $creator, $product['id'], __( 'Discard change', 'merchboard' ), __( 'Discard the creator\'s change of this product? The shop keeps the product as it is.', 'merchboard' ) );
			} else {
				echo '&mdash;';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The form that renames a product's addition.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @param string  $prefix  The fixed part of the name.
	 * @param string  $postfix The addition now.
	 */
	private function rename_form( Creator $creator, int $product, string $prefix, string $postfix ): void {
		$field = 'merchboard-design-name-' . $product;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->hidden( $creator, $product, 'rename' );
		echo '<label for="' . esc_attr( $field ) . '">' . esc_html( $prefix . DesignerNames::SEPARATOR ) . '</label> ';
		echo '<input type="text" id="' . esc_attr( $field ) . '" name="postfix" class="regular-text" maxlength="120" value="' . esc_attr( $postfix ) . '"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Save name', 'merchboard' ) . '</button>';
		echo '<br><label><input type="checkbox" name="same_name" value="1"> ' . esc_html__( 'Allow the same name as another product', 'merchboard' ) . '</label>';
		echo '</form>';
	}

	/**
	 * A button that deletes or discards a draft, after a confirmation.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @param string  $label   The button.
	 * @param string  $confirm The confirmation.
	 */
	private function delete_form( Creator $creator, int $product, string $label, string $confirm ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr( $confirm ) . '">';
		$this->hidden( $creator, $product, 'delete' );
		echo '<button type="submit" class="button button-link-delete">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * The fields every form has.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @param string  $what    `rename` or `delete`.
	 */
	private function hidden( Creator $creator, int $product, string $what ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( CreatorDesignsActions::ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		echo '<input type="hidden" name="product" value="' . esc_attr( (string) $product ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $what ) . '">';
		wp_nonce_field( CreatorDesignsActions::nonce_action( $creator->id, $product ), '_wpnonce', false );
	}
}
