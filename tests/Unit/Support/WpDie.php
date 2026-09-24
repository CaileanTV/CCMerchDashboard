<?php
/**
 * Exception standing in for wp_die().
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use RuntimeException;

/**
 * Thrown by the wp_die() stub so tests can assert that a request was stopped
 * (and that nothing after it ran).
 */
final class WpDie extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message  Message.
	 * @param int    $response HTTP status.
	 */
	public function __construct( string $message, public int $response ) {
		parent::__construct( $message );
	}
}
