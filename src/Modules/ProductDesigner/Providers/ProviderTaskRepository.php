<?php
/**
 * Mockup tasks at a provider.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_designer_provider_tasks` (migration 35, ADR-081 pkt. 5): one
 * row per side of a version: `queued` → `pending` (Printful has the task)
 * → `done` or `failed`. `result` holds the variant → colour map of the
 * task, never a file or a token.
 */
class ProviderTaskRepository {

	public const QUEUED = 'queued';

	public const PENDING = 'pending';

	public const DONE = 'done';

	public const FAILED = 'failed';

	private const COLUMNS = 'id, version_id, provider, side, task_key, status, attempts, next_poll_at, result, error';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Adds a queued task of a side.
	 *
	 * @param int    $version_id Version.
	 * @param string $provider   Provider.
	 * @param string $side       Side.
	 * @param string $result     JSON (the variant → colour map).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( int $version_id, string $provider, string $side, string $result ): int {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$done = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The designer's own table.
			$this->table(),
			array(
				'version_id' => $version_id,
				'provider'   => $provider,
				'side'       => $side,
				'status'     => self::QUEUED,
				'result'     => $result,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		if ( 1 !== $done ) {
			throw new DatabaseException( 'Could not store the mockup task.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * The tasks of a version.
	 *
	 * @param int $version_id Version.
	 * @return list<array<string, mixed>>
	 * @throws DatabaseException When they cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function for_version( int $version_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE version_id = %d ORDER BY id', $this->table(), $version_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own table.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the mockup tasks.' );
		}

		return $rows;
	}

	/**
	 * Changes a task.
	 *
	 * @param int                  $id     Task.
	 * @param array<string, mixed> $fields Columns: status, task_key, attempts, next_poll_at, error.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update( int $id, array $fields ): void {
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		if ( isset( $fields['error'] ) && is_string( $fields['error'] ) ) {
			$fields['error'] = substr( $fields['error'], 0, 191 );
		}

		$done = $this->wpdb->update( $this->table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.

		if ( false === $done ) {
			throw new DatabaseException( 'Could not change the mockup task.' );
		}
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::DESIGNER_PROVIDER_TASKS );
	}
}
