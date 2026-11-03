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
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Providers\CatalogImport;
use MerchBoard\Modules\ProductDesigner\Providers\CatalogRenderer;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoException;
use MerchBoard\Modules\ProductDesigner\Providers\ImageProbe;
use MerchBoard\Modules\ProductDesigner\Providers\PrintifyCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\SizeUnits;
use MerchBoard\Modules\ProductDesigner\Providers\PrintifyException;
use MerchBoard\Modules\ProductDesigner\Providers\ProdigiCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\ProdigiException;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulException;
use MerchBoard\Modules\ProductDesigner\ThreadPacks;
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
 * Gelato in 30.12–30.13. From review 30.15 a new base product can be
 * searched and picked in Printful's or Gelato's catalog (read-only GET
 * requests; the provider's answers are escaped), and an imported one shows
 * its import's state, a failure's reason with "Try again" and, on request,
 * the provider's answers.
 */
final class BaseProductsPage {

	/**
	 * The page slug.
	 */
	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_base_result';

	/**
	 * `admin_menu` priority: after "News" (28), with "Campaigns" (29).
	 */
	public const PRIORITY = 29;

	/**
	 * The admin-ajax action with an import's status.
	 */
	public const STATUS_ACTION = 'merchboard_base_import_status';

	/**
	 * The most colour photos of one request; PHP's own limit may be lower.
	 */
	private const PHOTOS_PER_REQUEST = 10;

	/**
	 * Seconds without a round before a running import is shown as stuck.
	 */
	private const STALE_IMPORT = 300;

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
	 * @param CatalogImport|null      $catalog        The catalog import (review 30.15).
	 * @param PrintfulCatalog|null    $printful       Printful's catalog.
	 * @param GelatoCatalog|null      $gelato         Gelato's catalogs and templates.
	 * @param ProdigiCatalog|null     $prodigi        Prodigi's catalogue.
	 * @param bool                    $prodigi_ready  Whether a Prodigi key is stored.
	 * @param PrintifyCatalog|null    $printify       Printify's catalog.
	 * @param bool                    $printify_ready Whether a Printify token is stored.
	 */
	public function __construct( private BaseProductRepository $bases, private DesignerAssetRepository $assets, private int $decimals, private string $currency, private string $plugin_dir = '', private string $plugin_url = '', private bool $printful_ready = false, private bool $gelato_ready = false, private ?CatalogImport $catalog = null, private ?PrintfulCatalog $printful = null, private ?GelatoCatalog $gelato = null, private ?ProdigiCatalog $prodigi = null, private bool $prodigi_ready = false, private ?PrintifyCatalog $printify = null, private bool $printify_ready = false ) {
	}

	/**
	 * WordPress entry point of the import's status (logged-in users only).
	 */
	public function handle_status(): void {
		// The nonce is verified in import_status() before any input is used.
		$result = $this->import_status( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in import_status().

		if ( 200 === $result['status'] ) {
			wp_send_json_success( $result['data'] );
		}

		wp_send_json_error( null, $result['status'] );
	}

	/**
	 * An import's status as escaped HTML. Order of checks: capability → nonce.
	 *
	 * @param array<mixed> $input Unslashed request data (`base`, `nonce`).
	 * @return array{status: int, data: array{state?: string, html?: string}}
	 */
	public function import_status( array $input ): array {
		$nonce = is_scalar( $input['nonce'] ?? null ) ? (string) $input['nonce'] : '';

		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) || 1 !== wp_verify_nonce( $nonce, self::STATUS_ACTION ) ) {
			return array(
				'status' => 403,
				'data'   => array(),
			);
		}

		$base_id = absint( is_scalar( $input['base'] ?? null ) ? $input['base'] : 0 );

		try {
			$import = $this->bases->import( $base_id );
		} catch ( DatabaseException ) {
			$import = null;
		}

		if ( null === $import ) {
			return array(
				'status' => 404,
				'data'   => array(),
			);
		}

		return array(
			'status' => 200,
			'data'   => array(
				'state' => $import['state'],
				'html'  => self::import_progress( $import['state'], $import['data'], $base_id ),
			),
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
			'photo_moved'                           => array( __( 'The order of the mockups was saved. It is used for designs sent from now on.', 'merchboard' ), 'success' ),
			'activated'                             => array( __( 'The base product is active: creators can design on it.', 'merchboard' ), 'success' ),
			'deactivated'                           => array( __( 'The base product is a draft again: creators cannot start new designs on it. Products in the shop are not affected.', 'merchboard' ), 'success' ),
			'deleted'                               => array( __( 'The base product was deleted.', 'merchboard' ), 'success' ),
			'importing'                             => array( __( 'The base product was created as a draft. Its photos and print areas are imported in the background; this takes a few minutes.', 'merchboard' ), 'success' ),
			DesignerException::BASE_PICK            => array( __( 'Tick at least one colour, one size and one side.', 'merchboard' ), 'error' ),
			DesignerException::IMPORT_RUNNING       => array( __( 'The base product is being imported. Wait until the import is done.', 'merchboard' ), 'error' ),
			PrintfulException::NOT_CONFIGURED       => array( __( 'Printful is switched off or has no API token.', 'merchboard' ), 'error' ),
			PrintfulException::UNAUTHORIZED         => array( __( 'Printful refused the API token. Enter a new one under Settings → Product designer.', 'merchboard' ), 'error' ),
			PrintfulException::NOT_FOUND            => array( __( 'Printful does not know that product.', 'merchboard' ), 'error' ),
			PrintfulException::RATE_LIMITED         => array( __( 'Printful asks us to wait a minute. Please try again shortly.', 'merchboard' ), 'error' ),
			PrintfulException::INVALID              => array( __( 'The Printful product has no variants or print files that can be used.', 'merchboard' ), 'error' ),
			PrintfulException::FAILED               => array( __( 'Printful could not be reached. Please try again later.', 'merchboard' ), 'error' ),
			PrintfulException::NOT_IN_EU            => array( __( 'No variant of this product is in stock in Europe, so it cannot be offered.', 'merchboard' ), 'error' ),
			PrintfulException::UNSUPPORTED          => array( __( 'Knitted products cannot be designed yet.', 'merchboard' ), 'error' ),
			PrintfulException::V2_REQUIRED          => array( __( 'An all-over product can only be imported through Printful\'s newest API, which failed. Please try again later.', 'merchboard' ), 'error' ),
			GelatoException::NOT_CONFIGURED         => array( __( 'Gelato is switched off or has no API key or store id.', 'merchboard' ), 'error' ),
			GelatoException::UNAUTHORIZED           => array( __( 'Gelato refused the API key. Enter a new one under Settings → Product designer.', 'merchboard' ), 'error' ),
			GelatoException::NOT_FOUND              => array( __( 'Gelato does not know that template id.', 'merchboard' ), 'error' ),
			GelatoException::RATE_LIMITED           => array( __( 'Gelato asks us to wait a minute. Please try again shortly.', 'merchboard' ), 'error' ),
			GelatoException::INVALID                => array( __( 'The Gelato template has no usable variants or image placeholders.', 'merchboard' ), 'error' ),
			GelatoException::FAILED                 => array( __( 'Gelato could not be reached. Please try again later.', 'merchboard' ), 'error' ),
			PrintifyException::NOT_CONFIGURED       => array( __( 'Printify is switched off or has no access token.', 'merchboard' ), 'error' ),
			PrintifyException::UNAUTHORIZED         => array( __( 'Printify refused the access token. Enter a new one under Settings → Product designer.', 'merchboard' ), 'error' ),
			PrintifyException::NOT_FOUND            => array( __( 'Printify does not know that product or producer.', 'merchboard' ), 'error' ),
			PrintifyException::RATE_LIMITED         => array( __( 'Printify asks us to wait a minute. Please try again shortly.', 'merchboard' ), 'error' ),
			PrintifyException::INVALID              => array( __( 'The Printify product has no variants or print areas that can be used.', 'merchboard' ), 'error' ),
			PrintifyException::FAILED               => array( __( 'Printify could not be reached. Please try again later.', 'merchboard' ), 'error' ),
			ProdigiException::NOT_CONFIGURED        => array( __( 'Prodigi is switched off or has no API key.', 'merchboard' ), 'error' ),
			ProdigiException::UNAUTHORIZED          => array( __( 'Prodigi refused the API key. Enter a new one under Settings → Product designer.', 'merchboard' ), 'error' ),
			ProdigiException::NOT_FOUND             => array( __( 'Prodigi does not know that product.', 'merchboard' ), 'error' ),
			ProdigiException::RATE_LIMITED          => array( __( 'Prodigi asks us to wait a minute. Please try again shortly.', 'merchboard' ), 'error' ),
			ProdigiException::INVALID               => array( __( 'The Prodigi product has no SKUs or print areas that can be used.', 'merchboard' ), 'error' ),
			ProdigiException::FAILED                => array( __( 'Prodigi could not be reached. Please try again later.', 'merchboard' ), 'error' ),
			ProdigiException::NOT_IN_EU             => array( __( 'This product is not made in the EU, so it cannot be offered.', 'merchboard' ), 'error' ),
			'retired'                               => array( __( 'The base product is retired. Its products are being taken out of the shop, and the creators are told.', 'merchboard' ), 'success' ),
			'retire_unconfirmed'                    => array( __( 'Tick the confirmation to retire the base product.', 'merchboard' ), 'error' ),
			DesignerException::BASE_RETIRED         => array( __( 'The base product is retired and cannot be changed.', 'merchboard' ), 'error' ),
			'clipart_added'                         => array( __( 'The clipart was added.', 'merchboard' ), 'success' ),
			'clipart_deleted'                       => array( __( 'The clipart was deleted.', 'merchboard' ), 'success' ),
			DesignerException::BASE_NAME            => array( __( 'Enter a name of at most 191 characters.', 'merchboard' ), 'error' ),
			DesignerException::BASE_PRICE           => array( __( 'Enter a base price above 0 with at most the store\'s decimals.', 'merchboard' ), 'error' ),
			DesignerException::BASE_EXTRA           => array( __( 'Enter a price for extra design areas of at least 0 with at most the store\'s decimals.', 'merchboard' ), 'error' ),
			DesignerException::BASE_CATEGORY        => array( __( 'The category is not a product category.', 'merchboard' ), 'error' ),
			DesignerException::BASE_COLORS          => array( __( 'Enter one colour per line: a name and a colour code such as #1a2b3c. Names must differ.', 'merchboard' ), 'error' ),
			DesignerException::BASE_SIZES           => array( __( 'Sizes must differ and have at most 20 characters.', 'merchboard' ), 'error' ),
			DesignerException::BASE_VIEW            => array( __( 'Check the sides: each needs a unique name, a background or colour mask, a print area inside the template and a print size of 1–2000 mm at 72–600 dpi that gives a print file of at most 8000 × 8000 pixels.', 'merchboard' ), 'error' ),
			DesignerException::BASE_LAYER_SIZE      => array( __( 'All layers of a side must have the same size in pixels.', 'merchboard' ), 'error' ),
			DesignerException::BASE_INCOMPLETE      => array( __( 'An active base product needs at least one colour, one size and one side.', 'merchboard' ), 'error' ),
			DesignerException::BASE_UNKNOWN         => array( __( 'The base product no longer exists.', 'merchboard' ), 'error' ),
			DesignerException::BASE_IN_USE          => array( __( 'The base product has designs and cannot be deleted.', 'merchboard' ), 'error' ),
			DesignerException::THREAD_PACK          => array( __( 'The embroidery pack does not exist.', 'merchboard' ), 'error' ),
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

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		if ( '' === $view && 0 === $id ) {
			echo '<p><a class="button" href="' . esc_url( self::edit_url() ) . '">' . esc_html__( 'New base product', 'merchboard' ) . '</a></p>';
		}

		try {
			if ( 'cliparts' === $view ) {
				$this->render_cliparts();
			} elseif ( 'new' === $view ) {
				$this->render_new();
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
	}

	/**
	 * The list.
	 *
	 * @throws DatabaseException When the base products cannot be read.
	 */
	private function render_list(): void {
		echo '<p>' . esc_html__( 'The products creators design on. The base price incl. VAT is what the company always keeps outside its own campaigns; the creator\'s profit comes on top. A base product is a draft until it is activated.', 'merchboard' ) . '</p>';

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
				esc_html( self::status( $base->status ) . self::import_label( $this->bases->import( (int) $base->id )['state'] ?? CatalogImport::NONE ) ),
				esc_html( $this->money( $base->base_units ) . ' ' . $base->currency . ( $base->extra_side_units > 0 ? ' + ' . $this->money( $base->extra_side_units ) : '' ) ),
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

		if ( null !== $base ) {
			$this->render_import( $base );
			$this->render_photo_order( $base );
		}

		if ( $designs > 0 ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Creators have designs on this base product. A new base price or template raises the version; the designer products are repriced in a later step, and sales already registered keep their amounts.', 'merchboard' ) . '</p></div>';
		}

		// What one request may hold, for the script that sends a side's colour photos in batches.
		$limits = ' data-photo-files="' . esc_attr( (string) min( self::PHOTOS_PER_REQUEST, max( 1, (int) ini_get( 'max_file_uploads' ) ) ) ) . '" data-photo-bytes="' . esc_attr( (string) wp_max_upload_size() ) . '" data-result-arg="' . esc_attr( self::RESULT_ARG ) . '"';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $limits . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		echo '<input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="do" value="save">';
		echo '<input type="hidden" name="base_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( BaseProductActions::ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="merchboard-base-name">' . esc_html__( 'Name', 'merchboard' ) . '</label></th><td><input type="text" id="merchboard-base-name" name="name" class="regular-text" maxlength="191" required value="' . esc_attr( $base->name ?? '' ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-base-description">' . esc_html__( 'Description', 'merchboard' ) . '</label></th><td><textarea id="merchboard-base-description" name="description" rows="4" class="large-text">' . esc_textarea( $base->description ?? '' ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-base-note">' . esc_html__( 'Note in the designer', 'merchboard' ) . '</label></th><td><textarea id="merchboard-base-note" name="note" rows="3" maxlength="1000" class="large-text">' . esc_textarea( $base->note ?? '' ) . '</textarea><p class="description">' . esc_html__( 'Shown to creators while they design this product. Leave it empty to show nothing.', 'merchboard' ) . '</p></td></tr>';
		/* translators: %s: currency code, e.g. "DKK". */
		echo '<tr><th scope="row"><label for="merchboard-base-price">' . esc_html( sprintf( __( 'Base price incl. VAT (%s)', 'merchboard' ), $this->currency ) ) . '</label></th><td><input type="text" inputmode="decimal" id="merchboard-base-price" name="base_price" required value="' . esc_attr( null === $base ? '' : $this->money( $base->base_units ) ) . '"><p class="description">' . esc_html__( 'What the company keeps per unit outside its own campaigns, with one design area included. Creators add their profit on top.', 'merchboard' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Price per size', 'merchboard' ) . '</th><td><label><input type="checkbox" id="merchboard-base-size-priced" name="size_priced" value="1"' . checked( array() !== ( $base->size_prices ?? array() ), true, false ) . '> ' . esc_html__( 'Different base prices per size', 'merchboard' ) . '</label>';
		echo '<div id="merchboard-base-size-prices" data-prices="' . esc_attr( (string) wp_json_encode( array_map( array( $this, 'money' ), $base->size_prices ?? array() ) ) ) . '"></div>';
		echo '<p class="description">' . esc_html__( 'Without the tick, every size has the base price above. With it, a size left empty has that price too. A creator\'s percent is taken of each size\'s price; a fixed profit is the same for every size.', 'merchboard' ) . '</p></td></tr>';
		/* translators: %s: currency code, e.g. "DKK". */
		echo '<tr><th scope="row"><label for="merchboard-base-extra">' . esc_html( sprintf( __( 'Price per extra design area incl. VAT (%s)', 'merchboard' ), $this->currency ) ) . '</label></th><td><input type="text" inputmode="decimal" id="merchboard-base-extra" name="extra_side_price" value="' . esc_attr( null === $base ? '0' : $this->money( $base->extra_side_units ) ) . '"><p class="description">' . esc_html__( 'What the company keeps for each design area after the first. 0 means extra areas cost nothing.', 'merchboard' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-base-category">' . esc_html__( 'Default category', 'merchboard' ) . '</label></th><td>' . $this->category_select( $base->category_id ?? null ) . '<p class="description">' . esc_html__( 'Used for a creator without their own product category.', 'merchboard' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- category_select() escapes.
		echo '<tr><th scope="row"><label for="merchboard-base-colors">' . esc_html__( 'Colours', 'merchboard' ) . '</label></th><td><textarea id="merchboard-base-colors" name="colors" rows="5" class="large-text code">' . esc_textarea( $colors ) . '</textarea><p class="description">' . esc_html__( 'One per line: a name and a colour code, e.g. "Black #111111".', 'merchboard' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-base-sizes">' . esc_html__( 'Sizes', 'merchboard' ) . '</label></th><td><input type="text" id="merchboard-base-sizes" name="sizes" class="regular-text" value="' . esc_attr( implode( ', ', $base->sizes ?? array() ) ) . '"><p class="description">' . esc_html__( 'Separated by commas, e.g. "S, M, L, XL". Creators and customers see them in this order.', 'merchboard' ) . '</p><div id="merchboard-base-size-order"></div></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Sides', 'merchboard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each side has a mockup template of PNG layers of the same size: the background, the colour mask (tinted in the product colour), the shading and the highlight. The print area is where the design is placed on the template (in pixels) and its real size when printed (millimetres at a resolution in dpi).', 'merchboard' ) . '</p>';

		$index = 0;

		foreach ( $base->views ?? array() as $view ) {
			$this->render_view( $index++, $view, $psd, $base->colors ?? array(), 'own' === ( $base->source ?? 'own' ) );
		}

		$this->render_view( $index, null, $psd, $base->colors ?? array(), 'own' === ( $base->source ?? 'own' ) );

		echo '<p><button type="submit" class="button button-primary">' . esc_html( null === $base ? __( 'Create base product', 'merchboard' ) : __( 'Save base product', 'merchboard' ) ) . '</button></p>';
		echo '</form>';

		if ( null !== $base ) {
			$this->render_status( $base, $designs );
		}
	}

	/**
	 * One side of the form; null for the empty "new side".
	 *
	 * @phpstan-param list<array{key: string, name: string, hex: string}> $colors
	 *
	 * @param int           $index  Index in the form.
	 * @param BaseView|null $view   The side.
	 * @param bool          $psd    Whether the PSD import is loaded.
	 * @param array[]       $colors The base product's colours (a background per colour).
	 * @param bool          $own    Whether the base product is an own one (sides per size).
	 */
	private function render_view( int $index, ?BaseView $view, bool $psd, array $colors = array(), bool $own = false ): void {
		$field = static fn( string $name ): string => 'views[' . $index . '][' . $name . ']';
		$value = static fn( ?int $number ): string => null === $number ? '' : (string) $number;

		// The script gives a stored side its arrows; the form sends the sides in this order.
		echo '<fieldset class="card" style="max-width:none;margin-bottom:1em"' . ( null === $view ? '' : ' data-merchboard-side="' . esc_attr( $view->name ) . '"' ) . '><legend><strong>' . esc_html( null === $view ? __( 'New side', 'merchboard' ) : $view->name ) . '</strong></legend>';
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
			// The field has no name: the script sends the photos in batches when the form is saved.
			echo '<p><label>' . esc_html__( 'Colour photos', 'merchboard' ) . ' <input type="file" multiple accept="image/png" data-merchboard-photos="' . esc_attr( (string) $index ) . '"></label> ';
			echo '<span class="description" id="' . esc_attr( 'merchboard-photos-status-' . $index ) . '" aria-live="polite">' . esc_html__( 'Choose all the PNG photos of this side at once. A file named after a colour, e.g. "Black.png", becomes that colour\'s background, and a new colour is added to the list. Check the small pictures: exactly these are uploaded when you save.', 'merchboard' ) . '</span></p>';
			echo '<div id="' . esc_attr( 'merchboard-photos-preview-' . $index ) . '"></div>';
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

		// A colour's own background (a photo of the product in that colour; review 30.15): used instead of the background and the tinted mask.
		foreach ( $colors as $color ) {
			$layer = BaseView::color_layer( $color['key'] );
			$asset = $view->layers[ $layer ] ?? null;
			/* translators: %s: colour name. */
			echo '<tr><th scope="row" style="width:12rem">' . esc_html( sprintf( __( 'Background in %s', 'merchboard' ), $color['name'] ) ) . '</th><td>';

			if ( null !== $asset ) {
				echo '<img src="' . esc_url( DesignerAssetDownload::url( $asset ) ) . '" alt="" style="max-width:80px;max-height:80px;vertical-align:middle"> ';
				echo '<label><input type="checkbox" name="' . esc_attr( $field( 'clear' ) ) . '[]" value="' . esc_attr( $layer ) . '"> ' . esc_html__( 'Remove', 'merchboard' ) . '</label> ';
			}

			echo '<input type="file" name="' . esc_attr( 'layer_' . $index . '_bg_' . $color['key'] ) . '" accept="image/png"></td></tr>';
		}

		if ( $psd ) {
			$this->render_scene( $index, $view );
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

		echo '</p>';

		if ( $psd ) {
			// A colour's photo first: a white sheet from an import shows nothing to draw on.
			$template = array_values( null === $view ? array() : $view->color_backgrounds() )[0] ?? $view->layers['background'] ?? $view->layers['color'] ?? null;

			echo '<div data-merchboard-area="' . esc_attr( (string) $index ) . '" data-url="' . esc_attr( null === $template ? '' : DesignerAssetDownload::url( $template ) ) . '"></div>';
		}

		echo '<p>' . esc_html__( 'Print size:', 'merchboard' ) . ' ';
		echo '<label>' . esc_html__( 'Width (mm)', 'merchboard' ) . ' <input type="number" min="1" max="' . esc_attr( (string) BaseView::MAX_MM ) . '" step="1" style="width:6rem" name="' . esc_attr( $field( 'width_mm' ) ) . '" value="' . esc_attr( $value( $view->width_mm ?? null ) ) . '"></label> ';
		echo '<label>' . esc_html__( 'Height (mm)', 'merchboard' ) . ' <input type="number" min="1" max="' . esc_attr( (string) BaseView::MAX_MM ) . '" step="1" style="width:6rem" name="' . esc_attr( $field( 'height_mm' ) ) . '" value="' . esc_attr( $value( $view->height_mm ?? null ) ) . '"></label> ';
		echo '<label>' . esc_html__( 'Resolution (dpi)', 'merchboard' ) . ' <input type="number" min="' . esc_attr( (string) BaseView::MIN_DPI ) . '" max="' . esc_attr( (string) BaseView::MAX_DPI ) . '" step="1" style="width:6rem" name="' . esc_attr( $field( 'dpi' ) ) . '" value="' . esc_attr( $value( $view->dpi ?? 300 ) ) . '"></label>';

		if ( null !== $view ) {
			[ $width, $height ] = $view->print_pixels();
			/* translators: 1: width in px, 2: height in px. */
			echo ' <span class="description">' . esc_html( sprintf( __( 'Print file: %1$d × %2$d px', 'merchboard' ), $width, $height ) ) . '</span>';
		}

		echo '</p><p><label>' . esc_html__( 'Embroidery pack', 'merchboard' ) . ' ' . self::pack_select( $field( 'thread_pack' ), $view->thread_pack ?? '' ) . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pack_select() escapes.
		echo '<span class="description">' . esc_html__( 'On an embroidered side, the design keeps to the threads of the pack.', 'merchboard' ) . '</span>';

		if ( $psd && $own ) {
			echo '</p><div data-merchboard-side-sizes="' . esc_attr( 'views[' . $index . ']' ) . '" data-chosen="' . esc_attr( (string) wp_json_encode( $view->sizes ?? array() ) ) . '"></div><p>';
		} elseif ( null !== $view && array() !== $view->sizes ) {
			// An import's sides per size are set by the provider's print sizes.
			/* translators: %s: sizes. */
			echo '</p><p class="description">' . esc_html( sprintf( __( 'Only for these sizes: %s', 'merchboard' ), implode( ', ', $view->sizes ) ) ) . '</p><p>';
		}

		if ( null !== $view ) {
			echo '</p><p><label><input type="checkbox" name="' . esc_attr( $field( 'remove' ) ) . '" value="1"> ' . esc_html__( 'Remove this side', 'merchboard' ) . '</label>';
		}

		echo '</p></fieldset>';
	}

	/**
	 * The choice of a side's embroidery pack.
	 *
	 * @param string $name    Field name.
	 * @param string $current The chosen pack.
	 * @return string Escaped HTML.
	 */
	private static function pack_select( string $name, string $current ): string {
		$html = '<select name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( 'No embroidery', 'merchboard' ) . '</option>';

		foreach ( ThreadPacks::all() as $pack ) {
			$html .= '<option value="' . esc_attr( $pack['id'] ) . '"' . selected( $current, $pack['id'], false ) . '>' . esc_html( $pack['name'] ) . '</option>';
		}

		return $html . '</select>';
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
	 * "New base product": an own one, or one picked in Printful's or
	 * Gelato's catalog (review 30.15).
	 *
	 * @throws DatabaseException When a base product cannot be read.
	 */
	private function render_new(): void {
		// Read-only parameters; nothing is changed by this request.
		$source  = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$sources = array( '' => __( 'Own base product', 'merchboard' ) );

		if ( Settings::printful_enabled() && null !== $this->printful ) {
			$sources[ PrintfulCatalog::SOURCE ] = __( 'From Printful\'s catalog', 'merchboard' );
		}

		if ( Settings::gelato_enabled() && null !== $this->gelato ) {
			$sources[ GelatoCatalog::SOURCE ] = __( 'From a Gelato template', 'merchboard' );
		}

		if ( Settings::prodigi_enabled() && null !== $this->prodigi ) {
			$sources[ ProdigiCatalog::SOURCE ] = __( 'From Prodigi\'s catalogue', 'merchboard' );
		}

		if ( Settings::printify_enabled() && null !== $this->printify ) {
			$sources[ PrintifyCatalog::SOURCE ] = __( 'From Printify\'s catalog', 'merchboard' );
		}

		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← All base products', 'merchboard' ) . '</a></p>';

		if ( count( $sources ) > 1 ) {
			$links = array();

			foreach ( $sources as $key => $label ) {
				$links[] = $key === $source ? '<strong>' . esc_html( $label ) . '</strong>' : '<a href="' . esc_url( self::new_url( array( 'source' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
			}

			echo '<p class="subsubsub" style="float:none">' . implode( ' | ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}

		if ( PrintfulCatalog::SOURCE === $source && null !== $this->printful && isset( $sources[ $source ] ) ) {
			$this->render_printful_catalog( $this->printful );
		} elseif ( GelatoCatalog::SOURCE === $source && null !== $this->gelato && isset( $sources[ $source ] ) ) {
			$this->render_gelato_template( $this->gelato );
		} elseif ( ProdigiCatalog::SOURCE === $source && null !== $this->prodigi && isset( $sources[ $source ] ) ) {
			$this->render_prodigi_catalog( $this->prodigi );
		} elseif ( PrintifyCatalog::SOURCE === $source && null !== $this->printify && isset( $sources[ $source ] ) ) {
			$this->render_printify_catalog( $this->printify );
		} else {
			$this->render_form( null );
		}
	}

	/**
	 * Printful's catalog: search by words and category, then pick a product.
	 *
	 * @param PrintfulCatalog $printful Printful's catalog.
	 */
	private function render_printful_catalog( PrintfulCatalog $printful ): void {
		if ( ! $this->printful_ready ) {
			echo '<p>' . esc_html__( 'Enter the Printful API token under Settings → Product designer first.', 'merchboard' ) . '</p>';

			return;
		}

		// Read-only parameters; nothing is changed by this request.
		$query     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$category  = isset( $_GET['category'] ) ? absint( $_GET['category'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$pick      = isset( $_GET['pick'] ) ? absint( $_GET['pick'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$group     = isset( $_GET['group'] ) ? absint( $_GET['group'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$technique = isset( $_GET['technique'] ) ? sanitize_key( wp_unslash( $_GET['technique'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$page      = isset( $_GET['catalog_page'] ) ? absint( $_GET['catalog_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		$search = array(
			'source'       => PrintfulCatalog::SOURCE,
			'q'            => $query,
			'category'     => $category,
			'technique'    => $technique,
			'catalog_page' => $page > 1 ? $page : 0,
		);

		if ( $pick > 0 ) {
			$this->render_pick( $printful, PrintfulCatalog::SOURCE, (string) $pick, $group, $search, self::new_url( $search ) );

			return;
		}

		try {
			$labels     = $printful->category_labels();
			$techniques = $printful->techniques();
			$technique  = in_array( $technique, $techniques, true ) ? $technique : '';
			$found      = $printful->browse( $query, isset( $labels[ $category ] ) ? $category : 0, $technique, $page );
			$limited    = $printful->limited_to_eu();
		} catch ( PrintfulException $e ) {
			$this->provider_error( $e->reason(), $e->call, $e->detail );

			return;
		}

		echo '<p>' . esc_html__( 'Pick the product. Only products sold in Europe that can be sent to Denmark are listed. Printful renders it without a design in every colour you tick; those photos are what creators design on, and the print areas are measured on them. All-over products are designed on their flat sewing pattern instead.', 'merchboard' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( DesignerMenu::SLUG ) . '"><input type="hidden" name="tab" value="' . esc_attr( DesignerMenu::BASES ) . '"><input type="hidden" name="view" value="new"><input type="hidden" name="source" value="' . esc_attr( PrintfulCatalog::SOURCE ) . '">';
		echo '<p><label>' . esc_html__( 'Search', 'merchboard' ) . ' <input type="search" name="q" value="' . esc_attr( $query ) . '" class="regular-text"></label> ';
		echo '<label>' . esc_html__( 'Category', 'merchboard' ) . ' <select name="category"><option value="0">' . esc_html__( '— All —', 'merchboard' ) . '</option>';

		foreach ( $labels as $id => $label ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $category, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></label> ';

		if ( array() !== $techniques ) {
			echo '<label>' . esc_html__( 'Technique', 'merchboard' ) . ' <select name="technique"><option value="">' . esc_html__( '— All —', 'merchboard' ) . '</option>';

			foreach ( $techniques as $key ) {
				echo '<option value="' . esc_attr( $key ) . '"' . selected( $technique, $key, false ) . '>' . esc_html( self::technique_label( $key ) ) . '</option>';
			}

			echo '</select></label> ';
		}

		echo '<button type="submit" class="button">' . esc_html__( 'Show', 'merchboard' ) . '</button></p></form>';

		if ( ! $limited ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Printful\'s newest API does not answer right now, so the list is not limited to Europe. A product you pick is still checked.', 'merchboard' ) . '</p></div>';
		}

		/* translators: %d: number of products. */
		echo '<p class="description">' . esc_html( sprintf( _n( '%d product.', '%d products.', $found['total'], 'merchboard' ), $found['total'] ) ) . '</p>';

		if ( array() === $found['products'] ) {
			echo '<p>' . esc_html__( 'No products found.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<ul style="display:grid;grid-template-columns:repeat(auto-fill,minmax(10rem,1fr));gap:12px;list-style:none;margin:1em 0;padding:0">';

		foreach ( $found['products'] as $product ) {
			echo '<li class="card" style="margin:0;padding:8px;min-width:0"><a href="' . esc_url( self::new_url( array_merge( $search, array( 'pick' => $product['id'] ) ) ) ) . '" style="display:block;text-decoration:none">';

			if ( '' !== $product['image'] ) {
				echo '<img src="' . esc_url( $product['image'] ) . '" alt="" loading="lazy" style="display:block;width:100%;height:auto;aspect-ratio:1;object-fit:contain">';
			}

			echo '<strong style="display:block;margin-top:6px">' . esc_html( $product['title'] ) . '</strong><span class="description">' . esc_html( trim( $product['brand'] . ' ' . $product['model'] ) ) . '</span>';

			if ( array() !== $product['techniques'] ) {
				echo '<p class="description" style="margin:4px 0 0;font-size:12px">' . esc_html( implode( ', ', array_map( array( self::class, 'technique_label' ), $product['techniques'] ) ) ) . '</p>';
			}

			echo '</a></li>';
		}

		echo '</ul>';
		echo self::pager( $search, $found['page'], $found['pages'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pager() escapes.
	}

	/**
	 * A technique of Printful's for the administrator.
	 *
	 * @param string $key Technique key.
	 */
	private static function technique_label( string $key ): string {
		return match ( $key ) {
			'dtg'         => __( 'Direct-to-garment print', 'merchboard' ),
			'embroidery'  => __( 'Embroidery', 'merchboard' ),
			'cut-sew'     => __( 'All-over print (cut and sewn)', 'merchboard' ),
			'sublimation' => __( 'Sublimation', 'merchboard' ),
			'dtfilm'      => __( 'Print on film (DTF)', 'merchboard' ),
			'digital'     => __( 'Digital print', 'merchboard' ),
			'uv'          => __( 'UV print', 'merchboard' ),
			'knitwear'    => __( 'Knitwear', 'merchboard' ),
			default       => ucfirst( str_replace( array( '-', '_' ), ' ', $key ) ),
		};
	}

	/**
	 * Links to the previous and next page of a catalog.
	 *
	 * @param array<string, int|string> $args  The catalog's query arguments.
	 * @param int                       $page  Page.
	 * @param int                       $pages Pages.
	 */
	private static function pager( array $args, int $page, int $pages ): string {
		if ( $pages < 2 ) {
			return '';
		}

		$links = array();

		if ( $page > 1 ) {
			$links[] = '<a class="button" href="' . esc_url( self::new_url( array_merge( $args, array( 'catalog_page' => $page - 1 ) ) ) ) . '">' . esc_html__( '← Previous', 'merchboard' ) . '</a>';
		}

		/* translators: 1: page, 2: pages. */
		$links[] = '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'merchboard' ), $page, $pages ) ) . '</span>';

		if ( $page < $pages ) {
			$links[] = '<a class="button" href="' . esc_url( self::new_url( array_merge( $args, array( 'catalog_page' => $page + 1 ) ) ) ) . '">' . esc_html__( 'Next →', 'merchboard' ) . '</a>';
		}

		return '<p style="display:flex;gap:1em;align-items:center">' . implode( ' ', $links ) . '</p>';
	}

	/**
	 * Prodigi's catalogue: search by words or category, then pick a product.
	 * Only products made in the EU are listed.
	 *
	 * @param ProdigiCatalog $prodigi Prodigi's catalogue.
	 */
	private function render_prodigi_catalog( ProdigiCatalog $prodigi ): void {
		if ( ! $this->prodigi_ready ) {
			echo '<p>' . esc_html__( 'Enter the Prodigi API key under Settings → Product designer first.', 'merchboard' ) . '</p>';

			return;
		}

		// Read-only parameters; nothing is changed by this request.
		$query    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$pick     = isset( $_GET['pick'] ) ? sanitize_text_field( wp_unslash( $_GET['pick'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$group    = isset( $_GET['group'] ) ? absint( $_GET['group'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		if ( '' !== $pick ) {
			$this->render_pick( $prodigi, ProdigiCatalog::SOURCE, $pick, $group );

			return;
		}

		echo '<p>' . esc_html__( 'Search Prodigi\'s catalogue and pick the product. Only products made in the EU are listed. Creators design on a white sheet in the shape of the print area, and Prodigi makes the mockups when a design is sent for approval.', 'merchboard' ) . '</p>';

		try {
			$categories = $prodigi->categories();
		} catch ( ProdigiException $e ) {
			$this->provider_error( $e->reason(), $e->call, $e->detail );

			return;
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( DesignerMenu::SLUG ) . '"><input type="hidden" name="tab" value="' . esc_attr( DesignerMenu::BASES ) . '"><input type="hidden" name="view" value="new"><input type="hidden" name="source" value="' . esc_attr( ProdigiCatalog::SOURCE ) . '">';
		echo '<p><label>' . esc_html__( 'Search', 'merchboard' ) . ' <input type="search" name="q" value="' . esc_attr( $query ) . '" class="regular-text"></label> ';
		echo '<label>' . esc_html__( 'Category', 'merchboard' ) . ' <select name="category"><option value="">' . esc_html__( '— All —', 'merchboard' ) . '</option>';

		foreach ( $categories as $entry ) {
			echo '<option value="' . esc_attr( $entry['id'] ) . '"' . selected( $category, $entry['id'], false ) . '>' . esc_html( $entry['name'] ) . '</option>';
		}

		echo '</select></label> <button type="submit" class="button">' . esc_html__( 'Search', 'merchboard' ) . '</button></p></form>';

		if ( '' === $query && '' === $category ) {
			return;
		}

		try {
			$products = $prodigi->search( $query, $category );
		} catch ( ProdigiException $e ) {
			$this->provider_error( $e->reason(), $e->call, $e->detail );

			return;
		}

		if ( array() === $products ) {
			echo '<p>' . esc_html__( 'No products found.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<ul>';

		foreach ( $products as $product ) {
			echo '<li><a href="' . esc_url( self::new_url( array( 'source' => ProdigiCatalog::SOURCE, 'pick' => $product['id'] ) ) ) . '">' . esc_html( $product['title'] ) . '</a></li>'; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		echo '</ul>';

		if ( count( $products ) >= ProdigiCatalog::MAX_RESULTS ) {
			echo '<p class="description">' . esc_html__( 'Only the first results are shown. Add words to narrow the search.', 'merchboard' ) . '</p>';
		}
	}

	/**
	 * Printify's catalog: search the words, pick a product, then its producer.
	 * Producers anywhere are offered, the ones in the EU first.
	 *
	 * @param PrintifyCatalog $printify Printify's catalog.
	 */
	private function render_printify_catalog( PrintifyCatalog $printify ): void {
		if ( ! $this->printify_ready ) {
			echo '<p>' . esc_html__( 'Enter the Printify access token under Settings → Product designer first.', 'merchboard' ) . '</p>';

			return;
		}

		// Read-only parameters; nothing is changed by this request.
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$pick  = isset( $_GET['pick'] ) ? sanitize_text_field( wp_unslash( $_GET['pick'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		// Not "blueprint": WooCommerce stops every admin page with that parameter.
		$blueprint = isset( $_GET['printify_product'] ) ? absint( $_GET['printify_product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$group     = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$eu_only   = isset( $_GET['eu'] ) && '1' === $_GET['eu']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$page      = isset( $_GET['catalog_page'] ) ? absint( $_GET['catalog_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$producer  = isset( $_GET['producer'] ) ? absint( $_GET['producer'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$groups    = self::printify_groups();
		$group     = isset( $groups[ $group ] ) ? $group : '';
		$args      = array(
			'source'       => PrintifyCatalog::SOURCE,
			'q'            => $query,
			'group'        => $group,
			'eu'           => $eu_only ? '1' : '',
			'producer'     => $producer,
			'catalog_page' => $page > 1 ? $page : 0,
		);

		if ( '' !== $pick ) {
			// From the producers of a product back to them, else back to the catalog.
			$this->render_pick( $printify, PrintifyCatalog::SOURCE, $pick, 0, array(), self::new_url( array_merge( $args, array( 'printify_product' => $blueprint ) ) ) );

			return;
		}

		try {
			if ( $blueprint > 0 ) {
				$this->render_printify_producers( $printify, $blueprint, $eu_only, $args );

				return;
			}

			$producers = $printify->all_producers( $eu_only );
			$producer  = in_array( $producer, array_column( $producers, 'id' ), true ) ? $producer : 0;
			$found     = $printify->browse( $query, $group, $eu_only, $page, $producer );
		} catch ( PrintifyException $e ) {
			$this->provider_error( $e->reason(), $e->call, $e->detail );

			return;
		}

		echo '<p>' . esc_html__( 'Pick a product, then its producer. The import brings the colours, sizes and print areas. You add your own photos afterwards and draw the print area on them.', 'merchboard' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( DesignerMenu::SLUG ) . '"><input type="hidden" name="tab" value="' . esc_attr( DesignerMenu::BASES ) . '"><input type="hidden" name="view" value="new"><input type="hidden" name="source" value="' . esc_attr( PrintifyCatalog::SOURCE ) . '">';
		echo '<p><label>' . esc_html__( 'Search', 'merchboard' ) . ' <input type="search" name="q" value="' . esc_attr( $query ) . '" class="regular-text"></label> ';
		echo '<label>' . esc_html__( 'Category', 'merchboard' ) . ' <select name="group"><option value="">' . esc_html__( '— All —', 'merchboard' ) . '</option>';

		foreach ( $groups as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $group, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></label> <label>' . esc_html__( 'Producer', 'merchboard' ) . ' <select name="producer"><option value="">' . esc_html__( '— All —', 'merchboard' ) . '</option>';

		foreach ( $producers as $entry ) {
			echo '<option value="' . esc_attr( (string) $entry['id'] ) . '"' . selected( $producer, $entry['id'], false ) . '>' . esc_html( self::with_country( $entry['title'], $entry['country'] ) ) . '</option>';
		}

		echo '</select></label> <label><input type="checkbox" name="eu" value="1"' . checked( $eu_only, true, false ) . '> ' . esc_html__( 'Only producers in the EU', 'merchboard' ) . '</label> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Show', 'merchboard' ) . '</button></p></form>';

		/* translators: %d: number of products. */
		echo '<p class="description">' . esc_html( sprintf( _n( '%d product.', '%d products.', $found['total'], 'merchboard' ), $found['total'] ) ) . ( $eu_only ? ' ' . esc_html__( 'A producer is in the EU by the address Printify shows for it.', 'merchboard' ) : '' ) . '</p>';

		if ( ! $found['complete'] ) {
			echo '<p class="description">' . esc_html__( 'The producers are being read in the background. Reload the page in a minute to see all of them on the products.', 'merchboard' ) . '</p>';
		}

		if ( array() === $found['products'] ) {
			echo '<p>' . esc_html__( 'No products found.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<ul style="display:grid;grid-template-columns:repeat(auto-fill,minmax(10rem,1fr));gap:12px;list-style:none;margin:1em 0;padding:0">';

		foreach ( $found['products'] as $product ) {
			// With a producer chosen, a product goes straight to the import.
			$target = array_merge( $args, $producer > 0 ? array( 'pick' => $product['id'] . '-' . $producer ) : array( 'printify_product' => $product['id'] ) );

			echo '<li class="card" style="margin:0;padding:8px;min-width:0"><a href="' . esc_url( self::new_url( $target ) ) . '" style="display:block;text-decoration:none">';
			echo '<img src="' . esc_url( PrintifyThumbnails::url( $product['id'] ) ) . '" alt="" loading="lazy" width="' . esc_attr( (string) PrintifyCatalog::THUMB ) . '" height="' . esc_attr( (string) PrintifyCatalog::THUMB ) . '" style="display:block;width:100%;height:auto;aspect-ratio:1;object-fit:contain">';
			echo '<strong style="display:block;margin-top:6px">' . esc_html( $product['title'] ) . '</strong><span class="description">' . esc_html( $product['brand'] ) . '</span></a>';
			echo self::producer_line( $product['producers'] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- producer_line() escapes.
		}

		echo '</ul>';

		echo self::pager( $args, $found['page'], $found['pages'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pager() escapes.
	}

	/**
	 * The producers of a product on its card: the first three with their
	 * country, the ones in the EU first, and how many more there are.
	 *
	 * @param array<array{id: int, title: string, country: string, eu: bool}> $producers Producers.
	 */
	private static function producer_line( array $producers ): string {
		if ( array() === $producers ) {
			return '';
		}

		$names = array_map( static fn( array $producer ): string => self::with_country( $producer['title'], $producer['country'] ), array_slice( $producers, 0, 3 ) );
		$more  = count( $producers ) - count( $names );

		/* translators: %d: number of further producers. */
		return '<p class="description" style="margin:4px 0 0;font-size:12px">' . esc_html( implode( ', ', $names ) . ( $more > 0 ? ' ' . sprintf( __( '+%d more', 'merchboard' ), $more ) : '' ) ) . '</p>';
	}

	/**
	 * A button one step back while a base product is made.
	 *
	 * @param string $url The step before.
	 */
	private static function back_button( string $url ): string {
		return '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( '← Back', 'merchboard' ) . '</a></p>';
	}

	/**
	 * A producer's name with its country, when it has one.
	 *
	 * @param string $title   Name.
	 * @param string $country Country code.
	 */
	private static function with_country( string $title, string $country ): string {
		return '' === $country ? $title : $title . ' (' . $country . ')';
	}

	/**
	 * The groups of Printify's catalog by name, for the administrator.
	 *
	 * @return array<string, string>
	 */
	private static function printify_groups(): array {
		return array(
			'tshirts'     => __( 'T-shirts', 'merchboard' ),
			'hoodies'     => __( 'Hoodies', 'merchboard' ),
			'sweatshirts' => __( 'Sweatshirts', 'merchboard' ),
			'clothing'    => __( 'Other clothing and shoes', 'merchboard' ),
			'headwear'    => __( 'Caps and hats', 'merchboard' ),
			'drinkware'   => __( 'Mugs and bottles', 'merchboard' ),
			'bags'        => __( 'Bags', 'merchboard' ),
			'wall'        => __( 'Posters and wall art', 'merchboard' ),
			'tech'        => __( 'Phone and tech', 'merchboard' ),
			'home'        => __( 'Home and kitchen', 'merchboard' ),
			'paper'       => __( 'Stickers and paper', 'merchboard' ),
			'accessories' => __( 'Jewellery and accessories', 'merchboard' ),
			'pets'        => __( 'Pets', 'merchboard' ),
			'other'       => __( 'Other', 'merchboard' ),
		);
	}

	/**
	 * The producers of a Printify product.
	 *
	 * @param PrintifyCatalog $printify  Printify's catalog.
	 * @param int             $blueprint The product.
	 * @param bool            $eu_only   Only producers in the EU.
	 * @param array           $args      The catalog's filters, which go along.
	 * @throws PrintifyException When Printify refuses or fails.
	 *
	 * @phpstan-param array<string, int|string> $args
	 */
	private function render_printify_producers( PrintifyCatalog $printify, int $blueprint, bool $eu_only, array $args ): void {
		$producers = $printify->producers( $blueprint, $eu_only );

		echo self::back_button( self::new_url( $args ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- back_button() escapes.
		echo '<img src="' . esc_url( PrintifyThumbnails::url( $blueprint ) ) . '" alt="" width="' . esc_attr( (string) PrintifyCatalog::THUMB ) . '" height="' . esc_attr( (string) PrintifyCatalog::THUMB ) . '" style="max-width:100%;height:auto">';

		if ( array() === $producers ) {
			echo '<p>' . esc_html__( 'No producer makes this product.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<p>' . esc_html__( 'Choose the producer. Colours, sizes and print areas differ between them. The ones in the EU are listed first; the place is the address Printify shows for the producer.', 'merchboard' ) . '</p><ul>';

		foreach ( $producers as $producer ) {
			echo '<li><a href="' . esc_url( self::new_url( array_merge( $args, array( 'printify_product' => $blueprint, 'pick' => $blueprint . '-' . $producer['id'] ) ) ) ) . '">' . esc_html( $producer['title'] ) . '</a> ' . esc_html( trim( $producer['city'] . ', ' . $producer['country'], ', ' ) ) . '</li>'; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		echo '</ul>';
	}

	/**
	 * A Gelato template by its id: Gelato has no call that lists templates or
	 * shows its products, and only a template can be rendered.
	 *
	 * @param GelatoCatalog $gelato Gelato.
	 */
	private function render_gelato_template( GelatoCatalog $gelato ): void {
		if ( ! $this->gelato_ready ) {
			echo '<p>' . esc_html__( 'Enter the Gelato API key and store id under Settings → Product designer first.', 'merchboard' ) . '</p>';

			return;
		}

		// A read-only parameter; nothing is changed by this request.
		$template = isset( $_GET['template'] ) ? sanitize_text_field( wp_unslash( $_GET['template'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		if ( '' !== $template ) {
			$this->render_pick( $gelato, GelatoCatalog::SOURCE, $template, 0, array(), self::new_url( array( 'source' => GelatoCatalog::SOURCE ) ) );

			return;
		}

		echo '<p>' . esc_html__( 'Gelato renders products only from a template. Make one template in the Gelato dashboard of the product you want, with the colours and sizes creators may choose, one named image layer per side and at least one mockup: Gelato makes the photos from the mockups in the template. Any design in it is never shown: we replace it with an empty file and later with the creators\' print files. The template id is in the template\'s address at Gelato.', 'merchboard' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( DesignerMenu::SLUG ) . '"><input type="hidden" name="tab" value="' . esc_attr( DesignerMenu::BASES ) . '"><input type="hidden" name="view" value="new"><input type="hidden" name="source" value="' . esc_attr( GelatoCatalog::SOURCE ) . '">';
		echo '<p><label>' . esc_html__( 'Gelato template id', 'merchboard' ) . ' <input type="text" name="template" required class="regular-text" pattern="[A-Za-z0-9-]{1,100}" autocomplete="off"></label> <button type="submit" class="button">' . esc_html__( 'Fetch', 'merchboard' ) . '</button></p></form>';
	}

	/**
	 * The picked product: tick its colours, sizes and sides, set the base
	 * price and create it.
	 *
	 * @param CatalogRenderer $renderer The provider.
	 * @param string          $source   `printful` or `gelato`.
	 * @param string          $ref      The provider's product or template.
	 * @param int             $group    The group of sizes with the same print sizes.
	 * @param array           $keep     Query arguments that go along to another group of sizes.
	 * @param string          $back     The step before; '' for no button.
	 *
	 * @phpstan-param array<string, int|string> $keep
	 */
	private function render_pick( CatalogRenderer $renderer, string $source, string $ref, int $group = 0, array $keep = array(), string $back = '' ): void {
		if ( '' !== $back ) {
			echo self::back_button( $back ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- back_button() escapes.
		}

		try {
			$choice = $renderer->choice( $ref, $group );
		} catch ( PrintfulException | GelatoException | ProdigiException | PrintifyException $e ) {
			$this->provider_error( $e->reason(), $e->call, $e->detail );

			return;
		}

		$flat    = true === ( $choice['flat'] ?? false );
		$printed = true === ( $choice['printed'] ?? false );
		// Sizes in inches are shown in centimetres unless inches are asked for (the owner's choice 2026-11-02).
		$inches = in_array( $source, array( PrintfulCatalog::SOURCE, PrintifyCatalog::SOURCE ), true ) && SizeUnits::has_inches( $choice['sizes'] );
		$in_cm  = $inches && ! ( isset( $_GET['units'] ) && 'in' === $_GET['units'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$choice = $in_cm ? SizeUnits::choice( $choice ) : $choice;

		$defaults = array_values( array_intersect( array( 'front', 'back' ), array_column( $choice['sides'], 'placement' ) ) );
		$defaults = PrintfulCatalog::SOURCE === $source && true !== ( $choice['flat'] ?? false ) ? $defaults : array_column( $choice['sides'], 'placement' );
		$defaults = array() === $defaults ? array_slice( array_column( $choice['sides'], 'placement' ), 0, 1 ) : $defaults;

		echo '<h2>' . esc_html( $choice['name'] ) . '</h2>';

		if ( $inches ) {
			$link = $in_cm ? '<a href="' . esc_url( add_query_arg( 'units', 'in' ) ) . '">' . esc_html__( 'Show the sizes in inches', 'merchboard' ) . '</a>' : '<a href="' . esc_url( remove_query_arg( 'units' ) ) . '">' . esc_html__( 'Show the sizes in centimetres', 'merchboard' ) . '</a>';
			$note = $in_cm && SizeUnits::has_inches( $choice['sizes'] ) ? __( 'Two sizes would get the same name in whole centimetres, so they stay in inches.', 'merchboard' ) : ( $in_cm ? __( 'The sizes are shown in whole centimetres.', 'merchboard' ) : __( 'The sizes are shown in inches.', 'merchboard' ) );

			echo '<p>' . esc_html( $note ) . ' ' . $link . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}

		if ( '' !== $choice['image'] && 'https' === wp_parse_url( $choice['image'], PHP_URL_SCHEME ) ) {
			echo '<img src="' . esc_url( $choice['image'] ) . '" alt="" style="max-width:12rem;max-height:12rem;object-fit:contain">';
		}

		if ( array() !== ( $choice['groups'] ?? array() ) ) {
			$links = array();

			foreach ( $choice['groups'] as $index => $label ) {
				$links[] = ( $choice['group'] ?? 0 ) === $index ? '<strong>' . esc_html( $label ) . '</strong>' : '<a href="' . esc_url( self::new_url( array_merge( $keep, array( 'source' => $source, 'pick' => $ref, 'group' => $index ) ) ) ) . '">' . esc_html( $label ) . '</a>'; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}

			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The print size depends on the size. A base product holds one print size: make one base product per size you want to offer.', 'merchboard' ) . '</p><p>' . implode( ' | ', $links ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="do" value="catalog_create">';
		echo '<input type="hidden" name="source" value="' . esc_attr( $source ) . '"><input type="hidden" name="ref" value="' . esc_attr( $ref ) . '"><input type="hidden" name="group" value="' . esc_attr( (string) ( $choice['group'] ?? 0 ) ) . '"><input type="hidden" name="units" value="' . esc_attr( $in_cm ? 'cm' : 'in' ) . '">';
		wp_nonce_field( BaseProductActions::ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Colours', 'merchboard' ) . '</th><td>';

		foreach ( $choice['colors'] as $color ) {
			echo '<label style="display:inline-block;margin:0 1em .4em 0"><input type="checkbox" name="colors[]" value="' . esc_attr( $color['name'] ) . '" checked> <span style="display:inline-block;width:1em;height:1em;vertical-align:middle;border:1px solid #8c8f94;background:' . esc_attr( $color['hex'] ) . '"></span> ' . esc_html( $color['name'] ) . '</label>';
		}

		echo '</td></tr><tr><th scope="row">' . esc_html__( 'Sizes', 'merchboard' ) . '</th><td>';

		foreach ( $choice['sizes'] as $size ) {
			echo '<label style="display:inline-block;margin:0 1em .4em 0"><input type="checkbox" name="sizes[]" value="' . esc_attr( $size ) . '" checked> ' . esc_html( $size ) . '</label>';
		}

		echo '</td></tr><tr><th scope="row">' . esc_html__( 'Sides', 'merchboard' ) . '</th><td>';

		$by_placement = array();

		// One tick per placement: a placement with sides per group of sizes takes them all.
		foreach ( $choice['sides'] as $side ) {
			$by_placement[ $side['placement'] ][] = $side;
		}

		foreach ( $by_placement as $group ) {
			$side = $group[0];
			/* translators: 1: side name, 2: width in mm, 3: height in mm. */
			$label    = implode( '; ', array_map( static fn( array $one ): string => sprintf( __( '%1$s (%2$d × %3$d mm)', 'merchboard' ), $one['name'], $one['width_mm'], $one['height_mm'] ), $group ) );
			$stitched = GelatoCatalog::SOURCE === $source && 1 === preg_match( '/embroider/i', $side['name'] . ' ' . $side['placement'] );
			$first    = ThreadPacks::all()[0]['id'];
			echo '<p style="margin:0 0 .4em"><label><input type="checkbox" name="sides[]" value="' . esc_attr( $side['placement'] ) . '"' . checked( in_array( $side['placement'], $defaults, true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';

			if ( ! $flat ) {
				echo '<label>' . esc_html__( 'Embroidery pack', 'merchboard' ) . ' ' . self::pack_select( 'packs[' . $side['placement'] . ']', $stitched ? $first : '' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pack_select() escapes.
			}

			echo '</p>';
		}

		if ( count( $choice['sides'] ) > count( ImageProbe::MARKERS ) ) {
			/* translators: 1: sides of the product, 2: the most sides of a base product. */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'The product has %1$d sides with its groups of sizes, and a base product holds %2$d. Only the first ones of the ticked placements are imported: untick a placement you do not need.', 'merchboard' ), count( $choice['sides'] ), count( ImageProbe::MARKERS ) ) ) . '</p></div>';
		}

		echo '<p class="description">' . esc_html( $printed ? __( 'At most six sides. Each starts as a white sheet with the smallest print size of the sizes you can choose, which fits them all at 300 dpi.', 'merchboard' ) : ( $flat ? __( 'Each side is a flat part of the sewing pattern. Keep every part you want printed: an unticked part stays white on the product.', 'merchboard' ) : __( 'At most six sides. Each is rendered and measured; a side whose print area cannot be found on the photos is left out.', 'merchboard' ) ) ) . '</p></td></tr>';

		$this->render_photos( $choice['photos'] ?? array() );

		if ( GelatoCatalog::SOURCE === $source ) {
			$layers = array_map(
				/* translators: 1: side name, 2: width in mm, 3: height in mm. */
				static fn( array $side ): string => sprintf( __( '%1$s (%2$d × %3$d mm)', 'merchboard' ), $side['name'], $side['width_mm'], $side['height_mm'] ),
				$choice['sides']
			);

			/* translators: %s: the template's image layers with their sizes. */
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( sprintf( __( 'Image layers in the template: %s', 'merchboard' ), implode( ', ', $layers ) ) ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Check in Gelato that each image layer fills the whole print area. Creators can only design on this size, and we cannot check it ourselves, because Gelato does not tell us the largest area. If you change the template, make a new import.', 'merchboard' ) . '</p></div>';
		}

		/* translators: %s: currency code, e.g. "DKK". */
		echo '<tr><th scope="row"><label for="merchboard-base-price">' . esc_html( sprintf( __( 'Base price incl. VAT (%s)', 'merchboard' ), $this->currency ) ) . '</label></th><td><input type="text" inputmode="decimal" id="merchboard-base-price" name="base_price" required></td></tr>';
		/* translators: %s: currency code, e.g. "DKK". */
		echo '<tr><th scope="row"><label for="merchboard-base-extra">' . esc_html( sprintf( __( 'Price per extra design area incl. VAT (%s)', 'merchboard' ), $this->currency ) ) . '</label></th><td><input type="text" inputmode="decimal" id="merchboard-base-extra" name="extra_side_price" value="0"><p class="description">' . esc_html( $flat ? __( 'What the company keeps for each part after the first. An all-over design usually covers every part: 0 means they cost nothing extra.', 'merchboard' ) : __( 'What the company keeps for each design area after the first. 0 means extra areas cost nothing.', 'merchboard' ) ) . '</p></td></tr>';
		echo '</tbody></table>';
		echo '<p>' . esc_html( $printed ? __( 'The base product is created as a draft at once. Then choose your own photos on each side and draw the print area on them.', 'merchboard' ) : ( $flat ? __( 'The base product is created as a draft at once. Its sewing patterns, if it has any, are fetched in the background. Creators design on the flat parts; the provider makes their mockups when they are sent for approval.', 'merchboard' ) : __( 'The base product is created as a draft at once. The provider then renders its photos without a design and with a marker per side, from which the photos per colour and the print areas are taken, in the background (a few minutes). Check them and activate it.', 'merchboard' ) ) ) . '</p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create and import', 'merchboard' ) . '</button></p></form>';
	}

	/**
	 * The photos Printful can make of the product's designs, by category.
	 *
	 * @param array<array{id: int, category: string, view: string, default: bool}> $photos What the product offers.
	 */
	private function render_photos( array $photos ): void {
		if ( array() === $photos ) {
			return;
		}

		$categories = array();

		foreach ( $photos as $photo ) {
			$categories[ $photo['category'] ][] = $photo;
		}

		echo '<tr><th scope="row">' . esc_html__( 'Mockups', 'merchboard' ) . '</th><td>';

		foreach ( $categories as $category => $rows ) {
			$open = array() !== array_filter( array_column( $rows, 'default' ) );

			echo '<details style="margin:0 0 .4em"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( (string) $category ) . '</summary>';

			foreach ( $rows as $photo ) {
				echo '<label style="display:inline-block;margin:.2em 1em .2em 0"><input type="checkbox" name="photos[]" value="' . esc_attr( (string) $photo['id'] ) . '"' . checked( $photo['default'], true, false ) . '> ' . esc_html( $photo['view'] ) . '</label>';
			}

			echo '</details>';
		}

		/* translators: %d: the most photos of a design. */
		echo '<p class="description">' . esc_html( sprintf( __( 'The photos Printful makes of each design sent for approval. They become the product\'s images in the shop, in place of the mockups the creator\'s browser made, and each is made per colour. At most %d: more are left out.', 'merchboard' ), CatalogImport::MAX_PHOTOS ) ) . '</p></td></tr>';
	}

	/**
	 * A side's mockup PSD: read in the browser only, which fills the hidden
	 * fields with the scene and its image. The PSD itself is never sent.
	 *
	 * @param int           $index The side's index in the form.
	 * @param BaseView|null $view  The side.
	 */
	private function render_scene( int $index, ?BaseView $view ): void {
		$asset = $view->layers[ BaseView::SCENE ] ?? null;

		echo '<tr><th scope="row" style="width:12rem">' . esc_html__( 'Mockup PSD', 'merchboard' ) . '</th><td>';

		if ( null !== $asset ) {
			echo '<strong>' . esc_html__( 'Imported.', 'merchboard' ) . '</strong> ';
			echo '<label><input type="checkbox" name="' . esc_attr( 'views[' . $index . '][clear]' ) . '[]" value="' . esc_attr( BaseView::SCENE ) . '"> ' . esc_html__( 'Remove', 'merchboard' ) . '</label> ';
		}

		echo '<input type="file" accept=".psd,image/vnd.adobe.photoshop" data-merchboard-scene="' . esc_attr( (string) $index ) . '">';
		echo '<input type="file" name="' . esc_attr( 'layer_' . $index . '_' . BaseView::SCENE ) . '" accept="image/png" hidden>';
		echo '<input type="hidden" name="' . esc_attr( 'views[' . $index . '][scene]' ) . '" value="">';
		echo '<p><input type="hidden" name="' . esc_attr( 'views[' . $index . '][scene_preview]' ) . '" value="0"><label><input type="checkbox" name="' . esc_attr( 'views[' . $index . '][scene_preview]' ) . '" value="1"' . checked( true === ( $view->scene['preview'] ?? false ), true, false ) . '> ' . esc_html__( 'Let creators see this mockup while they design ("See the finished product")', 'merchboard' ) . '</label></p>';
		echo '<p class="description" id="' . esc_attr( 'merchboard-scene-status-' . $index ) . '" aria-live="polite">' . esc_html__( 'For a product whose mockup wraps the design, like a mug. Smart objects with "design" in their name take the print file: "højre" shows its right end, "venstre" its left end. Each design then gets this mockup instead of the one drawn on the template.', 'merchboard' ) . '</p></td></tr>';
	}

	/**
	 * The order of the photos Printful makes of a design: only the
	 * administrator sets it, and it can be changed at any time.
	 *
	 * @param BaseProduct $base Base product.
	 * @throws DatabaseException When its photos cannot be read.
	 */
	private function render_photo_order( BaseProduct $base ): void {
		$provider = $this->bases->provider( (int) $base->id );
		$photos   = $provider['photos'] ?? array();

		if ( 2 > count( $photos ) ) {
			return;
		}

		try {
			$names = null === $this->printful ? array() : $this->printful->style_names( (int) ( $provider['ref'] ?? 0 ) );
		} catch ( PrintfulException ) {
			$names = array();
		}

		echo '<h2>' . esc_html__( 'Order of the mockups', 'merchboard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The order of the photos in the shop. The first is the main image. Designs already sent keep theirs.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="do" value="photo_move">';
		echo '<input type="hidden" name="base_id" value="' . esc_attr( (string) $base->id ) . '">';
		wp_nonce_field( BaseProductActions::ACTION );
		echo '<ol>';

		foreach ( $photos as $index => $photo ) {
			/* translators: %s: the photo's id. */
			$name = $names[ $photo ] ?? sprintf( __( 'Mockup %s', 'merchboard' ), $photo );

			echo '<li>' . esc_html( $name ) . ' ';
			/* translators: %s: the photo's name. */
			echo '<button type="submit" class="button button-small" name="move" value="' . esc_attr( $photo . ':up' ) . '" aria-label="' . esc_attr( sprintf( __( 'Move %s up', 'merchboard' ), $name ) ) . '"' . disabled( 0 === $index, true, false ) . '>↑</button> ';
			/* translators: %s: the photo's name. */
			echo '<button type="submit" class="button button-small" name="move" value="' . esc_attr( $photo . ':down' ) . '" aria-label="' . esc_attr( sprintf( __( 'Move %s down', 'merchboard' ), $name ) ) . '"' . disabled( count( $photos ) - 1 === $index, true, false ) . '>↓</button></li>';
		}

		echo '</ol></form>';
	}

	/**
	 * The import of a base product from a catalog: its state, a failure's
	 * reason with "Try again", and on request the provider's answers.
	 *
	 * @param BaseProduct $base Base product.
	 * @throws DatabaseException When the import cannot be read.
	 */
	private function render_import( BaseProduct $base ): void {
		$import = $this->bases->import( (int) $base->id );
		$state  = $import['state'] ?? CatalogImport::NONE;
		$data   = $import['data'] ?? array();
		$name   = CatalogImport::provider_name( (string) ( $data['source'] ?? $base->source ) );

		if ( CatalogImport::NONE === $state ) {
			return;
		}

		if ( CatalogImport::RUNNING === $state ) {
			/* translators: %s: Printful or Gelato. */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'The photos and print areas are being imported from %s in the background. The status below updates by itself; the base product cannot be activated meanwhile.', 'merchboard' ), $name ) ) . '</p></div>';
			$this->enqueue_import_status( (int) $base->id );
		} elseif ( CatalogImport::FAILED === $state ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( /* translators: 1: Printful or Gelato, 2: reason. */ __( 'The import from %1$s failed: %2$s', 'merchboard' ), $name, DesignerNotifications::reason( is_string( $data['error'] ?? null ) ? $data['error'] : 'error' ) ) ) . '</p>';

			foreach ( array( 'call', 'detail' ) as $field ) {
				if ( is_string( $data[ $field ] ?? null ) && '' !== $data[ $field ] ) {
					echo '<p><code>' . esc_html( $data[ $field ] ) . '</code></p>';
				}
			}

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="do" value="import_retry"><input type="hidden" name="base_id" value="' . esc_attr( (string) $base->id ) . '">';
			wp_nonce_field( BaseProductActions::ACTION );
			echo '<p><button type="submit" class="button">' . esc_html__( 'Try again', 'merchboard' ) . '</button></p></form></div>';
		} else {
			/* translators: %s: Printful or Gelato. */
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( true === ( $data['printed'] ?? false ) ? __( 'Imported from %s: colours, sizes and print sizes. Add your own photos on each side, draw the print area on them, and check everything before you activate the base product.', 'merchboard' ) : ( true === ( $data['flat'] ?? false ) ? __( 'Imported from %s: the flat parts with their print areas. Check them before you activate the base product.', 'merchboard' ) : __( 'Imported from %s: one photo per colour and a measured print area per side. Check each print area before you activate the base product.', 'merchboard' ) ), $name ) ) . '</p>';

			if ( is_array( $data['skipped'] ?? null ) && array() !== $data['skipped'] ) {
				/* translators: 1: names of sides, 2: Printful or Gelato. */
				echo '<p>' . esc_html( sprintf( __( 'Left out because %2$s\'s photos do not show them: %1$s.', 'merchboard' ), implode( ', ', array_filter( $data['skipped'], 'is_string' ) ), $name ) ) . '</p>';
			}

			echo '</div>';
		}

		// Everything is escaped in import_progress().
		echo '<div id="merchboard-import-status">' . self::import_progress( $state, $data, (int) $base->id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- import_progress() escapes.

		// Read-only parameter; nothing is changed by this request.
		$diagnose = isset( $_GET['diagnose'] ) && '1' === $_GET['diagnose']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		if ( ! $diagnose || null === $this->catalog ) {
			echo '<p><a href="' . esc_url( add_query_arg( 'diagnose', '1', self::edit_url( (int) $base->id ) ) ) . '">' . esc_html__( 'Show the provider\'s answer', 'merchboard' ) . '</a></p>';

			return;
		}

		try {
			$answer = $this->catalog->diagnose( (int) $base->id );
		} catch ( DesignerException $e ) {
			$notice = self::result_message( $e->reason() );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $notice[0] ?? $e->reason() ) . '</p></div>';

			return;
		}

		echo '<details open><summary>' . esc_html__( 'The provider\'s answer (no keys)', 'merchboard' ) . '</summary><pre style="max-height:30rem;overflow:auto;background:#f6f7f7;padding:1em">' . esc_html( (string) wp_json_encode( $answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';
	}

	/**
	 * Loads the script that keeps the import's status up to date; the page
	 * works without it (reload to see the status).
	 *
	 * @param int $id Base product.
	 */
	private function enqueue_import_status( int $id ): void {
		if ( '' === $this->plugin_url ) {
			return;
		}

		wp_enqueue_script( 'merchboard-import-status', $this->plugin_url . 'assets/js/designer-import-status.js', array(), defined( 'MERCHBOARD_VERSION' ) ? (string) constant( 'MERCHBOARD_VERSION' ) : false, true );
		wp_add_inline_script(
			'merchboard-import-status',
			'window.merchboardImportStatus = ' . (string) wp_json_encode(
				array(
					'url'    => admin_url( 'admin-ajax.php' ),
					'action' => self::STATUS_ACTION,
					'nonce'  => wp_create_nonce( self::STATUS_ACTION ),
					'base'   => $id,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The import's progress: the steps and each render with the provider's
	 * own state and picture. Everything is escaped.
	 *
	 * @param string               $state   Import state.
	 * @param array<string, mixed> $data    Import data.
	 * @param int                  $base_id Base product.
	 */
	private static function import_progress( string $state, array $data, int $base_id ): string {
		$phase   = is_string( $data['phase'] ?? null ) ? $data['phase'] : 'render';
		$steps   = array(
			'render'   => __( 'The provider renders the product', 'merchboard' ),
			'measure'  => __( 'The print areas are measured', 'merchboard' ),
			'download' => __( 'The photos are downloaded', 'merchboard' ),
			'save'     => __( 'The sides are saved', 'merchboard' ),
		);
		$current = CatalogImport::RUNNING === $state ? array_search( $phase, array_keys( $steps ), true ) : false;
		$html    = '<ol>';
		$index   = 0;

		foreach ( $steps as $label ) {
			$html .= '<li>' . ( $index === $current ? '<strong>' . esc_html( $label ) . ' …</strong>' : esc_html( $label ) ) . '</li>';
			++$index;
		}

		$html   .= '</ol>';
		$renders = is_array( $data['renders'] ?? null ) ? $data['renders'] : array();

		if ( array() !== $renders ) {
			$html .= '<table class="widefat striped" style="max-width:50rem"><thead><tr><th>' . esc_html__( 'Render', 'merchboard' ) . '</th><th>' . esc_html__( 'Status', 'merchboard' ) . '</th><th>' . esc_html__( 'The provider says', 'merchboard' ) . '</th><th>' . esc_html__( 'Picture', 'merchboard' ) . '</th></tr></thead><tbody>';

			foreach ( $renders as $kind => $render ) {
				if ( is_array( $render ) ) {
					$html .= self::import_render_row( (string) $kind, $render );
				}
			}

			$html .= '</tbody></table>';
		}

		$updated = is_numeric( $data['updated'] ?? null ) ? time() - (int) $data['updated'] : 0;

		if ( CatalogImport::RUNNING === $state && $updated > self::STALE_IMPORT ) {
			/* translators: %d: minutes. */
			$html .= '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'The background job has not run for %d minutes. Check that WP-Cron or Action Scheduler runs on this site.', 'merchboard' ), (int) floor( $updated / 60 ) ) ) . '</p>';

			// A round that died queues no next one: after a while the import can be started over (30.15 review).
			if ( $updated > CatalogImport::STALE ) {
				$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( BaseProductActions::ACTION ) . '"><input type="hidden" name="do" value="import_retry"><input type="hidden" name="base_id" value="' . esc_attr( (string) $base_id ) . '">' . wp_nonce_field( BaseProductActions::ACTION, '_wpnonce', true, false ) . '<p><button type="submit" class="button">' . esc_html__( 'Start the import over', 'merchboard' ) . '</button></p></form>';
			}

			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * One render of the import's progress.
	 *
	 * @param string       $kind   `marker`, `blank` or `blank:<colour key>`.
	 * @param array<mixed> $render Render.
	 */
	private static function import_render_row( string $kind, array $render ): string {
		$label = 'marker' === $kind ? __( 'Product with markers', 'merchboard' ) : __( 'Blank product', 'merchboard' );

		if ( str_starts_with( $kind, 'blank:' ) ) {
			$label .= ': ' . substr( $kind, 6 );
		}

		$done  = 'done' === ( $render['status'] ?? '' );
		$polls = is_numeric( $render['polls'] ?? null ) ? (int) $render['polls'] : 0;
		$image = is_string( $render['image'] ?? null ) && 'https' === wp_parse_url( $render['image'], PHP_URL_SCHEME ) ? $render['image'] : '';
		$note  = is_string( $render['note'] ?? null ) ? $render['note'] : '';
		/* translators: 1: looks so far, 2: most looks. */
		$status = $done ? __( 'Ready', 'merchboard' ) : sprintf( __( 'Waiting (look %1$d of %2$d)', 'merchboard' ), $polls, CatalogImport::MAX_POLLS );

		return '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( $status ) . '</td><td><code>' . esc_html( $note ) . '</code></td><td>' . ( '' === $image ? '' : '<a href="' . esc_url( $image ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $image ) . '" alt="" style="width:64px;height:64px;object-fit:contain"></a>' ) . '</td></tr>';
	}

	/**
	 * A provider's refusal with the call and its own message.
	 *
	 * @param string $reason The provider exception's reason.
	 * @param string $call   The call.
	 * @param string $detail The provider's message.
	 */
	private function provider_error( string $reason, string $call, string $detail ): void {
		$notice = self::result_message( $reason );

		echo '<div class="notice notice-error inline"><p>' . esc_html( $notice[0] ?? $reason ) . '</p>';

		if ( '' !== $call || '' !== $detail ) {
			echo '<p><code>' . esc_html( trim( $call . ' · ' . $detail, ' ·' ) ) . '</code></p>';
		}

		echo '</div>';
	}

	/**
	 * A URL of "New base product" with query arguments.
	 *
	 * @param array<string, int|string> $args Query arguments.
	 */
	private static function new_url( array $args ): string {
		return self::page_url( array_merge( array( 'view' => 'new' ), array_filter( $args, static fn( int|string $value ): bool => '' !== $value && 0 !== $value ) ), '' );
	}

	/**
	 * The import's state after the status in the list, or ''.
	 *
	 * @param string $state Import state.
	 */
	private static function import_label( string $state ): string {
		return match ( $state ) {
			CatalogImport::RUNNING => ' · ' . __( 'importing…', 'merchboard' ),
			CatalogImport::FAILED  => ' · ' . __( 'import failed', 'merchboard' ),
			default                => '',
		};
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
		if ( '' !== $result ) {
			$args[ self::RESULT_ARG ] = $result;
		}

		return DesignerMenu::url( 'cliparts' === ( $args['view'] ?? '' ) ? DesignerMenu::CLIPARTS : DesignerMenu::BASES, $args );
	}
}
