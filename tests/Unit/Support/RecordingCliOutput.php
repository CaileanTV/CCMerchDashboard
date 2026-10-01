<?php
/**
 * CLI output for tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Modules\WooCommerce\Cli\CliOutput;
use RuntimeException;

/**
 * Records the output; error() throws like WP-CLI ends the command.
 */
final class RecordingCliOutput implements CliOutput {

	/**
	 * Lines, successes and errors in order, prefixed with their kind.
	 *
	 * @var list<string>
	 */
	public array $output = array();

	/**
	 * A line of output.
	 *
	 * @param string $message Plain text.
	 */
	public function line( string $message ): void {
		$this->output[] = 'line: ' . $message;
	}

	/**
	 * A success message.
	 *
	 * @param string $message Plain text.
	 */
	public function success( string $message ): void {
		$this->output[] = 'success: ' . $message;
	}

	/**
	 * An error message; ends the command.
	 *
	 * @param string $message Plain text.
	 * @throws RuntimeException Always.
	 */
	public function error( string $message ): never {
		$this->output[] = 'error: ' . $message;

		throw new RuntimeException( $message );
	}
}
