<?php
/**
 * Runs the import from MerchAlert.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Import;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertSettings;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessageRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Fonts\AlertFont;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Fonts\AlertFontsException;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlaySounds;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayStore;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\PaidOrder;
use MerchBoard\Modules\Alerts\PaidOrderReader;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;

defined( 'ABSPATH' ) || exit;

/**
 * The import from MerchAlert (step 25.10.1, ADR-065 pkt. 10), after the
 * administrator confirmed a dry run:
 *
 * 1. MerchAlert must be inactive and its data present, and a fresh dry run
 *    must have the confirmed fingerprint (otherwise nothing happens);
 * 2. per imported creator one transaction: the overlay with MerchAlert's id
 *    and settings (new or replacing; an earlier import's overlay stays as
 *    the creator changed it), their sounds become theirs, every alert with
 *    its time (never a first name), the Twitch chatbot's template and
 *    switch (first import only) and the announced lines (status sent, so old
 *    orders are never announced again); audited `alert_creator_imported`;
 * 3. once: the sound limits and the checkout username field, the Google
 *    Fonts key and approved fonts, and the global overlay with its id,
 *    switched off (the project owner's choice); then the marker option;
 * 4. `alert_import_completed` with the counts.
 *
 * Twitch tokens and the app secret are never imported (ADR-066): the bot is
 * logged in again, and creators add it to their channel again.
 */
class MerchAlertImporter {

	/**
	 * Marker of a MerchAlert file the import took over: it stays MerchAlert's
	 * and is never deleted on uninstall (25.13 review).
	 */
	public const IMPORTED_META = '_merchboard_alert_imported';

	/**
	 * Constructor.
	 *
	 * @param MerchAlertImportPlan     $plan         The dry run.
	 * @param MerchAlertSource         $source       MerchAlert's data.
	 * @param AlertsAvailability       $availability MerchAlert guard.
	 * @param Transaction              $transaction  One transaction per creator.
	 * @param AuditTrail               $audit        Audit log.
	 * @param AlertOverlayRepository   $overlays     Creators' overlays.
	 * @param AlertEventRepository     $events       Alerts.
	 * @param ChatbotChannelRepository $channels     Chatbot settings.
	 * @param ChatMessageRepository    $messages     Chat message log.
	 * @param PaidOrderReader          $orders       Orders (username, variation).
	 * @param AlertSettings            $settings     Alert settings.
	 * @param AlertFonts               $fonts        Fonts.
	 * @param GlobalOverlayStore       $global_overlays The global overlay.
	 */
	public function __construct(
		private MerchAlertImportPlan $plan,
		private MerchAlertSource $source,
		private AlertsAvailability $availability,
		private Transaction $transaction,
		private AuditTrail $audit,
		private AlertOverlayRepository $overlays,
		private AlertEventRepository $events,
		private ChatbotChannelRepository $channels,
		private ChatMessageRepository $messages,
		private PaidOrderReader $orders,
		private AlertSettings $settings,
		private AlertFonts $fonts,
		private GlobalOverlayStore $global_overlays
	) {
	}

	/**
	 * The dry run, when the import can run at all.
	 *
	 * @throws MerchAlertImportException When MerchAlert is active or has no data.
	 * @throws DatabaseException When the database cannot be read.
	 * @throws MerchAlertImportException When the current user lacks the alerts capability.
	 */
	public function preview(): MerchAlertImportReport {
		self::authorize();

		$this->preview_check();

		return $this->plan->build();
	}

