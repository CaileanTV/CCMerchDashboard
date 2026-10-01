<?php
/**
 * CliOutput through WP-CLI.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Cli;

use RuntimeException;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Prints with WP-CLI's own functions; used only while WP-CLI runs.
 */
final class WpCliOutput implements CliOutput {

	/**
	 * A line of output.
	 *
	 * @param string $message Plain text.
	 */
	public function line( string $message ): void {
		WP_CLI::line( $message );
	}

	/**
	 * A success message.
	 *
	 * @param string $message Plain text.
	 */
	public function success( string $message ): void {
		WP_CLI::success( $message );
	}

	/**
	 * An error message; WP-CLI exits.
	 *
	 * @param string $message Plain text.
	 * @return never
	 * @throws RuntimeException Only if WP-CLI did not exit.
	 */
	public function error( string $message ): never {
		WP_CLI::error( $message );

		throw new RuntimeException( $message );
	}
}
