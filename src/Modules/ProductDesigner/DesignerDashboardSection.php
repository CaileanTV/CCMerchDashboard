<?php
/**
 * The designer in the creator's dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Dashboard\DashboardSections;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the section "Designer" (`designer`, step 30.8, ADR-080 pkt. 4) after
 * "Products" while the designer is switched on; without the switch the
 * section is not there and `/me/designer` answers 404.
 */
final class DesignerDashboardSection {

	/**
	 * Hooks the section filter.
	 */
	public function register(): void {
		add_filter( DashboardSections::FILTER, array( self::class, 'add_section' ) );
	}

	/**
	 * Adds the section after "Products" (or last).
	 *
	 * @param mixed $sections Sections.
	 */
	public static function add_section( mixed $sections ): mixed {
		if ( ! is_array( $sections ) || ! Settings::designer_enabled() ) {
			return $sections;
		}

		$designer = array(
			'id'         => 'designer',
			'label'      => __( 'Product designer', 'merchboard' ),
			'path'       => 'designer',
			'capability' => Capabilities::ACCESS_DASHBOARD,
		);
		$result   = array();
		$added    = false;

		foreach ( $sections as $existing ) {
			$result[] = $existing;

			if ( ! $added && is_array( $existing ) && 'products' === ( $existing['id'] ?? null ) ) {
				$result[] = $designer;
				$added    = true;
			}
		}

		if ( ! $added ) {
			$result[] = $designer;
		}

		return $result;
	}
}
