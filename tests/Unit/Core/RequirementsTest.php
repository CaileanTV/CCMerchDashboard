<?php
/**
 * Tests for the runtime requirement checks.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use MerchBoard\Core\Requirements;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Requirements::class )]
final class RequirementsTest extends TestCase {

	public function test_plugin_header_matches_the_minimum_version_constants(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/merchboard.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local plugin file in a test.
		$header = static function ( string $name ) use ( $source ): string {
			self::assertSame( 1, preg_match( '/^\s*\*\s*' . preg_quote( $name, '/' ) . ':\s*(\S+)\s*$/m', $source, $matches ), $name );
			return $matches[1] ?? '';
		};

		self::assertSame( Requirements::MIN_PHP, $header( 'Requires PHP' ) );
		self::assertSame( Requirements::MIN_WP, $header( 'Requires at least' ) );
		self::assertSame( Requirements::MIN_WOOCOMMERCE, $header( 'WC requires at least' ) );
	}

	public function test_woocommerce_minimum_is_the_latest_line_for_the_wordpress_minimum(): void {
		// WooCommerce 10.3.x requires WordPress 6.7; 10.4 requires 6.8 (ADR-030).
		self::assertSame( '10.3', Requirements::MIN_WOOCOMMERCE );
		self::assertSame( '6.7', Requirements::MIN_WP );
	}

	public function test_no_errors_when_versions_are_supported(): void {
		self::assertSame( array(), Requirements::errors( '8.2.0', '6.7' ) );
		self::assertSame( array(), Requirements::errors( '8.5.10', '7.1.2' ) );
	}

	public function test_reports_too_old_php(): void {
		self::assertSame(
			array(
				'php' => array(
					'required' => '8.2',
					'current'  => '8.1.30',
				),
			),
			Requirements::errors( '8.1.30', '6.7' )
		);
	}

	public function test_reports_too_old_wordpress(): void {
		self::assertSame( array( 'wp' ), array_keys( Requirements::errors( '8.2.0', '6.6.2' ) ) );
	}

	public function test_reports_both(): void {
		self::assertSame( array( 'php', 'wp' ), array_keys( Requirements::errors( '7.4.33', '6.0' ) ) );
	}

	public function test_check_passes_without_registering_a_notice(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.2' );
		Actions\expectAdded( 'admin_notices' )->never();

		self::assertTrue( Requirements::check() );
	}

	public function test_check_fails_and_registers_notice_on_old_wordpress(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.6' );
		Actions\expectAdded( 'admin_notices' )->once();

		self::assertFalse( Requirements::check() );
	}

	public function test_notice_is_hidden_from_users_who_cannot_manage_plugins(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'activate_plugins' )->andReturn( false );

		$this->expectOutputString( '' );

		Requirements::render_notice( Requirements::errors( '8.1.0', '6.7' ) );
	}

	public function test_notice_lists_unmet_requirements_escaped(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		ob_start();
		Requirements::render_notice(
			array(
				'php' => array(
					'required' => '8.2',
					'current'  => '<b>8.1</b>',
				),
			)
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'PHP 8.2 or later is required. Current version: &lt;b&gt;8.1&lt;/b&gt;.', $html );
		self::assertStringNotContainsString( '<b>', $html );
	}
}
