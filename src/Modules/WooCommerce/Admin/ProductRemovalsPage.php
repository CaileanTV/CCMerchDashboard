<?php
/**
 * Admin page: the creators' requests to remove a legacy product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalException;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalRepository;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalRequest;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalStatus;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Removal requests" (ADR-083): the pending requests, oldest
 * first, each with a link to the product in WooCommerce, "Mark as handled" and
 * "Decline" with a required note the creator sees; the latest closed requests
 * below. The plugin never removes the product: the administrator does that in
 * WooCommerce. The menu item shows how many are waiting. Capability
 * `merchboard_manage_creators`.
 */
final class ProductRemovalsPage {

	public const SLUG = 'merchboard-product-removals';

	/**
	 * Query argument of the outcome notice.
	 */
	public const RESULT_ARG = 'merchboard_removal_result';

	/**
	 * Hook priority on `admin_menu` (after "Category proposals").
	 */
	public const PRIORITY = 12;

	/**
	 * Post statuses of a product that is gone from the shop.
	 */
	private const GONE = array( false, 'trash' );

	/**
	 * Constructor.
	 *
	 * @param ProductRemovalRepository $requests Requests.
	 * @param CreatorRepository        $creators Creators.
	 * @param Logger                   $logger   Technical log.
	 */
	public function __construct( private ProductRemovalRepository $requests, private CreatorRepository $creators, private Logger $logger ) {
	}

	/**
	 * URL of the page.
	 *
	 * @param array<string, int|string> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Whether the product still exists in WooCommerce, outside the trash.
	 *
	 * @param int $product_id WooCommerce parent product.
	 */
	public static function in_shop( int $product_id ): bool {
		return ! in_array( get_post_status( $product_id ), self::GONE, true );
	}

	/**
	 * Message and type of an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'warning'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'completed'                              => array( __( 'The request is marked as handled, and the creator has been told.', 'merchboard' ), 'success' ),
			'completed_in_shop'                      => array( __( 'The request is marked as handled, and the creator has been told. The product still exists in WooCommerce.', 'merchboard' ), 'warning' ),
			'declined'                               => array( __( 'The request is declined. The creator sees your note.', 'merchboard' ), 'success' ),
			ProductRemovalException::NOTE_REQUIRED   => array( __( 'Write a note to the creator before you decline the request.', 'merchboard' ), 'error' ),
			ProductRemovalException::NOT_PENDING,
			ProductRemovalException::NOT_FOUND       => array( __( 'The request is no longer waiting.', 'merchboard' ), 'warning' ),
			ProductRemovalException::NOT_AUTHORIZED  => array( __( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), 'error' ),
			'error'                                  => array( __( 'A technical error occurred. Nothing was changed. Please try again later.', 'merchboard' ), 'error' ),
			default                                  => null,
		};
	}

	/**
	 * Adds "Removal requests" with the number waiting to the Merch Dashboard menu.
	 */
	public function add_menu(): void {
		$count = $this->pending_count();
		$title = __( 'Removal requests', 'merchboard' );

		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Removal requests', 'merchboard' ),
			$count > 0 ? esc_html( $title ) . ' <span class="awaiting-mod"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>' : esc_html( $title ),
			Capabilities::MANAGE_CREATORS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		echo '<div class="wrap merchboard-removals">';
		echo '<h1>' . esc_html__( 'Removal requests', 'merchboard' ) . '</h1>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p class="description">' . esc_html__( 'Creators ask here to have a product removed that was not made in the product designer. Nothing happens to the product by itself, and it stays in the shop. Check it, remove it yourself in WooCommerce if it may go, and mark the request as handled.', 'merchboard' ) . '</p>';

		try {
			$pending = $this->requests->pending();
			$closed  = $this->requests->closed();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Reading the product removal requests failed.', $e->context() );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The requests could not be read. Please try again later.', 'merchboard' ) . '</p></div></div>';

			return;
		}

		if ( array() === $pending ) {
			echo '<p>' . esc_html__( 'No requests are waiting.', 'merchboard' ) . '</p>';
		} else {
			$this->render_pending( $pending );
		}

