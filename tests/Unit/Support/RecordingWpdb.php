<?php
/**
 * Recording wpdb double.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use RuntimeException;
use wpdb;

/**
 * Records how code talks to the database instead of executing SQL.
 *
 * The prepare() double does not interpolate: it returns a marker that get_row()/get_var()
 * must receive, which proves that every query went through prepare() and lets
 * tests assert the query template and its arguments separately.
 */
final class RecordingWpdb extends wpdb {

	/**
	 * Prepared queries: template and arguments.
	 *
	 * @var list<array{query: string, args: list<mixed>}>
	 */
	public array $prepared = array();

	/**
	 * Queries passed to get_row()/get_var().
	 *
	 * @var list<string>
	 */
	public array $executed = array();

	/**
	 * Calls to insert().
	 *
	 * @var list<array{table: string, data: array<string, mixed>, format: mixed}>
	 */
	public array $inserts = array();

	/**
	 * Rows returned by get_row(), in order.
	 *
	 * @var list<array<string, mixed>|null>
	 */
	public array $rows = array();

	/**
	 * Value returned by get_var().
	 *
	 * @var mixed
	 */
	public $var = null;

	/**
	 * Return value of insert().
	 *
	 * @var int|false
	 */
	public $insert_result = 1;

	/**
	 * When true, "SHOW TABLES LIKE %s" answers with the requested table.
	 *
	 * @var bool
	 */
	public bool $tables_exist = false;

	/**
	 * Results of get_var() by prepared marker (overrides $var).
	 *
	 * @var array<string, mixed>
	 */
	public array $vars = array();

	/**
	 * Result of get_results().
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $results = array();

	/**
	 * Result of get_col().
	 *
	 * @var list<mixed>
	 */
	public array $col = array();

	/**
	 * Calls to update().
	 *
	 * @var list<array{table: string, data: array<string, mixed>, where: array<string, mixed>, format: mixed, where_format: mixed}>
	 */
	public array $updates = array();

	/**
	 * Return value of update().
	 *
	 * @var int|false
	 */
	public $update_result = 1;

	/**
	 * Raw queries passed to query().
	 *
	 * @var list<string>
	 */
	public array $raw_queries = array();

	/**
	 * Return values of query() by query text (default true).
	 *
	 * @var array<string, int|bool>
	 */
	public array $query_results = array();

	/**
	 * Rows of the options table touched by prepared statements (the migration
	 * lock), option_name => option_value, with MySQL's affected-rows semantics.
	 *
	 * @var array<string, string>
	 */
	public array $option_rows = array();

	/**
	 * Columns added by prepared "ALTER TABLE %i ADD COLUMN" statements, "table.column" => true.
	 *
	 * @var array<string, true>
	 */
	public array $added_columns = array();

	/**
	 * Whether "SHOW COLUMNS FROM %i LIKE %s" finds the column; null follows $tables_exist.
	 *
	 * @var bool|null
	 */
	public ?bool $columns_exist = null;

	/**
	 * Tables dropped by prepared "DROP TABLE IF EXISTS %i" statements, name => true.
	 *
	 * @var array<string, true>
	 */
	public array $dropped_tables = array();

	/**
	 * No database connection (the real constructor connects to MySQL).
	 */
	public function __construct() {
		$this->prefix  = 'wp_';
		$this->options = 'wp_options';
	}

	/**
	 * Records a prepared query.
	 *
	 * @param string $query   Query template.
	 * @param mixed  ...$args Values.
	 * @return string Marker for the executing call.
	 */
	public function prepare( $query, ...$args ) {
		$this->prepared[] = array(
			'query' => $query,
			'args'  => array_values( $args ),
		);

		return 'PREPARED#' . ( count( $this->prepared ) - 1 );
	}

	/**
	 * Returns the next configured row.
	 *
	 * @param string|null $query  Prepared marker.
	 * @param string      $output Output type.
	 * @param int         $y      Row offset.
	 * @return array<string, mixed>|null
	 */
	public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
		$this->executed[] = (string) $query;

