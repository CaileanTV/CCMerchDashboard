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
use MerchBoard\Core\Database\Migrations\AddCreatorShareModel;
use MerchBoard\Core\Database\Migrations\AddPayoutGiftCards;
use MerchBoard\Core\Database\Migrations\CreateCreatorPricingTables;
use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorVatHistoryTable;
use MerchBoard\Core\Database\Migrations\CreatePayoutRecalculationExportsTable;
use MerchBoard\Core\Database\Migrations\CreatePayoutRecalculationTables;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\Installer;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\RoleManager;
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

		self::assertSame( 17, $this->migrator()->installed_version() );
		self::assertSame( '17', (string) $this->raw_option( Migrator::VERSION_OPTION ) );
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
		$before  = $this->rows( Tables::CREATORS );

		self::assertSame( 1, $this->migrator()->installed_version() );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::AUDIT_LOG ) ) ) );

		$this->install();

		self::assertSame( 17, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->rows_without_markup( Tables::CREATORS ) );
		self::assertSame( array( '0' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT default_markup_bps FROM %i', $this->table( Tables::CREATORS ) ) ) );
		self::assertSame( 'Før opgradering 🎸', ( new CreatorRepository( $this->wpdb ) )->find( $creator->id )?->display_name );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_upgrade_from_4_adds_only_the_sales_ledger_tables(): void {
		$migrations = Schema::migrations();
		unset( $migrations[5], $migrations[6], $migrations[7], $migrations[8], $migrations[9], $migrations[10], $migrations[11], $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		( new CreatorRepository( $this->wpdb ) )->create( 'Før salg', CreatorStatus::Active );
		$before = $this->rows( Tables::CREATORS );

		self::assertSame( 4, $this->migrator()->installed_version() );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::SALES ) ) ) );

		$this->install();

		self::assertSame( 17, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->rows_without_markup( Tables::CREATORS ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );
	}

	public function test_upgrade_from_5_adds_pricing_with_0_markup_for_existing_creators_and_keeps_the_ledger(): void {
		$migrations = Schema::migrations();
		unset( $migrations[6], $migrations[7], $migrations[8], $migrations[9], $migrations[10], $migrations[11], $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$repository = new CreatorRepository( $this->wpdb );
		$active     = $repository->create( 'Aktiv før prissætning', CreatorStatus::Active );
		$inactive   = $repository->create( 'Inaktiv før prissætning', CreatorStatus::Inactive );
		$before     = $this->rows( Tables::CREATORS );
		$sales      = $this->rows( Tables::SALES );
		$sales_cols = $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::SALES ) ) );

		self::assertSame( 5, $this->migrator()->installed_version() );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $this->table( Tables::CREATORS ), 'default_markup_bps' ) ) );

		$this->install();

		self::assertSame( 17, $this->migrator()->installed_version() );
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
		unset( $migrations[7], $migrations[8], $migrations[9], $migrations[10], $migrations[11], $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17] );
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

		self::assertSame( 17, $this->migrator()->installed_version() );
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
		unset( $migrations[8], $migrations[9], $migrations[10], $migrations[11], $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17] );
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
		$creators = $this->rows( Tables::CREATORS );
		$snapshot = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i', $this->table( Tables::SALES_LINE_PRICING ) ), ARRAY_A );
		$sales    = $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::SALES ) ) );

		self::assertSame( 7, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 17, $this->migrator()->installed_version() );
		self::assertSame( $creators, array_map( static fn( array $row ): array => array_diff_key( $row, array( 'payout_start_at' => null ) ), $this->rows( Tables::CREATORS ) ), 'Markup and VAT status unchanged (the settlement start is added by version 9).' );

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
		unset( $migrations[10], $migrations[11], $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før gavekort', CreatorStatus::Active );
		$this->wpdb->insert( $this->table( Tables::PAYOUTS ), array( 'creator_id' => $creator->id, 'currency' => 'DKK', 'amount' => '25.0000', 'price_decimals' => 2, 'method' => 'bank', 'status' => 'pending', 'destination_hint' => '•••• 1234', 'idempotency_key' => 'key-before-10', 'requested_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$before = $this->rows( Tables::PAYOUTS );

		self::assertSame( 9, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 17, $this->migrator()->installed_version() );
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
		unset( $migrations[15], $migrations[16], $migrations[17] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før eksport', CreatorStatus::Active );
		$this->wpdb->insert( $this->table( Tables::RECALCULATIONS ), array( 'rule' => 'adr063-remove-20', 'calculation_version' => 'adr063-adr054-v1', 'status' => 'closed', 'fingerprint' => str_repeat( 'a', 64 ), 'created_at' => '2026-10-17 12:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$before = array(
			'creators'       => $this->rows( Tables::CREATORS ),
			'recalculations' => $this->rows( Tables::RECALCULATIONS ),
		);

		self::assertSame( 14, $this->migrator()->installed_version() );
		self::assertFalse( $this->has_table( Tables::RECALCULATION_EXPORTS ), 'Version 14 has no exports table.' );
		self::assertTrue( $this->has_table( Tables::RECALCULATIONS ) );

		$this->install();

		self::assertSame( 17, $this->migrator()->installed_version() );
		self::assertTrue( $this->has_table( Tables::RECALCULATION_EXPORTS ) );
		self::assertSame( 0, $this->count_rows( Tables::RECALCULATION_EXPORTS ) );
		self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( Tables::RECALCULATION_EXPORTS ) ) ) );
		self::assertSame( array( 'id', 'recalculation_id', 'filters', 'files', 'manifest_sha256', 'sums_sha256', 'created_by', 'created_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::RECALCULATION_EXPORTS ) ) ) );
		self::assertSame( $before['creators'], $this->rows( Tables::CREATORS ), 'Existing data is unchanged.' );
		self::assertSame( $before['recalculations'], $this->rows( Tables::RECALCULATIONS ) );
		self::assertNotNull( ( new CreatorRepository( $this->wpdb ) )->find( $creator->id ) );

		// Re-running migration 15 changes nothing.
		( new CreatePayoutRecalculationExportsTable() )->up( $this->wpdb );
		self::assertSame( 0, $this->count_rows( Tables::RECALCULATION_EXPORTS ) );
		self::assertSame( 17, $this->migrator()->installed_version() );
	}

	public function test_migration_14_no_longer_creates_the_exports_table(): void {
		$migrations = Schema::migrations();
		unset( $migrations[15], $migrations[16], $migrations[17] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();

		( new CreatePayoutRecalculationTables() )->up( $this->wpdb );

		self::assertFalse( $this->has_table( Tables::RECALCULATION_EXPORTS ), 'Only migration 15 creates it.' );
		self::assertSame( array( 14, 15, 16, 17 ), array_slice( array_keys( Schema::migrations() ), -4 ) );
		self::assertInstanceOf( CreatePayoutRecalculationExportsTable::class, Schema::migrations()[15] );
	}

	public function test_upgrade_from_15_adds_only_the_empty_vat_history_and_keeps_the_current_status(): void {
		// A database of schema version 15 (as Local after R2).
		$migrations = Schema::migrations();
		unset( $migrations[16], $migrations[17] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før momshistorik', CreatorStatus::Active );
		( new CreatorVatRepository( $this->wpdb ) )->save( $creator->id, true );
		$before = $this->rows( Tables::CREATORS );

		self::assertSame( 15, $this->migrator()->installed_version() );
		self::assertFalse( $this->has_table( Tables::CREATOR_VAT_HISTORY ) );

		$this->install();

		self::assertSame( 17, $this->migrator()->installed_version() );
		self::assertTrue( $this->has_table( Tables::CREATOR_VAT_HISTORY ) );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_VAT_HISTORY ), 'No history is invented for existing creators.' );
		self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( Tables::CREATOR_VAT_HISTORY ) ) ) );
		self::assertSame( array( 'id', 'creator_id', 'vat_registered', 'effective_from', 'checked_on', 'documentation_reference', 'reason', 'recorded_by', 'recorded_at' ), $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::CREATOR_VAT_HISTORY ) ) ) );
		self::assertSame( $before, $this->rows( Tables::CREATORS ), 'The current VAT status and every creator are unchanged.' );
		self::assertTrue( ( new CreatorVatRepository( $this->wpdb ) )->find( $creator->id ) );

		// Re-running migration 16 changes nothing.
		( new CreateCreatorVatHistoryTable() )->up( $this->wpdb );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_VAT_HISTORY ) );
		self::assertInstanceOf( CreateCreatorVatHistoryTable::class, Schema::migrations()[16] );
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
		$before = $this->rows( Tables::CREATORS );

		// As if the version option was lost after a successful schema change.
		delete_option( Migrator::VERSION_OPTION );
		$this->install();
		$this->install();

		self::assertSame( 17, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->rows( Tables::CREATORS ) );
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

		self::assertSame( 17, $this->migrator()->installed_version() );
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
				unset( $row['default_markup_bps'], $row['vat_registered'], $row['payout_start_at'] );

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
