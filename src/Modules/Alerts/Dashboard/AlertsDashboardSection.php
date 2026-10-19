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
	 * Inserts the section before "profile", or at the end.
	 *
	 * @param mixed $sections Sections.
	 * @return mixed
	 */
	public static function add_section( mixed $sections ): mixed {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}

		$section = array(
			'id'         => 'alerts',
			'label'      => __( 'Alerts', 'merchboard' ),
			'path'       => 'alerts',
			'capability' => Capabilities::ACCESS_DASHBOARD,
		);
		$result  = array();
		$added   = false;

		foreach ( $sections as $existing ) {
			if ( ! $added && is_array( $existing ) && 'profile' === ( $existing['id'] ?? null ) ) {
				$result[] = $section;
				$added    = true;
			}

			$result[] = $existing;
		}

		if ( ! $added ) {
			$result[] = $section;
		}

		return $result;
	}
}
