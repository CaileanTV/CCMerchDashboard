<?php
/**
 * Migrations, version option and migration lock on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migration;
use MerchBoard\Core\Database\Migrations\AddAlertEventAmounts;
use MerchBoard\Core\Database\Migrations\AddAlertOrderLogIndexes;
use MerchBoard\Core\Database\Migrations\CreateAlertApiKeysTable;
use MerchBoard\Core\Database\Migrations\CreateAlertWebhookTables;
use MerchBoard\Core\Database\Migrations\GrantExistingAlertApiAccess;
use MerchBoard\Core\Database\Migrations\AddAlertEventImportedFlag;
use MerchBoard\Core\Database\Migrations\RemoveImportOptions;
use MerchBoard\Core\Database\Migrations\AddCreatorDeletedAt;
use MerchBoard\Core\Database\Migrations\CreateNewsTables;
use MerchBoard\Core\Database\Migrations\AddChatbotPlatforms;
use MerchBoard\Core\Database\Migrations\AddCreatorShareModel;
use MerchBoard\Core\Database\Migrations\AddPayoutGiftCards;
use MerchBoard\Core\Database\Migrations\CreateAlertChatbotTables;
use MerchBoard\Core\Database\Migrations\CreateAlertEventsTable;
use MerchBoard\Core\Database\Migrations\CreateAlertOverlaysTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorPricingTables;
use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorVatHistoryTable;
use MerchBoard\Core\Database\Migrations\CreatePayoutRecalculationExportsTable;
use MerchBoard\Core\Database\Migrations\CreatePayoutRecalculationTables;
use MerchBoard\Core\Database\Migrations\WidenChatbotChannels;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\Installer;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use wpdb;

final class MigrationTest extends IntegrationTestCase {

	public function test_fresh_install_creates_schema_version_9(): void {
		self::assertSame( 0, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( '31', (string) $this->raw_option( Migrator::VERSION_OPTION ) );
		self::assertSame( 0, $this->count_rows( Tables::CREATORS ) );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_META ) );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_CATEGORIES ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );
		self::assertSame( 0, $this->count_rows( Tables::PRICING_OVERRIDES ) );
		self::assertFalse( $this->has_table( Tables::ITEM_PRICING ), 'Created by version 6, dropped by version 8.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES_LINE_PRICING ) );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUTS ) );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUT_SECRETS ) );
		self::assertNull( $this->raw_option( Migrator::LOCK_OPTION ), 'Lock released.' );
		self::assertFalse( get_transient( Installer::FAILURE_TRANSIENT ) );
	}

	public function test_upgrade_from_1_adds_the_later_tables_and_keeps_creator_data(): void {
		( new Migrator( $this->wpdb, array( 1 => new CreateCreatorsTable() ) ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før opgradering 🎸', CreatorStatus::Active );
		$before  = $this->creator_rows();

		self::assertSame( 1, $this->migrator()->installed_version() );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::AUDIT_LOG ) ) ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->rows_without_markup( Tables::CREATORS ) );
		self::assertSame( array( '0' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT default_markup_bps FROM %i', $this->table( Tables::CREATORS ) ) ) );
		self::assertSame( 'Før opgradering 🎸', ( new CreatorRepository( $this->wpdb ) )->find( $creator->id )?->display_name );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_upgrade_from_4_adds_only_the_sales_ledger_tables(): void {
		$migrations = Schema::migrations();
		unset( $migrations[5], $migrations[6], $migrations[7], $migrations[8], $migrations[9], $migrations[10], $migrations[11], $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17], $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		( new CreatorRepository( $this->wpdb ) )->create( 'Før salg', CreatorStatus::Active );
		$before = $this->creator_rows();

		self::assertSame( 4, $this->migrator()->installed_version() );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::SALES ) ) ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->rows_without_markup( Tables::CREATORS ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );
	}

	public function test_upgrade_from_5_adds_pricing_with_0_markup_for_existing_creators_and_keeps_the_ledger(): void {
		$migrations = Schema::migrations();
		unset( $migrations[6], $migrations[7], $migrations[8], $migrations[9], $migrations[10], $migrations[11], $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17], $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$repository = new CreatorRepository( $this->wpdb );
		$active     = $repository->create( 'Aktiv før prissætning', CreatorStatus::Active );
		$inactive   = $repository->create( 'Inaktiv før prissætning', CreatorStatus::Inactive );
		$before     = $this->creator_rows();
		$sales      = $this->rows( Tables::SALES );
		$sales_cols = $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::SALES ) ) );

		self::assertSame( 5, $this->migrator()->installed_version() );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $this->table( Tables::CREATORS ), 'default_markup_bps' ) ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->rows_without_markup( Tables::CREATORS ), 'Existing creator data unchanged.' );

		$markups = new CreatorMarkupRepository( $this->wpdb );
		self::assertSame( 0, $markups->find( $active->id )?->bps );
		self::assertSame( 0, $markups->find( $inactive->id )?->bps );
		self::assertSame( $sales, $this->rows( Tables::SALES ), 'The sales ledger is untouched.' );
		self::assertSame( $sales_cols, $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::SALES ) ) ), 'No profit column on the ledger.' );
		self::assertSame( 0, $this->count_rows( Tables::PRICING_OVERRIDES ) );
		self::assertFalse( $this->has_table( Tables::ITEM_PRICING ), 'Created by version 6, dropped by version 8.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES_LINE_PRICING ) );

		// A creator created after the upgrade also starts at 0 %.
		self::assertSame( 0, $markups->find( $repository->create( 'Ny', CreatorStatus::Active )->id )?->bps );
	}

	public function test_pricing_migration_is_idempotent_and_adds_the_column_once(): void {
		$this->install();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Markup', CreatorStatus::Active );
		( new CreatorMarkupRepository( $this->wpdb ) )->save( $creator->id, new MarkupBps( 1950 ) );

		( new CreateCreatorPricingTables() )->up( $this->wpdb );
		( new CreateCreatorPricingTables() )->up( $this->wpdb );

		self::assertSame( 1950, ( new CreatorMarkupRepository( $this->wpdb ) )->find( $creator->id )?->bps, 'Re-running keeps the stored markup.' );
		self::assertCount( 1, $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $this->table( Tables::CREATORS ), 'default_markup_bps' ) ) );
	}

	public function test_upgrade_from_6_adds_vat_status_and_sales_tax_without_changing_existing_data(): void {
		$migrations = Schema::migrations();
		unset( $migrations[7], $migrations[8], $migrations[9], $migrations[10], $migrations[11], $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17], $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før moms', CreatorStatus::Active );
		$this->wpdb->insert(
			$this->table( Tables::SALES_LINE_PRICING ),
			array(
				'order_item_id' => 901,
				'order_id'      => 900,
				'creator_id'    => $creator->id,
				'product_id'    => 20,
				'variation_id'  => 0,
				'status'        => 'unpriced',
				'reason'        => 'never_applied',
				'currency'      => 'DKK',
				'captured_at'   => '2026-10-08 10:00:00',
			)
		);
		$before = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i', $this->table( Tables::SALES_LINE_PRICING ) ), ARRAY_A );

		self::assertSame( 6, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( '0', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT vat_registered FROM %i WHERE id = %d', $this->table( Tables::CREATORS ), $creator->id ) ), 'Existing creators: not VAT registered.' );
		$after = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i', $this->table( Tables::SALES_LINE_PRICING ) ), ARRAY_A );
		self::assertIsArray( $after );
		self::assertNull( $after['vat_registered'], 'Existing snapshots keep an unknown VAT status; never filled in.' );
		unset( $after['vat_registered'], $after['share_bps'], $after['share_source'], $after['price_decimals'] );
		self::assertSame( $before, $after );
		self::assertSame( 0, $this->count_rows( Tables::SALES_TAX ) );
	}

	public function test_upgrade_from_7_switches_to_the_share_model_without_changing_history(): void {
		$migrations = Schema::migrations();
		unset( $migrations[8], $migrations[9], $migrations[10], $migrations[11], $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17], $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før andel', CreatorStatus::Active );
		( new CreatorMarkupRepository( $this->wpdb ) )->save( $creator->id, new MarkupBps( 1667 ) );
		$this->wpdb->update( $this->table( Tables::CREATORS ), array( 'vat_registered' => 1 ), array( 'id' => $creator->id ) );
		$this->wpdb->insert(
			$this->table( Tables::SALES_LINE_PRICING ),
			array(
				'order_item_id'  => 901,
				'order_id'       => 900,
				'creator_id'     => $creator->id,
				'product_id'     => 20,
				'variation_id'   => 0,
				'status'         => 'priced',
				'currency'       => 'DKK',
				'rule'           => 'default',
				'markup_bps'     => 1900,
				'base_unit'      => '120.0000',
				'profit_unit'    => '22.8000',
				'price_unit'     => '142.8000',
				'captured_at'    => '2026-10-08 10:00:00',
				'vat_registered' => 0,
			)
		);
		$this->wpdb->insert( $this->table( Tables::PRICING_OVERRIDES ), array( 'product_id' => 20, 'creator_id' => $creator->id, 'fixed_profit' => '25.0000', 'currency' => 'DKK', 'created_at' => '2026-10-08 10:00:00', 'updated_at' => '2026-10-08 10:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->insert( $this->table( Tables::ITEM_PRICING ), array( 'item_id' => 20, 'product_id' => 20, 'base_price' => '120.0000', 'base_currency' => 'DKK', 'base_updated_at' => '2026-10-08 10:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$creators = $this->creator_rows();
		$snapshot = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i', $this->table( Tables::SALES_LINE_PRICING ) ), ARRAY_A );
		$sales    = $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::SALES ) ) );

		self::assertSame( 7, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( $creators, array_map( static fn( array $row ): array => array_diff_key( $row, array( 'payout_start_at' => null ) ), $this->creator_rows() ), 'Markup and VAT status unchanged (the settlement start is added by version 9).' );

		$after = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i', $this->table( Tables::SALES_LINE_PRICING ) ), ARRAY_A );
		self::assertIsArray( $after );
		self::assertNull( $after['share_bps'], 'The share of an existing snapshot is unknown and never filled in.' );
		self::assertNull( $after['share_source'] );
		self::assertNull( $after['price_decimals'] );
		unset( $after['share_bps'], $after['share_source'], $after['price_decimals'] );
		self::assertSame( $snapshot, $after, 'The existing snapshot is unchanged.' );

		self::assertSame( array( 'product_id', 'creator_id', 'markup_bps', 'created_at', 'updated_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::PRICING_OVERRIDES ) ) ) );
		self::assertSame( 0, $this->count_rows( Tables::PRICING_OVERRIDES ), 'A fixed amount cannot become a share without guessing.' );
		self::assertFalse( $this->has_table( Tables::ITEM_PRICING ) );
		self::assertSame( $sales, $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::SALES ) ) ), 'The ledger is untouched.' );

		// Re-running the migration changes nothing.
		( new AddCreatorShareModel() )->up( $this->wpdb );
		self::assertSame( 0, $this->count_rows( Tables::PRICING_OVERRIDES ) );
	}

	public function test_upgrade_from_9_adds_the_gift_card_columns_without_changing_payouts(): void {
		$migrations = Schema::migrations();
		unset( $migrations[10], $migrations[11], $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17], $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før gavekort', CreatorStatus::Active );
		$this->wpdb->insert( $this->table( Tables::PAYOUTS ), array( 'creator_id' => $creator->id, 'currency' => 'DKK', 'amount' => '25.0000', 'price_decimals' => 2, 'method' => 'bank', 'status' => 'pending', 'destination_hint' => '•••• 1234', 'idempotency_key' => 'key-before-10', 'requested_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$before = $this->rows( Tables::PAYOUTS );

		self::assertSame( 9, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		$after = $this->rows( Tables::PAYOUTS );
		self::assertNull( $after[0]['gift_card_provider'] );
		self::assertNull( $after[0]['gift_card_external_id'] );
		self::assertSame(
			$before,
			array_map(
				static fn( array $row ): array => array_diff_key(
					$row,
					array(
						'gift_card_provider'    => null,
						'gift_card_external_id' => null,
					)
				),
				$after
			),
			'Existing payouts are unchanged.'
		);

		// Re-running the migration changes nothing.
		( new AddPayoutGiftCards() )->up( $this->wpdb );
		self::assertSame( $after, $this->rows( Tables::PAYOUTS ) );
	}

	public function test_upgrade_from_14_adds_only_the_exports_table_and_keeps_existing_data(): void {
		// A database that ran schema version 14 without the exports table (as Local did).
		$migrations = Schema::migrations();
		unset( $migrations[15], $migrations[16], $migrations[17], $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før eksport', CreatorStatus::Active );
		$this->wpdb->insert( $this->table( Tables::RECALCULATIONS ), array( 'rule' => 'adr063-remove-20', 'calculation_version' => 'adr063-adr054-v1', 'status' => 'closed', 'fingerprint' => str_repeat( 'a', 64 ), 'created_at' => '2026-10-17 12:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$before = array(
			'creators'       => $this->creator_rows(),
			'recalculations' => $this->rows( Tables::RECALCULATIONS ),
		);

		self::assertSame( 14, $this->migrator()->installed_version() );
		self::assertFalse( $this->has_table( Tables::RECALCULATION_EXPORTS ), 'Version 14 has no exports table.' );
		self::assertTrue( $this->has_table( Tables::RECALCULATIONS ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertTrue( $this->has_table( Tables::RECALCULATION_EXPORTS ) );
		self::assertSame( 0, $this->count_rows( Tables::RECALCULATION_EXPORTS ) );
		self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( Tables::RECALCULATION_EXPORTS ) ) ) );
		self::assertSame( array( 'id', 'recalculation_id', 'filters', 'files', 'manifest_sha256', 'sums_sha256', 'created_by', 'created_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::RECALCULATION_EXPORTS ) ) ) );
		self::assertSame( $before['creators'], $this->creator_rows(), 'Existing data is unchanged.' );
		self::assertSame( $before['recalculations'], $this->rows( Tables::RECALCULATIONS ) );
		self::assertNotNull( ( new CreatorRepository( $this->wpdb ) )->find( $creator->id ) );

		// Re-running migration 15 changes nothing.
		( new CreatePayoutRecalculationExportsTable() )->up( $this->wpdb );
		self::assertSame( 0, $this->count_rows( Tables::RECALCULATION_EXPORTS ) );
		self::assertSame( 31, $this->migrator()->installed_version() );
	}

	public function test_migration_14_no_longer_creates_the_exports_table(): void {
		$migrations = Schema::migrations();
		unset( $migrations[15], $migrations[16], $migrations[17], $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();

		( new CreatePayoutRecalculationTables() )->up( $this->wpdb );

		self::assertFalse( $this->has_table( Tables::RECALCULATION_EXPORTS ), 'Only migration 15 creates it.' );
		self::assertSame( array( 22, 23, 24, 25, 26, 27, 28, 29, 30, 31 ), array_slice( array_keys( Schema::migrations() ), -10 ) );
		self::assertInstanceOf( CreatePayoutRecalculationExportsTable::class, Schema::migrations()[15] );
	}

	public function test_upgrade_from_15_adds_only_the_empty_vat_history_and_keeps_the_current_status(): void {
		// A database of schema version 15 (as Local after R2).
		$migrations = Schema::migrations();
		unset( $migrations[16], $migrations[17], $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før momshistorik', CreatorStatus::Active );
		( new CreatorVatRepository( $this->wpdb ) )->save( $creator->id, true );
		$before = $this->creator_rows();

		self::assertSame( 15, $this->migrator()->installed_version() );
		self::assertFalse( $this->has_table( Tables::CREATOR_VAT_HISTORY ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertTrue( $this->has_table( Tables::CREATOR_VAT_HISTORY ) );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_VAT_HISTORY ), 'No history is invented for existing creators.' );
		self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( Tables::CREATOR_VAT_HISTORY ) ) ) );
		self::assertSame( array( 'id', 'creator_id', 'vat_registered', 'effective_from', 'checked_on', 'documentation_reference', 'reason', 'recorded_by', 'recorded_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::CREATOR_VAT_HISTORY ) ) ) );
		self::assertSame( $before, $this->creator_rows(), 'The current VAT status and every creator are unchanged.' );
		self::assertTrue( ( new CreatorVatRepository( $this->wpdb ) )->find( $creator->id ) );

		// Re-running migration 16 changes nothing.
		( new CreateCreatorVatHistoryTable() )->up( $this->wpdb );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_VAT_HISTORY ) );
		self::assertInstanceOf( CreateCreatorVatHistoryTable::class, Schema::migrations()[16] );
	}

	public function test_upgrade_from_17_adds_only_the_empty_alert_events_table(): void {
		// A database of schema version 17 (with the creator discount codes).
		$migrations = Schema::migrations();
		unset( $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		( new CreatorRepository( $this->wpdb ) )->create( 'Før alerts', CreatorStatus::Active );
		$before = $this->creator_rows();

		self::assertSame( 17, $this->migrator()->installed_version() );
		self::assertFalse( $this->has_table( Tables::ALERT_EVENTS ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertTrue( $this->has_table( Tables::ALERT_EVENTS ) );
		self::assertSame( 0, $this->count_rows( Tables::ALERT_EVENTS ), 'No alerts are invented for earlier orders.' );
		self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( Tables::ALERT_EVENTS ) ) ) );
		self::assertSame( array( 'id', 'creator_id', 'order_id', 'order_number', 'order_item_id', 'product_id', 'variation_id', 'product_name', 'variation', 'quantity', 'line_total', 'creator_total', 'currency', 'image_id', 'category_ids', 'customer_username', 'imported', 'created_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::ALERT_EVENTS ) ) ) );
		self::assertSame( array( 'order_item_id', 'creator_id' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0 ORDER BY SEQ_IN_INDEX', $this->table( Tables::ALERT_EVENTS ), 'order_item_creator' ) ) );
		self::assertSame( $before, $this->creator_rows() );

		// Re-running migrations 18 and 19 ends in the same table.
		( new CreateAlertEventsTable() )->up( $this->wpdb );
		( new CreateAlertOverlaysTable() )->up( $this->wpdb );
		self::assertSame( 0, $this->count_rows( Tables::ALERT_EVENTS ) );
		self::assertNotContains( 'customer_first_name', $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::ALERT_EVENTS ) ) ) );
		self::assertInstanceOf( CreateAlertEventsTable::class, Schema::migrations()[18] );
	}

	public function test_upgrade_from_18_adds_the_overlays_and_drops_the_customer_first_name(): void {
		// A database of schema version 18 (as Local after phase 25, step 2).
		$migrations = Schema::migrations();
		unset( $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$events = $this->table( Tables::ALERT_EVENTS );
		$insert = $this->wpdb->prepare( 'INSERT INTO %i (creator_id, order_id, order_number, order_item_id, product_id, variation_id, product_name, variation, quantity, image_id, category_ids, customer_first_name, customer_username, created_at) VALUES (1, 2, %s, 3, 4, 0, %s, %s, 1, 0, %s, %s, %s, %s)', $events, '02', 'Hoodie', '', '[]', 'Anna', 'anna_streams', '2026-10-19 10:00:00' );
		self::assertIsString( $insert );
		$this->wpdb->query( $insert );

		self::assertSame( 18, $this->migrator()->installed_version() );
		self::assertFalse( $this->has_table( Tables::ALERT_OVERLAYS ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertTrue( $this->has_table( Tables::ALERT_OVERLAYS ) );
		self::assertSame( 0, $this->count_rows( Tables::ALERT_OVERLAYS ) );
		self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( Tables::ALERT_OVERLAYS ) ) ) );
		self::assertSame( array( 'id', 'creator_id', 'overlay_id', 'settings', 'created_at', 'updated_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::ALERT_OVERLAYS ) ) ) );
		self::assertSame( array( 'creator_id', 'overlay_id' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0 AND INDEX_NAME <> %s ORDER BY INDEX_NAME', $this->table( Tables::ALERT_OVERLAYS ), 'PRIMARY' ) ) );
		self::assertNotContains( 'customer_first_name', $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $events ) ), 'Alerts show only the username (ADR-065, status note 2026-10-19).' );
		self::assertSame( array( 'anna_streams' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT customer_username FROM %i', $events ) ), 'The alert itself is kept.' );

		// Re-running migration 19 changes nothing.
		( new CreateAlertOverlaysTable() )->up( $this->wpdb );
		self::assertSame( 1, $this->count_rows( Tables::ALERT_EVENTS ) );
		self::assertInstanceOf( CreateAlertOverlaysTable::class, Schema::migrations()[19] );
	}

	public function test_upgrade_from_19_adds_the_three_empty_chatbot_tables(): void {
		// A database of schema version 19 (as Local after phase 25, step 7).
		$migrations = Schema::migrations();
		unset( $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		( new CreatorRepository( $this->wpdb ) )->create( 'Før chatbot', CreatorStatus::Active );
		$before = $this->creator_rows();

		self::assertSame( 19, $this->migrator()->installed_version() );
		self::assertFalse( $this->has_table( Tables::ALERT_CHATBOT ) );

		// Up to version 20 only; migration 21 has its own test.
		$up_to_20 = Schema::migrations();
		unset( $up_to_20[21], $up_to_20[22], $up_to_20[23], $up_to_20[24], $up_to_20[25], $up_to_20[26], $up_to_20[27], $up_to_20[28], $up_to_20[29], $up_to_20[30], $up_to_20[31] );
		( new Migrator( $this->wpdb, $up_to_20 ) )->migrate();

		self::assertSame( 20, $this->migrator()->installed_version() );
		$unique = array(
			Tables::ALERT_CHATBOT       => array( 'creator_id' ),
			Tables::ALERT_CHAT_MESSAGES => array( 'message' ),
			Tables::ALERT_CHAT_LOG      => array( 'message_id' ),
		);

		foreach ( $unique as $table => $indexes ) {
			self::assertTrue( $this->has_table( $table ) );
			self::assertSame( 0, $this->count_rows( $table ) );
			self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( $table ) ) ) );
			self::assertSame( $indexes, $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0 AND INDEX_NAME <> %s ORDER BY INDEX_NAME', $this->table( $table ), 'PRIMARY' ) ) );
		}

		self::assertSame( array( 'creator_id', 'order_id', 'order_item_id' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX', $this->table( Tables::ALERT_CHAT_MESSAGES ), 'message' ) ), 'A message is sent at most once per order line (ADR-066).' );
		self::assertNotContains( 'access_token', $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::ALERT_CHATBOT ) ) ), 'No Twitch tokens are stored per creator.' );
		self::assertSame( $before, $this->creator_rows() );

		// Re-running migration 20 changes nothing.
		( new CreateAlertChatbotTables() )->up( $this->wpdb );
		self::assertSame( 0, $this->count_rows( Tables::ALERT_CHATBOT ) );
		self::assertInstanceOf( CreateAlertChatbotTables::class, Schema::migrations()[20] );
	}

	public function test_upgrade_from_20_makes_the_chatbot_tables_platform_aware_and_keeps_twitch(): void {
		// A database of schema version 20 with a Twitch channel (as devpage after step 25.8).
		$migrations = Schema::migrations();
		unset( $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$chatbot  = $this->table( Tables::ALERT_CHATBOT );
		$messages = $this->table( Tables::ALERT_CHAT_MESSAGES );
		$log      = $this->table( Tables::ALERT_CHAT_LOG );
		$this->execute( 'INSERT INTO %i (creator_id, enabled, template, broadcaster_id, broadcaster_login, authorized_at, subscription_id, subscription_status, created_at, updated_at) VALUES (7, 1, %s, %s, %s, %s, %s, %s, %s, %s)', $chatbot, 'Hi $Name', '500', 'nova', '2026-10-20 10:00:00', 'sub-1', 'enabled', '2026-10-20 10:00:00', '2026-10-20 10:00:00' );
		$this->execute( 'INSERT INTO %i (creator_id, order_id, order_item_id, status, reason, attempts, created_at, updated_at) VALUES (7, 100, 1, %s, %s, 1, %s, %s)', $messages, 'sent', '', '2026-10-20 10:00:00', '2026-10-20 10:00:00' );
		$this->execute( 'INSERT INTO %i (broadcaster_id, message_id, chatter_login, chatter_name, color, text, created_at) VALUES (%s, %s, %s, %s, %s, %s, %s)', $log, '500', 'msg-1', 'viewer', 'Viewer', '', 'hype', '2026-10-20 10:00:00' );

		self::assertSame( 20, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );

		$unique = array(
			Tables::ALERT_CHATBOT       => array( 'creator_platform' ),
			Tables::ALERT_CHAT_MESSAGES => array( 'platform_message' ),
			Tables::ALERT_CHAT_LOG      => array( 'platform_message_id' ),
		);

		foreach ( $unique as $table => $indexes ) {
			self::assertSame( array( 'twitch' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT platform FROM %i', $this->table( $table ) ) ), 'Existing rows are Twitch\'s.' );
			self::assertSame( $indexes, $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0 AND INDEX_NAME <> %s ORDER BY INDEX_NAME', $this->table( $table ), 'PRIMARY' ) ) );
		}

		self::assertSame( array( 'creator_id', 'platform' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX', $chatbot, 'creator_platform' ) ) );
		self::assertSame( array( 'creator_id', 'platform', 'order_id', 'order_item_id' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX', $messages, 'platform_message' ) ) );
		self::assertSame( array( 'Hi $Name', 'nova', 'enabled' ), array_values( (array) $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT template, broadcaster_login, subscription_status FROM %i', $chatbot ), ARRAY_A ) ), 'The Twitch channel is kept.' );
		self::assertContains( 'access_token', $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $chatbot ) ) );

		// The same creator, order and message id on Kick.
		$this->execute( 'INSERT INTO %i (creator_id, platform, enabled, template, created_at, updated_at) VALUES (7, %s, 1, %s, %s, %s)', $chatbot, 'kick', '', '2026-10-20 10:00:00', '2026-10-20 10:00:00' );
		$this->execute( 'INSERT INTO %i (creator_id, platform, order_id, order_item_id, status, reason, attempts, created_at, updated_at) VALUES (7, %s, 100, 1, %s, %s, 1, %s, %s)', $messages, 'kick', 'sent', '', '2026-10-20 10:00:00', '2026-10-20 10:00:00' );
		$this->execute( 'INSERT INTO %i (platform, broadcaster_id, message_id, created_at) VALUES (%s, %s, %s, %s)', $log, 'kick', '500', 'msg-1', '2026-10-20 10:00:00' );
		self::assertSame( 2, $this->count_rows( Tables::ALERT_CHATBOT ) );
		self::assertSame( 2, $this->count_rows( Tables::ALERT_CHAT_MESSAGES ) );
		self::assertSame( 2, $this->count_rows( Tables::ALERT_CHAT_LOG ) );

		// Re-running migrations 20 and 21 changes nothing, also with two platforms.
		( new CreateAlertChatbotTables() )->up( $this->wpdb );
		( new AddChatbotPlatforms() )->up( $this->wpdb );
		self::assertSame( 2, $this->count_rows( Tables::ALERT_CHATBOT ) );
		self::assertSame( array( 'creator_platform' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0 AND INDEX_NAME <> %s', $chatbot, 'PRIMARY' ) ) );
		self::assertInstanceOf( AddChatbotPlatforms::class, Schema::migrations()[21] );
	}

	public function test_upgrade_from_21_widens_the_channel_columns_for_youtube_and_keeps_the_channels(): void {
		// A database of schema version 21 with a Twitch and a Kick channel (as devpage after step 25.8.2).
		$migrations = Schema::migrations();
		unset( $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$chatbot = $this->table( Tables::ALERT_CHATBOT );
		$log     = $this->table( Tables::ALERT_CHAT_LOG );
		$width   = fn( string $table, string $column ): int => (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, $column ) );

		foreach ( array(
			'twitch' => array( '500', 'nova' ),
			'kick'   => array( '777', 'nova_kick' ),
		) as $platform => [ $id, $login ] ) {
			$this->execute( 'INSERT INTO %i (creator_id, platform, enabled, template, broadcaster_id, broadcaster_login, created_at, updated_at) VALUES (7, %s, 1, %s, %s, %s, %s, %s)', $chatbot, $platform, '', $id, $login, '2026-10-21 10:00:00', '2026-10-21 10:00:00' );
		}

		self::assertSame( array( 20, 25, 20 ), array( $width( $chatbot, 'broadcaster_id' ), $width( $chatbot, 'broadcaster_login' ), $width( $log, 'broadcaster_id' ) ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( array( 64, 100, 64 ), array( $width( $chatbot, 'broadcaster_id' ), $width( $chatbot, 'broadcaster_login' ), $width( $log, 'broadcaster_id' ) ) );
		self::assertSame( array( '500,nova', '777,nova_kick' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT CONCAT_WS(%s, broadcaster_id, broadcaster_login) FROM %i ORDER BY id', ',', $chatbot ) ), 'The channels are kept.' );

		// A YouTube channel id and a long channel name fit.
		$name = str_repeat( 'Ø', 100 );
		$this->execute( 'INSERT INTO %i (creator_id, platform, enabled, template, broadcaster_id, broadcaster_login, created_at, updated_at) VALUES (7, %s, 1, %s, %s, %s, %s, %s)', $chatbot, 'youtube', '', 'UCnovanovanovanovanova12', $name, '2026-10-21 10:00:00', '2026-10-21 10:00:00' );
		self::assertSame( array( 'UCnovanovanovanovanova12', $name ), array_values( (array) $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT broadcaster_id, broadcaster_login FROM %i WHERE platform = %s', $chatbot, 'youtube' ), ARRAY_A ) ) );

		// Re-running migration 22 changes nothing.
		( new WidenChatbotChannels() )->up( $this->wpdb );
		self::assertSame( 3, $this->count_rows( Tables::ALERT_CHATBOT ) );
		self::assertInstanceOf( WidenChatbotChannels::class, Schema::migrations()[22] );
	}

	public function test_upgrade_from_22_adds_the_order_log_indexes_and_keeps_the_alerts(): void {
		// A database of schema version 22 with an alert and a chat message (as devpage after step 25.9.2).
		$migrations = Schema::migrations();
		unset( $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$events   = $this->table( Tables::ALERT_EVENTS );
		$messages = $this->table( Tables::ALERT_CHAT_MESSAGES );
		$index    = fn( string $table, string $name ): array => $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX', $table, $name ) );

		$this->execute( 'INSERT INTO %i (creator_id, order_id, order_number, order_item_id, product_id, variation_id, product_name, variation, quantity, image_id, category_ids, customer_username, created_at) VALUES (7, 40, %s, 41, 5, 0, %s, %s, 1, 0, %s, %s, %s)', $events, '40', 'Hoodie', '', '[]', 'nova_fan', '2026-10-22 10:00:00' );
		$this->execute( 'INSERT INTO %i (creator_id, platform, order_id, order_item_id, status, reason, attempts, created_at, updated_at) VALUES (7, %s, 40, 0, %s, %s, 1, %s, %s)', $messages, 'twitch', 'sent', '', '2026-10-22 10:00:00', '2026-10-22 10:00:00' );
		$before = array( $this->rows( Tables::ALERT_EVENTS ), $this->rows( Tables::ALERT_CHAT_MESSAGES ) );

		self::assertSame( 22, $this->migrator()->installed_version() );
		self::assertSame( array( array(), array() ), array( $index( $events, 'created_at' ), $index( $messages, 'order_id' ) ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( array( array( 'created_at', 'id' ), array( 'order_id' ) ), array( $index( $events, 'created_at' ), $index( $messages, 'order_id' ) ) );
		self::assertSame( $before, array( array_map( static fn( array $row ): array => array_diff_key( $row, array_flip( array( 'line_total', 'creator_total', 'currency', 'imported' ) ) ), $this->rows( Tables::ALERT_EVENTS ) ), $this->rows( Tables::ALERT_CHAT_MESSAGES ) ), 'The alerts and messages are kept (migration 24 adds the amounts, 29 the imported flag).' );

		// Re-running migration 23 changes nothing.
		( new AddAlertOrderLogIndexes() )->up( $this->wpdb );
		self::assertSame( array( 'created_at', 'id' ), $index( $events, 'created_at' ) );
		self::assertInstanceOf( AddAlertOrderLogIndexes::class, Schema::migrations()[23] );
	}

	public function test_upgrade_from_23_adds_the_alert_amounts_and_keeps_the_alerts(): void {
		// A database of schema version 23 with an alert (as devpage after step 25.10.1).
		$migrations = Schema::migrations();
		unset( $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$events = $this->table( Tables::ALERT_EVENTS );
		$this->execute( 'INSERT INTO %i (creator_id, order_id, order_number, order_item_id, product_id, variation_id, product_name, variation, quantity, image_id, category_ids, customer_username, created_at) VALUES (7, 40, %s, 41, 5, 0, %s, %s, 2, 0, %s, %s, %s)', $events, '40', 'Hoodie', '', '[]', 'nova_fan', '2026-10-22 10:00:00' );
		$before = $this->rows( Tables::ALERT_EVENTS );

		self::assertSame( 23, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		$columns = $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $events ), ARRAY_A ) ?? array();
		$types   = array_column( $columns, 'Type', 'Field' );
		self::assertSame( array( 'decimal(19,4)', 'decimal(19,4)', 'char(3)' ), array( $types['line_total'] ?? null, $types['creator_total'] ?? null, $types['currency'] ?? null ) );
		$after = $this->rows( Tables::ALERT_EVENTS );
		self::assertSame( array( null, null, '' ), array( $after[0]['line_total'], $after[0]['creator_total'], $after[0]['currency'] ), 'Earlier alerts have no amounts.' );
		self::assertSame( $before, array_map( static fn( array $row ): array => array_diff_key( $row, array_flip( array( 'line_total', 'creator_total', 'currency', 'imported' ) ) ), $after ), 'The alerts are kept.' );

		// Re-running migration 24 changes nothing.
		( new AddAlertEventAmounts() )->up( $this->wpdb );
		self::assertCount( count( $columns ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $events ) ) );
		self::assertInstanceOf( AddAlertEventAmounts::class, Schema::migrations()[24] );
	}

	public function test_upgrade_from_24_adds_the_empty_news_tables(): void {
		$migrations = Schema::migrations();
		unset( $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		( new CreatorRepository( $this->wpdb ) )->create( 'Før nyheder', CreatorStatus::Active );
		$before = $this->creator_rows();

		self::assertSame( 24, $this->migrator()->installed_version() );
		self::assertFalse( $this->has_table( Tables::NEWS ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->creator_rows() );

		foreach ( array( Tables::NEWS, Tables::NEWS_READS ) as $table ) {
			self::assertTrue( $this->has_table( $table ) );
			self::assertSame( 0, $this->count_rows( $table ) );
			self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( $table ) ) ) );
		}

		self::assertSame( array( 'id', 'title', 'body', 'status', 'published_at', 'created_by', 'created_at', 'updated_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::NEWS ) ) ) );
		self::assertSame( array( 'news_id', 'creator_id', 'seen_at', 'closed_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::NEWS_READS ) ) ) );

		// Re-running migration 25 changes nothing.
		( new CreateNewsTables() )->up( $this->wpdb );
		self::assertInstanceOf( CreateNewsTables::class, Schema::migrations()[25] );
	}

	public function test_upgrade_from_25_adds_the_empty_alert_api_keys_table(): void {
		$migrations = Schema::migrations();
		unset( $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		( new CreatorRepository( $this->wpdb ) )->create( 'Før API-nøgler', CreatorStatus::Active );
		$before = $this->creator_rows();

		self::assertSame( 25, $this->migrator()->installed_version() );
		self::assertFalse( $this->has_table( Tables::ALERT_API_KEYS ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->creator_rows() );
		self::assertTrue( $this->has_table( Tables::ALERT_API_KEYS ) );
		self::assertSame( 0, $this->count_rows( Tables::ALERT_API_KEYS ) );
		self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( Tables::ALERT_API_KEYS ) ) ) );
		self::assertSame( array( 'id', 'creator_id', 'name', 'lookup', 'secret_hash', 'created_at', 'last_used_at', 'revoked_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::ALERT_API_KEYS ) ) ) );

		// Re-running migration 26 changes nothing.
		( new CreateAlertApiKeysTable() )->up( $this->wpdb );
		self::assertInstanceOf( CreateAlertApiKeysTable::class, Schema::migrations()[26] );
	}

	public function test_upgrade_from_26_adds_the_empty_webhook_tables(): void {
		$migrations = Schema::migrations();
		unset( $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		( new CreatorRepository( $this->wpdb ) )->create( 'Før webhooks', CreatorStatus::Active );
		$before = $this->creator_rows();

		self::assertSame( 26, $this->migrator()->installed_version() );
		self::assertFalse( $this->has_table( Tables::ALERT_WEBHOOKS ) );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->creator_rows() );

		foreach ( array( Tables::ALERT_WEBHOOKS, Tables::ALERT_WEBHOOK_DELIVERIES ) as $table ) {
			self::assertTrue( $this->has_table( $table ) );
			self::assertSame( 0, $this->count_rows( $table ) );
			self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( $table ) ) ) );
		}

		self::assertSame( array( 'id', 'creator_id', 'name', 'format', 'url_encrypted', 'url_host', 'url_hint', 'secret_encrypted', 'template', 'enabled', 'failures', 'paused_at', 'last_delivery_at', 'created_at', 'updated_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::ALERT_WEBHOOKS ) ) ) );
		self::assertSame( array( 'id', 'webhook_id', 'creator_id', 'event_id', 'kind', 'order_ref', 'payload', 'status', 'http_status', 'error', 'created_at', 'sent_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::ALERT_WEBHOOK_DELIVERIES ) ) ) );

		// Re-running migration 27 changes nothing.
		( new CreateAlertWebhookTables() )->up( $this->wpdb );
		self::assertInstanceOf( CreateAlertWebhookTables::class, Schema::migrations()[27] );
	}

	public function test_upgrade_from_27_keeps_the_alert_api_for_its_users(): void {
		$migrations = Schema::migrations();
		unset( $migrations[28], $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$creators = new CreatorRepository( $this->wpdb );
		$both     = $creators->create( 'Both', CreatorStatus::Active )->id;
		$keys     = $creators->create( 'Keys', CreatorStatus::Active )->id;
		$hooks    = $creators->create( 'Hooks', CreatorStatus::Active )->id;
		$revoked  = $creators->create( 'Revoked', CreatorStatus::Active )->id;
		$none     = $creators->create( 'None', CreatorStatus::Active )->id;
		$set      = $creators->create( 'Already set', CreatorStatus::Active )->id;
		$now      = gmdate( 'Y-m-d H:i:s' );

		foreach ( array( array( $both, 'aaaaaaaaaaa1', null ), array( $keys, 'aaaaaaaaaaa2', null ), array( $revoked, 'aaaaaaaaaaa3', $now ), array( $set, 'aaaaaaaaaaa4', null ) ) as [ $creator, $lookup, $revoked_at ] ) {
			$this->wpdb->insert( $this->table( Tables::ALERT_API_KEYS ), array( 'creator_id' => $creator, 'name' => 'Bot', 'lookup' => $lookup, 'secret_hash' => str_repeat( 'a', 64 ), 'created_at' => $now, 'revoked_at' => $revoked_at ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test fixture.
		}

		foreach ( array( $both, $hooks ) as $creator ) {
			$this->wpdb->insert( $this->table( Tables::ALERT_WEBHOOKS ), array( 'creator_id' => $creator, 'name' => 'Hook', 'format' => 'json', 'url_encrypted' => 'x', 'url_host' => 'bot.example.com', 'url_hint' => 'bot.example.com…hook', 'created_at' => $now, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test fixture.
		}

		$meta = new CreatorMetaStore( $this->wpdb );
		$meta->set( $set, CreatorMetaStore::ALERT_API_ACCESS, array( 'keys' => false, 'webhooks' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short value.
		self::assertSame( 27, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame(
			array(
				'keys'     => true,
				'webhooks' => true,
			),
			$meta->get( $both, CreatorMetaStore::ALERT_API_ACCESS )
		);
		self::assertSame(
			array(
				'keys'     => true,
				'webhooks' => false,
			),
			$meta->get( $keys, CreatorMetaStore::ALERT_API_ACCESS )
		);
		self::assertSame(
			array(
				'keys'     => false,
				'webhooks' => true,
			),
			$meta->get( $hooks, CreatorMetaStore::ALERT_API_ACCESS )
		);
		self::assertNull( $meta->get( $revoked, CreatorMetaStore::ALERT_API_ACCESS ), 'A revoked key gives no access.' );
		self::assertNull( $meta->get( $none, CreatorMetaStore::ALERT_API_ACCESS ) );
		self::assertSame(
			array(
				'keys'     => false,
				'webhooks' => true,
			),
			$meta->get( $set, CreatorMetaStore::ALERT_API_ACCESS ),
			'An existing choice is kept.'
		);

		// Re-running migration 28 changes nothing.
		$meta_rows = fn(): mixed => $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY meta_id', $this->table( Tables::CREATOR_META ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test.
		$rows      = $meta_rows();
		( new GrantExistingAlertApiAccess() )->up( $this->wpdb );
		self::assertSame( $rows, $meta_rows() );
		self::assertInstanceOf( GrantExistingAlertApiAccess::class, Schema::migrations()[28] );
	}

	public function test_upgrade_from_28_marks_new_imported_alerts_only(): void {
		$migrations = Schema::migrations();
		unset( $migrations[29], $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$table = $this->table( Tables::ALERT_EVENTS );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$this->wpdb->insert( $table, array( 'creator_id' => 1, 'order_id' => 10, 'order_number' => '10', 'order_item_id' => 11, 'product_id' => 1, 'variation_id' => 0, 'product_name' => 'Hoodie', 'variation' => '', 'quantity' => 1, 'currency' => 'DKK', 'image_id' => 0, 'category_ids' => '', 'customer_username' => '', 'created_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test fixture.
		self::assertSame( 28, $this->migrator()->installed_version() );

		$this->install();
		$this->forget_table_metadata();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( array( '0' ), array_map( 'strval', array_column( $this->rows( Tables::ALERT_EVENTS ), 'imported' ) ), 'Existing alerts stay live.' );

		// Re-running migration 29 changes nothing.
		( new AddAlertEventImportedFlag() )->up( $this->wpdb );
		self::assertCount( 1, $this->rows( Tables::ALERT_EVENTS ) );
		self::assertInstanceOf( AddAlertEventImportedFlag::class, Schema::migrations()[29] );
	}

	public function test_upgrade_to_30_deletes_the_removed_import_tools_options_only(): void {
		$migrations = Schema::migrations();
		unset( $migrations[30], $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		update_option( 'merchboard_alert_import', array( 'completed_at' => '2026-10-25 00:39:00' ), false );
		update_option( 'merchboard_vendor_import', array( 'vendors' => array( 7 => 3 ) ), false );
		update_option( 'merchboard_alert_import_other', 'kept', false );
		self::assertSame( 29, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( array( false, false, 'kept' ), array( get_option( 'merchboard_alert_import' ), get_option( 'merchboard_vendor_import' ), get_option( 'merchboard_alert_import_other' ) ), 'Only the options of the tools removed in step 27.1.' );
		delete_option( 'merchboard_alert_import_other' );

		// Re-running migration 30 changes nothing.
		( new RemoveImportOptions() )->up( $this->wpdb );
		self::assertInstanceOf( RemoveImportOptions::class, Schema::migrations()[30] );
	}

	public function test_upgrade_to_31_marks_no_creator_deleted(): void {
		$migrations = Schema::migrations();
		unset( $migrations[31] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$this->wpdb->insert( $this->table( Tables::CREATORS ), array( 'display_name' => 'Nova', 'status' => 'active', 'created_at' => '2026-10-25 10:00:00', 'updated_at' => '2026-10-25 10:00:00' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test fixture.
		self::assertSame( 30, $this->migrator()->installed_version() );

		$this->install();
		$this->forget_table_metadata();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( array( null ), array_column( $this->rows( Tables::CREATORS ), 'deleted_at' ), 'Existing creators stay current.' );

		// Re-running migration 31 changes nothing.
		( new AddCreatorDeletedAt() )->up( $this->wpdb );
		self::assertCount( 1, $this->rows( Tables::CREATORS ) );
		self::assertInstanceOf( AddCreatorDeletedAt::class, Schema::migrations()[31] );
	}

	public function test_current_schema_runs_no_queries(): void {
		$this->install();
		$migrator = $this->migrator();
		$migrator->installed_version(); // Warm the option cache like a normal request.
		$before = $this->wpdb->num_queries;

		self::assertTrue( $migrator->migrate() );
		self::assertSame( $before, $this->wpdb->num_queries );
	}

	public function test_rerunning_all_migrations_is_idempotent_and_keeps_data(): void {
		$this->install();
		( new CreatorRepository( $this->wpdb ) )->create( 'Stays', CreatorStatus::Inactive );
		$before = $this->creator_rows();

		// As if the version option was lost after a successful schema change.
		delete_option( Migrator::VERSION_OPTION );
		$this->install();
		$this->install();

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->creator_rows() );
	}

	public function test_failed_migration_is_not_recorded_and_releases_the_lock(): void {
		$migrator = new Migrator(
			$this->wpdb,
			array(
				1 => new CreateCreatorsTable(),
				2 => new class() implements Migration {
					/**
					 * Fails with a real database error.
					 *
					 * @param wpdb $wpdb Database.
					 */
					public function up( wpdb $wpdb ): void {
						$wpdb->suppress_errors( true );
						$wpdb->query( 'ALTER TABLE merchboard_table_that_does_not_exist ADD COLUMN x INT' );
						$wpdb->suppress_errors( false );
						throw new DatabaseException( 'Could not alter table.' );
					}
				},
			)
		);

		try {
			$migrator->migrate();
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 2, $e->context()['schema_version'] );
			self::assertSame( 1, $e->context()['installed_version'] );
			self::assertStringContainsString( "doesn't exist", (string) $e->context()['db_error'], 'Real server error for the log.' );
		}

		self::assertSame( 1, $migrator->installed_version(), 'Only the successful migration is recorded.' );
		self::assertNull( $this->raw_option( Migrator::LOCK_OPTION ), 'Lock released after the failure.' );
	}

	public function test_installer_pauses_and_logs_a_failed_migration(): void {
		$logger    = $this->service( Logger::class );
		$installer = new Installer(
			new Migrator(
				$this->wpdb,
				array(
					1 => new class() implements Migration {
						/**
						 * Fails.
						 *
						 * @param wpdb $wpdb Database.
						 */
						public function up( wpdb $wpdb ): void {
							throw new DatabaseException( 'Could not create table.' );
						}
					},
				)
			),
			$this->service( RoleManager::class ),
			$logger
		);
		file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.

		$installer->install();

		self::assertNotFalse( get_transient( Installer::FAILURE_TRANSIENT ) );
		self::assertSame( 0, ( new Migrator( $this->wpdb, Schema::migrations() ) )->installed_version() );
		self::assertStringContainsString( 'ERROR: Database migration failed.', (string) file_get_contents( MERCHBOARD_TEST_LOG ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.
	}

	public function test_lock_held_by_another_request_blocks_migration(): void {
		$foreign = time() . ':other-request';
		$this->insert_lock( $foreign );

		self::assertFalse( $this->migrator()->migrate() );

		self::assertSame( 0, $this->migrator()->installed_version() );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::CREATORS ) ) ) );
		self::assertSame( $foreign, $this->raw_option( Migrator::LOCK_OPTION ), 'Foreign lock untouched.' );
	}

	public function test_abandoned_lock_is_taken_over_and_released(): void {
		$this->insert_lock( ( time() - 3600 ) . ':crashed-request' );

		self::assertTrue( $this->migrator()->migrate() );

		self::assertSame( 31, $this->migrator()->installed_version() );
		self::assertNull( $this->raw_option( Migrator::LOCK_OPTION ) );
	}

	public function test_lock_insert_is_atomic_on_this_server(): void {
		$sql = 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)';

		self::assertSame( 1, $this->execute( $sql, $this->wpdb->options, Migrator::LOCK_OPTION, '1:a', 'off' ) );
		self::assertSame( 0, $this->execute( $sql, $this->wpdb->options, Migrator::LOCK_OPTION, '2:b', 'off' ) );
		self::assertSame( '1:a', $this->raw_option( Migrator::LOCK_OPTION ), 'The second insert neither wins nor overwrites.' );
	}

	public function test_add_option_is_not_a_lock_when_the_cache_is_stale(): void {
		// Why the migrator does not use add_option(): a request whose cache still
		// says "no such option" overwrites a lock that another request inserted
		// and is told it succeeded (INSERT … ON DUPLICATE KEY UPDATE).
		wp_cache_set( 'notoptions', array( Migrator::LOCK_OPTION => true ), 'options' );
		$this->insert_lock( time() . ':other-request' );

		self::assertTrue( add_option( Migrator::LOCK_OPTION, time() . ':me', '', false ) );
		self::assertSame( time() . ':me', $this->raw_option( Migrator::LOCK_OPTION ) );
	}

	public function test_concurrent_requests_run_each_migration_exactly_once(): void {
		$this->wpdb->insert(
			$this->wpdb->options,
			array(
				'option_name'  => 'merchboard_test_runs',
				'option_value' => '0',
				'autoload'     => 'off',
			)
		);
		$start     = microtime( true ) + 4;
		$processes = array();
		$outputs   = array();

		for ( $i = 0; $i < 6; $i++ ) {
			$process = proc_open(
				array( PHP_BINARY, __DIR__ . '/../Fixtures/migrate-contender.php', (string) $start ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			self::assertIsResource( $process, 'Contender process could not be started.' );
			$processes[] = $process;
			$outputs[]   = $pipes;
		}

		$results = array();

		foreach ( $processes as $i => $process ) {
			$results[] = trim( (string) stream_get_contents( $outputs[ $i ][1] ) ) . trim( (string) stream_get_contents( $outputs[ $i ][2] ) );
			proc_close( $process );
		}

		foreach ( $results as $result ) {
			self::assertContains( $result, array( 'migrated', 'locked' ), 'Contender output: ' . $result );
		}

		self::assertSame( '1', $this->raw_option( 'merchboard_test_runs' ), 'The migration ran exactly once: ' . implode( ', ', $results ) );
		self::assertContains( 'locked', $results, 'At least one request found the lock held.' );
		self::assertSame( '1', (string) $this->raw_option( Migrator::VERSION_OPTION ) );
		self::assertNull( $this->raw_option( Migrator::LOCK_OPTION ) );

		$this->wpdb->delete( $this->wpdb->options, array( 'option_name' => 'merchboard_test_runs' ) );
	}

	private function migrator(): Migrator {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( Migrator::VERSION_OPTION, 'options' );

		return new Migrator( $this->wpdb, Schema::migrations() );
	}

	/**
	 * Rows without the columns added by migrations 6, 7 and 9, to compare with
	 * rows read before them; fails when a row's markup, VAT status or settlement
	 * start is not the default.
	 *
	 * @param string $table Tables constant.
	 * @return list<array<string, string|null>>
	 */
	private function rows_without_markup( string $table ): array {
		return array_map(
			static function ( array $row ): array {
				self::assertSame( '0', $row['default_markup_bps'] ?? null, 'Existing creators get 0 %.' );
				self::assertSame( '0', $row['vat_registered'] ?? null, 'Existing creators are not VAT registered (ADR-048).' );
				self::assertArrayHasKey( 'payout_start_at', $row );
				self::assertNull( $row['payout_start_at'], 'Existing creators count their whole history (D6).' );
				self::assertNull( $row['deleted_at'] ?? null, 'Existing creators are not deleted (ADR-076).' );
				unset( $row['default_markup_bps'], $row['vat_registered'], $row['payout_start_at'], $row['deleted_at'] );

				return $row;
			},
			$this->rows( $table )
		);
	}

	/**
	 * Option value straight from the database (no cache).
	 *
	 * @param string $name Option name.
	 */
	private function raw_option( string $name ): ?string {
		$value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->wpdb->options, $name ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Inserts a lock row as another request would.
	 *
	 * @param string $value Lock value.
	 */
	private function insert_lock( string $value ): void {
		$this->wpdb->insert(
			$this->wpdb->options,
			array(
				'option_name'  => Migrator::LOCK_OPTION,
				'option_value' => $value,
				'autoload'     => 'off',
			)
		);
	}

	/**
	 * Whether a plugin table exists.
	 *
	 * @param string $table Tables constant.
	 */
	private function has_table( string $table ): bool {
		return null !== $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( $table ) ) );
	}
}
