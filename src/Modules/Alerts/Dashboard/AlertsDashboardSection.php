<?php
/**
 * The "Alerts" section of the creator dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Dashboard;

use Closure;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Dashboard\DashboardSections;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Alerts" (ADR-065 pkt. 3) before "Profile", so the creator's own
 * settings stay last. Every creator with dashboard access sees it; the
 * overlay itself is set up from the section.
 */
final class AlertsDashboardSection {

	/**
	 * Constructor.
	 *
	 * @param (Closure(): array{keys: bool, webhooks: bool})|null $access The current creator's access to API keys and webhooks (step 25.12.4); null shows both pages.
	 */
	public function __construct( private ?Closure $access = null ) {
	}

	/**
	 * Registers the filter.
	 */
	public function register(): void {
		add_filter( DashboardSections::FILTER, fn( mixed $sections ): mixed => self::add_section( $sections, null === $this->access ? null : ( $this->access )() ) );
	}

	/**
	 * Inserts the group "Alerts" before "profile", or at the end: the
	 * overlay settings (the former "Alerts" page, same URL) and the history
	 * (filled in step 25.7).
	 *
	 * @param mixed                                  $sections Sections.
	 * @param array{keys: bool, webhooks: bool}|null $access   The creator's access to the API pages; null for both.
	 * @return mixed
	 */
	public static function add_section( mixed $sections, ?array $access = null ): mixed {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}

		$group = array(
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
			// The Twitch chatbot (step 25.8).
			array(
				'id'          => 'alerts-chatbot',
				'label'       => __( 'Chatbot', 'merchboard' ),
				'path'        => 'alerts/chatbot',
				'capability'  => Capabilities::ACCESS_DASHBOARD,
				'group'       => 'alerts',
				'group_label' => __( 'Alerts', 'merchboard' ),
			),
		);

		// The alert API (steps 25.12.1 and 25.12.2, ADR-073): only while the
		// administrator has it on. Keys (the bot asks) and webhooks (the shop
		// sends) work apart, so each has its own page.
		// Each page only with the administrator's access to it (step 25.12.4).
		if ( AlertApiSwitch::on() && ( $access['keys'] ?? true ) ) {
			$group[] = array(
				'id'          => 'alerts-api',
				'label'       => __( 'API keys', 'merchboard' ),
				'path'        => 'alerts/api',
				'capability'  => Capabilities::ACCESS_DASHBOARD,
				'group'       => 'alerts',
				'group_label' => __( 'Alerts', 'merchboard' ),
			);
		}

		if ( AlertApiSwitch::on() && ( $access['webhooks'] ?? true ) ) {
			$group[] = array(
				'id'          => 'alerts-webhooks',
				'label'       => __( 'Webhooks', 'merchboard' ),
				'path'        => 'alerts/webhooks',
				'capability'  => Capabilities::ACCESS_DASHBOARD,
				'group'       => 'alerts',
				'group_label' => __( 'Alerts', 'merchboard' ),
			);
		}

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
