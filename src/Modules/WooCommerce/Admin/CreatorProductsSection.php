<?php
/**
 * "Products" section on the creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLinkException;
use MerchBoard\Modules\WooCommerce\ProductRecord;
use MerchBoard\Modules\WooCommerce\ProductStore;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the creator's linked WooCommerce products and lets an administrator
 * search products (name, description, SKU or product category) and link
 * or remove them (ADR-032). Read-only rendering: every change goes through
 * CreatorProductsActions and ProductCreatorLink, which decide what may be
 * linked. This is the only product-link UI; the WooCommerce product editor
 * has none.
 *
 * Product names are only listed for users who may edit WooCommerce products
 * (`edit_products`); each change is still authorised per product.
 */
final class CreatorProductsSection {

	/**
	 * Most linked products listed.
	 */
	public const LIST_LIMIT = 200;

	/**
	 * Most search results listed.
	 */
	public const SEARCH_LIMIT = 20;

	/**
	 * Creator names by id, looked up once per request.
	 *
	 * @var array<int, string|null>
	 */
	private array $creator_names = array();

	/**
	 * Constructor.
	 *
	 * @param ProductStore      $products Product reads.
	 * @param CreatorRepository $creators Creator names.
	 */
	public function __construct(
		private ProductStore $products,
		private CreatorRepository $creators
	) {
	}

