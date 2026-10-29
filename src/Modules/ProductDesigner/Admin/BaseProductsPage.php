<?php
/**
 * Merch Dashboard → Base products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoException;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulException;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;

defined( 'ABSPATH' ) || exit;

/**
 * The base products and cliparts of the product designer (step 30.6,
 * ADR-080 pkt. 2), for `merchboard_manage_designer`:
 *
 * - **Base products:** the list (status, price, colours, sizes, sides,
 *   version, designs) and the form: name, description, base price incl. VAT,
 *   default category, colours, sizes and per side the template layers (PNG)
 *   and the print area in px, mm and dpi; activate, back to draft, delete.
 * - **Cliparts:** upload and delete.
 *
 * Every change goes through BaseProductActions; files are shown through
 * DesignerAssetDownload. PSD import follows in step 30.7, Printful and
 * Gelato in 30.12–30.13.
 */
final class BaseProductsPage {

	/**
	 * The page slug.
	 */
	public const SLUG = 'merchboard-base-products';

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_base_result';

	/**
	 * `admin_menu` priority: after "News" (28), with "Campaigns" (29).
	 */
	public const PRIORITY = 29;

	/**
	 * Constructor.
	 *
	 * @param BaseProductRepository   $bases    Base products.
	 * @param DesignerAssetRepository $assets   File records.
	 * @param int                     $decimals   Store price decimals.
	 * @param string                  $currency   Store currency.
	 * @param string                  $plugin_dir Plugin directory with a trailing slash (the PSD import bundle).
	 * @param string                  $plugin_url Plugin URL with a trailing slash.
	 * @param bool                    $printful_ready Whether a Printful token is stored.
	 * @param bool                    $gelato_ready   Whether a Gelato key and store are stored.
	 */
	public function __construct( private BaseProductRepository $bases, private DesignerAssetRepository $assets, private int $decimals, private string $currency, private string $plugin_dir = '', private string $plugin_url = '', private bool $printful_ready = false, private bool $gelato_ready = false ) {
	}

	/**
	 * Adds the submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Base products', 'merchboard' ),
			__( 'Base products', 'merchboard' ),
			Capabilities::MANAGE_DESIGNER,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The list's URL.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result = '' ): string {
		return self::page_url( array(), $result );
	}

	/**
	 * The form's URL: a new base product, or one to change.
	 *
	 * @param int    $id     Base product; 0 for a new one.
	 * @param string $result Outcome code.
	 */
	public static function edit_url( int $id = 0, string $result = '' ): string {
		return self::page_url( $id > 0 ? array( 'base' => $id ) : array( 'view' => 'new' ), $result );
	}

	/**
	 * The cliparts tab's URL.
	 *
	 * @param string $result Outcome code.
	 */
	public static function cliparts_url( string $result = '' ): string {
		return self::page_url( array( 'view' => 'cliparts' ), $result );
	}

