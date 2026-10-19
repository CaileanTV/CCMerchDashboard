<?php
/**
 * A creator's alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_alert_overlays` (ADR-065).
 */
final readonly class AlertOverlay {

	/**
	 * Constructor.
	 *
	 * @param int             $id         Row id.
	 * @param int             $creator_id Creator.
	 * @param string          $overlay_id Secret overlay id.
	 * @param OverlaySettings $settings   Settings.
	 * @param string          $updated_at Last change (UTC, `Y-m-d H:i:s`); part of the version.
	 */
	public function __construct(
		public int $id,
		public int $creator_id,
		public string $overlay_id,
		public OverlaySettings $settings,
		public string $updated_at
	) {
	}

	/**
	 * A version that changes whenever the settings or the id change; the
	 * overlay page reloads when it differs from the one it started with.
	 */
	public function version(): string {
		return substr( hash( 'sha256', $this->overlay_id . '|' . $this->updated_at . '|' . $this->settings->to_json() ), 0, 16 );
	}
}
