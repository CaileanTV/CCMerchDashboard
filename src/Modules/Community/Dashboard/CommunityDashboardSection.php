<?php
/**
 * The creator dashboard's menu "Community Features".
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Dashboard;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Dashboard\DashboardSections;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the group "Community Features" (ROADMAP "Community Features") with
 * its pages, the Twitch subscriber discount (phase 43) and Twitch gifting
 * (phase 44), right before the profile.
 */
final class CommunityDashboardSection {

	/**
	 * Hooks the section into the dashboard.
	 */
	public function register(): void {
		add_filter( DashboardSections::FILTER, array( self::class, 'add_section' ) );
	}

	/**
	 * Adds the group before the profile (at the end when there is none).
	 *
	 * @param mixed $sections Sections.
	 * @return mixed
	 */
	public static function add_section( mixed $sections ): mixed {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}

		$group = array(
			array(
				'id'          => 'community-twitch-discount',
				'label'       => __( 'Twitch subscriber discount', 'merchboard' ),
				'path'        => 'community/twitch-discount',
				'capability'  => Capabilities::ACCESS_DASHBOARD,
				'group'       => 'community',
				'group_label' => __( 'Community Features', 'merchboard' ),
			),
			array(
				'id'          => 'community-twitch-gifting',
				'label'       => __( 'Twitch gifting', 'merchboard' ),
				'path'        => 'community/twitch-gifting',
				'capability'  => Capabilities::ACCESS_DASHBOARD,
				'group'       => 'community',
				'group_label' => __( 'Community Features', 'merchboard' ),
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
