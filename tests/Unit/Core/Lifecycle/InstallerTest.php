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
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
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

	protected function setUp(): void {
		parent::setUp();
		$this->log = new ArrayObject();
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
		$options = InMemoryOptions::install( array( Migrator::VERSION_OPTION => 1 ) );

		$this->installer()->maybe_upgrade();

		self::assertSame( array(), $this->log->getArrayCopy() );
		self::assertSame( array(), $options->updates );
	}

	public function test_maybe_upgrade_migrates_an_outdated_schema(): void {
		$options = InMemoryOptions::install( array( Migrator::VERSION_OPTION => 0 ) );

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
		return new Installer(
			new Migrator( new RecordingWpdb(), array( 1 => new RecordingMigration( 'm1', $this->log, $failing ) ) )
		);
	}
}
