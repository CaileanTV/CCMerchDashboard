<?php
/**
 * Tests for the alert kill switch and the MerchAlert guard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

#[CoversClass( AlertsAvailability::class )]
final class AlertsAvailabilityTest extends TestCase {

	private InMemoryOptions $options;

	protected function setUp(): void {
		parent::setUp();
		$this->options = InMemoryOptions::install();
	}

	public function test_alerts_are_sent_only_when_switched_on_and_merchalert_is_inactive(): void {
		$merchalert   = false;
		$availability = new AlertsAvailability(
			static function () use ( &$merchalert ): bool {
				return $merchalert;
			}
		);

		self::assertFalse( $availability->sending(), 'Off by default.' );

		$this->options->options[ Settings::OPTION ] = array( Settings::ALERTS_ENABLED => true );
		self::assertTrue( $availability->enabled() );
		self::assertTrue( $availability->sending() );

		$merchalert = true;
		self::assertTrue( $availability->merchalert_active() );
		self::assertFalse( $availability->sending(), 'Paused while MerchAlert is active.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_merchalert_is_recognised_by_its_constant(): void {
		$availability = new AlertsAvailability();
		self::assertFalse( $availability->merchalert_active() );

		define( AlertsAvailability::MERCHALERT_CONSTANT, 'Beta 2.0.2' );
		self::assertTrue( $availability->merchalert_active() );
	}
}
