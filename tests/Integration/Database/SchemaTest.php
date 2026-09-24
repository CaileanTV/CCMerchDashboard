<?php
/**
 * Schema as created by the migrations on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Core\Database\Migrations\CreateAuditLogTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Tests\Integration\IntegrationTestCase;

final class SchemaTest extends IntegrationTestCase {

	public function test_creators_table_matches_the_documented_schema(): void {
		$this->install();

		self::assertSame(
			array(
				'id'           => array( 'bigint', 'unsigned', 'NO', 'auto_increment' ),
				'user_id'      => array( 'bigint', 'unsigned', 'YES', '' ),
				'display_name' => array( 'varchar(191)', '', 'NO', '' ),
				'status'       => array( 'varchar(20)', '', 'NO', '' ),
				'created_at'   => array( 'datetime', '', 'NO', '' ),
				'updated_at'   => array( 'datetime', '', 'NO', '' ),
			),
			$this->columns( Tables::CREATORS )
		);
		self::assertSame(
			array(
				'PRIMARY' => array( 'unique', 'id' ),
				'status'  => array( 'non-unique', 'status' ),
				'user_id' => array( 'unique', 'user_id' ),
			),
			$this->indexes( Tables::CREATORS )
		);
	}

	public function test_audit_table_matches_the_documented_schema(): void {
		$this->install();

		self::assertSame(
			array(
				'id'            => array( 'bigint', 'unsigned', 'NO', 'auto_increment' ),
				'created_at'    => array( 'datetime', '', 'NO', '' ),
				'action'        => array( 'varchar(50)', '', 'NO', '' ),
				'actor_user_id' => array( 'bigint', 'unsigned', 'YES', '' ),
				'creator_id'    => array( 'bigint', 'unsigned', 'YES', '' ),
				'context'       => array( 'longtext', '', 'YES', '' ),
			),
			$this->columns( Tables::AUDIT_LOG )
		);
		self::assertSame(
			array(
				'PRIMARY'    => array( 'unique', 'id' ),
				'action'     => array( 'non-unique', 'action' ),
				'created_at' => array( 'non-unique', 'created_at' ),
				'creator_id' => array( 'non-unique', 'creator_id' ),
			),
			$this->indexes( Tables::AUDIT_LOG )
		);
	}

	public function test_tables_use_the_site_prefix_charset_and_collation_of_wordpress(): void {
		$this->install();

		foreach ( Tables::all() as $table ) {
			$status = $this->table_status( $table );

			self::assertSame( $this->wpdb->prefix . $table, $status['TABLE_NAME'] );
			self::assertSame( $this->wpdb->collate, $status['TABLE_COLLATION'], $table . ' collation' );
			self::assertStringStartsWith( $this->wpdb->charset . '_', (string) $status['TABLE_COLLATION'] );
		}

		// Text columns inherit the table default (nothing hardcoded per column).
		$columns = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s) AND COLLATION_NAME IS NOT NULL',
				$this->table( Tables::CREATORS ),
				$this->table( Tables::AUDIT_LOG )
			)
		);
		self::assertNotEmpty( $columns );
		self::assertSame( array( $this->wpdb->collate ), array_values( array_unique( $columns ) ) );
	}

	public function test_tables_use_a_transactional_engine_by_default(): void {
		$this->install();

		foreach ( Tables::all() as $table ) {
			self::assertSame( 'YES', $this->table_status( $table )['TRANSACTIONS'], $table . ' engine' );
		}
	}

	public function test_dbdelta_reports_no_changes_for_an_existing_schema(): void {
		$this->install();

		$charset_collate = $this->wpdb->get_charset_collate();

		self::assertSame( array(), dbDelta( CreateCreatorsTable::schema( $this->table( Tables::CREATORS ), $charset_collate ) ) );
		self::assertSame( array(), dbDelta( CreateAuditLogTable::schema( $this->table( Tables::AUDIT_LOG ), $charset_collate ) ) );
	}

	/**
	 * Columns as [type without integer display width, unsigned, nullable, extra].
	 *
	 * MySQL 8.0.19+ no longer reports integer display widths (bigint(20) →
	 * bigint), MariaDB does; both are normalized here.
	 *
	 * @param string $table Tables constant.
	 * @return array<string, list<string>>
	 */
	private function columns( string $table ): array {
		$rows    = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
				$this->table( $table )
			),
			ARRAY_A
		);
		$columns = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$type = strtolower( (string) $row['COLUMN_TYPE'] );

			$columns[ (string) $row['COLUMN_NAME'] ] = array(
				(string) preg_replace( '/^(bigint)\(\d+\)/', '$1', str_replace( ' unsigned', '', $type ) ),
				str_contains( $type, 'unsigned' ) ? 'unsigned' : '',
				(string) $row['IS_NULLABLE'],
				strtolower( (string) $row['EXTRA'] ),
			);
		}

		return $columns;
	}

	/**
	 * Indexes as name => [unique|non-unique, columns].
	 *
	 * @param string $table Tables constant.
	 * @return array<string, list<string>>
	 */
	private function indexes( string $table ): array {
		$rows    = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s GROUP BY INDEX_NAME, NON_UNIQUE ORDER BY INDEX_NAME',
				$this->table( $table )
			),
			ARRAY_A
		);
		$indexes = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$indexes[ (string) $row['INDEX_NAME'] ] = array( '0' === (string) $row['NON_UNIQUE'] ? 'unique' : 'non-unique', (string) $row['cols'] );
		}

		ksort( $indexes );

		return $indexes;
	}

	/**
	 * Table name, collation and whether its engine supports transactions.
	 *
	 * @param string $table Tables constant.
	 * @return array<mixed>
	 */
	private function table_status( string $table ): array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT t.TABLE_NAME, t.TABLE_COLLATION, t.ENGINE, e.TRANSACTIONS FROM information_schema.TABLES t LEFT JOIN information_schema.ENGINES e ON e.ENGINE = t.ENGINE WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = %s',
				$this->table( $table )
			),
			ARRAY_A
		);

		self::assertIsArray( $row, $table . ' exists' );

		return $row;
	}
}