		return array_shift( $this->rows );
	}

	/**
	 * Returns the configured value.
	 *
	 * @param string|null $query Prepared marker.
	 * @param int         $x     Column offset.
	 * @param int         $y     Row offset.
	 * @return mixed
	 */
	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$this->executed[] = (string) $query;

		$prepared = $this->prepared_for( (string) $query );

		if ( $this->tables_exist && null !== $prepared && 'SHOW TABLES LIKE %s' === $prepared['query'] ) {
			$table = stripslashes( (string) $prepared['args'][0] );

			return isset( $this->dropped_tables[ $table ] ) ? null : $table;
		}

		if ( null !== $prepared && 'SHOW COLUMNS FROM %i LIKE %s' === $prepared['query'] && ! array_key_exists( (string) $query, $this->vars ) ) {
			$column = (string) $prepared['args'][1];

			return ( $this->columns_exist ?? $this->tables_exist ) || isset( $this->added_columns[ $prepared['args'][0] . '.' . $column ] ) ? $column : null;
		}

		if ( array_key_exists( (string) $query, $this->vars ) ) {
			return $this->vars[ (string) $query ];
		}

		if ( null !== $prepared && 'SELECT option_value FROM %i WHERE option_name = %s' === $prepared['query'] ) {
			return $this->option_rows[ (string) $prepared['args'][1] ] ?? null;
		}

		return $this->var;
	}

	/**
	 * Returns the configured result set.
	 *
	 * @param string|null $query  Prepared marker.
	 * @param string      $output Output type.
	 * @return list<array<string, mixed>>
	 */
	public function get_results( $query = null, $output = OBJECT ) {
		$this->executed[] = (string) $query;

		return $this->results;
	}

	/**
	 * Returns the configured column.
	 *
	 * @param string|null $query Prepared marker.
	 * @param int         $x     Column offset.
	 * @return list<mixed>
	 */
	public function get_col( $query = null, $x = 0 ) {
		$this->executed[] = (string) $query;

		return $this->col;
	}

	/**
	 * Records an update.
	 *
	 * @param string               $table        Table.
	 * @param array<string, mixed> $data         Values.
	 * @param array<string, mixed> $where        Where.
	 * @param mixed                $format       Formats.
	 * @param mixed                $where_format Where formats.
	 * @return int|false
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->updates[] = array(
			'table'        => $table,
			'data'         => $data,
			'where'        => $where,
			'format'       => $format,
			'where_format' => $where_format,
		);

		return $this->update_result;
	}

	/**
	 * Records a raw query (transactions); executes the prepared statements of
	 * the migration lock against $option_rows.
	 *
	 * @param string $query Query or prepared marker.
	 * @return int|bool
	 */
	public function query( $query ) {
		$this->raw_queries[] = $query;
		$prepared            = $this->prepared_for( (string) $query );

		if ( null !== $prepared && 'DROP TABLE IF EXISTS %i' === $prepared['query'] ) {
			$result = $this->query_results[ $query ] ?? true;

			if ( false !== $result ) {
				$this->dropped_tables[ (string) $prepared['args'][0] ] = true;
			}

			return $result;
		}

		if ( null !== $prepared && 1 === preg_match( '/^ALTER TABLE %i ADD COLUMN (\w+) /', $prepared['query'], $match ) ) {
			$result = $this->query_results[ $query ] ?? true;

			if ( false !== $result ) {
				$this->added_columns[ $prepared['args'][0] . '.' . $match[1] ] = true;
			}

			return $result;
		}

		if ( null !== $prepared ) {
			return $this->option_statement( $prepared['query'], $prepared['args'] );
		}

		return $this->query_results[ $query ] ?? true;
	}

	/**
	 * Executes a lock statement; returns the number of affected rows.
	 *
	 * @param string            $sql  Template.
	 * @param array<int, mixed> $args Values (the first is the options table).
	 * @throws RuntimeException On any other statement.
	 */
	private function option_statement( string $sql, array $args ): int {
		$args = array_map( 'strval', $args );

		switch ( $sql ) {
			case 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)':
				if ( array_key_exists( $args[1], $this->option_rows ) ) {
					return 0;
				}
				$this->option_rows[ $args[1] ] = $args[2];
				return 1;
			case 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s':
				if ( ( $this->option_rows[ $args[2] ] ?? null ) !== $args[3] ) {
					return 0;
				}
				$this->option_rows[ $args[2] ] = $args[1];
				return 1;
			case 'DELETE FROM %i WHERE option_name = %s AND option_value = %s':
				if ( ( $this->option_rows[ $args[1] ] ?? null ) !== $args[2] ) {
					return 0;
				}
				unset( $this->option_rows[ $args[1] ] );
				return 1;
		}

		throw new RuntimeException( 'Unsupported prepared query: ' . $sql );
	}

	/**
	 * The prepare() record behind a marker.
	 *
	 * @param string $marker Marker returned by prepare().
	 * @return array{query: string, args: list<mixed>}|null
	 */
	private function prepared_for( string $marker ): ?array {
		if ( ! str_starts_with( $marker, 'PREPARED#' ) ) {
			return null;
		}

		return $this->prepared[ (int) substr( $marker, 9 ) ] ?? null;
	}

	/**
	 * Records an insert.
	 *
	 * @param string               $table  Table name.
	 * @param array<string, mixed> $data   Column values.
	 * @param mixed                $format Formats.
	 * @return int|false
	 */
	public function insert( $table, $data, $format = null ) {
		$this->inserts[] = array(
			'table'  => $table,
			'data'   => $data,
			'format' => $format,
		);

		return $this->insert_result;
	}

	/**
	 * Site prefix (single site).
	 *
	 * @param int|null $blog_id Blog id.
	 * @return string
	 */
	public function get_blog_prefix( $blog_id = null ) {
		return $this->prefix;
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
	 * Fixed charset/collation clause.
	 *
	 * @return string
	 */
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}
}
