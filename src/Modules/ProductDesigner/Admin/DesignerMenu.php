<?php
/**
 * The product designer's one admin page with its tabs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;

/**
 * Everything about the designer under one menu item (the owner's choice,
 * 2026-10-31): designs, base products, cliparts, campaigns, embroidery packs,
 * notifications and settings, each a tab. The pages keep their own forms and
 * notices. The menu item is there while the designer is switched off, so the
 * switch can be reached. The former pages' addresses, as in e-mails already
 * sent, lead to their tabs.
 */
final class DesignerMenu {

	public const SLUG = 'merchboard-designer';

	public const DESIGNS = 'designs';

	public const BASES = 'bases';

	public const CLIPARTS = 'cliparts';

	public const CAMPAIGNS = 'campaigns';

	public const PACKS = 'packs';

	public const NOTIFICATIONS = 'notifications';

	public const SETTINGS = 'settings';

	/**
	 * What a notification links to: a notification names a page, and these
	 * lead to their tabs.
	 */
	public const LINK_DESIGNS = 'merchboard-designs';

	public const LINK_BASES = 'merchboard-base-products';

	/**
	 * The former pages' tabs.
	 */
	private const FORMER_PAGES = array(
		self::LINK_DESIGNS     => self::DESIGNS,
		self::LINK_BASES       => self::BASES,
		'merchboard-campaigns' => self::CAMPAIGNS,
	);

	/**
	 * Constructor.
	 *
	 * @param DesignsPage             $designs   Designs and notifications.
	 * @param BaseProductsPage        $bases     Base products and cliparts.
	 * @param CampaignsPage           $campaigns Campaigns.
	 * @param DesignerSettingsSection $settings  Settings and embroidery packs.
	 */
	public function __construct(
		private DesignsPage $designs,
		private BaseProductsPage $bases,
		private CampaignsPage $campaigns,
		private DesignerSettingsSection $settings
	) {
	}

	/**
	 * Adds the menu item, and sends a former page's address to its tab.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Product designer', 'merchboard' ),
			__( 'Product designer', 'merchboard' ),
			Capabilities::MANAGE_DESIGNER,
			self::SLUG,
			array( $this, 'render' )
		);

		// Read-only parameter; the redirect changes nothing.
		$former = self::former_url( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in former_url().

		if ( null !== $former && current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_safe_redirect( $former );
			exit;
		}
	}

	/**
	 * A tab's URL.
	 *
	 * @param string                    $tab  The tab.
	 * @param array<string, int|string> $args Query arguments.
	 */
	public static function url( string $tab, array $args = array() ): string {
		$args['page'] = self::SLUG;
		$args['tab']  = $tab;

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Where a former page's address leads; null for any other address.
	 *
	 * @param array<mixed> $query Unslashed query.
	 */
	public static function former_url( array $query ): ?string {
		$page = is_string( $query['page'] ?? null ) ? $query['page'] : '';
		$tab  = self::FORMER_PAGES[ $page ] ?? null;

		if ( null === $tab ) {
			return null;
		}

		$view = is_string( $query['view'] ?? null ) ? $query['view'] : '';
		$args = array();

		foreach ( $query as $key => $value ) {
			if ( is_string( $key ) && 'page' !== $key && is_scalar( $value ) ) {
				$args[ sanitize_key( $key ) ] = rawurlencode( sanitize_text_field( (string) $value ) );
			}
		}

		return self::url( in_array( $view, array( self::NOTIFICATIONS, self::CLIPARTS ), true ) ? $view : $tab, $args );
	}

	/**
	 * Renders the page: the tabs and the open tab's page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only parameter; nothing is changed by this request.
		$asked = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$tabs  = self::tabs();
		$open  = isset( $tabs[ $asked ] ) ? $asked : self::DESIGNS;

		echo '<div class="wrap"><h1>' . esc_html__( 'Product designer', 'merchboard' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $tab => $label ) {
			printf( '<a class="nav-tab%1$s" href="%2$s">%3$s</a>', $tab === $open ? ' nav-tab-active' : '', esc_url( self::url( $tab, self::tab_args( $tab ) ) ), esc_html( $label ) );
		}

		echo '</nav>';

		match ( $open ) {
			self::BASES, self::CLIPARTS => $this->bases->render(),
			self::CAMPAIGNS             => $this->campaigns->render(),
			self::PACKS                 => $this->settings->render_packs(),
			self::SETTINGS              => $this->settings->render(),
			default                     => $this->designs->render(),
		};

		echo '</div>';
	}

	/**
	 * The tabs the current user may open.
	 *
	 * @return array<string, string> Label by tab.
	 */
	private static function tabs(): array {
		$tabs = array(
			self::DESIGNS       => __( 'Designs', 'merchboard' ),
			self::BASES         => __( 'Base products', 'merchboard' ),
			self::CLIPARTS      => __( 'Cliparts', 'merchboard' ),
			self::CAMPAIGNS     => __( 'Campaigns', 'merchboard' ),
			self::PACKS         => __( 'Embroidery packs', 'merchboard' ),
			self::NOTIFICATIONS => __( 'Notifications', 'merchboard' ),
			self::SETTINGS      => __( 'Settings', 'merchboard' ),
		);

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			unset( $tabs[ self::NOTIFICATIONS ] );
		}

		return $tabs;
	}

	/**
	 * What a tab's page reads to open as that tab.
	 *
	 * @param string $tab The tab.
	 * @return array<string, string>
	 */
	private static function tab_args( string $tab ): array {
		return in_array( $tab, array( self::NOTIFICATIONS, self::CLIPARTS ), true ) ? array( 'view' => $tab ) : array();
	}
}
