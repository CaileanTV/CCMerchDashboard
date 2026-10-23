<?php
/**
 * A standard overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Presets;

use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;

defined( 'ABSPATH' ) || exit;

/**
 * An overlay look the administrator designs and names (step 25.9.5), which
 * creators can copy into their own overlay as a starting point.
 */
final readonly class OverlayPreset {

	/**
	 * Constructor.
	 *
	 * @param string          $id       16 lowercase hex characters.
	 * @param string          $name     Name shown to creators.
	 * @param OverlaySettings $settings The look and the texts.
	 */
	public function __construct(
		public string $id,
		public string $name,
		public OverlaySettings $settings
	) {
	}

	/**
	 * Whether a string is a well-formed id.
	 *
	 * @param string $id Candidate.
	 */
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[0-9a-f]{16}$/', $id );
	}
}
