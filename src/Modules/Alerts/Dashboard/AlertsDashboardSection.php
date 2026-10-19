<?php
/**
 * The "Alerts" section of the creator dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Dashboard;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Dashboard\DashboardSections;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Alerts" (ADR-065 pkt. 3) before "Profile", so the creator's own
 * settings stay last. Every creator with dashboard access sees it; the
 * overlay itself is set up from the section.
 */
final class AlertsDashboardSection {

	/**
	 * Registers the filter.
	 */
	public function register(): void {
		add_filter( DashboardSections::FILTER, array( self::class, 'add_section' ) );
	}

	/**
	 * Inserts the group "Alerts" before "profile", or at the end: the
	 * overlay settings (the former "Alerts" page, same URL) and the history
	 * (filled in step 25.7).
	 *
	 * @param mixed $sections Sections.
	 * @return mixed
	 */
	public static function add_section( mixed $sections ): mixed {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}

		$group  = array(
			array(
				'id'          => 'alerts',
				'label'       => __( 'Overlay settings', 'merchboard' ),
				'path'        => 'alerts',
				'capability'  => Capabilities::ACCESS_DASHBOARD,
				'group'       => 'alerts',
				'group_label' => __( 'Alerts', 'merchboard' ),
			),
			array(
				'id'          => 'alerts-history',
				'label'       => __( 'History', 'merchboard' ),
				'path'        => 'alerts/history',
				'capability'  => Capabilities::ACCESS_DASHBOARD,
				'group'       => 'alerts',
				'group_label' => __( 'Alerts', 'merchboard' ),
			),
		);
		$result = array();
		$added  = false;

		foreach ( $sections as $existing ) {
			if ( ! $added && is_array( $existing ) && 'profile' === ( $existing['id'] ?? null ) ) {
				array_push( $result, ...$group );
				$added = true;
			}

			$result[] = $existing;
		}

		if ( ! $added ) {
			array_push( $result, ...$group );
		}

		return $result;
	}
}
