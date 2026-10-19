<?php
/**
 * Tests for the alert template.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use DateTimeImmutable;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( AlertTemplate::class )]
final class AlertTemplateTest extends TestCase {

	private AlertTemplate $template;

	protected function setUp(): void {
		parent::setUp();
		$names          = array(
			5 => 'Hoodies',
			6 => 'Merch',
		);
		$this->template = new AlertTemplate( static fn( int $id ): ?string => $names[ $id ] ?? null );
	}

	public function test_every_variable_is_replaced(): void {
		$text = $this->template->render( '$UserName bought $Quantity × $Product ($Variation) in $Category, order $OrderNumber', self::event( 'nova_fan' ) );

		self::assertSame( 'nova_fan bought 2 × Hoodie (Size: L) in Hoodies, Merch, order CC-1007', $text );
	}

	public function test_first_name_shows_the_username_never_a_name(): void {
		self::assertSame( 'Thanks nova_fan!', $this->template->render( 'Thanks $FirstName!', self::event( 'nova_fan' ) ) );
		self::assertSame( 'Thanks Anonymous!', $this->template->render( 'Thanks $FirstName!', self::event( '' ) ) );
		self::assertSame( 'Anonymous', $this->template->render( '$UserName', self::event( '' ) ) );
	}

	public function test_values_are_never_expanded_again_and_unknown_variables_stay(): void {
		$event = new AlertEvent( 1, 7, 'CC-1007', 70, 10, 0, 'Shirt $Category $UserName', '', 1, 0, array( 5 ), '$OrderNumber', new DateTimeImmutable() );

		self::assertSame( 'Shirt $Category $UserName by $OrderNumber $Unknown', $this->template->render( '$Product by $UserName $Unknown', $event ) );
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
		return new AlertEvent( 1, 7, 'CC-1007', 70, 10, 11, 'Hoodie', 'Size: L', 2, 55, array( 5, 6 ), $username, new DateTimeImmutable() );
	}
}
