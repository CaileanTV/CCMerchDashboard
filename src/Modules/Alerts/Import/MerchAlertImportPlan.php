<?php
/**
 * The dry run of the import from MerchAlert.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Import;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayStore;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what the import would do, without writing anything (step
 * 25.10.1). A MerchAlert creator is matched by their category to the
 * creator the category belongs to here (`merchboard_creator_categories`):
 *
 * - **new:** the creator has no overlay yet; it gets MerchAlert's id;
 * - **replace:** the creator has an overlay with another id; MerchAlert's id
 *   and settings replace it (the project owner's choice 2026-10-23);
 * - **imported:** the creator has MerchAlert's id already (an earlier
 *   import); only missing alerts and chat messages are added;
 * - skipped and shown: no category, no creator for it, no overlay in
 *   MerchAlert, a second MerchAlert creator for the same creator, or an id
 *   another creator or the global overlay has.
 */
class MerchAlertImportPlan {

	/**
	 * The option that marks the first import (settings, fonts and the global
	 * overlay are imported once).
	 */
	public const MARKER_OPTION = 'merchboard_alert_import';

	/**
	 * MerchAlert's default checkout label (not imported).
	 */
	public const DEFAULT_LABEL = 'Stream Username';

	/**
	 * Constructor.
	 *
	 * @param MerchAlertSource          $source     MerchAlert's data.
	 * @param CreatorCategoryRepository $categories Category mapping.
	 * @param CreatorRepository         $creators   Creators.
	 * @param AlertOverlayRepository    $overlays   Creators' overlays.
	 * @param GlobalOverlayStore        $global_overlays The global overlay.
	 */
	public function __construct(
		private MerchAlertSource $source,
		private CreatorCategoryRepository $categories,
		private CreatorRepository $creators,
		private AlertOverlayRepository $overlays,
		private GlobalOverlayStore $global_overlays
	) {
	}

	/**
	 * Whether the settings, fonts and the global overlay were imported.
	 */
	public static function done(): bool {
		wp_cache_delete( self::MARKER_OPTION, 'options' );

		return is_array( get_option( self::MARKER_OPTION, null ) );
	}

	/**
	 * The dry run.
	 *
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function build(): MerchAlertImportReport {
		$rows   = array();
		$taken  = array();
		$global = $this->global_overlays->find();

		foreach ( $this->source->creators() as $creator ) {
			$row = array(
				'id'           => $creator['id'],
				'name'         => $creator['name'],
				'term_id'      => $creator['term_id'],
				'creator_id'   => 0,
				'creator_name' => '',
				'status'       => MerchAlertImportReport::NO_CATEGORY,
				'events'       => 0,
				'messages'     => 0,
				'sounds'       => 0,
				'chatbot'      => false,
			);

			$category = $creator['term_id'] > 0 ? $this->categories->find( $creator['term_id'] ) : null;

			if ( $creator['term_id'] > 0 ) {
				$row['status'] = MerchAlertImportReport::NO_MATCH;
			}

			if ( null !== $category ) {
				$row['creator_id']   = $category->creator_id;
				$row['creator_name'] = $this->creators->find( $category->creator_id )->display_name ?? '';
				$row['status']       = $this->status( $creator['overlay_id'], $category->creator_id, $taken, $global->overlay_id ?? '' );
			}

			if ( MerchAlertImportReport::imports( $row['status'] ) ) {
				$taken[ $category->creator_id ?? 0 ] = true;
				$row['events']                       = count( $this->source->events( $creator['id'] ) );
				$row['messages']                     = count( $this->source->messages( $creator['id'] ) );
				$row['sounds']                       = count( MerchAlertMapping::sounds( MerchAlertMapping::settings( $creator['settings'] ), $this->creator_sound( $creator['id'] ) )[1] );
				$row['chatbot']                      = null !== $this->source->chatbot( $creator['id'] );
			}

			$rows[] = $row;
		}

		return new MerchAlertImportReport( $rows, $this->global_status( $global->overlay_id ?? '' ), $this->settings() );
	}

	/**
	 * Whether a sound attachment may stay chosen for a MerchAlert creator:
	 * their own or an administrator's upload.
	 *
	 * @param int $creator_id MerchAlert creator.
	 * @return \Closure(int): bool
	 */
	public function creator_sound( int $creator_id ): \Closure {
		return function ( int $attachment ) use ( $creator_id ): bool {
			$owner = $this->source->sound_owner( $attachment );

			return null !== $owner && ( 0 === $owner || $creator_id === $owner );
		};
	}

