<?php
/**
 * Merch Dashboard → Proposals → Images without background.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\Approvals\ApprovalTab;
use MerchBoard\Modules\Admin\Approvals\ApprovalsPage;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerCutouts;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;

defined( 'ABSPATH' ) || exit;

/**
 * The approval of the product images without background (ADR-114 pkt. 4),
 * a tab on "Proposals" (the owner's choice 2026-11-02: everything the
 * administrator approves). Every approved designer product in the shop with
 * its images: the shop's image beside the new one on a checkered ground.
 *
 * - "Make images" (per product and for all) runs in the browser
 *   (build/designer-cutouts-admin.js): it reads the job (ACTION_JOB),
 *   draws each image as the designer does and sends it (ACTION_UPLOAD).
 * - "Approve", "Discard" and "Undo" post the form ACTION.
 * - "Use images without background on all products" (the owner's wish
 *   2026-11-15: it runs by itself) makes each product's images and applies
 *   them at once (ACTION_APPLY); "Undo" stays per product.
 *
 * Every handler checks `merchboard_manage_designer` first, then a nonce
 * bound to the product (and the image); files are sent `no-store` and
 * `nosniff`.
 */
final class DesignerCutoutsPage {

	public const TAB = 'cutouts';

	public const ACTION = 'merchboard_designer_cutouts';

	public const ACTION_JOB = 'merchboard_designer_cutouts_job';

	public const ACTION_UPLOAD = 'merchboard_designer_cutouts_upload';

	public const ACTION_FILE = 'merchboard_designer_cutouts_file';

	public const ACTION_APPLY = 'merchboard_designer_cutouts_apply';

	public const ACTION_OUTLINE_JOB = 'merchboard_designer_cutouts_outline_job';

	public const ACTION_OUTLINE = 'merchboard_designer_cutouts_outline';

	public const RESULT_ARG = 'merchboard_cutouts_result';

	/**
	 * Constructor.
	 *
	 * @param DesignerCutouts    $cutouts    The images without background.
	 * @param CreatorRepository  $creators   Creators.
	 * @param Logger             $logger     Technical log.
	 * @param string             $plugin_dir Plugin folder (with slash).
	 * @param string             $plugin_url Plugin URL (with slash).
	 * @param BaseProductService $bases     Base products (the silhouettes).
	 */
	public function __construct(
		private DesignerCutouts $cutouts,
		private CreatorRepository $creators,
		private Logger $logger,
		private string $plugin_dir,
		private string $plugin_url,
		private BaseProductService $bases
	) {
	}

	/**
	 * Hooks the handlers (logged-in users only).
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_decision' ) );
		add_action( 'admin_post_' . self::ACTION_JOB, array( $this, 'handle_job' ) );
		add_action( 'admin_post_' . self::ACTION_UPLOAD, array( $this, 'handle_upload' ) );
		add_action( 'admin_post_' . self::ACTION_FILE, array( $this, 'handle_file' ) );
		add_action( 'admin_post_' . self::ACTION_APPLY, array( $this, 'handle_apply' ) );
		add_action( 'admin_post_' . self::ACTION_OUTLINE_JOB, array( $this, 'handle_outline_job' ) );
		add_action( 'admin_post_' . self::ACTION_OUTLINE, array( $this, 'handle_outline' ) );
	}

	/**
	 * The tab's URL.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result = '' ): string {
		return ApprovalsPage::url( self::TAB, '' === $result ? array() : array( self::RESULT_ARG => $result ) );
	}

	/**
	 * Nonce action of a product.
	 *
	 * @param int $product Designer product.
	 */
	public static function nonce_action( int $product ): string {
		return self::ACTION . '_' . $product;
	}

