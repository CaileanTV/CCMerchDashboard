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
		$this->tables( array( 'wp_merchboard_creators' => 'YES', 'wp_merchboard_audit_log' => 'YES', 'wp_merchboard_creatormeta' => 'YES' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

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
				'wp_merchboard_creators'    => 'MyISAM',
				'wp_merchboard_audit_log'   => 'missing',
				'wp_merchboard_creatormeta' => 'missing',
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
		);

		self::assertSame( array( 'wp_merchboard_creators' => 'unknown' ), ( new TransactionSupport( $this->wpdb ) )->unsupported_tables() );
	}

	public function test_failed_query_fails_closed(): void {
		$this->wpdb->results = array();

		self::assertFalse( ( new TransactionSupport( $this->wpdb ) )->available() );
	}

	public function test_lower_cased_table_names_are_matched(): void {
		$this->wpdb->prefix = 'WP_Shop_';
		$this->tables( array( 'wp_shop_merchboard_creators' => 'YES', 'wp_shop_merchboard_audit_log' => 'YES', 'wp_shop_merchboard_creatormeta' => 'YES' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertTrue( ( new TransactionSupport( $this->wpdb ) )->available() );
	}

	public function test_positive_result_is_cached_but_a_negative_one_is_checked_again(): void {
		$support = new TransactionSupport( $this->wpdb );

		$this->tables( array( 'wp_merchboard_creators' => 'YES' ) );
		self::assertFalse( $support->available() );

		$this->tables( array( 'wp_merchboard_creators' => 'YES', 'wp_merchboard_audit_log' => 'YES', 'wp_merchboard_creatormeta' => 'YES' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
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
