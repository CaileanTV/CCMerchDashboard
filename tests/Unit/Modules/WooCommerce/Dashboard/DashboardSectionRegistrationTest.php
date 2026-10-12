<?php
/**
 * Tests for the WooCommerce sections of the creator dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Dashboard;

use Brain\Monkey\Functions;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Dashboard\DashboardSections;
use MerchBoard\Modules\WooCommerce\Dashboard\DashboardSectionRegistration;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DashboardSectionRegistration::class )]
final class DashboardSectionRegistrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_sales_products_and_orders_follow_the_overview(): void {
		$sections = DashboardSectionRegistration::add_sections(
			array(
				self::section( 'overview', '' ),
				self::section( 'profile', 'profile' ),
			)
		);

		self::assertIsArray( $sections );
		self::assertSame( array( 'overview', 'sales', 'products', 'orders', 'payouts', 'profile' ), array_column( $sections, 'id' ) );
		self::assertSame(
			array(
				'id'         => 'sales',
				'label'      => 'Sales',
				'path'       => 'sales',
				'capability' => Capabilities::ACCESS_DASHBOARD,
			),
			$sections[1]
		);
		self::assertSame(
			array(
				'id'         => 'products',
				'label'      => 'Products',
				'path'       => 'products',
				'capability' => Capabilities::ACCESS_DASHBOARD,
			),
			$sections[2]
		);
		self::assertSame(
			array(
				'id'         => 'orders',
				'label'      => 'Orders',
				'path'       => 'orders',
				'capability' => Capabilities::ACCESS_DASHBOARD,
			),
			$sections[3]
		);
		self::assertSame(
			array(
				'id'         => 'payouts',
				'label'      => 'Payouts',
				'path'       => 'payouts',
				'capability' => Capabilities::ACCESS_DASHBOARD,
			),
			$sections[4]
		);
	}

	public function test_without_an_overview_entry_the_sections_come_last(): void {
		$sections = DashboardSectionRegistration::add_sections( array( self::section( 'profile', 'profile' ) ) );

		self::assertIsArray( $sections );
		self::assertSame( array( 'profile', 'sales', 'products', 'orders', 'payouts' ), array_column( $sections, 'id' ) );
	}

	public function test_a_filter_value_that_is_not_a_list_is_passed_on(): void {
		self::assertSame( 'broken', DashboardSectionRegistration::add_sections( 'broken' ) );
		self::assertNull( DashboardSectionRegistration::add_sections( null ) );
	}

	public function test_other_entries_are_kept_untouched(): void {
		$sections = DashboardSectionRegistration::add_sections( array( 'not a section', self::section( 'overview', '' ) ) );

		self::assertIsArray( $sections );
		self::assertSame( 'not a section', $sections[0] );
		self::assertCount( 6, $sections );
	}

	public function test_register_hooks_the_section_filter(): void {
		( new DashboardSectionRegistration() )->register();

		self::assertSame( 10, has_filter( DashboardSections::FILTER, array( DashboardSectionRegistration::class, 'add_sections' ) ) );
	}

	/**
	 * A section entry.
	 *
	 * @param string $id   Id.
	 * @param string $path Path.
	 * @return array{id: string, label: string, path: string, capability: string}
	 */
	private static function section( string $id, string $path ): array {
		return array(
			'id'         => $id,
			'label'      => ucfirst( $id ),
			'path'       => $path,
			'capability' => Capabilities::ACCESS_DASHBOARD,
		);
	}
}
