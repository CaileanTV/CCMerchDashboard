<?php
/**
 * Output of a WP-CLI command.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * What a command prints (WpCliOutput in WP-CLI, a recorder in tests).
 */
interface CliOutput {

	/**
	 * A line of output.
	 *
	 * @param string $message Plain text.
	 */
	public function line( string $message ): void;

	/**
	 * A success message.
	 *
	 * @param string $message Plain text.
	 */
	public function success( string $message ): void;

	/**
	 * An error message; ends the command with a non-zero exit code (never returns).
	 *
	 * @param string $message Plain text.
	 * @return never
	 */
	public function error( string $message ): never;
}
