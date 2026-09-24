<?php
/**
 * Tests for the capability definitions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Security;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( Capabilities::class )]
final class CapabilitiesTest extends TestCase {

	/**
	 * WordPress capabilities that must never be part of the creator role.
	 *
	 * @return array<string, array{string}>
	 */
	public static function administrative_capabilities(): array {
		$caps = array(
			'manage_options',
			'activate_plugins',
			'install_plugins',
			'edit_plugins',
			'edit_users',
			'delete_users',
			'create_users',
			'list_users',
			'promote_users',
			'edit_posts',
			'upload_files',
			'unfiltered_html',
			'edit_theme_options',
			'manage_woocommerce',
		);

		return array_combine( $caps, array_map( static fn( string $c ): array => array( $c ), $caps ) );
	}

	#[DataProvider( 'administrative_capabilities' )]
	public function test_creator_role_never_contains_administrative_capabilities( string $capability ): void {
		self::assertNotContains( $capability, Capabilities::creator_capabilities() );
	}

	public function test_creator_role_has_exactly_read_and_dashboard_access(): void {
		self::assertSame( array( 'read', 'merchboard_access_dashboard' ), Capabilities::creator_capabilities() );
	}

	public function test_creator_role_has_no_plugin_admin_capabilities(): void {
		self::assertSame( array(), array_intersect( Capabilities::creator_capabilities(), Capabilities::admin_capabilities() ) );
	}

	public function test_admin_capabilities_are_the_documented_set(): void {
		self::assertSame(
			array( 'merchboard_manage', 'merchboard_manage_creators', 'merchboard_manage_settings' ),
			Capabilities::admin_capabilities()
		);
	}

	public function test_all_lists_only_the_capabilities_needed_now(): void {
		// Later phases add their own capabilities (audit view, payouts, reports …).
		self::assertSame(
			array( 'merchboard_access_dashboard', 'merchboard_manage', 'merchboard_manage_creators', 'merchboard_manage_settings' ),
			Capabilities::all()
		);
	}

	public function test_every_plugin_capability_and_the_role_use_the_prefix(): void {
		foreach ( Capabilities::all() as $capability ) {
			self::assertTrue( Capabilities::is_plugin_capability( $capability ), $capability );
		}

		self::assertStringStartsWith( 'merchboard_', Capabilities::CREATOR_ROLE );
	}

	public function test_ownership_is_decided_by_prefix(): void {
		self::assertTrue( Capabilities::is_plugin_capability( 'merchboard_anything' ) );
		self::assertFalse( Capabilities::is_plugin_capability( 'manage_options' ) );
		self::assertFalse( Capabilities::is_plugin_capability( 'manage_woocommerce' ) );
		self::assertFalse( Capabilities::is_plugin_capability( 'read' ) );
		self::assertFalse( Capabilities::is_plugin_capability( 'xmerchboard_manage' ) );
	}

	public function test_creator_role_display_name(): void {
		self::assertSame( 'Merch Dashboard Creator', Capabilities::CREATOR_ROLE_NAME );
	}
}
