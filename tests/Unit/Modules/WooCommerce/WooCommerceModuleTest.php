<?php
/**
 * Tests for the WooCommerce module skeleton.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use MerchBoard\Core\Container;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\WooCommerceModule;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( WooCommerceModule::class )]
final class WooCommerceModuleTest extends TestCase {

	public function test_module_id(): void {
		self::assertSame( 'woocommerce', ( new WooCommerceModule() )->id() );
	}

	public function test_skeleton_registers_no_services_and_grants_nothing(): void {
		$container = new Container();

		( new WooCommerceModule() )->register( $container );

		// Step 2 of phase 17 is the skeleton only (no product link yet).
		self::assertFalse( $container->has( WooCommerceModule::class ) );
		// The module never widens the creator role (ADR-030).
		self::assertSame( array( 'read', Capabilities::ACCESS_DASHBOARD ), Capabilities::creator_capabilities() );
	}
}
