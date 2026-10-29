<?php
/**
 * Merch Dashboard → Designs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Notifications\Admin\NotificationSwitches;
use MerchBoard\Modules\Notifications\NotificationsException;
use MerchBoard\Modules\Notifications\NotificationType;
use MerchBoard\Modules\Notifications\NotificationTypes;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * The designer's admin page (`merchboard_manage_designer`, ADR-080 pkt. 3):
 *
 * - "Waiting for approval": the designs creators submitted, oldest first,
 *   with their mockups and print files (DesignerVersionFiles), colours,
 *   sizes and price; "Approve" and "Reject" with a note (step 30.10,
 *   DesignerReviewActions);
 * - "On the way to the shop": approved designs not applied yet, with the
 *   step and reason of a failure and "Try again";
 * - "Notifications" (also `merchboard_manage_settings`): the designer's
 *   notification types with their switches, example and test e-mail
 *   (DesignerNotifications; phase 29's switches, its own form).
 */
final class DesignsPage {

	public const SLUG = 'merchboard-designs';

	public const RESULT_ARG = 'merchboard_designs_result';

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products Designer products.
	 * @param BaseProductRepository     $bases    Base products.
	 * @param CreatorRepository         $creators Creators.
	 * @param NotificationTypes         $types    Notification catalog.
	 * @param NotificationSwitches      $switches Switches and examples.
	 * @param int                       $decimals Store price decimals.
	 */
	public function __construct(
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private CreatorRepository $creators,
		private NotificationTypes $types,
		private NotificationSwitches $switches,
		private int $decimals
	) {
	}

