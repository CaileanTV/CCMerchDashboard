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
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
	}

	public function test_the_api_page_is_added_only_while_the_api_is_on(): void {
		// The section reads only the switch (AlertApiSwitch::OPTION).
		Functions\when( 'get_option' )->justReturn( '1' );

		$sections = AlertsDashboardSection::add_section( array( array( 'id' => 'overview' ) ) );

		self::assertIsArray( $sections );
		self::assertSame( array( 'overview', 'alerts', 'alerts-history', 'alerts-chatbot', 'alerts-api', 'alerts-webhooks' ), array_column( $sections, 'id' ) );
		self::assertSame( 'alerts/api', $sections[4]['path'] ?? null );
		self::assertSame( 'alerts/webhooks', $sections[5]['path'] ?? null, 'Keys and webhooks work apart: two pages.' );
	}

	public function test_each_api_page_only_with_the_creators_access(): void {
		Functions\when( 'get_option' )->justReturn( '1' );
		$base = array( array( 'id' => 'overview' ) );
		$ids  = static fn( mixed $sections ): array => is_array( $sections ) ? array_column( $sections, 'id' ) : array();

		self::assertSame(
			array( 'overview', 'alerts', 'alerts-history', 'alerts-chatbot', 'alerts-webhooks' ),
			$ids(
				AlertsDashboardSection::add_section(
					$base,
					array(
						'keys'     => false,
						'webhooks' => true,
					)
				)
			)
		);
		self::assertSame(
			array( 'overview', 'alerts', 'alerts-history', 'alerts-chatbot' ),
			$ids(
				AlertsDashboardSection::add_section(
					$base,
					array(
						'keys'     => false,
						'webhooks' => false,
					)
				)
			),
			'No access: no API pages.'
		);
	}

	public function test_the_group_comes_before_the_profile(): void {
		$sections = AlertsDashboardSection::add_section(
			array(
				array( 'id' => 'overview' ),
				array( 'id' => 'payouts' ),
				array( 'id' => 'profile' ),
			)
		);

		self::assertIsArray( $sections );
		self::assertSame( array( 'overview', 'payouts', 'alerts', 'alerts-history', 'alerts-chatbot', 'profile' ), array_column( $sections, 'id' ) );
		self::assertSame(
			array(
				'id'          => 'alerts',
				'label'       => 'Overlay settings',
				'path'        => 'alerts',
				'capability'  => 'merchboard_access_dashboard',
				'group'       => 'alerts',
				'group_label' => 'Alerts',
			),
			$sections[2],
			'The overlay settings keep the former URL.'
		);
		self::assertSame( 'alerts/history', $sections[3]['path'] ?? null );
		self::assertSame( 'alerts', $sections[3]['group'] ?? null );
	}

	public function test_without_a_profile_it_comes_last_and_other_values_pass_through(): void {
		$sections = AlertsDashboardSection::add_section( array( array( 'id' => 'overview' ) ) );

		self::assertIsArray( $sections );
		self::assertSame( array( 'overview', 'alerts', 'alerts-history', 'alerts-chatbot' ), array_column( $sections, 'id' ) );
		self::assertSame( 'not an array', AlertsDashboardSection::add_section( 'not an array' ) );
	}
}