	/**
	 * Whether the import can run at all (MerchAlert inactive, its data here).
	 *
	 * @throws MerchAlertImportException When MerchAlert is active or has no data.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function preview_check(): void {
		if ( $this->availability->merchalert_active() ) {
			self::refuse( MerchAlertImportException::MERCHALERT_ACTIVE );
		}

		if ( ! $this->source->available() ) {
			self::refuse( MerchAlertImportException::NO_DATA );
		}
	}

	/**
	 * Refuses the import.
	 *
	 * @param string $reason MerchAlertImportException constant.
	 * @throws MerchAlertImportException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new MerchAlertImportException( $reason );
	}

	/**
	 * Imports, when the dry run is still the confirmed one.
	 *
	 * @param string $fingerprint The confirmed dry run's fingerprint.
	 * @return array{creators: int, events: int, messages: int} What was added.
	 * @throws MerchAlertImportException When refused.
	 * @throws DatabaseException When it cannot be stored (the creator's transaction is rolled back).
	 * @throws MerchAlertImportException When the current user lacks the alerts capability.
	 */
	public function run( string $fingerprint ): array {
		self::authorize();

		$report = $this->preview();

		if ( ! hash_equals( $report->fingerprint(), $fingerprint ) ) {
			self::refuse( MerchAlertImportException::PREVIEW_OUTDATED );
		}

		$added  = array(
			'creators' => 0,
			'events'   => 0,
			'messages' => 0,
		);
		$wanted = array();

		foreach ( $this->source->creators() as $creator ) {
			$wanted[ $creator['id'] ] = $creator;
		}

		foreach ( $report->creators as $row ) {
			$creator = $wanted[ $row['id'] ] ?? null;

			if ( null === $creator || ! MerchAlertImportReport::imports( $row['status'] ) ) {
				continue;
			}

			$counts = $this->transaction->run( fn(): array => $this->import_creator( $creator, $row['creator_id'], $row['status'] ) );

			if ( MerchAlertImportReport::IMPORTED !== $row['status'] ) {
				++$added['creators'];
			}

			$added['events']   += $counts[0];
			$added['messages'] += $counts[1];
		}

		if ( ! $report->settings['done'] ) {
			$this->import_once( $report );
		}

		// Marks the first import (settings, fonts, global overlay) and shows the last run; together with its audit record (25.13 review).
		$this->transaction->run(
			function () use ( $added, $report ): void {
				update_option(
					MerchAlertImportPlan::MARKER_OPTION,
					array(
						'completed_at' => gmdate( 'Y-m-d H:i:s' ),
						'last'         => $added,
					),
					false
				);
				$this->audit->record( AuditAction::AlertImportCompleted, null, array_merge( $report->audit_context(), array( 'added_events' => $added['events'] ) ) );
			}
		);

		return $added;
	}

