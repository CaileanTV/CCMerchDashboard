<?php
/**
 * Tests for the storage engine check.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Database;

use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

#[CoversClass( TransactionSupport::class )]
final class TransactionSupportTest extends TestCase {

	private RecordingWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new RecordingWpdb();
	}

	public function test_all_tables_on_a_transactional_engine_are_available(): void {
		$this->tables( array( 'wp_merchboard_creators' => 'YES', 'wp_merchboard_audit_log' => 'YES', 'wp_merchboard_creatormeta' => 'YES', 'wp_merchboard_creator_categories' => 'YES', 'wp_merchboard_sales' => 'YES', 'wp_merchboard_sales_cycles' => 'YES', 'wp_merchboard_pricing_overrides' => 'YES', 'wp_merchboard_sales_line_pricing' => 'YES', 'wp_merchboard_sales_tax' => 'YES', 'wp_merchboard_payouts' => 'YES', 'wp_merchboard_payout_secrets' => 'YES', 'wp_merchboard_settlement_documents' => 'YES', 'wp_merchboard_settlement_document_rows' => 'YES', 'wp_merchboard_settlement_counters' => 'YES', 'wp_merchboard_order_gift_card_assessments' => 'YES', 'wp_merchboard_order_gift_card_payouts' => 'YES', 'wp_merchboard_order_gift_card_attempts' => 'YES', 'wp_merchboard_g1_state' => 'YES', 'wp_merchboard_operations' => 'YES', 'wp_merchboard_payout_recalculations' => 'YES', 'wp_merchboard_payout_recalculation_items' => 'YES', 'wp_merchboard_payout_adjustments' => 'YES', 'wp_merchboard_payout_adjustment_rows' => 'YES', 'wp_merchboard_payout_recalculation_exports' => 'YES', 'wp_merchboard_creator_vat_history' => 'YES', 'wp_merchboard_creator_codes' => 'YES', 'wp_merchboard_order_exclusions' => 'YES', 'wp_merchboard_alert_events' => 'YES', 'wp_merchboard_alert_overlays' => 'YES' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$support = new TransactionSupport( $this->wpdb );

		self::assertTrue( $support->available() );
		self::assertSame( array(), $support->unsupported_tables() );
	}

	public function test_asks_information_schema_for_the_engine_of_the_plugin_tables_only(): void {
		$this->tables( array() );

		( new TransactionSupport( $this->wpdb ) )->available();

		self::assertCount( 1, $this->wpdb->prepared );
		self::assertStringContainsString( 'information_schema.TABLES', $this->wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'e.TRANSACTIONS', $this->wpdb->prepared[0]['query'], 'Uses what the server reports per engine, no hardcoded engine list.' );
		self::assertStringContainsString( 'TABLE_SCHEMA = DATABASE()', $this->wpdb->prepared[0]['query'] );
		self::assertSame( array( 'wp\_merchboard\_%' ), $this->wpdb->prepared[0]['args'] );
	}

	public function test_non_transactional_and_missing_tables_are_reported(): void {
		$this->wpdb->results = array(
			array(
				'name'         => 'wp_merchboard_creators',
				'engine'       => 'MyISAM',
				'transactions' => 'NO',
			),
		);

		$support = new TransactionSupport( $this->wpdb );

		self::assertFalse( $support->available() );
		self::assertSame(
			array(
				'wp_merchboard_creators'                   => 'MyISAM',
				'wp_merchboard_audit_log'                  => 'missing',
				'wp_merchboard_creatormeta'                => 'missing',
				'wp_merchboard_creator_categories'         => 'missing',
				'wp_merchboard_sales'                      => 'missing',
				'wp_merchboard_sales_cycles'               => 'missing',
				'wp_merchboard_pricing_overrides'          => 'missing',
				'wp_merchboard_sales_line_pricing'         => 'missing',
				'wp_merchboard_sales_tax'                  => 'missing',
				'wp_merchboard_payouts'                    => 'missing',
				'wp_merchboard_payout_secrets'             => 'missing',
				'wp_merchboard_settlement_documents'       => 'missing',
				'wp_merchboard_settlement_document_rows'   => 'missing',
				'wp_merchboard_settlement_counters'        => 'missing',
				'wp_merchboard_order_gift_card_assessments' => 'missing',
				'wp_merchboard_order_gift_card_payouts'    => 'missing',
				'wp_merchboard_order_gift_card_attempts'   => 'missing',
				'wp_merchboard_g1_state'                   => 'missing',
				'wp_merchboard_operations'                 => 'missing',
				'wp_merchboard_payout_recalculations'      => 'missing',
				'wp_merchboard_payout_recalculation_items' => 'missing',
				'wp_merchboard_payout_adjustments'         => 'missing',
				'wp_merchboard_payout_adjustment_rows'     => 'missing',
				'wp_merchboard_payout_recalculation_exports' => 'missing',
				'wp_merchboard_creator_vat_history'        => 'missing',
				'wp_merchboard_creator_codes'              => 'missing',
				'wp_merchboard_order_exclusions'           => 'missing',
				'wp_merchboard_alert_events'               => 'missing',
				'wp_merchboard_alert_overlays'             => 'missing',
			),
			$support->unsupported_tables()
		);
	}

	public function test_unknown_engine_fails_closed(): void {
		$this->wpdb->results = array(
			array(
				'name'         => 'wp_merchboard_creators',
				'engine'       => null,
				'transactions' => null,
			),
			array(
				'name'         => 'wp_merchboard_audit_log',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_creatormeta',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_creator_categories',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_sales',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_sales_cycles',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_pricing_overrides',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_sales_line_pricing',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_sales_tax',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payouts',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_secrets',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_settlement_documents',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_settlement_document_rows',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_settlement_counters',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_order_gift_card_assessments',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_order_gift_card_payouts',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_order_gift_card_attempts',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_g1_state',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_operations',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_recalculations',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_recalculation_items',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_adjustments',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_adjustment_rows',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_recalculation_exports',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_creator_vat_history',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_creator_codes',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_order_exclusions',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_alert_events',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_alert_overlays',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
		);

		self::assertSame( array( 'wp_merchboard_creators' => 'unknown' ), ( new TransactionSupport( $this->wpdb ) )->unsupported_tables() );
	}

	public function test_failed_query_fails_closed(): void {
		$this->wpdb->results = array();

		self::assertFalse( ( new TransactionSupport( $this->wpdb ) )->available() );
	}

	public function test_lower_cased_table_names_are_matched(): void {
		$this->wpdb->prefix = 'WP_Shop_';
		$this->tables( array( 'wp_shop_merchboard_creators' => 'YES', 'wp_shop_merchboard_audit_log' => 'YES', 'wp_shop_merchboard_creatormeta' => 'YES', 'wp_shop_merchboard_creator_categories' => 'YES', 'wp_shop_merchboard_sales' => 'YES', 'wp_shop_merchboard_sales_cycles' => 'YES', 'wp_shop_merchboard_pricing_overrides' => 'YES', 'wp_shop_merchboard_sales_line_pricing' => 'YES', 'wp_shop_merchboard_sales_tax' => 'YES', 'wp_shop_merchboard_payouts' => 'YES', 'wp_shop_merchboard_payout_secrets' => 'YES', 'wp_shop_merchboard_settlement_documents' => 'YES', 'wp_shop_merchboard_settlement_document_rows' => 'YES', 'wp_shop_merchboard_settlement_counters' => 'YES', 'wp_shop_merchboard_order_gift_card_assessments' => 'YES', 'wp_shop_merchboard_order_gift_card_payouts' => 'YES', 'wp_shop_merchboard_order_gift_card_attempts' => 'YES', 'wp_shop_merchboard_g1_state' => 'YES', 'wp_shop_merchboard_operations' => 'YES', 'wp_shop_merchboard_payout_recalculations' => 'YES', 'wp_shop_merchboard_payout_recalculation_items' => 'YES', 'wp_shop_merchboard_payout_adjustments' => 'YES', 'wp_shop_merchboard_payout_adjustment_rows' => 'YES', 'wp_shop_merchboard_payout_recalculation_exports' => 'YES', 'wp_shop_merchboard_creator_vat_history' => 'YES', 'wp_shop_merchboard_creator_codes' => 'YES', 'wp_shop_merchboard_order_exclusions' => 'YES', 'wp_shop_merchboard_alert_events' => 'YES', 'wp_shop_merchboard_alert_overlays' => 'YES' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertTrue( ( new TransactionSupport( $this->wpdb ) )->available() );
	}

	public function test_positive_result_is_cached_but_a_negative_one_is_checked_again(): void {
		$support = new TransactionSupport( $this->wpdb );

		$this->tables( array( 'wp_merchboard_creators' => 'YES' ) );
		self::assertFalse( $support->available() );

		$this->tables( array( 'wp_merchboard_creators' => 'YES', 'wp_merchboard_audit_log' => 'YES', 'wp_merchboard_creatormeta' => 'YES', 'wp_merchboard_creator_categories' => 'YES', 'wp_merchboard_sales' => 'YES', 'wp_merchboard_sales_cycles' => 'YES', 'wp_merchboard_pricing_overrides' => 'YES', 'wp_merchboard_sales_line_pricing' => 'YES', 'wp_merchboard_sales_tax' => 'YES', 'wp_merchboard_payouts' => 'YES', 'wp_merchboard_payout_secrets' => 'YES', 'wp_merchboard_settlement_documents' => 'YES', 'wp_merchboard_settlement_document_rows' => 'YES', 'wp_merchboard_settlement_counters' => 'YES', 'wp_merchboard_order_gift_card_assessments' => 'YES', 'wp_merchboard_order_gift_card_payouts' => 'YES', 'wp_merchboard_order_gift_card_attempts' => 'YES', 'wp_merchboard_g1_state' => 'YES', 'wp_merchboard_operations' => 'YES', 'wp_merchboard_payout_recalculations' => 'YES', 'wp_merchboard_payout_recalculation_items' => 'YES', 'wp_merchboard_payout_adjustments' => 'YES', 'wp_merchboard_payout_adjustment_rows' => 'YES', 'wp_merchboard_payout_recalculation_exports' => 'YES', 'wp_merchboard_creator_vat_history' => 'YES', 'wp_merchboard_creator_codes' => 'YES', 'wp_merchboard_order_exclusions' => 'YES', 'wp_merchboard_alert_events' => 'YES', 'wp_merchboard_alert_overlays' => 'YES' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertTrue( $support->available(), 'A table created later in the request is picked up.' );

		$this->tables( array() );
		self::assertTrue( $support->available() );
		self::assertCount( 2, $this->wpdb->prepared, 'No query once available.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sqlite_is_always_transactional_without_asking_for_engines(): void {
		define( 'DB_ENGINE', 'sqlite' );

		self::assertTrue( ( new TransactionSupport( $this->wpdb ) )->available() );
		self::assertSame( array(), $this->wpdb->prepared );
	}

	/**
	 * Configures information_schema rows: table => TRANSACTIONS.
	 *
	 * @param array<string, string> $tables Tables.
	 */
	private function tables( array $tables ): void {
		$this->wpdb->results = array();

		foreach ( $tables as $name => $transactions ) {
			$this->wpdb->results[] = array(
				'name'         => $name,
				'engine'       => 'YES' === $transactions ? 'InnoDB' : 'MyISAM',
				'transactions' => $transactions,
			);
		}
	}
}
