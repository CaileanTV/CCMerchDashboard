<?php
/**
 * Tests for the alert template.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The WooCommerce functions stubbed here must not exist for other tests,
 * so every test runs in its own process.
 */
#[CoversClass( AlertTemplate::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AlertTemplateTest extends TestCase {

	private AlertTemplate $template;

	protected function setUp(): void {
		parent::setUp();
		$names          = array(
			5 => 'Hoodies',
			6 => 'Merch',
		);
		$this->template = new AlertTemplate( static fn( int $id ): ?string => $names[ $id ] ?? null );
		// A shop with the currency code after the amount.
		AlertMoneyTest::shop( '.', ',' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'DKK' );
		Functions\when( 'get_woocommerce_currency_symbol' )->returnArg();
		Functions\when( 'get_option' )->justReturn( 'right_space' );
	}

	public function test_every_variable_is_replaced(): void {
		$text = $this->template->render( '$UserName bought $Quantity × $Product ($Variation) in $Category for $LineTotal ($Price each), $Total in all, order $OrderNumber', self::event( 'nova_fan' ) );

		// $OrderNumber is removed.
		self::assertSame( 'nova_fan bought 2 × Hoodie (Size: L) in Hoodies, Merch for 498.00 DKK (249.00 DKK each), 747.00 DKK in all, order ', $text );
	}

	public function test_first_name_shows_the_username_never_a_name(): void {
		self::assertSame( 'Thanks nova_fan!', $this->template->render( 'Thanks $FirstName!', self::event( 'nova_fan' ) ) );
		self::assertSame( 'Thanks Anonymous!', $this->template->render( 'Thanks $FirstName!', self::event( '' ) ) );
		self::assertSame( 'Anonymous', $this->template->render( '$UserName', self::event( '' ) ) );
	}

	public function test_values_are_never_expanded_again_and_unknown_variables_stay(): void {
		$event = new AlertEvent( 1, 7, 'CC-1007', 70, 10, 0, 'Shirt $Category $Total', '', 1, 0, array( 5 ), '$Price', new DateTimeImmutable(), 0, '100.0000', '100.0000', 'DKK' );

		self::assertSame( 'Shirt $Category $Total by $Price $Unknown', $this->template->render( '$Product by $UserName $Unknown', $event ) );
	}

	public function test_an_alert_without_stored_amounts_shows_none(): void {
		$event = new AlertEvent( 1, 7, '7', 70, 10, 0, 'Shirt', '', 1, 0, array(), '', new DateTimeImmutable() );

		self::assertSame( 'Shirt:  /  / ', $this->template->render( '$Product: $Price / $LineTotal / $Total', $event ) );
	}

	public function test_deleted_categories_are_left_out(): void {
		$event = new AlertEvent( 1, 7, '7', 70, 10, 0, 'Shirt', '', 1, 0, array( 99, 6 ), '', new DateTimeImmutable() );

		self::assertSame( 'Merch', $this->template->render( '$Category', $event ) );
		self::assertSame( '', $this->template->render( '', $event ) );
	}

	/**
	 * An alert of a hoodie in two categories.
	 *
	 * @param string $username Username.
	 */
	private static function event( string $username ): AlertEvent {
		return new AlertEvent( 1, 7, 'CC-1007', 70, 10, 11, 'Hoodie', 'Size: L', 2, 55, array( 5, 6 ), $username, new DateTimeImmutable(), 0, '498.0000', '747.0000', 'DKK' );
	}
}
