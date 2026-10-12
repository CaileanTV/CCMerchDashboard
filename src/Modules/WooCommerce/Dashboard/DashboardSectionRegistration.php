<?php
/**
 * The WooCommerce sections of the creator dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Dashboard\DashboardSections;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the sections that need WooCommerce to the dashboard's section registry
 * (`merchboard_dashboard_sections`, docs/FRONTEND.md#sektioner). The
 * WooCommerce module hooks it, so without WooCommerce the sections are not
 * sent to the dashboard (ADR-039 P11). DashboardSections still validates every
 * entry. Right after the overview: Sales (phase 20, ADR-040), Products and
 * Orders (phase 19) and Payouts (phase 23, ADR-054–056).
 */
final class DashboardSectionRegistration {

	/**
	 * Hooks the registration.
	 */
	public function register(): void {
		add_filter( DashboardSections::FILTER, array( self::class, 'add_sections' ) );
	}

	/**
	 * The sections with Sales, Products, Orders and Payouts after the overview (or at the end
	 * when there is no overview entry). Anything that is not a list is passed
	 * on untouched for DashboardSections to reject.
	 *
	 * @param mixed $sections Filtered sections.
	 * @return mixed
	 */
	public static function add_sections( mixed $sections ): mixed {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}

		$added_sections = array(
			array(
				'id'         => 'sales',
				'label'      => __( 'Sales', 'merchboard' ),
				'path'       => 'sales',
				'capability' => Capabilities::ACCESS_DASHBOARD,
			),
			array(
				'id'         => 'products',
				'label'      => __( 'Products', 'merchboard' ),
				'path'       => 'products',
				'capability' => Capabilities::ACCESS_DASHBOARD,
			),
			array(
				'id'         => 'orders',
				'label'      => __( 'Orders', 'merchboard' ),
				'path'       => 'orders',
				'capability' => Capabilities::ACCESS_DASHBOARD,
			),
			array(
				'id'         => 'payouts',
				'label'      => __( 'Payouts', 'merchboard' ),
				'path'       => 'payouts',
				'capability' => Capabilities::ACCESS_DASHBOARD,
			),
		);
		$result         = array();
		$added          = false;

		foreach ( $sections as $section ) {
			$result[] = $section;

			if ( ! $added && is_array( $section ) && 'overview' === ( $section['id'] ?? null ) ) {
				array_push( $result, ...$added_sections );
				$added = true;
			}
		}

		if ( ! $added ) {
			array_push( $result, ...$added_sections );
		}

		return $result;
	}
}
