<?php
/**
 * Tests for the cart guard of the design collections.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Collections;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Collections\CollectionCartGuard;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WC_Cart;

#[CoversClass( CollectionCartGuard::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CollectionCartGuardTest extends TestCase {

	private const COLLECTION = 10;

	private const DESIGN = 21;

	private CollectionCartGuard $guard;

	/**
	 * The notices shown.
	 *
	 * @var list<string>
	 */
	private array $notices = array();

	protected function setUp(): void {
		parent::setUp();
		$this->notices = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wc_add_notice' )->alias(
			function ( string $message ): void {
				$this->notices[] = $message;
			}
		);

		$collections = $this->createStub( DesignCollections::class );
		$collections->method( 'is_collection' )->willReturnCallback( static fn( int $id ): bool => self::COLLECTION === $id );

		$this->guard = new CollectionCartGuard( $collections );
	}

	public function test_the_forms_refuse_a_collection_with_a_reason(): void {
		self::assertFalse( $this->guard->validate( true, self::COLLECTION ) );
		self::assertCount( 1, $this->notices );
	}

	public function test_an_earlier_refusal_gets_no_second_reason(): void {
		self::assertFalse( $this->guard->validate( false, self::COLLECTION ) );
		self::assertSame( array(), $this->notices );
	}

	public function test_a_design_passes(): void {
		self::assertTrue( $this->guard->validate( true, self::DESIGN ) );
		self::assertSame( 2, $this->guard->quantity( 2, self::DESIGN ) );
		self::assertSame( array(), $this->notices );
	}

	public function test_a_collection_added_in_code_gets_no_quantity(): void {
		self::assertSame( 0, $this->guard->quantity( 1, self::COLLECTION ) );
		self::assertSame( 0, $this->guard->quantity( 1, (string) self::COLLECTION ) );
		self::assertSame( 1, $this->guard->quantity( 1, 'no id' ) );
	}

	public function test_a_collection_already_in_the_cart_stops_the_checkout(): void {
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-coupon.php';
		$cart                = new WC_Cart();
		$cart->cart_contents = array( 'a' => array( 'product_id' => self::DESIGN ) );
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );

		$this->guard->check_cart();
		self::assertSame( array(), $this->notices, 'A design is bought as before.' );

		$cart->cart_contents['b'] = array( 'product_id' => self::COLLECTION );

		$this->guard->check_cart();
		self::assertCount( 1, $this->notices );
	}
}
