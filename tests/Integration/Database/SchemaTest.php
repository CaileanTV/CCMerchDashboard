<?php
/**
 * Schema as created by the migrations on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Core\Database\Migrations\AddCreatorShareModel;
use MerchBoard\Core\Database\Migrations\AddCreatorVatAndSalesTax;
use MerchBoard\Core\Database\Migrations\CreateAuditLogTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorPricingTables;
use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;
use MerchBoard\Core\Database\Migrations\CreateSalesLedgerTables;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Tests\Integration\IntegrationTestCase;

final class SchemaTest extends IntegrationTestCase {

	public function test_creators_table_matches_the_documented_schema(): void {
		$this->install();

		self::assertSame(
			array(
				'id'                 => array( 'bigint', 'unsigned', 'NO', 'auto_increment' ),
				'user_id'            => array( 'bigint', 'unsigned', 'YES', '' ),
				'display_name'       => array( 'varchar(191)', '', 'NO', '' ),
				'status'             => array( 'varchar(20)', '', 'NO', '' ),
				'created_at'         => array( 'datetime', '', 'NO', '' ),
				'updated_at'         => array( 'datetime', '', 'NO', '' ),
				// Migration 6 (phase 22); 0 for existing and new creators.
				'default_markup_bps' => array( 'int', 'unsigned', 'NO', '' ),
				// Migration 7 (ADR-048); 0 = not VAT registered.
				'vat_registered'     => array( 'tinyint(1)', 'unsigned', 'NO', '' ),
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

	public function test_sales_table_matches_the_locked_schema(): void {
		$this->install();

		self::assertSame(
			array(
				'id'                => array( 'bigint', 'unsigned', 'NO', 'auto_increment' ),
				'event_key'         => array( 'varchar(64)', '', 'NO', '' ),
				'creator_id'        => array( 'bigint', 'unsigned', 'NO', '' ),
				'order_id'          => array( 'bigint', 'unsigned', 'NO', '' ),
				'order_item_id'     => array( 'bigint', 'unsigned', 'NO', '' ),
				'cycle'             => array( 'int', 'unsigned', 'NO', '' ),
				'refund_id'         => array( 'bigint', 'unsigned', 'NO', '' ),
				'product_id'        => array( 'bigint', 'unsigned', 'NO', '' ),
				'variation_id'      => array( 'bigint', 'unsigned', 'NO', '' ),
				'item_name'         => array( 'varchar(191)', '', 'NO', '' ),
				'type'              => array( 'varchar(10)', '', 'NO', '' ),
				'correction_reason' => array( 'varchar(32)', '', 'YES', '' ),
				'quantity'          => array( 'int', '', 'NO', '' ),
				'net_amount'        => array( 'decimal(19,4)', '', 'NO', '' ),
				'currency'          => array( 'char(3)', '', 'NO', '' ),
				'occurred_at'       => array( 'datetime', '', 'NO', '' ),
				'created_at'        => array( 'datetime', '', 'NO', '' ),
			),
			$this->columns( Tables::SALES )
		);
		self::assertSame(
			array(
				'PRIMARY'          => array( 'unique', 'id' ),
				'creator_occurred' => array( 'non-unique', 'creator_id,occurred_at' ),
				'event_key'        => array( 'unique', 'event_key' ),
				'order_id'         => array( 'non-unique', 'order_id' ),
				'refund_id'        => array( 'non-unique', 'refund_id' ),
			),
			$this->indexes( Tables::SALES ),
			'No UNIQUE(order_item_id, refund_id, type).'
		);
	}

	public function test_sales_cycles_table_matches_the_locked_schema(): void {
		$this->install();

		self::assertSame(
			array(
				'id'          => array( 'bigint', 'unsigned', 'NO', 'auto_increment' ),
				'order_id'    => array( 'bigint', 'unsigned', 'NO', '' ),
				'cycle'       => array( 'int', 'unsigned', 'NO', '' ),
				'event'       => array( 'varchar(5)', '', 'NO', '' ),
				'occurred_at' => array( 'datetime', '', 'NO', '' ),
				'created_at'  => array( 'datetime', '', 'NO', '' ),
			),
			$this->columns( Tables::SALES_CYCLES )
		);
		self::assertSame(
			array(
				'PRIMARY'           => array( 'unique', 'id' ),
				'order_cycle_event' => array( 'unique', 'order_id,cycle,event' ),
			),
			$this->indexes( Tables::SALES_CYCLES ),
			'No separate order_id index: the unique key covers it.'
		);
	}

	public function test_creator_markup_defaults_to_0(): void {
		$this->install();

		self::assertSame( '0', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $this->table( Tables::CREATORS ), 'default_markup_bps' ) ) );
	}

	public function test_pricing_overrides_table_matches_the_documented_schema(): void {
		$this->install();

		self::assertSame(
			array(
				'product_id' => array( 'bigint', 'unsigned', 'NO', '' ),
				'creator_id' => array( 'bigint', 'unsigned', 'NO', '' ),
				'markup_bps' => array( 'int', 'unsigned', 'NO', '' ),
				'created_at' => array( 'datetime', '', 'NO', '' ),
				'updated_at' => array( 'datetime', '', 'NO', '' ),
			),
			$this->columns( Tables::PRICING_OVERRIDES )
		);
		self::assertSame(
			array(
				'PRIMARY'    => array( 'unique', 'product_id' ),
				'creator_id' => array( 'non-unique', 'creator_id' ),
			),
			$this->indexes( Tables::PRICING_OVERRIDES ),
			'At most one product markup per parent product.'
		);
	}

	public function test_the_retired_item_pricing_table_does_not_exist(): void {
		$this->install();

		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::ITEM_PRICING ) ) ) );
	}

	public function test_sales_line_pricing_table_matches_the_documented_schema(): void {
		$this->install();

		self::assertSame(
			array(
				'order_item_id'  => array( 'bigint', 'unsigned', 'NO', '' ),
				'order_id'       => array( 'bigint', 'unsigned', 'NO', '' ),
				'creator_id'     => array( 'bigint', 'unsigned', 'NO', '' ),
				'product_id'     => array( 'bigint', 'unsigned', 'NO', '' ),
				'variation_id'   => array( 'bigint', 'unsigned', 'NO', '' ),
				'status'         => array( 'varchar(8)', '', 'NO', '' ),
				'reason'         => array( 'varchar(20)', '', 'YES', '' ),
				'currency'       => array( 'char(3)', '', 'NO', '' ),
				'rule'           => array( 'varchar(7)', '', 'YES', '' ),
				'markup_bps'     => array( 'int', 'unsigned', 'YES', '' ),
				'base_unit'      => array( 'decimal(19,4)', '', 'YES', '' ),
				'profit_unit'    => array( 'decimal(19,4)', '', 'YES', '' ),
				'price_unit'     => array( 'decimal(19,4)', '', 'YES', '' ),
				'captured_at'    => array( 'datetime', '', 'NO', '' ),
				// Migration 7 (ADR-048); NULL = unknown (snapshots from before).
				'vat_registered' => array( 'tinyint(1)', 'unsigned', 'YES', '' ),
				// Migration 8 (ADR-049); NULL = unknown share (snapshots from before).
				'share_bps'      => array( 'int', 'unsigned', 'YES', '' ),
				'share_source'   => array( 'varchar(8)', '', 'YES', '' ),
				'price_decimals' => array( 'tinyint', 'unsigned', 'YES', '' ),
			),
			$this->columns( Tables::SALES_LINE_PRICING )
		);
		self::assertSame(
			array(
				'PRIMARY'    => array( 'unique', 'order_item_id' ),
				'creator_id' => array( 'non-unique', 'creator_id' ),
				'order_id'   => array( 'non-unique', 'order_id' ),
			),
			$this->indexes( Tables::SALES_LINE_PRICING ),
			'One snapshot per order line.'
		);
	}

	public function test_sales_tax_table_matches_the_documented_schema(): void {
		$this->install();

		self::assertSame(
			array(
				'event_key'     => array( 'varchar(64)', '', 'NO', '' ),
				'order_id'      => array( 'bigint', 'unsigned', 'NO', '' ),
				'order_item_id' => array( 'bigint', 'unsigned', 'NO', '' ),
				'tax_amount'    => array( 'decimal(19,4)', '', 'NO', '' ),
				'currency'      => array( 'char(3)', '', 'NO', '' ),
				'created_at'    => array( 'datetime', '', 'NO', '' ),
			),
			$this->columns( Tables::SALES_TAX )
		);
		self::assertSame(
			array(
				'PRIMARY'  => array( 'unique', 'event_key' ),
				'order_id' => array( 'non-unique', 'order_id' ),
			),
			$this->indexes( Tables::SALES_TAX ),
			'One tax row per ledger row.'
		);
		self::assertSame( '0', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $this->table( Tables::CREATORS ), 'vat_registered' ) ) );
	}

	public function test_plugin_tables_have_no_foreign_keys(): void {
		$this->install();

		foreach ( Tables::all() as $table ) {
			$references = $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME = %s OR REFERENCED_TABLE_NAME = %s) AND REFERENCED_TABLE_NAME IS NOT NULL',
					$this->table( $table ),
					$this->table( $table )
				)
			);

			self::assertSame( '0', $references, $table . ' has no foreign keys (snapshots outlive orders, products and rules).' );
		}
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
		self::assertSame( array(), dbDelta( CreateSalesLedgerTables::sales_schema( $this->table( Tables::SALES ), $charset_collate ) ) );
		self::assertSame( array(), dbDelta( CreateSalesLedgerTables::cycles_schema( $this->table( Tables::SALES_CYCLES ), $charset_collate ) ) );
		self::assertSame( array(), dbDelta( AddCreatorShareModel::product_markups_schema( $this->table( Tables::PRICING_OVERRIDES ), $charset_collate ) ) );
		self::assertSame( array(), dbDelta( CreateCreatorPricingTables::sales_line_pricing_schema( $this->table( Tables::SALES_LINE_PRICING ), $charset_collate ) ) );
		self::assertSame( array(), dbDelta( AddCreatorVatAndSalesTax::sales_tax_schema( $this->table( Tables::SALES_TAX ), $charset_collate ) ) );
	}

	/**
	 * Columns as [type without integer display width, unsigned, nullable, extra].
	 *
	 * MySQL 8.0.19+ no longer reports integer display widths (bigint(20) →
	 * bigint, int(10) → int, tinyint(3) → tinyint; tinyint(1) is kept), MariaDB
	 * does; both are normalized here.
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
				(string) preg_replace( '/^(bigint|int|tinyint)\((?!1\))\d+\)/', '$1', str_replace( ' unsigned', '', $type ) ),
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
