<?php
/**
 * Tests for the installer.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Lifecycle;

use ArrayObject;
use Brain\Monkey\Functions;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Lifecycle\Installer;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\InMemoryRoles;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\RecordingMigration;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Installer::class )]
final class InstallerTest extends TestCase {

	/**
	 * Run log shared by the migration doubles.
	 *
	 * @var ArrayObject<int, string>
	 */
	private ArrayObject $log;

	private RecordingLogger $logger;

	private InMemoryRoles $roles;

	private RecordingWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb   = new RecordingWpdb();
		$this->log    = new ArrayObject();
		$this->logger = new RecordingLogger();
		$this->roles  = InMemoryRoles::install( array( 'administrator' => array( 'manage_options' => true ) ) );
	}

	public function test_install_synchronises_roles(): void {
		$options = InMemoryOptions::install();

		$this->installer()->install();

		self::assertArrayHasKey( 'merchboard_creator', $this->roles->roles );
		self::assertTrue( $this->roles->capabilities( 'administrator' )['merchboard_manage'] );
		self::assertSame( RoleManager::VERSION, $options->options[ RoleManager::VERSION_OPTION ] );
	}

	public function test_roles_are_synchronised_even_when_a_migration_fails(): void {
		InMemoryOptions::install();

		$this->installer( true )->install();

		self::assertArrayHasKey( 'merchboard_creator', $this->roles->roles );
	}

	public function test_maybe_upgrade_synchronises_outdated_roles(): void {
		$options = InMemoryOptions::install( array( Migrator::VERSION_OPTION => 1 ) );

		$this->installer()->maybe_upgrade();

		self::assertArrayHasKey( 'merchboard_creator', $this->roles->roles );
		self::assertSame( array( RoleManager::VERSION_OPTION ), $options->updates );
	}

	public function test_maybe_upgrade_leaves_current_roles_alone(): void {
		InMemoryOptions::install(
			array(
				Migrator::VERSION_OPTION    => 1,
				RoleManager::VERSION_OPTION => RoleManager::VERSION,
			)
		);

		$this->installer()->maybe_upgrade();

		self::assertSame( 0, $this->roles->add_role_calls );
		self::assertArrayNotHasKey( 'merchboard_creator', $this->roles->roles );
	}

	public function test_migration_failure_is_logged_once_as_error_with_diagnostics(): void {
		InMemoryOptions::install();

		$this->installer( true )->install();

		self::assertCount( 1, $this->logger->entries );
		self::assertSame( 'error', $this->logger->entries[0]['level'] );
		self::assertSame( 'Database migration failed.', $this->logger->entries[0]['message'] );
		self::assertSame(
			array(
				'schema_version'    => 1,
				'installed_version' => 0,
				'migration'         => RecordingMigration::class,
				'reason'            => 'Migration failed.',
				'db_error'          => "Can't create table",
				'retry_in_seconds'  => 300,
			),
			$this->logger->entries[0]['context']
		);
	}

	public function test_failure_keeps_the_five_minute_retry_pause(): void {
		$options = InMemoryOptions::install();

		$this->installer( true )->install();

		self::assertArrayHasKey( Installer::FAILURE_TRANSIENT, $options->transients );
		self::assertSame( 300, $options->transient_expiration[ Installer::FAILURE_TRANSIENT ] );
	}

	public function test_successful_migration_is_logged_as_info(): void {
		InMemoryOptions::install();

		$this->installer()->install();

		self::assertSame(
			array(
				array(
					'level'   => 'info',
					'message' => 'Database schema migrated.',
					'context' => array(
						'from_version' => 0,
						'to_version'   => 1,
					),
				),
			),
			$this->logger->entries
		);
	}

	public function test_current_schema_logs_nothing(): void {
		InMemoryOptions::install(
			array(
				Migrator::VERSION_OPTION    => 1,
				RoleManager::VERSION_OPTION => RoleManager::VERSION,
			)
		);

		$this->installer()->maybe_upgrade();
		$this->installer()->install();

		self::assertSame( array(), $this->logger->entries );
	}

	public function test_held_lock_is_logged_as_debug_only(): void {
		InMemoryOptions::install();
		$this->wpdb->option_rows[ Migrator::LOCK_OPTION ] = time() . ':other-request';

		$this->installer()->install();

		self::assertSame( array( 'debug' ), array_column( $this->logger->entries, 'level' ) );
	}

	public function test_failure_notice_never_contains_database_details(): void {
		InMemoryOptions::install();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		$installer = $this->installer( true );
		$installer->install();

		ob_start();
		$installer->render_failure_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'could not update its database tables', $html );
		foreach ( array( "Can't create table", 'RecordingMigration', 'schema_version', 'SELECT', 'CREATE', '.php' ) as $detail ) {
			self::assertStringNotContainsString( $detail, $html );
		}
	}

	public function test_install_creates_the_schema_on_activation(): void {
		$options = InMemoryOptions::install();

		$this->installer()->install();

		self::assertSame( array( 'm1' ), $this->log->getArrayCopy() );
		self::assertSame( 1, $options->options[ Migrator::VERSION_OPTION ] );
	}

	public function test_install_is_idempotent(): void {
		InMemoryOptions::install();
		$installer = $this->installer();

		$installer->install();
		$installer->install();

		self::assertSame( array( 'm1' ), $this->log->getArrayCopy() );
	}

	public function test_maybe_upgrade_is_a_no_op_when_schema_is_current(): void {
		$options = InMemoryOptions::install(
			array(
				Migrator::VERSION_OPTION    => 1,
				RoleManager::VERSION_OPTION => RoleManager::VERSION,
			)
		);

		$this->installer()->maybe_upgrade();

		self::assertSame( array(), $this->log->getArrayCopy() );
		self::assertSame( array(), $options->updates );
	}

	public function test_maybe_upgrade_migrates_an_outdated_schema(): void {
		$options = InMemoryOptions::install(
			array(
				Migrator::VERSION_OPTION    => 0,
				RoleManager::VERSION_OPTION => RoleManager::VERSION,
			)
		);

		$this->installer()->maybe_upgrade();

		self::assertSame( 1, $options->options[ Migrator::VERSION_OPTION ] );
	}

	public function test_failure_is_recorded_and_version_is_not_saved(): void {
		$options = InMemoryOptions::install();

		$this->installer( true )->install();

		self::assertArrayHasKey( Installer::FAILURE_TRANSIENT, $options->transients );
		self::assertArrayNotHasKey( Migrator::VERSION_OPTION, $options->options );
	}

	public function test_automatic_retry_is_paused_after_a_failure(): void {
		InMemoryOptions::install( array() )->transients[ Installer::FAILURE_TRANSIENT ] = time();

		$this->installer()->maybe_upgrade();

		self::assertSame( array(), $this->log->getArrayCopy() );
	}

	public function test_activation_retries_despite_a_recorded_failure_and_clears_it(): void {
		$options = InMemoryOptions::install();
		$options->transients[ Installer::FAILURE_TRANSIENT ] = time();

		$this->installer()->install();

		self::assertSame( array( 'm1' ), $this->log->getArrayCopy() );
		self::assertArrayNotHasKey( Installer::FAILURE_TRANSIENT, $options->transients );
	}

	public function test_failure_notice_is_shown_to_plugin_managers_without_details(): void {
		InMemoryOptions::install()->transients[ Installer::FAILURE_TRANSIENT ] = time();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		ob_start();
		$this->installer()->render_failure_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'could not update its database tables', $html );
	}

	public function test_failure_notice_is_hidden_from_other_users(): void {
		InMemoryOptions::install()->transients[ Installer::FAILURE_TRANSIENT ] = time();
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectOutputString( '' );

		$this->installer()->render_failure_notice();
	}

	public function test_no_notice_without_a_failure(): void {
		InMemoryOptions::install();
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->expectOutputString( '' );

		$this->installer()->render_failure_notice();
	}

	/**
	 * Installer with a single schema version 1.
	 *
	 * @param bool $failing Whether the migration fails.
	 */
	private function installer( bool $failing = false ): Installer {
		$wpdb             = $this->wpdb;
		$wpdb->last_error = $failing ? "Can't create table" : '';

		return new Installer(
			new Migrator( $wpdb, array( 1 => new RecordingMigration( 'm1', $this->log, $failing ) ) ),
			new RoleManager( $this->logger ),
			$this->logger
		);
	}
}
