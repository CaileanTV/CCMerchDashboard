<?php
/**
 * Base class for integration tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration;

use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\Installer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use wpdb;

/**
 * Every test starts without the plugin's tables and schema options, so each
 * one proves its own path from an empty schema. Users created by a test are
 * deleted afterwards; the site itself (installed once per run) is kept.
 */
abstract class IntegrationTestCase extends TestCase {

	/**
	 * The plugin's container, captured on `merchboard_loaded` (bootstrap.php).
	 *
	 * @var Container|null
	 */
	public static ?Container $container = null;

	protected wpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->wpdb = $wpdb;

		$this->drop_plugin_schema();
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		// Tests that provoke database errors suppress wpdb's HTML error output.
		$this->wpdb->suppress_errors( false );
		wp_set_current_user( 0 );

		foreach ( get_users( array( 'exclude' => array( 1 ), 'fields' => 'ID' ) ) as $user_id ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			wp_delete_user( (int) $user_id );
		}

		parent::tearDown();
	}

	/**
	 * A service from the plugin's container.
	 *
	 * @template T of object
	 * @param string $id Service id (class name).
	 * @phpstan-param class-string<T> $id
	 * @return T
	 */
	protected function service( string $id ): object {
		if ( null === self::$container ) {
			throw new RuntimeException( 'The plugin did not boot.' );
		}

		return self::$container->get( $id );
	}

	/**
	 * Activation path: migrations and role sync.
	 */
	protected function install(): void {
		$this->service( Installer::class )->install();
	}

	/**
	 * Removes the plugin's tables, schema version, lock and failure pause.
	 */
	protected function drop_plugin_schema(): void {
		foreach ( Tables::all() as $table ) {
			$this->execute( 'DROP TABLE IF EXISTS %i', $this->wpdb->prefix . $table );
		}

		delete_option( Migrator::VERSION_OPTION );
		delete_option( Migrator::LOCK_OPTION );
		delete_transient( Installer::FAILURE_TRANSIENT );
		$this->forget_table_metadata();

		// Recreated tables reuse ids; cached creator meta of a dropped table must not survive.
		wp_cache_flush();
	}

	/**
	 * Clears wpdb's per-request cache of table/column charsets, as a new request
	 * would start without it. Needed after a test recreates or alters a table.
	 */
	protected function forget_table_metadata(): void {
		( function (): void {
			$this->col_meta      = array();
			$this->table_charset = array();
		} )->call( $this->wpdb );
	}

	/**
	 * Prepares and runs a statement; fails the test when it cannot be prepared.
	 *
	 * @param string $query   Query with placeholders.
	 * @param mixed  ...$args Values.
	 * @phpstan-param literal-string $query
	 * @return int|bool Result of wpdb::query().
	 */
	protected function execute( string $query, mixed ...$args ): int|bool {
		$sql = $this->wpdb->prepare( $query, ...$args );
		self::assertIsString( $sql, 'Query could not be prepared.' );

		return $this->wpdb->query( $sql );
	}

	/**
	 * Full name of a plugin table.
	 *
	 * @param string $table Tables constant.
	 */
	protected function table( string $table ): string {
		return Tables::name( $this->wpdb, $table );
	}

	/**
	 * Number of rows in a plugin table.
	 *
	 * @param string $table Tables constant.
	 */
	protected function count_rows( string $table ): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( $table ) ) );
	}

	/**
	 * Rows of a plugin table as MySQL returns them (strings/null), ordered by id.
	 *
	 * @param string $table Tables constant.
	 * @return list<array<string, string|null>>
	 */
	protected function rows( string $table ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $this->table( $table ) ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * The creators' rows without `deleted_at` (schema version 31, ADR-076),
	 * so upgrade tests compare the data an older schema already had.
	 *
	 * @return list<array<string, string|null>>
	 */
	protected function creator_rows(): array {
		return array_map( static fn( array $row ): array => array_diff_key( $row, array( 'deleted_at' => null ) ), $this->rows( Tables::CREATORS ) );
	}

	/**
	 * Creates a subscriber.
	 *
	 * @param string $login Login.
	 */
	protected function create_user( string $login ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $login . '@example.org',
				'role'       => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'Could not create test user.' );
		}

		return $user_id;
	}

	/**
	 * Whether the connected server is MariaDB (otherwise MySQL).
	 */
	protected function is_mariadb(): bool {
		return str_contains( strtolower( (string) $this->wpdb->get_var( 'SELECT VERSION()' ) ), 'mariadb' );
	}
}
