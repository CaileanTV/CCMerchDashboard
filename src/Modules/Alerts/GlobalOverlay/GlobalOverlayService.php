<?php
/**
 * Managing the global alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\GlobalOverlay;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator's actions on the global overlay (step 25.9.4): set it
 * up, change its settings (the same fields and rules as a creator's,
 * AlertOverlays::merge()), switch it on or off, give it a new URL, send a
 * test alert and replay the latest order. Tests and replays go only into
 * its own queue (scope 'global'), never a creator's and never the chat.
 * Setting up, switching and a new URL are audited; settings are not (as a
 * creator's look-and-feel). The callers check `merchboard_manage_alerts`.
 */
final class GlobalOverlayService {

	/**
	 * Username of a test alert (as a creator's).
	 */
	public const TEST_USERNAME = AlertOverlays::TEST_USERNAME;

	/**
	 * Constructor.
	 *
	 * @param GlobalOverlayStore       $store  The global overlay.
	 * @param GlobalOverlayFeed        $feed   Rendering.
	 * @param OverlayTestQueue         $tests  Test alerts (scope 'global').
	 * @param AlertEventRepository     $events Alerts.
	 * @param AuditTrail               $audit  Audit log.
	 * @param AlertFonts|null          $fonts  Approved fonts.
	 * @param GlobalOverlayImages|null $images Text fill images; null clears image fills.
	 */
	public function __construct(
		private GlobalOverlayStore $store,
		private GlobalOverlayFeed $feed,
		private OverlayTestQueue $tests,
		private AlertEventRepository $events,
		private AuditTrail $audit,
		private ?AlertFonts $fonts = null,
		private ?GlobalOverlayImages $images = null
	) {
	}

	/**
	 * The overlay, or null before it is set up.
	 */
	public function find(): ?GlobalOverlay {
		return $this->store->find();
	}

	/**
	 * Sets the overlay up (switched off); the existing one when it is set up.
	 *
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create(): GlobalOverlay {
		[ $overlay, $created ] = $this->store->create();

		if ( $created ) {
			$this->audit->record( AuditAction::AlertGlobalOverlayCreated, null );
		}

		return $overlay;
	}

	/**
	 * Changes the settings.
	 *
	 * @param array<mixed> $input Submitted fields; unknown keys are ignored.
	 * @return GlobalOverlay|null The changed overlay; null before it is set up.
	 * @throws InvalidArgumentException When the font is not approved (message: the field).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update_settings( array $input ): ?GlobalOverlay {
		$overlay = $this->store->find();

		if ( null === $overlay ) {
			return null;
		}

		$settings = AlertOverlays::merge( $overlay->settings, $input, $this->fonts );

		// A fill keeps only the global overlay's own image or a library image.
		if ( is_array( $input['text_fills'] ?? null ) && null !== $this->images ) {
			$values               = $settings->to_array();
			$values['text_fills'] = $this->images->clean( $values['text_fills'] );
			$settings             = OverlaySettings::from_array( $values );
		}

		return $this->store->update_settings( $overlay, $settings );
	}

	/**
	 * Switches the overlay on or off.
	 *
	 * @param bool $enabled Whether it is on.
	 * @return bool|null Whether it changed; null before it is set up.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_enabled( bool $enabled ): ?bool {
		$overlay = $this->store->find();

		if ( null === $overlay ) {
			return null;
		}

		if ( $overlay->enabled === $enabled ) {
			return false;
		}

		$this->store->set_enabled( $overlay, $enabled );
		$this->audit->record( AuditAction::AlertGlobalOverlayToggled, null, array( 'enabled' => $enabled ) );

		return true;
	}

	/**
	 * A new URL; the old one stops working at once.
	 *
	 * @return GlobalOverlay|null The changed overlay; null before it is set up.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function regenerate(): ?GlobalOverlay {
		$overlay = $this->store->find();

		if ( null === $overlay ) {
			return null;
		}

		$changed = $this->store->regenerate( $overlay );
		$this->audit->record( AuditAction::AlertGlobalOverlayIdRegenerated, null );

		return $changed;
	}

	/**
	 * A test alert: the newest alert's product with the test username, or a
	 * test product when there is none yet.
	 *
	 * @return bool False before it is set up.
	 * @throws DatabaseException When the alerts cannot be read.
	 */
	public function send_test(): bool {
		$overlay = $this->store->find();

		if ( null === $overlay ) {
			return false;
		}

		$latest = $this->latest_order();
		$model  = $latest[0] ?? null;
		$event  = new AlertEvent(
			$model->creator_id ?? 0,
			0,
			'0001',
			0,
			$model->product_id ?? 0,
			$model->variation_id ?? 0,
			$model->product_name ?? __( 'Test product', 'merchboard' ),
			$model->variation ?? '',
			1,
			$model->image_id ?? 0,
			$model->category_ids ?? array(),
			self::TEST_USERNAME,
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
		);

		$this->tests->push( GlobalOverlay::QUEUE, $this->feed->render( $overlay, $event ) );

		return true;
	}

	/**
	 * Shows the latest order's alerts again, as they were recorded.
	 *
	 * @return int|null The number of alerts queued (0: no order yet); null before it is set up.
	 * @throws DatabaseException When the alerts cannot be read.
	 */
	public function replay_latest(): ?int {
		$overlay = $this->store->find();

		if ( null === $overlay ) {
			return null;
		}

		$events = $this->latest_order();

		foreach ( $events as $event ) {
			$this->tests->push( GlobalOverlay::QUEUE, array_merge( $this->feed->render( $overlay, $event ), array( 'id' => 0 ) ) );
		}

		return count( $events );
	}

	/**
	 * Every alert of the newest order with alerts, oldest first.
	 *
	 * @return list<AlertEvent>
	 * @throws DatabaseException When the alerts cannot be read.
	 */
	private function latest_order(): array {
		return $this->events->for_orders( $this->events->log_page( null, new Pagination( 1, 1 ) ) );
	}
}
