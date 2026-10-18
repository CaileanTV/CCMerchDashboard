<?php
/**
 * Migration 17 (ADR-068) on a real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Core\Database\Migrations\CreateCreatorCodeTables;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;

/**
 * A site on schema version 16 (1.0.0, e.g. production) gets exactly the two
 * creator code tables, empty, on InnoDB, with the UNIQUE keys that hold one
 * active code per creator and one active creator per code; existing data is
 * unchanged and re-running the migration changes nothing. The expected
 * latest version is read from Schema, so later migrations do not change it.
 */
final class CreatorCodeTablesMigrationTest extends IntegrationTestCase {

	public function test_upgrade_from_16_adds_only_the_empty_creator_code_tables(): void {
		$migrations = Schema::migrations();

		foreach ( array_keys( $migrations ) as $version ) {
			if ( $version >= 17 ) {
				unset( $migrations[ $version ] );
			}
		}

		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før rabatkoder', CreatorStatus::Active );
		$before  = $this->rows( Tables::CREATORS );

		self::assertSame( '16', (string) get_option( Migrator::VERSION_OPTION ) );
		self::assertFalse( $this->has_table( Tables::CREATOR_CODES ) );
		self::assertFalse( $this->has_table( Tables::ORDER_EXCLUSIONS ) );

		$this->install();

		self::assertSame( (string) array_key_last( Schema::migrations() ), (string) get_option( Migrator::VERSION_OPTION ) );
		self::assertInstanceOf( CreateCreatorCodeTables::class, Schema::migrations()[17] );

		foreach ( array( Tables::CREATOR_CODES, Tables::ORDER_EXCLUSIONS ) as $table ) {
			self::assertTrue( $this->has_table( $table ) );
			self::assertSame( 0, $this->count_rows( $table ) );
			self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( $table ) ) ) );
		}

		self::assertSame( array( 'id', 'creator_id', 'code', 'coupon_id', 'valid_from', 'valid_to', 'active_creator_id', 'active_code', 'created_by', 'ended_by' ), $this->columns( Tables::CREATOR_CODES ) );
		self::assertSame( array( 'order_id', 'status', 'reason', 'code_id', 'creator_id', 'code', 'coupon_deviation', 'order_created_at', 'assessed_at' ), $this->columns( Tables::ORDER_EXCLUSIONS ) );
		self::assertSame( array( 'PRIMARY', 'active_code', 'active_creator' ), $this->unique_keys( Tables::CREATOR_CODES ) );
		self::assertSame( $before, $this->rows( Tables::CREATORS ), 'Existing data is unchanged.' );
		self::assertNotNull( ( new CreatorRepository( $this->wpdb ) )->find( $creator->id ) );

		// Re-running migration 17 changes nothing.
		( new CreateCreatorCodeTables() )->up( $this->wpdb );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_CODES ) );
		self::assertSame( 0, $this->count_rows( Tables::ORDER_EXCLUSIONS ) );
	}

	/**
	 * Whether a plugin table exists.
	 *
	 * @param string $table Tables constant.
	 */
	private function has_table( string $table ): bool {
		$name = $this->table( $table );

		return $name === $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $name ) ) );
	}

	/**
	 * The table's columns in order.
	 *
	 * @param string $table Tables constant.
	 * @return list<string>
	 */
	private function columns( string $table ): array {
		return array_map( 'strval', $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( $table ) ) ) );
	}

	/**
	 * The names of the table's unique keys, sorted.
	 *
	 * @param string $table Tables constant.
	 * @return list<string>
	 */
	private function unique_keys( string $table ): array {
		$keys = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0', $this->table( $table ) ) );
		$keys = array_map( 'strval', $keys );
		sort( $keys );

		return $keys;
	}
}
