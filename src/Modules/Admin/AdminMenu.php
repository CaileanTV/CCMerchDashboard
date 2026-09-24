<?php
/**
 * The Merch Dashboard admin menu.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin;

use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the top-level "Merch Dashboard" menu (capability `merchboard_manage`),
 * whose first item is Overview. Feature pages (Creators, Settings) add
 * submenus under PARENT_SLUG with their own, more specific capability; every
 * page checks its capability again when it renders or handles a request, so
 * a hidden menu item is never the only protection.
 */
final class AdminMenu {

	/**
	 * Top-level menu slug (also the Overview page).
	 */
	public const PARENT_SLUG = 'merchboard';

	/**
	 * Hook priority on `admin_menu`: before feature modules add submenus (10+).
	 */
	public const PRIORITY = 9;

	/**
	 * Constructor.
	 *
	 * @param OverviewPage $overview Overview screen.
	 */
	public function __construct( private OverviewPage $overview ) {
	}

	/**
	 * Adds the top-level menu and Overview as its first item.
	 */
	public function register(): void {
		add_menu_page(
			__( 'Merch Dashboard', 'merchboard' ),
			__( 'Merch Dashboard', 'merchboard' ),
			Capabilities::MANAGE,
			self::PARENT_SLUG,
			array( $this->overview, 'render' ),
			'dashicons-store',
			56
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Merch Dashboard Overview', 'merchboard' ),
			__( 'Overview', 'merchboard' ),
			Capabilities::MANAGE,
			self::PARENT_SLUG,
			array( $this->overview, 'render' )
		);
	}
}
