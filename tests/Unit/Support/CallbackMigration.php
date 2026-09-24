<?php
/**
 * Migration double that runs a callback.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use Closure;
use MerchBoard\Core\Database\Migration;
use wpdb;

/**
 * Lets a test act while a migration runs (e.g. simulate another request).
 */
final class CallbackMigration implements Migration {

	/**
	 * Constructor.
	 *
	 * @param Closure $callback Runs as the migration.
	 * @phpstan-param Closure(wpdb): void $callback
	 */
	public function __construct( private Closure $callback ) {
	}

	/**
	 * Runs the callback.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function up( wpdb $wpdb ): void {
		( $this->callback )( $wpdb );
	}
}
