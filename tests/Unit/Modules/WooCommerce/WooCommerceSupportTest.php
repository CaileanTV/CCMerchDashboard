<?php
/**
 * Tests for the WooCommerce version gate.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\WooCommerceSupport;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( WooCommerceSupport::class )]
final class WooCommerceSupportTest extends TestCase {

	/**
	 * Versions at or above the minimum.
	 *
	 * @return array<string, array{string}>
	 */
	public static function supported_versions(): array {
		return array(
			'minimum'          => array( '10.3' ),
			'minimum x.y.0'    => array( '10.3.0' ),
			'last 10.3 patch'  => array( '10.3.8' ),
			'next minor'       => array( '10.4.0' ),
			'current major'    => array( '11.1.2' ),
			'future two-digit' => array( '20.0.0' ),
		);
	}

	/**
	 * Versions below the minimum, or unknown.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unsupported_versions(): array {
		return array(
			'last 10.2 patch' => array( '10.2.2' ),
			'old major'       => array( '9.9.0' ),
			// Greater than "10.3" as a string, smaller as a version.
			'9.10 vs 10.3'    => array( '9.10.0' ),
			'no version'      => array( '' ),
		);
	}

	#[DataProvider( 'supported_versions' )]
	public function test_supported_versions( string $version ): void {
		$support = new WooCommerceSupport( $version );

		self::assertTrue( $support->is_active() );
		self::assertTrue( $support->is_supported() );
	}

	#[DataProvider( 'unsupported_versions' )]
	public function test_unsupported_versions( string $version ): void {
		$support = new WooCommerceSupport( $version );

		self::assertTrue( $support->is_active() );
		self::assertFalse( $support->is_supported() );
	}

	public function test_without_woocommerce_nothing_is_active_or_supported(): void {
		$support = new WooCommerceSupport( null );

		self::assertFalse( $support->is_active() );
		self::assertFalse( $support->is_supported() );
	}

	public function test_environment_without_woocommerce(): void {
		self::assertFalse( class_exists( 'WooCommerce', false ) );
		self::assertFalse( WooCommerceSupport::from_environment()->is_active() );
	}

	public function test_notice_is_registered_only_for_an_unsupported_version(): void {
		$old = new WooCommerceSupport( '10.2.2' );

		Actions\expectAdded( 'admin_notices' )->once()->with( array( $old, 'render_notice' ) );

		$old->register_notice();
	}

	public function test_no_notice_for_a_supported_version(): void {
		Actions\expectAdded( 'admin_notices' )->never();

		( new WooCommerceSupport( '10.3.8' ) )->register_notice();
	}

	public function test_no_notice_without_woocommerce(): void {
		Actions\expectAdded( 'admin_notices' )->never();

		( new WooCommerceSupport( null ) )->register_notice();
	}

	public function test_notice_is_hidden_from_users_who_cannot_manage_plugins(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'activate_plugins' )->andReturn( false );

		$this->expectOutputString( '' );

		( new WooCommerceSupport( '10.2.2' ) )->render_notice();
	}

	public function test_notice_is_neutral_and_escaped(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		ob_start();
		( new WooCommerceSupport( '<b>10.2</b>' ) )->render_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice-warning', $html );
		self::assertStringNotContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'WooCommerce 10.3 or later is required. Current version: &lt;b&gt;10.2&lt;/b&gt;.', $html );
		self::assertStringContainsString( 'The rest of Merch Dashboard works normally.', $html );
		self::assertStringNotContainsString( '<b>', $html );
	}

	public function test_notice_names_an_unknown_version(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		ob_start();
		( new WooCommerceSupport( '' ) )->render_notice();

		self::assertStringContainsString( 'Current version: unknown.', (string) ob_get_clean() );
	}
}
