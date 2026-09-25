<?php
/**
 * Creator dashboard sections.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The registry of dashboard sections (docs/FRONTEND.md#sektioner): which
 * sections exist, in which order, and which of them the current user may see.
 *
 * Each section is `{ id, label, path, capability }`. Modules add sections with
 * the `merchboard_dashboard_sections` filter; the frontend renders a section
 * only when this build has a component for its id. The server decides the
 * list: sections whose capability the user lacks are never sent.
 *
 * Filtered entries are validated; invalid ones (bad id, label, path or
 * capability, duplicate id or path) are dropped and logged. The overview at
 * the start page ('') always exists.
 */
final class DashboardSections {

	/**
	 * Filter for adding, removing or reordering sections.
	 */
	public const FILTER = 'merchboard_dashboard_sections';

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Technical log.
	 */
	public function __construct( private Logger $logger ) {
	}

	/**
	 * Built-in sections.
	 *
	 * @return array<int, array{id: string, label: string, path: string, capability: string}>
	 */
	public function defaults(): array {
		return array(
			array(
				'id'         => 'overview',
				'label'      => __( 'Overview', 'merchboard' ),
				'path'       => '',
				'capability' => Capabilities::ACCESS_DASHBOARD,
			),
			array(
				'id'         => 'profile',
				'label'      => __( 'Profile', 'merchboard' ),
				'path'       => 'profile',
				'capability' => Capabilities::ACCESS_DASHBOARD,
			),
		);
	}

	/**
	 * Every valid section after the filter, in order.
	 *
	 * @return array<int, array{id: string, label: string, path: string, capability: string}>
	 */
	public function all(): array {
		/**
		 * Filters the creator dashboard sections.
		 *
		 * @param array<int, array{id: string, label: string, path: string, capability: string}> $sections Sections in display order.
		 */
		$filtered = apply_filters( 'merchboard_dashboard_sections', $this->defaults() );
		$sections = array();
		$ids      = array();
		$paths    = array();

		foreach ( self::entries( $filtered ) as $candidate ) {
			$section = $this->validated( $candidate );

			if ( null === $section || isset( $ids[ $section['id'] ] ) || isset( $paths[ $section['path'] ] ) ) {
				$this->logger->warning( 'Invalid or duplicate dashboard section ignored.', array( 'filter' => self::FILTER ) );
				continue;
			}

			$ids[ $section['id'] ]     = true;
			$paths[ $section['path'] ] = true;
			$sections[]                = $section;
		}

		if ( ! isset( $paths[''] ) ) {
			array_unshift( $sections, $this->defaults()[0] );
		}

		return $sections;
	}

	/**
	 * The sections the current user may see, for the bootstrap data (the
	 * capability itself is not sent).
	 *
	 * @return array<int, array{id: string, label: string, path: string}>
	 */
	public function for_current_user(): array {
		$visible = array();

		foreach ( $this->all() as $section ) {
			if ( current_user_can( $section['capability'] ) ) {
				$visible[] = array(
					'id'    => $section['id'],
					'label' => $section['label'],
					'path'  => $section['path'],
				);
			}
		}

		return $visible;
	}

	/**
	 * The filtered value as a list (a faulty filter may return anything).
	 *
	 * @param mixed $value Filter result.
	 * @return array<mixed>
	 */
	private static function entries( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * A valid section, or null.
	 *
	 * @param mixed $candidate Filtered entry.
	 * @return array{id: string, label: string, path: string, capability: string}|null
	 */
	private function validated( mixed $candidate ): ?array {
		if ( ! is_array( $candidate ) ) {
			return null;
		}

		$id         = $candidate['id'] ?? null;
		$label      = $candidate['label'] ?? null;
		$path       = $candidate['path'] ?? null;
		$capability = $candidate['capability'] ?? null;

		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,39}$/', $id ) ) {
			return null;
		}

		if ( ! is_string( $label ) || '' === trim( $label ) || ! is_string( $capability ) || '' === $capability || ! is_string( $path ) ) {
			return null;
		}

		$normalized = DashboardRoute::normalize_path( $path );

		if ( '' === $normalized && '' !== trim( $path, '/' ) ) {
			return null;
		}

		return array(
			'id'         => $id,
			'label'      => trim( wp_strip_all_tags( $label ) ),
			'path'       => $normalized,
			'capability' => $capability,
		);
	}
}
