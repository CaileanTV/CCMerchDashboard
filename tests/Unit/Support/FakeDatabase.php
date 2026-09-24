<?php
/**
 * In-memory database for the plugin's own queries.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use RuntimeException;
use wpdb;

/**
 * A wpdb double that executes the plugin's prepared queries against arrays,
 * so repositories, the audit trail and transactions can be tested together
 * with realistic behaviour:
 *
 * - rows come back as strings/null like MySQL;
 * - AUTO_INCREMENT ids and UNIQUE(user_id) on the creators table;
 * - START TRANSACTION / COMMIT / ROLLBACK with snapshot and restore;
 * - inserts or updates into one table can be forced to fail.
 *
 * It understands only the query shapes the plugin uses and throws on anything
 * else, so a new, untested query shape cannot pass silently.
 */
final class FakeDatabase extends wpdb {

	/**
	 * Rows per full table name.
	 *
	 * @var array<string, array<int, array<string, string|null>>>
	 */
	public array $data = array();

	/**
	 * Next id per table.
	 *
	 * @var array<string, int>
	 */
	private array $next_id = array();

	/**
	 * Snapshot taken at START TRANSACTION.
	 *
	 * @var array{data: array<string, array<int, array<string, string|null>>>, next_id: array<string, int>}|null
	 */
	private ?array $snapshot = null;

	/**
	 * Tables (unprefixed) whose writes fail.
	 *
	 * @var list<string>
	 */
	public array $fail_writes_to = array();

	/**
	 * Transaction statements executed, in order.
	 *
	 * @var list<string>
	 */
	public array $transaction_log = array();

	/**
	 * Prepared statements by marker.
	 *
	 * @var list<array{query: string, args: list<mixed>}>
	 */
	private array $prepared = array();

	/**
	 * No connection.
	 */
	public function __construct() {
		$this->prefix = 'wp_';
	}

	/**
	 * Rows of a plugin table (unprefixed name), ordered by id.
	 *
	 * @param string $table Unprefixed table name.
	 * @return list<array<string, string|null>>
	 */
	public function rows( string $table ): array {
		$rows = $this->data[ $this->prefix . $table ] ?? array();
		ksort( $rows );

		return array_values( $rows );
	}

	/**
	 * Records a prepared statement.
	 *
	 * @param string $query   Template.
	 * @param mixed  ...$args Values.
	 * @return string Marker.
	 */
	public function prepare( $query, ...$args ) {
		$this->prepared[] = array(
			'query' => $query,
			'args'  => array_values( $args ),
		);

		return 'PREPARED#' . ( count( $this->prepared ) - 1 );
	}

	/**
	 * Single row lookups by id or user_id.
	 *
	 * @param string|null $query  Marker.
	 * @param string      $output Output type.
	 * @param int         $y      Row offset.
	 * @return array<string, string|null>|null
	 */
	public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
		[ $sql, $args ] = $this->statement( $query );

		if ( 1 !== preg_match( '/ FROM %i WHERE (id|user_id) = %d$/', $sql, $m ) ) {
			throw new RuntimeException( 'Unsupported get_row: ' . $sql );
		}