	/**
	 * The URL of a waiting image.
	 *
	 * @param int    $product Designer product.
	 * @param string $key     `<side>:<colour>`.
	 */
	public static function file_url( int $product, string $key ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION_FILE,
				'product'  => $product,
				'key'      => rawurlencode( $key ),
				'_wpnonce' => wp_create_nonce( self::ACTION_FILE . '_' . $product . '_' . $key ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The tab on "Merch Dashboard → Proposals", with the number of products
	 * whose images wait for approval.
	 */
	public function approval_tab(): ApprovalTab {
		return new ApprovalTab( self::TAB, __( 'Images without background', 'merchboard' ), Capabilities::MANAGE_DESIGNER, fn(): int => $this->cutouts->waiting(), fn() => $this->render() );
	}

	/**
	 * Message and notice type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'approved'                         => array( __( 'The images without background are in the shop now.', 'merchboard' ), 'success' ),
			'reverted'                         => array( __( 'The old images are back in the shop. The images without background wait for approval again.', 'merchboard' ), 'success' ),
			'discarded'                        => array( __( 'The images were discarded.', 'merchboard' ), 'success' ),
			DesignerException::DESIGN_BUSY     => array( __( 'The product is being changed right now. Try again in a minute.', 'merchboard' ), 'error' ),
			DesignerException::DESIGN_STALE    => array( __( 'The images belong to an older version of the product, or there is nothing to change. Make them again.', 'merchboard' ), 'error' ),
			DesignerException::NOT_LIVE        => array( __( 'The product is no longer in the shop.', 'merchboard' ), 'error' ),
			DesignerException::NOT_AUTHORIZED  => array( __( 'You are not allowed to manage the product designer.', 'merchboard' ), 'error' ),
			'error'                            => array( __( 'A technical error occurred. Nothing was changed; try again or check the log.', 'merchboard' ), 'error' ),
			default                            => null,
		};
	}

	/**
	 * Renders the tab.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		if ( null !== $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice[1] ) . '"><p>' . esc_html( $notice[0] ) . '</p></div>';
		}

		try {
			$rows = $this->cutouts->overview();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The images without background could not be read.', $e->context() );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The products could not be read. Try again or check the log.', 'merchboard' ) . '</p></div>';

			return;
		}

		$script = $this->enqueue();

		echo '<p>' . esc_html__( 'The product images of designs approved before can be made without background: only the product, as PNG, cut to the template\'s own PNG photo (or its silhouette or colour mask). They are used in the merch ad and the Twitch panel and in the shop, in the place of the old images. Photos from the provider (e.g. Printful) get their plain background removed.', 'merchboard' ) . '</p>';

		if ( ! $script ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The images are made in the browser, but its script is missing. Build the plugin (npm run build).', 'merchboard' ) . '</p></div>';
		}

		$open = array_values( array_filter( $rows, static fn( array $row ): bool => in_array( $row['state'], array( 'todo', 'pending', 'none' ), true ) ) );

		if ( $script && array() !== $open ) {
			echo '<p><button type="button" class="button button-primary merchboard-cutouts-all">' . esc_html(
				/* translators: %d: number of products. */
				sprintf( _n( 'Use images without background on %d product', 'Use images without background on all %d products', count( $open ), 'merchboard' ), count( $open ) )
			) . '</button> <span class="merchboard-cutouts-all__status" aria-live="polite"></span></p>';
			echo '<p class="description">' . esc_html__( 'Makes the images and puts them in the shop at once, product by product. Keep this page open until it is done. "Undo" puts a product\'s old images back.', 'merchboard' ) . '</p>';
		}

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'There are no approved designer products in the shop.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<style>.merchboard-cutouts td{vertical-align:top}.merchboard-cutouts__images{display:flex;flex-wrap:wrap;gap:12px;margin:0}.merchboard-cutouts__images figure{margin:0;display:grid;gap:4px;justify-items:start}.merchboard-cutouts__pair{display:flex;gap:6px}.merchboard-cutouts__pair img,.merchboard-cutouts__pair span{width:96px;height:96px;object-fit:contain;border:1px solid #dcdcde;border-radius:4px}.merchboard-cutouts__pair .is-new{background:repeating-conic-gradient(#ccc 0 25%,#fff 0 50%) 0 0/16px 16px}.merchboard-cutouts__pair span{display:grid;place-items:center;color:#646970;font-size:11px;text-align:center}.merchboard-cutouts figcaption{font-size:12px;color:#50575e}.merchboard-cutouts form{display:inline}</style>';
		// The browser checks each image against the upload limit before it sends it.
		echo '<table class="widefat striped merchboard-cutouts" data-max-bytes="' . esc_attr( (string) DesignerFiles::max_bytes() ) . '"><thead><tr><th scope="col">' . esc_html__( 'Product', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Images (shop → without background)', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$this->render_row( $row, $script );
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders one product.
	 *
	 * @param array{product: int, creator_id: int, base_id: int, wc_product_id: int, version_id: int, title: string, state: string, undo: bool, slots: list<array{key: string, label: string, old: int|null, waiting: bool, approved: int|null}>} $row    Product.
	 * @param bool                                                                                                                                                                                                                                $script Whether the browser can make images.
	 */
	private function render_row( array $row, bool $script ): void {
		$product = $row['product'];
		$creator = $this->creators->find( $row['creator_id'] );
		$edit    = get_edit_post_link( $row['wc_product_id'] );
		$nonce   = wp_create_nonce( self::nonce_action( $product ) );

		echo '<tr data-product="' . esc_attr( (string) $product ) . '"><td><strong>';
		echo is_string( $edit ) ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $row['title'] ) . '</a>' : esc_html( $row['title'] );
		echo '</strong><br><span class="description">' . esc_html( null === $creator ? '' : $creator->display_name ) . '</span><br>';
		echo esc_html( self::state_label( $row['state'] ) );

		if ( $row['undo'] ) {
			echo '<br><span class="description">' . esc_html__( 'Images without background are in the shop.', 'merchboard' ) . '</span>';
		}

		echo '</td><td><div class="merchboard-cutouts__images">';

		foreach ( $row['slots'] as $slot ) {
			$old = null === $slot['old'] ? false : wp_get_attachment_image_url( $slot['old'], 'medium' );
			$new = match ( true ) {
				null !== $slot['approved'] => wp_get_attachment_image_url( $slot['approved'], 'medium' ),
				$slot['waiting']           => self::file_url( $product, $slot['key'] ),
				default                    => false,
			};

			echo '<figure><div class="merchboard-cutouts__pair">';
			echo is_string( $old ) ? '<img src="' . esc_url( $old ) . '" alt="" loading="lazy">' : '<span>' . esc_html__( 'No image', 'merchboard' ) . '</span>';
			echo is_string( $new ) ? '<img class="is-new" src="' . esc_url( $new ) . '" alt="" loading="lazy">' : '<span class="is-new">' . esc_html__( 'Not made yet', 'merchboard' ) . '</span>';
			echo '</div><figcaption>' . esc_html( $slot['label'] ) . ( null !== $slot['approved'] ? ' · ' . esc_html__( 'in the shop', 'merchboard' ) : '' ) . '</figcaption></figure>';
		}

		echo '</div></td><td>';

		if ( $script && in_array( $row['state'], array( 'todo', 'pending', 'none' ), true ) ) {
			$job    = add_query_arg(
				array(
					'action'   => self::ACTION_JOB,
					'product'  => $product,
					'_wpnonce' => $nonce,
				),
				admin_url( 'admin-post.php' )
			);
			$upload = add_query_arg(
				array(
					'action'   => self::ACTION_UPLOAD,
					'product'  => $product,
					'_wpnonce' => $nonce,
				),
				admin_url( 'admin-post.php' )
			);
			$apply  = add_query_arg(
				array(
					'action'   => self::ACTION_APPLY,
					'product'  => $product,
					'_wpnonce' => $nonce,
				),
				admin_url( 'admin-post.php' )
			);

			$outline = array();

			// The old images on white: a silhouette from the template's photo first (the owner's wish 2026-11-15).
			if ( 'none' === $row['state'] ) {
				$base_nonce = wp_create_nonce( self::base_nonce_action( $row['base_id'] ) );

				foreach ( array(
					'job'  => self::ACTION_OUTLINE_JOB,
					'save' => self::ACTION_OUTLINE,
				) as $name => $action ) {
					$outline[ $name ] = add_query_arg(
						array(
							'action'   => $action,
							'base'     => $row['base_id'],
							'_wpnonce' => $base_nonce,
						),
						admin_url( 'admin-post.php' )
					);
				}
			}

			echo '<p><button type="button" class="button merchboard-cutouts-make" data-state="' . esc_attr( $row['state'] ) . '" data-job="' . esc_url( $job ) . '" data-upload="' . esc_url( $upload ) . '" data-apply="' . esc_url( $apply ) . '"' . ( isset( $outline['job'], $outline['save'] ) ? ' data-outline-job="' . esc_url( $outline['job'] ) . '" data-outline="' . esc_url( $outline['save'] ) . '"' : '' ) . '>' . esc_html(
				match ( $row['state'] ) {
					'pending' => __( 'Make again', 'merchboard' ),
					'none'    => __( 'Remove the white background and make images', 'merchboard' ),
					default   => __( 'Make images', 'merchboard' ),
				}
			) . '</button> <span class="merchboard-cutouts-make__status" aria-live="polite"></span></p>';

			if ( 'none' === $row['state'] ) {
				echo '<p class="description">' . wp_kses(
					sprintf(
						/* translators: %s: link to the base product. */
						__( 'The template\'s photo is on white: its background is removed to give the product an outline. If the background is not plain, upload a PNG without background (e.g. exported from the PSD) as the silhouette on %s.', 'merchboard' ),
						'<a href="' . esc_url( BaseProductsPage::edit_url( $row['base_id'] ) ) . '">' . esc_html__( 'the base product', 'merchboard' ) . '</a>'
					),
					array( 'a' => array( 'href' => array() ) )
				) . '</p>';

				// Or the outline directly: a PNG without background exported from the PSD.
				try {
					$sides = $this->cutouts->outline_job( $row['base_id'] );
				} catch ( DesignerException | DatabaseException ) {
					$sides = array();
				}

				foreach ( $sides as $side ) {
					$field = 'merchboard-silhouette-' . $product . '-' . $side['key'];

					echo '<p><label for="' . esc_attr( $field ) . '">' . esc_html(
						/* translators: %s: the side's name. */
						sprintf( __( 'Upload a PNG without background for "%s":', 'merchboard' ), $side['name'] )
					) . '</label> <input type="file" accept="image/png" class="merchboard-cutouts-silhouette" id="' . esc_attr( $field ) . '" data-side="' . esc_attr( $side['key'] ) . '" data-outline="' . esc_url( $outline['save'] ) . '"> <span aria-live="polite"></span></p>';
				}
			}
		}

		if ( 'pending' === $row['state'] ) {
			$this->decision_form( $product, $nonce, 'approve', __( 'Approve and use in the shop', 'merchboard' ), 'button-primary' );
			echo ' ';
			$this->decision_form( $product, $nonce, 'discard', __( 'Discard', 'merchboard' ), '' );
		}

		if ( $row['undo'] ) {
			echo ' ';
			$this->decision_form( $product, $nonce, 'revert', __( 'Undo: old images back', 'merchboard' ), '' );
		}

		echo '</td></tr>';
	}