	/**
	 * Adds the submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Designs', 'merchboard' ),
			__( 'Designs', 'merchboard' ),
			Capabilities::MANAGE_DESIGNER,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The queue's URL.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result = '' ): string {
		return self::page_url( array(), $result );
	}

	/**
	 * The notifications tab's URL, or a type's example.
	 *
	 * @param string $preview Type key ('' for the list).
	 * @param string $result  Outcome code.
	 */
	public static function notifications_url( string $preview = '', string $result = '' ): string {
		return self::page_url( '' === $preview ? array( 'view' => 'notifications' ) : array( 'view' => 'notifications', 'preview' => $preview ), $result ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Message and notice type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'approved'                             => array( __( 'The design is approved. It is being put in the shop; the creator gets a notification when it is there.', 'merchboard' ), 'success' ),
			'rejected'                             => array( __( 'The design is rejected. The creator gets your note and can change it.', 'merchboard' ), 'success' ),
			'retried'                              => array( __( 'The design is being put in the shop again.', 'merchboard' ), 'success' ),
			DesignerException::MOCKUPS_PENDING     => array( __( 'Printful is still making the mockups of this design. Try again in a minute.', 'merchboard' ), 'error' ),
			DesignerException::DESIGN_STALE        => array( __( 'The base product changed after the design was sent. Reject it with a note so the creator sends it again.', 'merchboard' ), 'error' ),
			DesignerException::DESIGN_PROFIT       => array( __( 'The price of the design could not be computed.', 'merchboard' ), 'error' ),
			DesignerException::NOT_PENDING         => array( __( 'The design is no longer waiting (withdrawn or decided already).', 'merchboard' ), 'error' ),
			DesignerException::REVIEW_NOTE         => array( __( 'Write a note of at most 2000 characters to the creator.', 'merchboard' ), 'error' ),
			DesignerException::NOT_AUTHORIZED      => array( __( 'You are not allowed to manage the product designer.', 'merchboard' ), 'error' ),
			'saved'                                => array( __( 'The notification settings are saved.', 'merchboard' ), 'success' ),
			'test_sent'                            => array( __( 'The test e-mail was sent to you. Check your inbox (and the spam folder).', 'merchboard' ), 'success' ),
			NotificationsException::NOT_SENT       => array( __( 'The test e-mail could not be sent. Check that the site can send e-mail; the log has the reason.', 'merchboard' ), 'error' ),
			NotificationsException::UNKNOWN_TYPE   => array( __( 'That notification does not exist.', 'merchboard' ), 'error' ),
			NotificationsException::NOT_AUTHORIZED => array( __( 'You are not allowed to change the notifications.', 'merchboard' ), 'error' ),
			'error'                                => array( __( 'A technical error occurred. Nothing was changed; try again or check the log.', 'merchboard' ), 'error' ),
			default                                => null,
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
		$view          = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$preview       = isset( $_GET['preview'] ) ? sanitize_key( wp_unslash( $_GET['preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$code          = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice        = self::result_message( $code );
		$notifications = 'notifications' === $view && current_user_can( Capabilities::MANAGE_SETTINGS );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Designs', 'merchboard' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		printf( '<a class="nav-tab%1$s" href="%2$s">%3$s</a>', $notifications ? '' : ' nav-tab-active', esc_url( self::url() ), esc_html__( 'Waiting for approval', 'merchboard' ) );

		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			printf( '<a class="nav-tab%1$s" href="%2$s">%3$s</a>', $notifications ? ' nav-tab-active' : '', esc_url( self::notifications_url() ), esc_html__( 'Notifications', 'merchboard' ) );
		}

		echo '</nav>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		if ( $notifications ) {
			$this->render_notifications( $preview );
		} else {
			$this->render_queue();
		}

		echo '</div>';
	}

	/**
	 * The designs waiting for approval and those on their way to the shop.
	 */
	private function render_queue(): void {
		try {
			$rows     = $this->products->pending_queue();
			$applying = $this->products->applying();
			$names    = array();
			$bases    = array();

			foreach ( $this->bases->all() as $base ) {
				$bases[ (int) $base->id ] = $base;
			}
		} catch ( DatabaseException ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The product designer\'s data could not be read. Please try again later.', 'merchboard' ) . '</p></div>';

			return;
		}

		echo '<p>' . esc_html__( 'Designs that creators sent for approval, oldest first. Nothing is in the shop before you approve it; an approved design becomes a product (or changes one) with the mockups as product images. The print files stay private: download them here, on the product and on the order.', 'merchboard' ) . '</p>';

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No designs are waiting for approval.', 'merchboard' ) . '</p>';
		}

		foreach ( $rows as $row ) {
			$this->render_pending( $row, $bases[ (int) $row['base_id'] ] ?? null, $this->creator_name( (int) $row['creator_id'], $names ) );
		}

		if ( array() === $applying ) {
			return;
		}

		echo '<h2>' . esc_html__( 'On the way to the shop', 'merchboard' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'Approved', 'merchboard' ), __( 'Creator', 'merchboard' ), __( 'Product', 'merchboard' ), __( 'Status', 'merchboard' ), '' ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $applying as $row ) {
			$failed = DesignerProductRepository::APPLY_FAILED === $row['apply_state'];

			echo '<tr>';
			echo '<td>' . esc_html( get_date_from_gmt( (string) $row['decided_at'], 'Y-m-d H:i' ) ) . '</td>';
			echo '<td>' . esc_html( $this->creator_name( (int) $row['creator_id'], $names ) ) . '</td>';
			/* translators: %d: version number. */
			echo '<td>' . esc_html( (string) $row['title'] ) . ' ' . esc_html( sprintf( __( '(version %d)', 'merchboard' ), (int) $row['number'] ) ) . '</td>';
			echo '<td>' . esc_html( $failed ? DesignerNotifications::reason( (string) $row['apply_error'] ) : __( 'Being put in the shop…', 'merchboard' ) ) . '</td>';
			echo '<td>';

			if ( $failed ) {
				$this->review_form( (int) $row['id'], 'retry', __( 'Try again', 'merchboard' ), 'secondary' );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One waiting design: its facts, files and the decision.
	 *
	 * @param array<string, mixed> $row     Version (pending_queue()).
	 * @param BaseProduct|null     $base    Its base product.
	 * @param string               $creator Creator name.
	 */
	private function render_pending( array $row, ?BaseProduct $base, string $creator ): void {
		$id     = (int) $row['id'];
		$slots  = DesignerVersionFiles::slots( is_string( $row['files'] ) ? $row['files'] : null );
		$colors = json_decode( (string) $row['colors'], true );
		$sizes  = json_decode( (string) $row['sizes'], true );
		$names  = null === $base ? array() : array_column( $base->colors, 'name', 'key' );

		echo '<div class="card" style="max-width:none;margin:16px 0;padding:12px 16px">';
		/* translators: 1: product title, 2: version number. */
		echo '<h3 style="margin-top:0">' . esc_html( sprintf( __( '%1$s (version %2$d)', 'merchboard' ), (string) $row['title'], (int) $row['number'] ) ) . '</h3>';
		echo '<p>';
		/* translators: 1: creator name, 2: date and time. */
		echo esc_html( sprintf( __( 'By %1$s, sent %2$s.', 'merchboard' ), $creator, get_date_from_gmt( (string) $row['submitted_at'], 'Y-m-d H:i' ) ) ) . ' ';
		echo esc_html( null === $row['live_version_id'] ? __( 'A new product.', 'merchboard' ) : __( 'A change of a product in the shop; the shop keeps the approved version until you approve this one.', 'merchboard' ) );
		echo '<br>';
		/* translators: %s: base product name. */
		echo esc_html( sprintf( __( 'Base product: %s', 'merchboard' ), null === $base ? '#' . (int) $row['base_id'] : $base->name ) ) . ' · ';
		/* translators: %s: colour names. */
		echo esc_html( sprintf( __( 'Colours: %s', 'merchboard' ), implode( ', ', array_map( static fn( mixed $key ): string => (string) ( $names[ $key ] ?? $key ), is_array( $colors ) ? $colors : array() ) ) ) ) . ' · ';
		/* translators: %s: sizes. */
		echo esc_html( sprintf( __( 'Sizes: %s', 'merchboard' ), implode( ', ', is_array( $sizes ) ? array_map( 'strval', $sizes ) : array() ) ) ) . ' · ';
		/* translators: %s: price. */
		echo esc_html( sprintf( __( 'Customer price incl. VAT: %s', 'merchboard' ), null === $row['quoted_price'] ? '–' : $this->money( (string) $row['quoted_price'], null === $base ? '' : $base->currency ) ) );
		echo '</p>';

		if ( '' !== trim( (string) $row['description'] ) ) {
			echo '<p class="description">' . esc_html( (string) $row['description'] ) . '</p>';
		}

		if ( 'running' === ( $row['mockup_state'] ?? '' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Printful is making the mockups; the creator\'s mockups are shown until then.', 'merchboard' ) . '</p></div>';
		} elseif ( 'failed' === ( $row['mockup_state'] ?? '' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Printful could not make the mockups; the creator\'s mockups are shown.', 'merchboard' ) . '</p></div>';
		} elseif ( 'ready' === ( $row['mockup_state'] ?? '' ) ) {
			echo '<p class="description">' . esc_html__( 'Mockups from Printful.', 'merchboard' ) . '</p>';
		}

		echo '<div style="display:flex;flex-wrap:wrap;gap:8px">';

		foreach ( $slots['mockups'] as $label => $slot ) {
			echo '<figure style="margin:0;text-align:center"><a href="' . esc_url( DesignerVersionFiles::url( $id, $slot ) ) . '" target="_blank" rel="noopener"><img src="' . esc_url( DesignerVersionFiles::url( $id, $slot ) ) . '" alt="" style="width:160px;height:160px;object-fit:contain;border:1px solid #dcdcde;background:#fff"></a><figcaption>' . esc_html( $label ) . '</figcaption></figure>';
		}

		echo '</div><p><strong>' . esc_html__( 'Print files:', 'merchboard' ) . '</strong> ';
		$links = array();

		foreach ( $slots['print'] as $side => $slot ) {
			$links[] = '<a href="' . esc_url( DesignerVersionFiles::url( $id, $slot ) ) . '">' . esc_html( $side ) . '</a>';
		}

		echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		echo '</p><div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">';
		$this->review_form( $id, 'approve', __( 'Approve and put in the shop', 'merchboard' ), 'primary' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( DesignerReviewActions::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="reject">';
		echo '<input type="hidden" name="version" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( DesignerReviewActions::nonce_action( $id ) );
		echo '<label for="merchboard-note-' . esc_attr( (string) $id ) . '" class="screen-reader-text">' . esc_html__( 'Note to the creator', 'merchboard' ) . '</label>';
		echo '<textarea id="merchboard-note-' . esc_attr( (string) $id ) . '" name="note" rows="2" cols="50" maxlength="2000" required placeholder="' . esc_attr__( 'Note to the creator: why, and what to change', 'merchboard' ) . '"></textarea><br>';
		submit_button( __( 'Reject', 'merchboard' ), 'secondary', 'submit', false );
		echo '</form></div></div>';
	}

	/**
	 * A one-button form of the review actions.
	 *
	 * @param int    $version Version.
	 * @param string $action  `approve` or `retry`.
	 * @param string $label   Button label.
	 * @param string $type    Button type.
	 */
	private function review_form( int $version, string $action, string $label, string $type ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( DesignerReviewActions::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="version" value="' . esc_attr( (string) $version ) . '">';
		wp_nonce_field( DesignerReviewActions::nonce_action( $version ) );
		submit_button( $label, $type, 'submit', false );
		echo '</form>';
	}

	/**
	 * The designer's notifications, or one's example.
	 *
	 * @param string $preview Type key ('' for the list).
	 */
	private function render_notifications( string $preview ): void {
		$types = $this->types->in_groups( array( DesignerNotifications::GROUP ) );
		$type  = $types[ $preview ] ?? null;

		if ( null !== $type ) {
			$this->switches->preview( $type, self::notifications_url(), DesignerNotificationActions::ACTION );

			return;
		}

		echo '<p>' . esc_html__( 'Choose which of the product designer\'s notifications are sent. They use the same e-mail template, bell and log as the other notifications (Merch Dashboard → Notifications).', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( DesignerNotificationActions::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="save">';
		wp_nonce_field( DesignerNotificationActions::ACTION );

		$creator = array_filter( $types, static fn( NotificationType $type ): bool => NotificationType::CREATOR === $type->audience );
		$admin   = array_filter( $types, static fn( NotificationType $type ): bool => NotificationType::ADMIN === $type->audience );
		$preview = static fn( string $key ): string => self::notifications_url( $key );

		$this->switches->table( __( 'To the creator', 'merchboard' ), $creator, false, $preview );
		$this->switches->table( __( 'To the administrator', 'merchboard' ), $admin, true, $preview );

		submit_button( __( 'Save notification settings', 'merchboard' ) );
		echo '</form>';
	}

	/**
	 * A creator's name, read once per page.
	 *
	 * @param int                $creator_id Creator.
	 * @param array<int, string> $names      Names read so far.
	 */
	private function creator_name( int $creator_id, array &$names ): string {
		if ( ! isset( $names[ $creator_id ] ) ) {
			try {
				$names[ $creator_id ] = $this->creators->find( $creator_id )->display_name ?? '#' . $creator_id;
			} catch ( DatabaseException ) {
				$names[ $creator_id ] = '#' . $creator_id;
			}
		}

		return $names[ $creator_id ];
	}

	/**
	 * An amount with its currency.
	 *
	 * @param string $amount   Decimal.
	 * @param string $currency Currency code.
	 */
	private function money( string $amount, string $currency ): string {
		$units = SalesAmount::parse( $amount );
		$text  = PricingMath::fits_precision( $units, $this->decimals ) ? PricingMath::price_string( $units, $this->decimals ) : (string) ( $units / 10000 );

		return trim( $text . ' ' . $currency );
	}

	/**
	 * A URL of the page.
	 *
	 * @param array<string, string> $args   Query arguments.
	 * @param string                $result Outcome code.
	 */
	private static function page_url( array $args, string $result ): string {
		$args['page'] = self::SLUG;

		if ( '' !== $result ) {
			$args[ self::RESULT_ARG ] = $result;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