	/**
	 * Message and notice type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		$size = size_format( DesignerFiles::max_bytes() );

		return match ( $code ) {
			'saved'                                 => array( __( 'The base product was saved. A new base price reaches the designer products in the shop in the background.', 'merchboard' ), 'success' ),
			'activated'                             => array( __( 'The base product is active: creators can design on it.', 'merchboard' ), 'success' ),
			'deactivated'                           => array( __( 'The base product is a draft again: creators cannot start new designs on it. Products in the shop are not affected.', 'merchboard' ), 'success' ),
			'deleted'                               => array( __( 'The base product was deleted.', 'merchboard' ), 'success' ),
			'imported'                              => array( __( 'The product was imported from Printful as a draft. Check the colours, sizes and sides, then activate it.', 'merchboard' ), 'success' ),
			PrintfulException::NOT_CONFIGURED       => array( __( 'Printful is switched off or has no API token.', 'merchboard' ), 'error' ),
			PrintfulException::UNAUTHORIZED         => array( __( 'Printful refused the API token. Enter a new one under Settings → Product designer.', 'merchboard' ), 'error' ),
			PrintfulException::NOT_FOUND            => array( __( 'Printful does not know that product id.', 'merchboard' ), 'error' ),
			PrintfulException::RATE_LIMITED         => array( __( 'Printful asks us to wait a minute. Please try again shortly.', 'merchboard' ), 'error' ),
			PrintfulException::INVALID              => array( __( 'The Printful product has no usable templates or variants.', 'merchboard' ), 'error' ),
			PrintfulException::FAILED               => array( __( 'Printful could not be reached. Please try again later.', 'merchboard' ), 'error' ),
			'gelato_imported'                       => array( __( 'The template was imported from Gelato as a draft. Set the colour codes, check the sides and move the print areas onto the product, then activate it.', 'merchboard' ), 'success' ),
			GelatoException::NOT_CONFIGURED         => array( __( 'Gelato is switched off or has no API key or store id.', 'merchboard' ), 'error' ),
			GelatoException::UNAUTHORIZED           => array( __( 'Gelato refused the API key. Enter a new one under Settings → Product designer.', 'merchboard' ), 'error' ),
			GelatoException::NOT_FOUND              => array( __( 'Gelato does not know that template id.', 'merchboard' ), 'error' ),
			GelatoException::RATE_LIMITED           => array( __( 'Gelato asks us to wait a minute. Please try again shortly.', 'merchboard' ), 'error' ),
			GelatoException::INVALID                => array( __( 'The Gelato template has no usable variants, image placeholders or preview.', 'merchboard' ), 'error' ),
			GelatoException::FAILED                 => array( __( 'Gelato could not be reached. Please try again later.', 'merchboard' ), 'error' ),
			'retired'                               => array( __( 'The base product is retired. Its products are being taken out of the shop, and the creators are told.', 'merchboard' ), 'success' ),
			'retire_unconfirmed'                    => array( __( 'Tick the confirmation to retire the base product.', 'merchboard' ), 'error' ),
			DesignerException::BASE_RETIRED         => array( __( 'The base product is retired and cannot be changed.', 'merchboard' ), 'error' ),
			'clipart_added'                         => array( __( 'The clipart was added.', 'merchboard' ), 'success' ),
			'clipart_deleted'                       => array( __( 'The clipart was deleted.', 'merchboard' ), 'success' ),
			DesignerException::BASE_NAME            => array( __( 'Enter a name of at most 191 characters.', 'merchboard' ), 'error' ),
			DesignerException::BASE_PRICE           => array( __( 'Enter a base price above 0 with at most the store\'s decimals.', 'merchboard' ), 'error' ),
			DesignerException::BASE_CATEGORY        => array( __( 'The category is not a product category.', 'merchboard' ), 'error' ),
			DesignerException::BASE_COLORS          => array( __( 'Enter one colour per line: a name and a colour code such as #1a2b3c. Names must differ.', 'merchboard' ), 'error' ),
			DesignerException::BASE_SIZES           => array( __( 'Sizes must differ and have at most 20 characters.', 'merchboard' ), 'error' ),
			DesignerException::BASE_VIEW            => array( __( 'Check the sides: each needs a unique name, a background or colour mask, a print area inside the template and a print size of 1–2000 mm at 72–600 dpi that gives a print file of at most 8000 × 8000 pixels.', 'merchboard' ), 'error' ),
			DesignerException::BASE_LAYER_SIZE      => array( __( 'All layers of a side must have the same size in pixels.', 'merchboard' ), 'error' ),
			DesignerException::BASE_INCOMPLETE      => array( __( 'An active base product needs at least one colour, one size and one side.', 'merchboard' ), 'error' ),
			DesignerException::BASE_UNKNOWN         => array( __( 'The base product no longer exists.', 'merchboard' ), 'error' ),
			DesignerException::BASE_IN_USE          => array( __( 'The base product has designs and cannot be deleted.', 'merchboard' ), 'error' ),
			DesignerException::CLIPART_NAME         => array( __( 'Enter a name of at most 100 characters for the clipart.', 'merchboard' ), 'error' ),
			DesignerException::CLIPART_UNKNOWN      => array( __( 'The clipart no longer exists.', 'merchboard' ), 'error' ),
			DesignerException::FILE_MISSING         => array( __( 'The file could not be uploaded. Please try again.', 'merchboard' ), 'error' ),
			/* translators: %s: largest file size, e.g. "25 MB". */
			DesignerException::FILE_TOO_LARGE       => array( sprintf( __( 'The image is too large: at most %s and 8000 × 8000 pixels.', 'merchboard' ), (string) $size ), 'error' ),
			DesignerException::FILE_TYPE            => array( __( 'Use PNG, JPG or WebP images; template layers must be PNG.', 'merchboard' ), 'error' ),
			DesignerException::NOT_AUTHORIZED       => array( __( 'You are not allowed to manage the product designer.', 'merchboard' ), 'error' ),
			'error'                                 => array( __( 'A technical error occurred. Nothing was changed.', 'merchboard' ), 'error' ),
			default                                 => null,
		};
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only parameters; nothing is changed by this request.
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$id     = isset( $_GET['base'] ) ? absint( $_GET['base'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Base products', 'merchboard' ) . '</h1>';

		if ( '' === $view && 0 === $id ) {
			echo ' <a class="page-title-action" href="' . esc_url( self::edit_url() ) . '">' . esc_html__( 'New base product', 'merchboard' ) . '</a>';
		}

		echo '<hr class="wp-header-end">';
		echo '<nav class="nav-tab-wrapper">';
		printf( '<a class="nav-tab%1$s" href="%2$s">%3$s</a>', 'cliparts' !== $view ? ' nav-tab-active' : '', esc_url( self::url() ), esc_html__( 'Base products', 'merchboard' ) );
		printf( '<a class="nav-tab%1$s" href="%2$s">%3$s</a>', 'cliparts' === $view ? ' nav-tab-active' : '', esc_url( self::cliparts_url() ), esc_html__( 'Cliparts', 'merchboard' ) );
		echo '</nav>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		try {
			if ( 'cliparts' === $view ) {
				$this->render_cliparts();
			} elseif ( 'new' === $view ) {
				$this->render_form( null );
			} elseif ( $id > 0 ) {
				$base = $this->bases->find( $id );

				if ( null === $base ) {
					echo '<p>' . esc_html__( 'The base product no longer exists.', 'merchboard' ) . '</p>';
				} else {
					$this->render_form( $base );
				}
			} else {
				$this->render_list();
			}
		} catch ( DatabaseException ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The product designer\'s data could not be read. Please try again later.', 'merchboard' ) . '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * The list.
	 *
	 * @throws DatabaseException When the base products cannot be read.
	 */
	private function render_list(): void {
		echo '<p>' . esc_html__( 'The products creators design on. The base price incl. VAT is what the company always keeps outside its own campaigns; the creator\'s profit comes on top. A base product is a draft until it is activated.', 'merchboard' ) . '</p>';
		$this->render_printful_import();
		$this->render_gelato_import();

		$bases = $this->bases->all();

		if ( array() === $bases ) {
			echo '<p>' . esc_html__( 'No base products yet.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'merchboard' ) . '</th><th>' . esc_html__( 'Status', 'merchboard' ) . '</th><th>' . esc_html__( 'Base price', 'merchboard' ) . '</th><th>' . esc_html__( 'Colours', 'merchboard' ) . '</th><th>' . esc_html__( 'Sizes', 'merchboard' ) . '</th><th>' . esc_html__( 'Sides', 'merchboard' ) . '</th><th>' . esc_html__( 'Version', 'merchboard' ) . '</th><th>' . esc_html__( 'Designs', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $bases as $base ) {
			printf(
				'<tr><td><a href="%1$s"><strong>%2$s</strong></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td><td>%9$s</td></tr>',
				esc_url( self::edit_url( (int) $base->id ) ),
				esc_html( $base->name ),
				esc_html( self::status( $base->status ) ),
				esc_html( $this->money( $base->base_units ) . ' ' . $base->currency ),
				esc_html( (string) count( $base->colors ) ),
				esc_html( implode( ', ', $base->sizes ) ),
				esc_html( implode( ', ', array_map( static fn( BaseView $view ): string => $view->name, $base->views ) ) ),
				esc_html( (string) $base->version ),
				esc_html( (string) $this->bases->products( (int) $base->id ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The form.
	 *
	 * @param BaseProduct|null $base The base product; null for a new one.
	 * @throws DatabaseException When its designs cannot be counted.
	 */
	private function render_form( ?BaseProduct $base ): void {
		$id      = (int) ( $base->id ?? 0 );
		$designs = null === $base ? 0 : $this->bases->products( $id );
		$colors  = implode( "\n", array_map( static fn( array $color ): string => $color['name'] . ' ' . $color['hex'], $base->colors ?? array() ) );
		$psd     = $this->enqueue_psd_import();

		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← All base products', 'merchboard' ) . '</a></p>';

		if ( null !== $base ) {
			/* translators: 1: status, 2: version, 3: number of designs. */
			echo '<p>' . esc_html( sprintf( __( 'Status: %1$s · Version %2$d · %3$d designs', 'merchboard' ), self::status( $base->status ), $base->version, $designs ) ) . '</p>';
		}

		if ( $designs > 0 ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Creators have designs on this base product. A new base price or template raises the version; the designer products are repriced in a later step, and sales already registered keep their amounts.', 'merchboard' ) . '</p></div>';
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="do" value="save">';
		echo '<input type="hidden" name="base_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( BaseProductActions::ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="merchboard-base-name">' . esc_html__( 'Name', 'merchboard' ) . '</label></th><td><input type="text" id="merchboard-base-name" name="name" class="regular-text" maxlength="191" required value="' . esc_attr( $base->name ?? '' ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-base-description">' . esc_html__( 'Description', 'merchboard' ) . '</label></th><td><textarea id="merchboard-base-description" name="description" rows="4" class="large-text">' . esc_textarea( $base->description ?? '' ) . '</textarea></td></tr>';
		/* translators: %s: currency code, e.g. "DKK". */
		echo '<tr><th scope="row"><label for="merchboard-base-price">' . esc_html( sprintf( __( 'Base price incl. VAT (%s)', 'merchboard' ), $this->currency ) ) . '</label></th><td><input type="text" inputmode="decimal" id="merchboard-base-price" name="base_price" required value="' . esc_attr( null === $base ? '' : $this->money( $base->base_units ) ) . '"><p class="description">' . esc_html__( 'What the company keeps per unit outside its own campaigns. Creators add their profit on top.', 'merchboard' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-base-category">' . esc_html__( 'Default category', 'merchboard' ) . '</label></th><td>' . $this->category_select( $base->category_id ?? null ) . '<p class="description">' . esc_html__( 'Used for a creator without their own product category.', 'merchboard' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- category_select() escapes.
		echo '<tr><th scope="row"><label for="merchboard-base-colors">' . esc_html__( 'Colours', 'merchboard' ) . '</label></th><td><textarea id="merchboard-base-colors" name="colors" rows="5" class="large-text code">' . esc_textarea( $colors ) . '</textarea><p class="description">' . esc_html__( 'One per line: a name and a colour code, e.g. "Black #111111".', 'merchboard' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-base-sizes">' . esc_html__( 'Sizes', 'merchboard' ) . '</label></th><td><input type="text" id="merchboard-base-sizes" name="sizes" class="regular-text" value="' . esc_attr( implode( ', ', $base->sizes ?? array() ) ) . '"><p class="description">' . esc_html__( 'Separated by commas, e.g. "S, M, L, XL".', 'merchboard' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Sides', 'merchboard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each side has a mockup template of PNG layers of the same size: the background, the colour mask (tinted in the product colour), the shading and the highlight. The print area is where the design is placed on the template (in pixels) and its real size when printed (millimetres at a resolution in dpi).', 'merchboard' ) . '</p>';

		$index = 0;

		foreach ( $base->views ?? array() as $view ) {
			$this->render_view( $index++, $view, $psd );
		}

		$this->render_view( $index, null, $psd );

		echo '<p><button type="submit" class="button button-primary">' . esc_html( null === $base ? __( 'Create base product', 'merchboard' ) : __( 'Save base product', 'merchboard' ) ) . '</button></p>';
		echo '</form>';

		if ( null !== $base ) {
			$this->render_status( $base, $designs );
		}
	}

	/**
	 * One side of the form; null for the empty "new side".
	 *
	 * @param int           $index Index in the form.
	 * @param BaseView|null $view  The side.
	 * @param bool          $psd   Whether the PSD import is loaded.
	 */
	private function render_view( int $index, ?BaseView $view, bool $psd ): void {
		$field = static fn( string $name ): string => 'views[' . $index . '][' . $name . ']';
		$value = static fn( ?int $number ): string => null === $number ? '' : (string) $number;

		echo '<fieldset class="card" style="max-width:none;margin-bottom:1em"><legend><strong>' . esc_html( null === $view ? __( 'New side', 'merchboard' ) : $view->name ) . '</strong></legend>';
		echo '<input type="hidden" name="' . esc_attr( $field( 'key' ) ) . '" value="' . esc_attr( $view->key ?? '' ) . '">';
		echo '<p><label>' . esc_html__( 'Name', 'merchboard' ) . ' <input type="text" name="' . esc_attr( $field( 'name' ) ) . '" maxlength="60" value="' . esc_attr( $view->name ?? '' ) . '"></label>';

		if ( null === $view ) {
			echo ' <span class="description">' . esc_html__( 'Leave empty to add no side.', 'merchboard' ) . '</span>';
		} else {
			/* translators: 1: width in px, 2: height in px. */
			echo ' <span class="description">' . esc_html( sprintf( __( 'Template %1$d × %2$d px', 'merchboard' ), $view->width, $view->height ) ) . '</span>';
		}

		echo '</p>';

		if ( $psd ) {
			// Read in the browser only: the field has no name, so the PSD is never sent.
			echo '<p><label>' . esc_html__( 'Import from PSD', 'merchboard' ) . ' <input type="file" accept=".psd,image/vnd.adobe.photoshop" data-merchboard-psd="' . esc_attr( (string) $index ) . '"></label> ';
			echo '<span class="description" id="' . esc_attr( 'merchboard-psd-status-' . $index ) . '" aria-live="polite">' . esc_html__( 'Layers mb_background, mb_color, mb_shading, mb_highlight and mb_print_area. The PSD stays on your computer; only the PNG layers are uploaded when you save.', 'merchboard' ) . '</span></p>';
		}

		echo '<table class="widefat" style="max-width:60rem"><tbody>';

		foreach ( BaseView::LAYERS as $layer ) {
			$asset = $view->layers[ $layer ] ?? null;
			echo '<tr><th scope="row" style="width:12rem">' . esc_html( self::layer( $layer ) ) . '</th><td>';

			if ( null !== $asset ) {
				echo '<img src="' . esc_url( DesignerAssetDownload::url( $asset ) ) . '" alt="" style="max-width:80px;max-height:80px;vertical-align:middle;background:repeating-conic-gradient(#ccc 0 25%,#fff 0 50%) 0 0/16px 16px"> ';
				echo '<label><input type="checkbox" name="' . esc_attr( $field( 'clear' ) ) . '[]" value="' . esc_attr( $layer ) . '"> ' . esc_html__( 'Remove', 'merchboard' ) . '</label> ';
			}

			echo '<input type="file" name="' . esc_attr( 'layer_' . $index . '_' . $layer ) . '" accept="image/png"></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Print area in the template (px):', 'merchboard' ) . ' ';

		foreach ( array(
			'x'      => 'X',
			'y'      => 'Y',
			'width'  => __( 'Width', 'merchboard' ),
			'height' => __( 'Height', 'merchboard' ),
		) as $key => $label ) {
			echo '<label>' . esc_html( $label ) . ' <input type="number" min="0" max="8000" step="1" style="width:6rem" name="' . esc_attr( $field( $key ) ) . '" value="' . esc_attr( $value( $view->area[ $key ] ?? null ) ) . '"></label> ';
		}

		echo '</p><p>' . esc_html__( 'Print size:', 'merchboard' ) . ' ';
		echo '<label>' . esc_html__( 'Width (mm)', 'merchboard' ) . ' <input type="number" min="1" max="' . esc_attr( (string) BaseView::MAX_MM ) . '" step="1" style="width:6rem" name="' . esc_attr( $field( 'width_mm' ) ) . '" value="' . esc_attr( $value( $view->width_mm ?? null ) ) . '"></label> ';
		echo '<label>' . esc_html__( 'Height (mm)', 'merchboard' ) . ' <input type="number" min="1" max="' . esc_attr( (string) BaseView::MAX_MM ) . '" step="1" style="width:6rem" name="' . esc_attr( $field( 'height_mm' ) ) . '" value="' . esc_attr( $value( $view->height_mm ?? null ) ) . '"></label> ';
		echo '<label>' . esc_html__( 'Resolution (dpi)', 'merchboard' ) . ' <input type="number" min="' . esc_attr( (string) BaseView::MIN_DPI ) . '" max="' . esc_attr( (string) BaseView::MAX_DPI ) . '" step="1" style="width:6rem" name="' . esc_attr( $field( 'dpi' ) ) . '" value="' . esc_attr( $value( $view->dpi ?? 300 ) ) . '"></label>';

		if ( null !== $view ) {
			[ $width, $height ] = $view->print_pixels();
			/* translators: 1: width in px, 2: height in px. */
			echo ' <span class="description">' . esc_html( sprintf( __( 'Print file: %1$d × %2$d px', 'merchboard' ), $width, $height ) ) . '</span>';
			echo '</p><p><label><input type="checkbox" name="' . esc_attr( $field( 'remove' ) ) . '" value="1"> ' . esc_html__( 'Remove this side', 'merchboard' ) . '</label>';
		}

		echo '</p></fieldset>';
	}

	/**
	 * Activate, back to draft and delete.
	 *
	 * @param BaseProduct $base    Base product.
	 * @param int         $designs Number of designs.
	 */
	private function render_status( BaseProduct $base, int $designs ): void {
		echo '<hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="base_id" value="' . esc_attr( (string) $base->id ) . '">';
		wp_nonce_field( BaseProductActions::ACTION );

		if ( BaseProduct::RETIRED === $base->status ) {
			echo '<p>' . esc_html__( 'This base product is retired: its products are out of the shop, and it cannot be changed or activated again.', 'merchboard' ) . '</p></form>';

			return;
		}

		if ( BaseProduct::ACTIVE === $base->status ) {
			echo '<p><button type="submit" name="do" value="deactivate" class="button">' . esc_html__( 'Back to draft', 'merchboard' ) . '</button> <span class="description">' . esc_html__( 'Creators can no longer start designs on it. Products in the shop are not affected.', 'merchboard' ) . '</span></p>';
		} else {
			echo '<p><button type="submit" name="do" value="activate" class="button button-primary">' . esc_html__( 'Activate', 'merchboard' ) . '</button> <span class="description">' . esc_html__( 'Creators can design on it.', 'merchboard' ) . '</span></p>';
		}

		if ( 0 === $designs ) {
			echo '<p><button type="submit" name="do" value="delete" class="button-link button-link-delete">' . esc_html__( 'Delete base product', 'merchboard' ) . '</button></p>';
		} else {
			echo '<fieldset style="margin-top:12px;padding:8px 12px;border:1px solid #d63638"><legend>' . esc_html__( 'Retire', 'merchboard' ) . '</legend>';
			echo '<p>' . esc_html__( 'Retiring takes every product of this base product out of the shop (they become drafts and stay for the order history), rejects the designs waiting for approval and tells the creators. It cannot be undone.', 'merchboard' ) . '</p>';
			echo '<p><label><input type="checkbox" name="confirm_retire" value="1"> ' . esc_html__( 'Yes, retire this base product for good.', 'merchboard' ) . '</label></p>';
			echo '<p><button type="submit" name="do" value="retire" class="button button-link-delete">' . esc_html__( 'Retire base product', 'merchboard' ) . '</button></p>';
			echo '</fieldset>';
		}

		echo '</form>';
	}

	/**
	 * The Printful import (step 30.12): a catalog product id and the base price.
	 */
	private function render_printful_import(): void {
		if ( ! Settings::printful_enabled() ) {
			return;
		}

		echo '<details style="margin:12px 0"><summary><strong>' . esc_html__( 'Import from Printful', 'merchboard' ) . '</strong></summary>';

		if ( ! $this->printful_ready ) {
			echo '<p>' . esc_html__( 'Enter the Printful API token under Settings → Product designer first.', 'merchboard' ) . '</p></details>';

			return;
		}

		echo '<p>' . esc_html__( 'The product id is the number in the catalog product\'s address at Printful (or in its API). The colours, sizes and sides with their templates and print areas are imported as a draft; check them, add a colour mask if you want the editor to tint the product, and activate it. Mockups of designs on it come from Printful.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="do" value="printful_import">';
		wp_nonce_field( BaseProductActions::ACTION );
		echo '<p><label>' . esc_html__( 'Printful product id', 'merchboard' ) . ' <input type="number" min="1" name="printful_product" required class="small-text"></label> ';
		echo '<label>' . esc_html__( 'Base price incl. VAT', 'merchboard' ) . ' <input type="text" name="base_price" required class="small-text" inputmode="decimal"></label> ';
		submit_button( __( 'Import', 'merchboard' ), 'secondary', 'submit', false );
		echo '</p></form></details>';
	}

	/**
	 * The Gelato import (step 30.13): a template id and the base price.
	 */
	private function render_gelato_import(): void {
		if ( ! Settings::gelato_enabled() ) {
			return;
		}

		echo '<details style="margin:12px 0"><summary><strong>' . esc_html__( 'Import from Gelato', 'merchboard' ) . '</strong></summary>';

		if ( ! $this->gelato_ready ) {
			echo '<p>' . esc_html__( 'Enter the Gelato API key and store id under Settings → Product designer first.', 'merchboard' ) . '</p></details>';

			return;
		}

		echo '<p>' . esc_html__( 'Make a template in the Gelato dashboard first: the product, the colours and sizes creators may choose, and one image placeholder per side. The template id is in the template\'s address at Gelato. The colours, sizes and sides are imported as a draft with the template\'s preview as the background; set the colour codes, move each print area onto the product (or add your own layers) and activate it. Mockups of designs on it come from Gelato.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="do" value="gelato_import">';
		wp_nonce_field( BaseProductActions::ACTION );
		echo '<p><label>' . esc_html__( 'Gelato template id', 'merchboard' ) . ' <input type="text" name="gelato_template" required class="regular-text" pattern="[A-Za-z0-9-]{1,100}" autocomplete="off"></label> ';
		echo '<label>' . esc_html__( 'Base price incl. VAT', 'merchboard' ) . ' <input type="text" name="base_price" required class="small-text" inputmode="decimal"></label> ';
		submit_button( __( 'Import', 'merchboard' ), 'secondary', 'submit', false );
		echo '</p></form></details>';
	}

	/**
	 * Loads the PSD import (build/base-products-admin.js); false without a
	 * build, when the form works without it.
	 */
	private function enqueue_psd_import(): bool {
		$file  = $this->plugin_dir . 'build/base-products-admin.asset.php';
		$asset = '' !== $this->plugin_dir && is_readable( $file ) && is_readable( $this->plugin_dir . 'build/base-products-admin.js' ) ? require $file : null;

		if ( ! is_array( $asset ) || ! is_string( $asset['version'] ?? null ) || ! is_array( $asset['dependencies'] ?? null ) ) {
			return false;
		}

		$dependencies = array_values( array_filter( $asset['dependencies'], static fn( mixed $dependency ): bool => is_string( $dependency ) && '' !== $dependency ) );

		wp_enqueue_script( 'merchboard-base-products-admin', $this->plugin_url . 'build/base-products-admin.js', $dependencies, $asset['version'], true );
		wp_set_script_translations( 'merchboard-base-products-admin', 'merchboard', $this->plugin_dir . 'languages' );

		return true;
	}

	/**
	 * The cliparts tab.
	 *
	 * @throws DatabaseException When the cliparts cannot be read.
	 */
	private function render_cliparts(): void {
		echo '<p>' . esc_html__( 'Images every creator can use in the designer. PNG, JPG or WebP; use PNG for transparency.', 'merchboard' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="do" value="clipart_add">';
		wp_nonce_field( BaseProductActions::ACTION );
		echo '<p><label>' . esc_html__( 'Name', 'merchboard' ) . ' <input type="text" name="clipart_name" maxlength="100" required></label> ';
		echo '<input type="file" name="clipart" accept="image/png,image/jpeg,image/webp" required> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Add clipart', 'merchboard' ) . '</button></p></form>';

		$cliparts = $this->assets->shared( DesignerAssetRepository::CLIPART );

		if ( array() === $cliparts ) {
			echo '<p>' . esc_html__( 'No cliparts yet.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<div style="display:flex;flex-wrap:wrap;gap:1em">';

		foreach ( $cliparts as $clipart ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="card" style="margin:0;width:10rem;text-align:center">';
			echo '<input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="do" value="clipart_delete"><input type="hidden" name="asset_id" value="' . esc_attr( (string) $clipart['id'] ) . '">';
			wp_nonce_field( BaseProductActions::ACTION );
			echo '<img src="' . esc_url( DesignerAssetDownload::url( $clipart['id'] ) ) . '" alt="" style="max-width:100%;max-height:8rem;background:repeating-conic-gradient(#ccc 0 25%,#fff 0 50%) 0 0/16px 16px">';
			echo '<p>' . esc_html( $clipart['name'] ) . '<br><span class="description">' . esc_html( $clipart['width'] . ' × ' . $clipart['height'] . ' px' ) . '</span></p>';
			echo '<p><button type="submit" class="button-link button-link-delete">' . esc_html__( 'Delete', 'merchboard' ) . '</button></p></form>';
		}

		echo '</div>';
	}

	/**
	 * The default category select.
	 *
	 * @param int|null $selected Selected term.
	 */
	private function category_select( ?int $selected ): string {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$html  = '<select id="merchboard-base-category" name="category_id"><option value="0">' . esc_html__( '— None —', 'merchboard' ) . '</option>';

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$html .= '<option value="' . esc_attr( (string) $term->term_id ) . '"' . selected( $selected, $term->term_id, false ) . '>' . esc_html( $term->name ) . '</option>';
		}

		return $html . '</select>';
	}

	/**
	 * An amount at the store precision.
	 *
	 * @param int $units Units.
	 */
	private function money( int $units ): string {
		return PricingMath::fits_precision( $units, $this->decimals ) ? PricingMath::price_string( $units, $this->decimals ) : (string) ( $units / 10000 );
	}

	/**
	 * A status in words.
	 *
	 * @param string $status Status.
	 */
	private static function status( string $status ): string {
		return match ( $status ) {
			BaseProduct::ACTIVE  => __( 'Active', 'merchboard' ),
			BaseProduct::RETIRED => __( 'Retired', 'merchboard' ),
			default              => __( 'Draft', 'merchboard' ),
		};
	}

	/**
	 * A layer in words.
	 *
	 * @param string $layer Layer.
	 */
	private static function layer( string $layer ): string {
		return match ( $layer ) {
			'background' => __( 'Background', 'merchboard' ),
			'color'      => __( 'Colour mask', 'merchboard' ),
			'shading'    => __( 'Shading', 'merchboard' ),
			default      => __( 'Highlight', 'merchboard' ),
		};
	}

	/**
	 * A URL of the page.
	 *
	 * @param array<string, int|string> $args   Query arguments.
	 * @param string                    $result Outcome code.
	 */
	private static function page_url( array $args, string $result ): string {
		$args['page'] = self::SLUG;

		if ( '' !== $result ) {
			$args[ self::RESULT_ARG ] = $result;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