	/**
	 * One decision's form.
	 *
	 * @param int    $product Designer product.
	 * @param string $nonce   The product's nonce.
	 * @param string $action  approve, discard or revert.
	 * @param string $label   Button text.
	 * @param string $style   Extra button class.
	 */
	private function decision_form( int $product, string $nonce, string $action, string $label, string $style ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="product" value="' . esc_attr( (string) $product ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		echo '<button type="submit" class="button ' . esc_attr( $style ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * A state's text.
	 *
	 * @param string $state State from DesignerCutouts::overview().
	 */
	private static function state_label( string $state ): string {
		return match ( $state ) {
			'none'     => __( 'The template\'s photo is on white: no outline yet', 'merchboard' ),
			'done'     => __( 'Nothing left to make', 'merchboard' ),
			'pending'  => __( 'Waiting for your approval', 'merchboard' ),
			default    => __( 'Images can be made', 'merchboard' ),
		};
	}

	/**
	 * Handles Approve, Discard and Undo, and redirects.
	 */
	public function handle_decision(): void {
		// The nonce is verified in decide() before any input is used.
		wp_safe_redirect( $this->decide( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in decide(); only ids and a choice are read.
		exit;
	}

	/**
	 * Handles a decision.
	 *
	 * @param array<mixed> $input Unslashed POST data.
	 * @return string Redirect URL.
	 */
	public function decide( array $input ): string {
		$product = $this->product( $input );

		try {
			switch ( is_string( $input['do'] ?? null ) ? $input['do'] : '' ) {
				case 'approve':
					$this->cutouts->approve( $product );

					return self::url( 'approved' );
				case 'revert':
					$this->cutouts->revert( $product );

					return self::url( 'reverted' );
				case 'discard':
					$this->cutouts->discard( $product );

					return self::url( 'discarded' );
				default:
					return self::url( 'error' );
			}
		} catch ( DesignerException $e ) {
			return self::url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'An approval of images without background failed.', array_merge( $e->context(), array( 'design_product_id' => $product ) ) );

			return self::url( 'error' );
		}
	}

	/**
	 * Sends a product's job as JSON.
	 */
	public function handle_job(): void {
		$product = $this->product( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in product(); only an id is read.

		try {
			wp_send_json_success( $this->cutouts->job( $product ) );
		} catch ( DesignerException $e ) {
			wp_send_json_error( array( 'reason' => $e->reason() ), 409 );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The job of images without background failed.', array_merge( $e->context(), array( 'design_product_id' => $product ) ) );
			wp_send_json_error( array( 'reason' => 'error' ), 500 );
		}
	}

	/**
	 * Whether a key names an image: `<side>:<colour>`, where the side is a
	 * side of the base product or a provider's photo (`_photo-<n>`; the
	 * owner's test 2026-11-16: their waiting images did not show).
	 *
	 * @param string $key Key.
	 */
	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^([a-z0-9][a-z0-9-]{0,31}|_photo-[0-9]{1,18}):[a-z0-9%_-]{1,32}$/', $key );
	}

	/**
	 * Nonce action of a base product's silhouettes.
	 *
	 * @param int $base_id Base product.
	 */
	public static function base_nonce_action( int $base_id ): string {
		return self::ACTION_OUTLINE . '_' . $base_id;
	}

	/**
	 * Sends the sides of a base product that need a silhouette, as JSON.
	 */
	public function handle_outline_job(): void {
		$base = $this->base( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in base(); only an id is read.

		try {
			wp_send_json_success( array( 'sides' => $this->cutouts->outline_job( $base ) ) );
		} catch ( DesignerException $e ) {
			wp_send_json_error( array( 'reason' => $e->reason() ), 409 );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The sides without an outline could not be read.', array_merge( $e->context(), array( 'base_id' => $base ) ) );
			wp_send_json_error( array( 'reason' => 'error' ), 500 );
		}
	}

	/**
	 * Keeps a side's silhouette the browser made and answers in JSON.
	 */
	public function handle_outline(): void {
		$base = $this->base( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in base(); only an id is read.
		self::refuse_dropped_upload();
		$side   = isset( $_POST['side'] ) && is_string( $_POST['side'] ) ? sanitize_key( wp_unslash( $_POST['side'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in base().
		$upload = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in base(); DesignerFiles validates the file.

		try {
			$this->bases->set_silhouette( $base, $side, $upload );
			wp_send_json_success();
		} catch ( DesignerException $e ) {
			wp_send_json_error( array( 'reason' => $e->reason() ), 422 );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A silhouette could not be kept.', array_merge( $e->context(), array( 'base_id' => $base ) ) );
			wp_send_json_error( array( 'reason' => 'error' ), 500 );
		}
	}

	/**
	 * Answers `file_too_large` when PHP dropped the whole request body
	 * (larger than `post_max_size`): nothing but the URL arrived.
	 */
	private static function refuse_dropped_upload(): void {
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

		if ( array() === $_FILES && array() === $_POST && $length > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only whether anything arrived.
			wp_send_json_error( array( 'reason' => DesignerException::FILE_TOO_LARGE ), 413 );
		}
	}

	/**
	 * The base product of a request, after the capability and its nonce.
	 *
	 * @param array<mixed> $input Unslashed input.
	 */
	private function base( array $input ): int {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$base  = is_string( $input['base'] ?? null ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', $input['base'] ) ? (int) $input['base'] : 0;
		$nonce = is_string( $input['_wpnonce'] ?? null ) ? $input['_wpnonce'] : '';

		if ( 0 === $base || false === wp_verify_nonce( $nonce, self::base_nonce_action( $base ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		return $base;
	}

	/**
	 * Applies a product's waiting images (the automatic run) and answers in JSON.
	 */
	public function handle_apply(): void {
		$product = $this->product( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in product(); only an id is read.

		try {
			wp_send_json_success( array( 'images' => $this->cutouts->approve( $product ) ) );
		} catch ( DesignerException $e ) {
			wp_send_json_error( array( 'reason' => $e->reason() ), 409 );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Images without background could not be applied.', array_merge( $e->context(), array( 'design_product_id' => $product ) ) );
			wp_send_json_error( array( 'reason' => 'error' ), 500 );
		}
	}

	/**
	 * Keeps one image the browser drew and answers in JSON.
	 */
	public function handle_upload(): void {
		$product = $this->product( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in product(); only an id is read.
		self::refuse_dropped_upload();
		$key    = isset( $_POST['key'] ) && is_string( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in product().
		$upload = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in product(); DesignerFiles validates the file.

		try {
			$this->cutouts->store( $product, $key, $upload );
			wp_send_json_success();
		} catch ( DesignerException $e ) {
			wp_send_json_error( array( 'reason' => $e->reason() ), 422 );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'An image without background could not be kept.', array_merge( $e->context(), array( 'design_product_id' => $product ) ) );
			wp_send_json_error( array( 'reason' => 'error' ), 500 );
		}
	}

	/**
	 * Sends a waiting image.
	 */
	public function handle_file(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$input   = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below before use.
		$product = is_string( $input['product'] ?? null ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', $input['product'] ) ? (int) $input['product'] : 0;
		$key     = is_string( $input['key'] ?? null ) ? rawurldecode( $input['key'] ) : '';
		$nonce   = is_string( $input['_wpnonce'] ?? null ) ? $input['_wpnonce'] : '';

		if ( 0 === $product || ! self::valid_key( $key ) || false === wp_verify_nonce( $nonce, self::ACTION_FILE . '_' . $product . '_' . $key ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			$file = $this->cutouts->waiting_file( $product, $key );
		} catch ( DatabaseException ) {
			$file = null;
		}

		if ( null === $file ) {
			wp_die( esc_html__( 'The file no longer exists.', 'merchboard' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $file['mime'] );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- A private image for the administrator.
		exit;
	}

	/**
	 * The product of a request, after the capability and its nonce.
	 *
	 * @param array<mixed> $input Unslashed input.
	 */
	private function product( array $input ): int {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$product = is_string( $input['product'] ?? null ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', $input['product'] ) ? (int) $input['product'] : 0;
		$nonce   = is_string( $input['_wpnonce'] ?? null ) ? $input['_wpnonce'] : '';

		if ( 0 === $product || false === wp_verify_nonce( $nonce, self::nonce_action( $product ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		return $product;
	}

	/**
	 * Loads the browser's part (build/designer-cutouts-admin.js); false
	 * without a build.
	 */
	private function enqueue(): bool {
		$file  = $this->plugin_dir . 'build/designer-cutouts-admin.asset.php';
		$asset = '' !== $this->plugin_dir && is_readable( $file ) && is_readable( $this->plugin_dir . 'build/designer-cutouts-admin.js' ) ? require $file : null;

		if ( ! is_array( $asset ) || ! is_string( $asset['version'] ?? null ) || ! is_array( $asset['dependencies'] ?? null ) ) {
			return false;
		}

		$dependencies = array_values( array_filter( $asset['dependencies'], static fn( mixed $dependency ): bool => is_string( $dependency ) && '' !== $dependency ) );

		wp_enqueue_script( 'merchboard-designer-cutouts-admin', $this->plugin_url . 'build/designer-cutouts-admin.js', $dependencies, $asset['version'], true );
		wp_set_script_translations( 'merchboard-designer-cutouts-admin', 'merchboard', $this->plugin_dir . 'languages' );

		return true;
	}
}
