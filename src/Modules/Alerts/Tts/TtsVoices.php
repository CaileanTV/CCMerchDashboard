<?php
/**
 * The voices an alert can be read out with.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Tts;

defined( 'ABSPATH' ) || exit;

/**
 * A fixed list of Google Cloud Text-to-Speech standard voices (ADR-089):
 * the cheapest voices with the largest free allowance. A creator picks one
 * of these; any other name falls back to DEFAULT.
 */
final class TtsVoices {

	/**
	 * The default voice.
	 */
	public const DEFAULT = 'da-DK-Standard-A';

	/**
	 * Voice name → language code, in the order the dashboard lists them.
	 */
	public const VOICES = array(
		'da-DK-Standard-A' => 'da-DK',
		'da-DK-Standard-C' => 'da-DK',
		'en-GB-Standard-A' => 'en-GB',
		'en-GB-Standard-B' => 'en-GB',
		'en-US-Standard-C' => 'en-US',
		'en-US-Standard-D' => 'en-US',
		'de-DE-Standard-A' => 'de-DE',
		'de-DE-Standard-B' => 'de-DE',
		'sv-SE-Standard-A' => 'sv-SE',
		'nb-NO-Standard-A' => 'nb-NO',
	);

	/**
	 * A known voice, or the default.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function clean( mixed $value ): string {
		return is_string( $value ) && array_key_exists( $value, self::VOICES ) ? $value : self::DEFAULT;
	}
}
