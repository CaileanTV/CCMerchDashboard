<?php
/**
 * Recording wpdb double.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

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
	 * No database connection (the real constructor connects to MySQL).
	 */
	public function __construct() {
		$this->prefix = 'wp_';
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
			return stripslashes( (string) $prepared['args'][0] );
		}

		return array_key_exists( (string) $query, $this->vars ) ? $this->vars[ (string) $query ] : $this->var;
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
	 * Records a raw query (transactions).
	 *
	 * @param string $query Query.
	 * @return int|bool
	 */
	public function query( $query ) {
		$this->raw_queries[] = $query;

		return $this->query_results[ $query ] ?? true;
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
