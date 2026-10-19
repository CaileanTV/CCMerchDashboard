<?php
/**
 * The secret id of an overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

defined( 'ABSPATH' ) || exit;

/**
 * 128 random bits as 32 lowercase hex characters, the format MerchAlert
 * uses (ADR-065 pkt. 4), so imported OBS URLs keep working. The id is the
 * only credential of the public overlay page and its feed.
 */
final class OverlayId {

	/**
	 * A new random id.
	 */
	public static function generate(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Whether a string has the format of an overlay id.
	 *
	 * @param string $id Candidate.
	 */
	public static function valid( string $id ): bool {
		return 1 === preg_match( '/^[0-9a-f]{32}$/D', $id );
	}
}