	/**
	 * Whether a sound attachment may stay chosen for the global overlay: an
	 * administrator's upload, never a creator's.
	 *
	 * @return \Closure(int): bool
	 */
	public function global_sound(): \Closure {
		return fn( int $attachment ): bool => 0 === $this->source->sound_owner( $attachment );
	}

	/**
	 * A matched creator's status.
	 *
	 * @param string           $overlay_id     MerchAlert's overlay id.
	 * @param int              $creator_id     Our creator.
	 * @param array<int, bool> $taken          Creators an earlier MerchAlert creator imports into.
	 * @param string           $global_id      The global overlay's id here.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function status( string $overlay_id, int $creator_id, array $taken, string $global_id ): string {
		if ( ! OverlayId::valid( $overlay_id ) ) {
			return MerchAlertImportReport::NO_OVERLAY;
		}

		if ( isset( $taken[ $creator_id ] ) ) {
			return MerchAlertImportReport::DUPLICATE;
		}

		$owner = $this->overlays->find_by_overlay_id( $overlay_id );

		if ( ( null !== $owner && $owner->creator_id !== $creator_id ) || hash_equals( $global_id, $overlay_id ) ) {
			return MerchAlertImportReport::ID_TAKEN;
		}

		$current = $this->overlays->find_for_creator( $creator_id );

		return match ( true ) {
			null === $current => MerchAlertImportReport::NEW,
			$current->overlay_id === $overlay_id => MerchAlertImportReport::IMPORTED,
			default => MerchAlertImportReport::REPLACE,
		};
	}

	/**
	 * The global overlay's status and sounds.
	 *
	 * @param string $global_id The global overlay's id here.
	 * @return array{status: string, sounds: int}
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function global_status( string $global_id ): array {
		$stored = $this->source->global_overlay();
		$id     = is_string( $stored['overlay_id'] ?? null ) ? $stored['overlay_id'] : '';

		if ( null === $stored || ! OverlayId::valid( $id ) ) {
			return array(
				'status' => MerchAlertImportReport::NONE,
				'sounds' => 0,
			);
		}

		$status = match ( true ) {
			'' === $global_id => MerchAlertImportReport::NEW,
			$global_id === $id => MerchAlertImportReport::IMPORTED,
			default => MerchAlertImportReport::REPLACE,
		};

		if ( null !== $this->overlays->find_by_overlay_id( $id ) ) {
			$status = MerchAlertImportReport::ID_TAKEN;
		}

		return array(
			'status' => $status,
			'sounds' => count( MerchAlertMapping::sounds( MerchAlertMapping::settings( $stored, $this->source->legacy_layout() ), $this->global_sound() )[1] ),
		);
	}

	/**
	 * The settings and fonts to import.
	 *
	 * @return array{sound_max_bytes: int|null, sound_max_seconds: int|null, username_enabled: bool, username_label: string, fonts: int, api_key: bool, done: bool}
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function settings(): array {
		$settings = $this->source->settings();
		$bytes    = is_numeric( $settings['max_audio_size'] ?? null ) ? (int) $settings['max_audio_size'] : 0;
		$seconds  = is_numeric( $settings['max_audio_duration'] ?? null ) ? (int) $settings['max_audio_duration'] : 0;
		$label    = is_string( $settings['username_label'] ?? null ) ? trim( $settings['username_label'] ) : '';
		$key      = is_string( $settings['google_fonts_api_key'] ?? null ) ? trim( $settings['google_fonts_api_key'] ) : '';
		$enabled  = $settings['username_enabled'] ?? 0;

		return array(
			'sound_max_bytes'   => Settings::valid_sound_max_bytes( $bytes ) ? $bytes : null,
			'sound_max_seconds' => Settings::valid_sound_max_seconds( $seconds ) ? $seconds : null,
			'username_enabled'  => in_array( $enabled, array( 1, '1', true ), true ),
			'username_label'    => self::DEFAULT_LABEL === $label ? '' : mb_substr( $label, 0, 100 ),
			'fonts'             => count( $this->source->approved_fonts() ),
			'api_key'           => '' !== $key,
			'done'              => self::done(),
		);
	}
}
