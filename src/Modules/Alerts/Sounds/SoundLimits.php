<?php
/**
 * The limits an alert sound upload is checked against.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Sounds;

defined( 'ABSPATH' ) || exit;

/**
 * The largest file (bytes, already within the site's upload limit) and the
 * longest sound (seconds) of one upload: the site-wide limits from Settings
 * → Alerts (step 25.9.2), or a creator's own limits (CreatorSoundLimits).
 */
final class SoundLimits {

	/**
	 * Constructor.
	 *
	 * @param int $max_bytes   Largest file in bytes.
	 * @param int $max_seconds Longest sound in seconds.
	 */
	public function __construct( public readonly int $max_bytes, public readonly int $max_seconds ) {
	}

	/**
	 * The site-wide limits.
	 */
	public static function site_wide(): self {
		return new self( SoundFiles::max_bytes(), SoundFiles::max_seconds() );
	}
}
