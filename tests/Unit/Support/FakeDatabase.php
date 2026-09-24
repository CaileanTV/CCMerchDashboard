<?php
/**
 * In-memory database for the plugin's own queries.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Core\Database\Tables;
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
	 * Tables (unprefixed) whose single-row reads fail, like a missing table.
	 *
	 * @var list<string>
	 */
	public array $fail_reads_from = array();

	/**
	 * Storage engine per unprefixed table (default InnoDB); anything but InnoDB
	 * is reported as non-transactional.
	 *
	 * @var array<string, string>
	 */
	public array $engines = array();

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

		// Like wpdb::flush() at the start of every query.
		$this->last_error = '';

		if ( in_array( substr( (string) $args[0], strlen( $this->prefix ) ), $this->fail_reads_from, true ) ) {
			$this->last_error = "Table '" . $args[0] . "' doesn't exist";
			return null;
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

		if ( str_starts_with( $sql, 'SELECT COUNT(*) FROM %i WHERE 1=1' ) ) {
			return (string) count( $this->audit_filter( $sql, $args ) );
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

		if ( str_starts_with( $sql, 'SELECT id, created_at, action, actor_user_id, creator_id, context FROM %i WHERE 1=1' ) ) {
			$rows               = array_reverse( $this->audit_filter( $sql, $args ) );
			[ $limit, $offset ] = array_slice( $args, -2 );

			return array_slice( $rows, (int) $offset, (int) $limit );
		}

		if ( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status' === $sql ) {
			$totals = array();

			foreach ( $this->filter( (string) $args[0] ) as $row ) {
				$totals[ (string) $row['status'] ] = ( $totals[ (string) $row['status'] ] ?? 0 ) + 1;
			}

			return array_map(
				static fn( string $status, int $total ): array => array(
					'status' => $status,
					'total'  => (string) $total,
				),
				array_keys( $totals ),
				$totals
			);
		}

		if ( str_contains( $sql, 'FROM information_schema.TABLES' ) ) {
			return array_map(
				fn( string $table ): array => array(
					'name'         => $this->prefix . $table,
					'engine'       => $this->engines[ $table ] ?? 'InnoDB',
					'transactions' => 'InnoDB' === ( $this->engines[ $table ] ?? 'InnoDB' ) ? 'YES' : 'NO',
				),
				Tables::all()
			);
		}

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

		// Every WHERE column must match, like "id = %d AND user_id = %d".
		foreach ( self::stringify( $where ) as $column => $value ) {
			if ( ( $this->data[ $table ][ $id ][ $column ] ?? null ) !== $value ) {
				return 0;
			}
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
	 * Audit rows matching the "WHERE 1=1 AND …" conditions of AuditRepository::paginate().
	 * Throws on any condition it does not know, like the rest of this double.
	 *
	 * @param string            $sql  Template.
	 * @param array<int, mixed> $args Arguments (table first).
	 * @return list<array<string, string|null>>
	 */
	private function audit_filter( string $sql, array $args ): array {
		preg_match_all( '/ AND (action = %s|creator_id = %d|created_at >= %s|created_at < %s)/', $sql, $matches );
		$tests = array();
		$index = 1;

		foreach ( $matches[1] as $condition ) {
			$value   = (string) $args[ $index++ ];
			$tests[] = match ( $condition ) {
				'action = %s'        => static fn( array $row ): bool => $row['action'] === $value,
				'creator_id = %d'    => static fn( array $row ): bool => $row['creator_id'] === $value,
				'created_at >= %s'   => static fn( array $row ): bool => (string) $row['created_at'] >= $value,
				'created_at < %s'    => static fn( array $row ): bool => (string) $row['created_at'] < $value,
			};
		}

		$rest = (string) preg_replace( '/ AND (action = %s|creator_id = %d|created_at >= %s|created_at < %s)/', '', $sql );

		if ( 1 !== preg_match( '/WHERE 1=1( ORDER BY id DESC LIMIT %d OFFSET %d)?$/', $rest ) ) {
			throw new RuntimeException( 'Unsupported audit query: ' . $sql );
		}

		return array_values(
			array_filter(
				$this->filter( (string) $args[0] ),
				static function ( array $row ) use ( $tests ): bool {
					foreach ( $tests as $test ) {
						if ( ! $test( $row ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
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
