<?php
/**
 * Tests for the WooCommerce module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductStore;
use MerchBoard\Modules\WooCommerce\WooCommerceModule;
use MerchBoard\Modules\WooCommerce\WooCommerceProductStore;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use wpdb;

#[CoversClass( WooCommerceModule::class )]
final class WooCommerceModuleTest extends TestCase {

	public function test_module_id(): void {
		self::assertSame( 'woocommerce', ( new WooCommerceModule() )->id() );
	}

	public function test_registers_the_product_link_service_with_the_woocommerce_store(): void {
		$db        = new FakeDatabase();
		$container = new Container();
		$container->set( wpdb::class, static fn(): wpdb => $db );
		$container->set( CreatorRepository::class, static fn(): CreatorRepository => new CreatorRepository( $db ) );
		$container->set( AuditTrail::class, static fn(): AuditTrail => new AuditTrail( new AuditRepository( $db ) ) );
		$container->set( Transaction::class, static fn(): Transaction => new Transaction( $db, new TransactionSupport( $db ) ) );

		( new WooCommerceModule() )->register( $container );

		self::assertInstanceOf( WooCommerceProductStore::class, $container->get( ProductStore::class ) );
		self::assertInstanceOf( ProductCreatorLink::class, $container->get( ProductCreatorLink::class ) );
		// The module never widens the creator role (ADR-030).
		self::assertSame( array( 'read', Capabilities::ACCESS_DASHBOARD ), Capabilities::creator_capabilities() );
	}
}