	/**
	 * Registers the section on the creator's edit page.
	 */
	public function register(): void {
		add_action( 'merchboard_creators_edit_sections', array( $this, 'render' ) );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'linked'                                           => array( __( 'Linked to this creator.', 'merchboard' ), 'success' ),
			'replaced'                                         => array( __( 'Taken over from the previous creator and linked to this creator.', 'merchboard' ), 'success' ),
			'unlinked'                                         => array( __( 'Link removed.', 'merchboard' ), 'success' ),
			'unchanged'                                        => array( __( 'Nothing changed.', 'merchboard' ), 'info' ),
			CreatorProductsActions::NO_SELECTION               => array( __( 'Select at least one product.', 'merchboard' ), 'info' ),
			'linked_elsewhere'                                 => array( __( 'The product is linked to another creator, so it was not removed here.', 'merchboard' ), 'error' ),
			ProductCreatorLinkException::ALREADY_LINKED        => array( __( 'Not changed: the product is linked to another creator or holds an invalid link. Tick "Take over" for this product to move it.', 'merchboard' ), 'error' ),
			ProductCreatorLinkException::NOT_AUTHORIZED        => array( __( 'You are not allowed to edit this product.', 'merchboard' ), 'error' ),
			ProductCreatorLinkException::PRODUCT_NOT_FOUND     => array( __( 'The product does not exist.', 'merchboard' ), 'error' ),
			ProductCreatorLinkException::VARIATION             => array( __( 'Variations cannot be linked on their own; they belong to the creator of their parent product.', 'merchboard' ), 'error' ),
			ProductCreatorLinkException::PRODUCT_NOT_SUPPORTED => array( __( 'Products in the trash or not yet saved cannot be linked.', 'merchboard' ), 'error' ),
			ProductCreatorLinkException::CREATOR_NOT_FOUND     => array( __( 'The creator does not exist.', 'merchboard' ), 'error' ),
			ProductCreatorLinkException::CREATOR_INACTIVE      => array( __( 'Inactive creators cannot get new products. Activate the creator first.', 'merchboard' ), 'error' ),
			'error'                                            => array( __( 'A technical error occurred. The product may not have been changed; check it and try again later.', 'merchboard' ), 'error' ),
			default                                            => null,
		};
	}

	/**
	 * Renders the section below the creator form.
	 *
	 * @param Creator $creator Creator being edited.
	 */
	public function render( Creator $creator ): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return;
		}

		echo '<hr><h2 id="merchboard-products">' . esc_html__( 'Products', 'merchboard' ) . '</h2>';

		if ( ! current_user_can( Capabilities::EDIT_PRODUCTS ) ) {
			echo '<p>' . esc_html__( 'You need permission to edit WooCommerce products to see and change the products of this creator.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'WooCommerce products that belong to this creator. Links are managed only here; the WooCommerce product screen does not show or change them. Variations belong to the creator of their parent product.', 'merchboard' ) . '</p>';

		// Read-only display parameters; nothing is changed by this request.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$results = isset( $_GET[ CreatorProductsActions::RESULTS_ARG ] ) ? CreatorProductsActions::parse_results( sanitize_text_field( wp_unslash( $_GET[ CreatorProductsActions::RESULTS_ARG ] ) ) ) : array();
		$search  = isset( $_GET[ CreatorProductsActions::SEARCH_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ CreatorProductsActions::SEARCH_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->render_results( $results );
		$this->render_linked( $creator );
		$this->render_search( $creator, $search );
	}

	/**
	 * Outcomes of the previous request, one line per product.
	 *
	 * @param array<int, string> $results Product id (0 = request) => code.
	 */
	private function render_results( array $results ): void {
		foreach ( $results as $product_id => $code ) {
			$message = self::result_message( $code );

			if ( null === $message ) {
				continue;
			}

			$text = $message[0];

			if ( $product_id > 0 ) {
				/* translators: 1: product label, 2: outcome message. */
				$text = sprintf( __( '%1$s: %2$s', 'merchboard' ), $this->product_label( $product_id ), $message[0] );
			}

			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $message[1] ), esc_html( $text ) );
		}
	}

	/**
	 * The creator's linked products, each with a remove button.
	 *
	 * @param Creator $creator Creator.
	 */
	private function render_linked( Creator $creator ): void {
		$linked = $this->products->linked_to( $creator->id, self::LIST_LIMIT );

		echo '<h3>' . esc_html__( 'Linked products', 'merchboard' ) . '</h3>';

		if ( array() === $linked ) {
			echo '<p>' . esc_html__( 'No products are linked to this creator yet.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped merchboard-linked-products"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Product', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'SKU', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'merchboard' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'merchboard' ) . '</span></th>';
		echo '</tr></thead><tbody>';

		foreach ( $linked as $product ) {
			echo '<tr><td>' . esc_html( self::name( $product ) );

			if ( $product->has_invalid_link() ) {
				echo '<br><span class="description">' . esc_html__( 'The stored link is invalid. Link the product again (with "Take over") to repair it, or remove it.', 'merchboard' ) . '</span>';
			}

			echo '</td><td>' . esc_html( $product->sku ) . '</td>';
			echo '<td>' . esc_html( self::status_label( $product->status ) ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( CreatorProductsActions::UNLINK_ACTION ) . '">';
			echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
			echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $product->id ) . '">';
			wp_nonce_field( CreatorProductsActions::unlink_nonce_action( $creator->id ) );
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Remove', 'merchboard' ) . '</button>';
			echo '</form></td></tr>';
		}

		echo '</tbody></table>';

		if ( count( $linked ) >= self::LIST_LIMIT ) {
			/* translators: %d: number of products shown. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Only the first %d products are shown.', 'merchboard' ), self::LIST_LIMIT ) ) . '</p>';
		}
	}

	/**
	 * The product search and, for a search term, the selectable results.
	 *
	 * @param Creator $creator Creator.
	 * @param string  $search  Search term.
	 */
	private function render_search( Creator $creator, string $search ): void {
		echo '<h3>' . esc_html__( 'Add products', 'merchboard' ) . '</h3>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="merchboard-product-search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( CreatorsAdminPage::SLUG ) . '">';
		echo '<input type="hidden" name="action" value="edit">';
		echo '<input type="hidden" name="creator" value="' . esc_attr( (string) $creator->id ) . '">';
		echo '<label for="merchboard-product-search">' . esc_html__( 'Search by product name, SKU or category', 'merchboard' ) . '</label> ';
		echo '<input type="search" id="merchboard-product-search" name="' . esc_attr( CreatorProductsActions::SEARCH_ARG ) . '" value="' . esc_attr( $search ) . '" class="regular-text"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Search products', 'merchboard' ) . '</button>';
		echo '</form>';

		if ( '' === $search ) {
			return;
		}

		$found = $this->products->search( $search, self::SEARCH_LIMIT );

		if ( array() === $found ) {
			echo '<p>' . esc_html__( 'No products found.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CreatorProductsActions::LINK_ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		echo '<input type="hidden" name="' . esc_attr( CreatorProductsActions::SEARCH_ARG ) . '" value="' . esc_attr( $search ) . '">';
		wp_nonce_field( CreatorProductsActions::link_nonce_action( $creator->id ) );

		echo '<table class="widefat striped merchboard-product-results"><thead><tr>';
		echo '<td class="check-column"><span class="screen-reader-text">' . esc_html__( 'Select', 'merchboard' ) . '</span></td>';
		echo '<th scope="col">' . esc_html__( 'Product', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'SKU', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Current creator', 'merchboard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $found as $product ) {
			$this->render_result_row( $creator, $product );
		}

		echo '</tbody></table>';

		if ( count( $found ) >= self::SEARCH_LIMIT ) {
			/* translators: %d: number of results shown. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Only the first %d results are shown. Refine the search to find other products.', 'merchboard' ), self::SEARCH_LIMIT ) ) . '</p>';
		}

		submit_button( __( 'Link selected products', 'merchboard' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * One search result: select box and, when the product belongs to another
	 * creator or holds an invalid link, the explicit "take over" choice.
	 *
	 * @param Creator       $creator Creator being edited.
	 * @param ProductRecord $product Product.
	 */
	private function render_result_row( Creator $creator, ProductRecord $product ): void {
		$current  = $product->creator_id();
		$box_id   = 'merchboard-product-' . $product->id;
		$linked   = $current === $creator->id;
		$disabled = $linked ? ' disabled' : '';

		echo '<tr><th scope="row" class="check-column">';
		echo '<input type="checkbox" id="' . esc_attr( $box_id ) . '" name="products[]" value="' . esc_attr( (string) $product->id ) . '"' . esc_attr( $disabled ) . '>';
		echo '</th><td><label for="' . esc_attr( $box_id ) . '">' . esc_html( self::name( $product ) ) . '</label></td>';
		echo '<td>' . esc_html( $product->sku ) . '</td>';
		echo '<td>' . esc_html( self::status_label( $product->status ) ) . '</td><td>';

		if ( $linked ) {
			echo esc_html__( 'Already linked to this creator', 'merchboard' );
		} elseif ( null !== $current || $product->has_invalid_link() ) {
			$owner = null === $current ? null : $this->creator_name( $current );

			if ( null === $current ) {
				echo esc_html__( 'Invalid link', 'merchboard' );
				$label = __( 'Take over: replace the invalid link', 'merchboard' );
			} else {
				/* translators: %s: creator name. */
				echo esc_html( sprintf( __( 'Linked to %s', 'merchboard' ), $owner ?? '#' . $current ) );
				/* translators: %s: creator name. */
				$label = sprintf( __( 'Take over the product from %s', 'merchboard' ), $owner ?? '#' . $current );
			}

			echo '<br><label><input type="checkbox" name="replace[]" value="' . esc_attr( (string) $product->id ) . '"> ' . esc_html( $label ) . '</label>';
		} else {
			echo esc_html__( 'None', 'merchboard' );
		}

		echo '</td></tr>';
	}

	/**
	 * Label of a product for outcome messages.
	 *
	 * @param int $product_id Product id.
	 */
	private function product_label( int $product_id ): string {
		$product = $this->products->find( $product_id );

		return null === $product ? '#' . $product_id : self::name( $product );
	}

	/**
	 * A creator's name, or null when it does not exist.
	 *
	 * @param int $creator_id Creator id.
	 */
	private function creator_name( int $creator_id ): ?string {
		if ( ! array_key_exists( $creator_id, $this->creator_names ) ) {
			$this->creator_names[ $creator_id ] = $this->creators->find( $creator_id )?->display_name;
		}

		return $this->creator_names[ $creator_id ];
	}

	/**
	 * Product name with id, so identical names can be told apart.
	 *
	 * @param ProductRecord $product Product.
	 */
	private static function name( ProductRecord $product ): string {
		$name = '' === $product->name ? __( '(no name)', 'merchboard' ) : $product->name;

		/* translators: 1: product name, 2: product id. */
		return sprintf( __( '%1$s (#%2$d)', 'merchboard' ), $name, $product->id );
	}

	/**
	 * WordPress' label of a post status.
	 *
	 * @param string $status Post status.
	 */
	private static function status_label( string $status ): string {
		$object = get_post_status_object( $status );

		return null !== $object && is_string( $object->label ) ? $object->label : $status;
	}
}
