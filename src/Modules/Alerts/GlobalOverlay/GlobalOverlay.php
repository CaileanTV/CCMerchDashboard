<?php
/**
 * The global alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\GlobalOverlay;

use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator's overlay for every creator's alerts (ADR-065 pkt. 7,
 * step 25.9.4). It has no creator and shares nothing with a creator's
 * overlay but the settings schema (OverlaySettings).
 */
final readonly class GlobalOverlay {

	/**
	 * The test queue's row number (OverlayTestQueue in the scope 'global').
	 */
	public const QUEUE = 1;

	/**
	 * Constructor.
	 *
	 * @param string          $overlay_id Secret id in the browser source URL.
	 * @param bool            $enabled    Whether the administrator switched it on.
	 * @param OverlaySettings $settings   Settings.
	 * @param int             $revision   Grows with every change.
	 */
	public function __construct(
		public string $overlay_id,
		public bool $enabled,
		public OverlaySettings $settings,
		public int $revision
	) {
	}

	/**
	 * The version the page compares: a new id, new settings or "Stop all
	 * alerts" change it, so open pages reload.
	 */
	public function version(): string {
		return substr( hash( 'sha256', $this->overlay_id . '|' . $this->revision . '|' . $this->settings->to_json() ), 0, 16 );
	}
}
