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
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * The designer's admin page (`merchboard_manage_designer`, ADR-080 pkt. 3):
 *
 * - "Waiting for approval": the designs creators submitted, oldest first
 *   (step 30.9 lists them; approving and rejecting follow in 30.10);
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
	 * The designs waiting for approval.
	 */
	private function render_queue(): void {
		echo '<p>' . esc_html__( 'Designs that creators sent for approval, oldest first. Nothing is in the shop before it is approved. Approving and rejecting arrive with the next update of the product designer.', 'merchboard' ) . '</p>';

		try {
			$rows  = $this->products->pending_queue();
			$names = array();
			$bases = array();

			foreach ( $this->bases->all() as $base ) {
				$bases[ (int) $base->id ] = $base;
			}
		} catch ( DatabaseException ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The product designer\'s data could not be read. Please try again later.', 'merchboard' ) . '</p></div>';

			return;
		}

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No designs are waiting for approval.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'Sent', 'merchboard' ), __( 'Creator', 'merchboard' ), __( 'Product', 'merchboard' ), __( 'Base product', 'merchboard' ), __( 'Kind', 'merchboard' ), __( 'Customer price incl. VAT', 'merchboard' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$base = $bases[ (int) $row['base_id'] ] ?? null;

			echo '<tr>';
			echo '<td>' . esc_html( get_date_from_gmt( (string) $row['submitted_at'], 'Y-m-d H:i' ) ) . '</td>';
			echo '<td>' . esc_html( $this->creator_name( (int) $row['creator_id'], $names ) ) . '</td>';
			/* translators: %d: version number. */
			echo '<td><strong>' . esc_html( (string) $row['title'] ) . '</strong> ' . esc_html( sprintf( __( '(version %d)', 'merchboard' ), (int) $row['number'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $base ? '#' . (int) $row['base_id'] : $base->name ) . '</td>';
			echo '<td>' . esc_html( null === $row['live_version_id'] ? __( 'New product', 'merchboard' ) : __( 'Change', 'merchboard' ) ) . '</td>';
			echo '<td>' . esc_html( null === $row['quoted_price'] ? '' : $this->money( (string) $row['quoted_price'], null === $base ? '' : $base->currency ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
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
