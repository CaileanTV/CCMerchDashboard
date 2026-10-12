<?php
/**
 * Tests for ending a blocked plugin deletion.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Lifecycle;

use Brain\Monkey\Functions;
use MerchBoard\Core\Lifecycle\UninstallBlocked;
use MerchBoard\Core\Lifecycle\UninstallHalt;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;

/**
 * Each mechanism is replaced by a double that throws, as the real one ends
 * the request. Whether WordPress' interfaces show the message is a runtime
 * question and not covered here.
 */
#[CoversClass( UninstallHalt::class )]
#[CoversClass( UninstallBlocked::class )]
final class UninstallHaltTest extends TestCase {

	private const PLUGIN = 'merchboard/merchboard.php';

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	public function test_the_plugins_screen_gets_the_fields_of_wordpress_own_delete_error(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\expect( 'wp_die' )->never();
		$sent = null;
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( mixed $data ) use ( &$sent ): void {
				$sent = $data;
				throw new RuntimeException( 'ended' );
			}
		);

		$this->ends( new UninstallBlocked( 2 ) );

		self::assertSame(
			array(
				'delete'       => 'plugin',
				'slug'         => 'merchboard',
				'plugin'       => self::PLUGIN,
				'pluginName'   => 'Merch Dashboard',
				'errorCode'    => 'merchboard_pending_payouts',
				'errorMessage' => UninstallHalt::message( new UninstallBlocked( 2 ) ),
			),
			$sent,
			'wp.updates.deletePluginError() finds the row by `plugin` and shows `errorMessage`.'
		);
	}

	public function test_any_other_request_gets_an_error_page_with_a_back_link(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\expect( 'wp_send_json_error' )->never();
		$args = null;
		Functions\when( 'wp_die' )->alias(
			static function ( mixed ...$received ) use ( &$args ): void {
				$args = $received;
				throw new RuntimeException( 'ended' );
			}
		);

		$this->ends( new UninstallBlocked( null ) );

		self::assertSame( UninstallHalt::message( new UninstallBlocked( null ) ), $args[0] ?? null );
		self::assertSame( 'Merch Dashboard was not deleted', $args[1] ?? null );
		self::assertSame(
			array(
				'response'  => 409,
				'back_link' => true,
			),
			$args[2] ?? null
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_wp_cli_gets_its_own_error(): void {
		define( 'WP_CLI', true );
		self::assertTrue( class_exists( 'WP_CLI' ), 'WP-CLI (dev dependency) is installed.' );
		// WP-CLI's own switch that turns its exit() into an ExitException (runcommand() uses it).
		$capture = new \ReflectionProperty( 'WP_CLI', 'capture_exit' );
		$capture->setValue( null, true );
		Functions\expect( 'wp_send_json_error' )->never();
		Functions\expect( 'wp_die' )->never();

		try {
			UninstallHalt::respond( new UninstallBlocked( 1 ), self::PLUGIN );
			self::fail( 'The request must end.' );
		} catch ( \WP_CLI\ExitException $e ) {
			self::assertSame( 1, $e->getCode(), 'WP_CLI::error() with exit code 1.' );
		}
	}

	public function test_the_message_says_nothing_was_deleted_and_what_to_do(): void {
		$one     = UninstallHalt::message( new UninstallBlocked( 1 ) );
		$several = UninstallHalt::message( new UninstallBlocked( 3 ) );
		$unknown = UninstallHalt::message( new UninstallBlocked( null ) );

		self::assertStringContainsString( '1 payout is still pending', $one );
		self::assertStringContainsString( '3 payouts are still pending', $several );
		self::assertStringContainsString( 'could not check whether payouts are pending', $unknown );

		foreach ( array( $one, $several, $unknown ) as $message ) {
			self::assertStringContainsString( 'Nothing was deleted', $message );
			self::assertStringContainsString( 'permanent deletion is still switched on', $message );
			self::assertStringContainsString( 'Activate Merch Dashboard again', $message );
		}
	}

	/**
	 * Requires respond() to end the request (the double throws).
	 *
	 * @param UninstallBlocked $blocked Why.
	 */
	private function ends( UninstallBlocked $blocked ): void {
		try {
			UninstallHalt::respond( $blocked, self::PLUGIN );
			self::fail( 'The request must end.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'ended', $e->getMessage() );
		}
	}
}
