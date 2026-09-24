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

		return $this->var;
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
