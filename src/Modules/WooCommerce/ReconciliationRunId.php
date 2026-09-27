<?php
/**
 * Identifies one category reconciliation run.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A random UUID v4, created once per run and carried through every batch,
 * product audit record and `category_sync_completed` (ADR-033). Immutable;
 * no shared state.
 */
final readonly class ReconciliationRunId {

	/**
	 * Lowercase UUID v4.
	 */
	private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/**
	 * Constructor.
	 *
	 * @param string $value Lowercase UUID v4.
	 * @throws InvalidArgumentException When the value is not one.
	 */
	public function __construct( public string $value ) {
		if ( 1 !== preg_match( self::PATTERN, $value ) ) {
			throw new InvalidArgumentException( 'A run id must be a lowercase UUID v4.' );
		}
	}

	/**
	 * A new random run id.
	 */
	public static function generate(): self {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return new self( vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) ) );
	}
}