		foreach ( $this->data[ (string) $args[0] ] ?? array() as $row ) {
			if ( (string) $args[1] === $row[ $m[1] ] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * SHOW TABLES and COUNT(*).
	 *
	 * @param string|null $query Marker.
	 * @param int         $x     Column.
	 * @param int         $y     Row.
	 * @return string
	 */
	public function get_var( $query = null, $x = 0, $y = 0 ) {
		[ $sql, $args ] = $this->statement( $query );

		if ( 'SHOW TABLES LIKE %s' === $sql ) {
			return stripslashes( (string) $args[0] );
		}

		if ( 'SELECT COUNT(*) FROM %i' === $sql ) {
			return (string) count( $this->data[ (string) $args[0] ] ?? array() );
		}

		if ( 'SELECT COUNT(*) FROM %i WHERE status = %s' === $sql ) {
			return (string) count( $this->filter( (string) $args[0], 'status', (string) $args[1] ) );
		}

		throw new RuntimeException( 'Unsupported get_var: ' . $sql );
	}

	/**
	 * Lists: audit by creator, creators page.
	 *
	 * @param string|null $query  Marker.
	 * @param string      $output Output type.
	 * @return list<array<string, string|null>>
	 */
	public function get_results( $query = null, $output = OBJECT ) {
		[ $sql, $args ] = $this->statement( $query );

		if ( str_ends_with( $sql, 'FROM %i WHERE creator_id = %d ORDER BY id DESC LIMIT %d' ) ) {
			$rows = array_reverse( $this->filter( (string) $args[0], 'creator_id', (string) $args[1] ) );

			return array_slice( $rows, 0, (int) $args[2] );
		}

		if ( 1 === preg_match( '/FROM %i (WHERE status = %s )?ORDER BY %i (ASC|DESC), id (ASC|DESC) LIMIT %d OFFSET %d$/', $sql, $m ) ) {
			$filtered                    = '' !== $m[1];
			$table                       = (string) $args[0];
			$rows                        = $filtered ? $this->filter( $table, 'status', (string) $args[1] ) : $this->filter( $table );
			[ $column, $limit, $offset ] = array_slice( $args, $filtered ? 2 : 1 );
			$direction                   = 'ASC' === $m[2] ? 1 : -1;

			usort(
				$rows,
				static fn( array $a, array $b ): int => $direction * ( 0 !== strnatcmp( (string) $a[ (string) $column ], (string) $b[ (string) $column ] ) ? strnatcmp( (string) $a[ (string) $column ], (string) $b[ (string) $column ] ) : ( (int) $a['id'] <=> (int) $b['id'] ) )
			);

			return array_slice( $rows, (int) $offset, (int) $limit );
		}

		throw new RuntimeException( 'Unsupported get_results: ' . $sql );
	}

	/**
	 * Linked user ids.
	 *
	 * @param string|null $query Marker.
	 * @param int         $x     Column.
	 * @return list<string>
	 */
	public function get_col( $query = null, $x = 0 ) {
		[ $sql, $args ] = $this->statement( $query );

		if ( 'SELECT user_id FROM %i WHERE user_id IS NOT NULL' !== $sql ) {
			throw new RuntimeException( 'Unsupported get_col: ' . $sql );
		}

		return array_values(
			array_map(
				static fn( array $row ): string => (string) $row['user_id'],
				array_filter( $this->filter( (string) $args[0] ), static fn( array $row ): bool => null !== $row['user_id'] )
			)
		);
	}

	/**
	 * Inserts a row.
	 *
	 * @param string               $table  Table.
	 * @param array<string, mixed> $data   Values.
	 * @param mixed                $format Formats.
	 * @return int|false
	 */
	public function insert( $table, $data, $format = null ) {
		if ( $this->fails( $table ) || ! $this->unique_ok( $table, $data, null ) ) {
			return false;
		}

		$id                          = $this->next_id[ $table ] ?? 1;
		$this->next_id[ $table ]     = $id + 1;
		$this->data[ $table ][ $id ] = array( 'id' => (string) $id ) + self::stringify( $data );
		$this->insert_id             = $id;
		$this->last_error            = '';

		return 1;
	}

	/**
	 * Updates rows by id.
	 *
	 * @param string               $table        Table.
	 * @param array<string, mixed> $data         Values.
	 * @param array<string, mixed> $where        Where (id only).
	 * @param mixed                $format       Formats.
	 * @param mixed                $where_format Where formats.
	 * @return int|false
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$id = (int) ( $where['id'] ?? 0 );

		if ( $this->fails( $table ) || ! $this->unique_ok( $table, $data, $id ) ) {
			return false;
		}

		if ( ! isset( $this->data[ $table ][ $id ] ) ) {
			return 0;
		}

		$this->data[ $table ][ $id ] = array_merge( $this->data[ $table ][ $id ], self::stringify( $data ) );

		return 1;
	}

	/**
	 * Transaction statements.
	 *
	 * @param string $query Query.
	 * @return bool
	 */
	public function query( $query ) {
		$this->transaction_log[] = $query;

		switch ( $query ) {
			case 'START TRANSACTION':
				$this->snapshot = array(
					'data'    => $this->data,
					'next_id' => $this->next_id,
				);
				return true;
			case 'COMMIT':
				$this->snapshot = null;
				return true;
			case 'ROLLBACK':
				if ( null !== $this->snapshot ) {
					$this->data     = $this->snapshot['data'];
					$this->next_id  = $this->snapshot['next_id'];
					$this->snapshot = null;
				}
				return true;
		}

		throw new RuntimeException( 'Unsupported query: ' . $query );
	}

	/**
	 * Mirrors wpdb::esc_like().
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Fixed collation clause.
	 *
	 * @return string
	 */
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	/**
	 * Statement behind a marker.
	 *
	 * @param mixed $marker Marker.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function statement( mixed $marker ): array {
		$index = is_string( $marker ) && str_starts_with( $marker, 'PREPARED#' ) ? (int) substr( $marker, 9 ) : -1;

		if ( ! isset( $this->prepared[ $index ] ) ) {
			throw new RuntimeException( 'Query was not prepared.' );
		}

		return array( $this->prepared[ $index ]['query'], $this->prepared[ $index ]['args'] );
	}

	/**
	 * Rows of a table, optionally where a column equals a value.
	 *
	 * @param string      $table  Full table name.
	 * @param string|null $column Column.
	 * @param string|null $value  Value.
	 * @return list<array<string, string|null>>
	 */
	private function filter( string $table, ?string $column = null, ?string $value = null ): array {
		$rows = $this->data[ $table ] ?? array();
		ksort( $rows );

		return array_values( array_filter( $rows, static fn( array $row ): bool => null === $column || $value === $row[ $column ] ) );
	}

	/**
	 * Whether writes to this table are forced to fail.
	 *
	 * @param string $table Full table name.
	 */
	private function fails( string $table ): bool {
		foreach ( $this->fail_writes_to as $name ) {
			if ( $this->prefix . $name === $table ) {
				$this->last_error = 'Forced failure for ' . $table;
				return true;
			}
		}

		return false;
	}

	/**
	 * UNIQUE(user_id) on the creators table.
	 *
	 * @param string               $table Full table name.
	 * @param array<string, mixed> $data  Values.
	 * @param int|null             $id    Row being updated.
	 */
	private function unique_ok( string $table, array $data, ?int $id ): bool {
		if ( $this->prefix . 'merchboard_creators' !== $table || ! isset( $data['user_id'] ) ) {
			return true;
		}

		foreach ( $this->data[ $table ] ?? array() as $row_id => $row ) {
			if ( $row_id !== $id && (string) $data['user_id'] === $row['user_id'] ) {
				$this->last_error = "Duplicate entry '" . $data['user_id'] . "' for key 'user_id'";
				return false;
			}
		}

		return true;
	}

	/**
	 * Converts values to what MySQL returns (strings or null).
	 *
	 * @param array<string, mixed> $data Values.
	 * @return array<string, string|null>
	 */
	private static function stringify( array $data ): array {
		return array_map( static fn( mixed $v ): ?string => null === $v ? null : (string) $v, $data );
	}
}
