<?php
/**
 * The "News" section of the creator dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Dashboard\DashboardSections;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "News" right after "Overview" (ADR-072): the published messages,
 * which the creator can read again after closing the popup.
 */
final class NewsDashboardSection {

	/**
	 * Registers the filter.
	 */
	public function register(): void {
		add_filter( DashboardSections::FILTER, array( self::class, 'add_section' ) );
	}

	/**
	 * Inserts the section after "overview", or at the end.
	 *
	 * @param mixed $sections Sections.
	 * @return mixed
	 */
	public static function add_section( mixed $sections ): mixed {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}

		$news   = array(
			'id'         => 'news',
			'label'      => __( 'News', 'merchboard' ),
			'path'       => 'news',
			'capability' => Capabilities::ACCESS_DASHBOARD,
		);
		$result = array();
		$added  = false;

		foreach ( $sections as $existing ) {
			$result[] = $existing;

			if ( ! $added && is_array( $existing ) && 'overview' === ( $existing['id'] ?? null ) ) {
				$result[] = $news;
				$added    = true;
			}
		}

		if ( ! $added ) {
			$result[] = $news;
		}

		return $result;
	}
}
