<?php
/**
 * Tests for the "Alerts" section of the creator dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Alerts\Dashboard\AlertsDashboardSection;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( AlertsDashboardSection::class )]
final class AlertsDashboardSectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_the_section_comes_before_the_profile(): void {
		$sections = AlertsDashboardSection::add_section(
			array(
				array( 'id' => 'overview' ),
				array( 'id' => 'payouts' ),
				array( 'id' => 'profile' ),
			)
		);

		self::assertIsArray( $sections );
		self::assertSame( array( 'overview', 'payouts', 'alerts', 'profile' ), array_column( $sections, 'id' ) );
		self::assertSame(
			array(
				'id'         => 'alerts',
				'label'      => 'Alerts',
				'path'       => 'alerts',
				'capability' => 'merchboard_access_dashboard',
			),
			$sections[2]
		);
	}

	public function test_without_a_profile_it_comes_last_and_other_values_pass_through(): void {
		$sections = AlertsDashboardSection::add_section( array( array( 'id' => 'overview' ) ) );

		self::assertIsArray( $sections );
		self::assertSame( array( 'overview', 'alerts' ), array_column( $sections, 'id' ) );
		self::assertSame( 'not an array', AlertsDashboardSection::add_section( 'not an array' ) );
	}
}