		if ( array() !== $closed ) {
			$this->render_closed( $closed );
		}

		echo '</div>';
	}

	/**
	 * The requests waiting.
	 *
	 * @phpstan-param list<ProductRemovalRequest> $requests
	 *
	 * @param ProductRemovalRequest[] $requests Pending requests.
	 */
	private function render_pending( array $requests ): void {
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Creator', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Product', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Requested', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Decision', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $requests as $request ) {
			echo '<tr><td>' . esc_html( $this->creator_name( $request->creator_id ) ) . '</td>';
			echo '<td>' . $this->product_cell( $request ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- product_cell() escapes.
			echo '<td>' . esc_html( self::time( $request->requested_at ) ) . '</td><td>';
			$this->render_form( ProductRemovalActions::COMPLETE, $request->id, '', '<button type="submit" class="button button-primary">' . esc_html__( 'Mark as handled', 'merchboard' ) . '</button>' );
			$this->render_form(
				ProductRemovalActions::DECLINE,
				$request->id,
				'<p><label>' . esc_html__( 'Note to the creator', 'merchboard' ) . '<br><input type="text" name="' . esc_attr( ProductRemovalActions::NOTE_FIELD ) . '" required maxlength="500" class="regular-text"></label></p>',
				'<button type="submit" class="button">' . esc_html__( 'Decline', 'merchboard' ) . '</button>'
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The latest closed requests.
	 *
	 * @phpstan-param list<ProductRemovalRequest> $requests
	 *
	 * @param ProductRemovalRequest[] $requests Closed requests.
	 */
	private function render_closed( array $requests ): void {
		echo '<h2>' . esc_html__( 'Handled requests', 'merchboard' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Creator', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Product', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Decision', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Handled', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $requests as $request ) {
			$decision = ProductRemovalStatus::Declined === $request->status
				/* translators: %s: the administrator's note. */
				? sprintf( __( 'Declined: %s', 'merchboard' ), (string) $request->note )
				: __( 'Handled', 'merchboard' );

			echo '<tr><td>' . esc_html( $this->creator_name( $request->creator_id ) ) . '</td><td>' . esc_html( $request->product_name ) . '</td><td>' . esc_html( $decision ) . '</td>';
			echo '<td>' . esc_html( null === $request->handled_at ? '' : self::time( $request->handled_at ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One decision's form.
	 *
	 * @param string $action     COMPLETE or DECLINE.
	 * @param int    $request_id Request.
	 * @param string $fields     Escaped extra fields.
	 * @param string $button     Escaped button.
	 */
	private function render_form( string $action, int $request_id, string $fields, string $button ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:.6em">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request_id ) . '">';
		wp_nonce_field( ProductRemovalActions::nonce_action( $action, $request_id ) );
		echo $fields . $button . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The callers escape.
	}

	/**
	 * The product's name with a link, or a note that it is gone.
	 *
	 * @param ProductRemovalRequest $request Request.
	 */
	private function product_cell( ProductRemovalRequest $request ): string {
		if ( ! self::in_shop( $request->product_id ) ) {
			return esc_html( $request->product_name ) . '<br><span class="description">' . esc_html__( 'No longer exists in WooCommerce.', 'merchboard' ) . '</span>';
		}

		return '<a href="' . esc_url( admin_url( 'post.php?post=' . $request->product_id . '&action=edit' ) ) . '">' . esc_html( $request->product_name ) . '</a>';
	}

	/**
	 * The creator's name, or their id for a creator that cannot be read.
	 *
	 * @param int $creator_id Creator.
	 */
	private function creator_name( int $creator_id ): string {
		return $this->creators->find( $creator_id )->display_name ?? '#' . $creator_id;
	}

	/**
	 * A time in the site's time zone.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function time( DateTimeImmutable $time ): string {
		return (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $time->getTimestamp() );
	}

	/**
	 * The number waiting, 0 when it cannot be read: the menu must not fail.
	 */
	private function pending_count(): int {
		try {
			return $this->requests->pending_count();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Counting the product removal requests failed.', $e->context() );

			return 0;
		}
	}
}
