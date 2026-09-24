<?php
/**
 * Migration test double.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migration;
use wpdb;

/**
 * Appends its name to a shared log when run; can be told to fail.
 */
final class RecordingMigration implements Migration {

	/**
	 * Constructor.
	 *
	 * @phpstan-param \ArrayObject<int, string> $log
	 *
	 * @param string       $name Name written to the log.
	 * @param \ArrayObject $log  Shared run log.
	 * @param bool         $fail Whether up() throws.
	 */
	public function __construct(
		private string $name,
		private \ArrayObject $log,
		private bool $fail = false
	) {
	}

	/**
	 * Records the run.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When configured to fail.
	 */
	public function up( wpdb $wpdb ): void {
		$this->log[] = $this->name;

		if ( $this->fail ) {
			throw new DatabaseException( 'Migration failed.' );
		}
	}
}
