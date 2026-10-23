<?php
/**
 * A creator's saved overlay design.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Designs;

use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;

defined( 'ABSPATH' ) || exit;

/**
 * An overlay design a creator saved under a name, for another part of a
 * stream: the look, the texts and the sounds of their overlay at the time.
 */
final readonly class CreatorDesign {

	/**
	 * Constructor.
	 *
	 * @param string          $id       16 lowercase hex characters.
	 * @param string          $name     Name.
	 * @param OverlaySettings $settings The overlay's settings when saved.
	 * @param string          $saved_at When it was saved (UTC, Y-m-d H:i:s).
	 */
	public function __construct(
		public string $id,
		public string $name,
		public OverlaySettings $settings,
		public string $saved_at
	) {
	}

	/**
	 * The sounds the design uses (attachment ids).
	 *
	 * @return list<int>
	 */
	public function sounds(): array {
		$values = $this->settings->to_array();

		return array_values( array_filter( array_merge( array( $values['audio_default_sound'] ), array_values( $values['audio_category_map'] ) ), static fn( int $id ): bool => $id > 0 ) );
	}
}