	/**
	 * One creator, inside its transaction.
	 *
	 * @param array{id: int, name: string, status: string, term_id: int, overlay_id: string, settings: array<string, mixed>} $creator MerchAlert's creator.
	 * @param int                                                                                                            $creator_id Our creator.
	 * @param string                                                                                                         $status     The dry run's status.
	 * @return array{0: int, 1: int} Alerts and chat messages added.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function import_creator( array $creator, int $creator_id, string $status ): array {
		if ( MerchAlertImportReport::IMPORTED !== $status ) {
			[ $settings, $sounds ] = MerchAlertMapping::sounds( MerchAlertMapping::settings( $creator['settings'] ), $this->plan->creator_sound( $creator['id'] ) );
			$current               = $this->overlays->find_for_creator( $creator_id );

			if ( null === $current ) {
				$this->overlays->create( $creator_id, $creator['overlay_id'], $settings );
			} else {
				$this->overlays->update_overlay_id( $current->id, $creator['overlay_id'] );
				$this->overlays->update_settings( $current->id, $settings );
			}

			foreach ( $sounds as $sound ) {
				update_post_meta( $sound, AlertSounds::OWNER_META, (string) $creator_id );
				update_post_meta( $sound, self::IMPORTED_META, '1' );
			}

			$chatbot = $this->source->chatbot( $creator['id'] );

			if ( null !== $chatbot ) {
				$this->channels->save_template( $creator_id, MerchAlertMapping::chat_template( $chatbot['template'] ) );
				$this->channels->save_enabled( $creator_id, ChatPlatform::Twitch, $chatbot['enabled'] );
			}
		}

		$events = 0;
		$orders = array();
		$rows   = $this->source->events( $creator['id'] );
		$lines  = array();

		// The creator's lines per order, for the creator's total ($Total).
		foreach ( $rows as $row ) {
			$lines[ $row['order_id'] ][] = $row['order_item_id'];
		}

		foreach ( $rows as $row ) {
			if ( ! array_key_exists( $row['order_id'], $orders ) ) {
				$orders[ $row['order_id'] ] = $this->order( $row['order_id'] );
			}

			$order = $orders[ $row['order_id'] ];
			$event = MerchAlertMapping::event( $row, $creator_id, $order, MerchAlertMapping::creator_total( $lines[ $row['order_id'] ] ?? array(), $order ) );

			if ( null !== $event && $this->events->insert( $event, true ) ) {
				++$events;
			}
		}

		$messages = 0;

		foreach ( $this->source->messages( $creator['id'] ) as $row ) {
			if ( $this->messages->import_sent( $creator_id, ChatPlatform::Twitch, $row['order_id'], $row['order_item_id'], $row['created_at'] ) ) {
				++$messages;
			}
		}

		if ( MerchAlertImportReport::IMPORTED !== $status || $events + $messages > 0 ) {
			$this->audit->record(
				AuditAction::AlertCreatorImported,
				$creator_id,
				array(
					'status'   => $status,
					'events'   => $events,
					'messages' => $messages,
				)
			);
		}

		return array( $events, $messages );
	}

	/**
	 * The settings, fonts and global overlay, once.
	 *
	 * @param MerchAlertImportReport $report The dry run.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function import_once( MerchAlertImportReport $report ): void {
		$wanted = $report->settings;

		if ( null !== $wanted['sound_max_bytes'] && null !== $wanted['sound_max_seconds'] ) {
			$this->settings->set_sound_limits( $wanted['sound_max_bytes'], $wanted['sound_max_seconds'] );
		}

		$this->settings->set_checkout_username(
			$wanted['username_enabled'],
			'' === $wanted['username_label'] ? Settings::checkout_username_label() : $wanted['username_label'],
			Settings::checkout_username_help()
		);

		$merchalert = $this->source->settings();
		$key        = is_string( $merchalert['google_fonts_api_key'] ?? null ) ? trim( $merchalert['google_fonts_api_key'] ) : '';

		if ( '' !== $key && ! $this->fonts->has_key() ) {
			try {
				$this->fonts->set_key( $key );
			} catch ( AlertFontsException ) {
				// An invalid key is left out; the administrator can save one.
				$key = '';
			}
		}

		$fonts = array();

		foreach ( $this->source->approved_fonts() as $font ) {
			$font = AlertFont::from_array( $font );

			if ( null !== $font ) {
				$fonts[] = $font;
			}
		}

		$this->fonts->import_approved( $fonts );

		$stored = $this->source->global_overlay();

		if ( null !== $stored && in_array( $report->global_overlay['status'], array( MerchAlertImportReport::NEW, MerchAlertImportReport::REPLACE ), true ) && is_string( $stored['overlay_id'] ?? null ) ) {
			[ $settings, $sounds ] = MerchAlertMapping::sounds( MerchAlertMapping::settings( $stored, $this->source->legacy_layout() ), $this->plan->global_sound() );

			foreach ( $sounds as $sound ) {
				update_post_meta( $sound, GlobalOverlaySounds::OWNER_META, '1' );
				update_post_meta( $sound, self::IMPORTED_META, '1' );
			}

			$this->global_overlays->import( $stored['overlay_id'], $settings );
		}
	}

	/**
	 * An order now, or null when it is gone.
	 *
	 * @param int $order_id Order.
	 */
	private function order( int $order_id ): ?PaidOrder {
		return $order_id > 0 ? $this->orders->read( $order_id ) : null;
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws MerchAlertImportException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new MerchAlertImportException( MerchAlertImportException::NOT_AUTHORIZED );
		}
	}
}
