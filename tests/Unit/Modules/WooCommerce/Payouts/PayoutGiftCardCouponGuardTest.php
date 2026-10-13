<?php
/**
 * Tests for the guard that refuses payout gift cards as coupons on orders (G1).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts;

use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\PayoutGiftCardCouponGuard;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WcOrderStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use WC_Cart;
use WC_Coupon;
use WC_Discounts;
use WC_Order;
use WP_Error;

/**
 * Coupons are validated through a stand-in of WC_Discounts::is_coupon_valid()
 * (11.1.2): admin `apply_coupon` and REST API v3 `coupon_lines` validate with
 * the order as object, the classic and block checkout with the cart.
 */
#[CoversClass( PayoutGiftCardCouponGuard::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PayoutGiftCardCouponGuardTest extends TestCase {

	private const PAYOUT_CARD = 'MB-PAYOUT-CARD';

	private RecordingLogger $logger;

	/**
	 * Codes looked up, in order.
	 *
	 * @var list<string>
	 */
	private array $lookups = array();

	private PayoutGiftCardCouponGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-order.php';
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-coupon.php';
		require_once dirname( __DIR__, 4 ) . '/Fixtures/wp-rest.php';
		WcOrderStorage::reset();
		\Brain\Monkey\Functions\when( 'esc_html__' )->returnArg();

		$this->logger                      = new RecordingLogger();
		$this->guard                       = $this->guard(
			function ( string $code ): bool {
				$this->lookups[] = $code;

				return self::PAYOUT_CARD === $code;
			}
		);
		$GLOBALS['wc_test_coupon_filters'] = array( array( $this->guard, 'coupon_is_valid' ) );
	}

	public function test_registers_on_the_coupon_validation_after_woocommerce(): void {
		$this->guard->register();

		self::assertSame( 20, has_filter( 'woocommerce_coupon_is_valid', array( $this->guard, 'coupon_is_valid' ) ) );
	}

	public function test_admin_apply_coupon_refuses_a_payout_card_with_a_message(): void {
		$result = ( new WC_Discounts( self::order( 501 ) ) )->is_coupon_valid( new WC_Coupon( self::PAYOUT_CARD ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertStringContainsString( 'issued as a creator payout', $result->get_error_message() );
		self::assertStringNotContainsString( self::PAYOUT_CARD, $result->get_error_message(), 'The message never repeats the code.' );
		self::assertSame( array( array( 'warning', 'A payout gift card was refused as a coupon on an order.', array( 'order_id' => 501 ) ) ), self::entries( $this->logger ) );
	}

	public function test_rest_api_v3_coupon_lines_refuse_a_payout_card_like_the_admin(): void {
		// The REST API v3 validates each coupon line with `new WC_Discounts( $order )` before applying it.
		$discounts = new WC_Discounts( self::order( 502 ) );

		self::assertInstanceOf( WP_Error::class, $discounts->is_coupon_valid( new WC_Coupon( self::PAYOUT_CARD ) ) );
		self::assertTrue( $discounts->is_coupon_valid( new WC_Coupon( 'SUMMER10' ) ), 'Other coupons stay valid.' );
	}

	public function test_checkout_with_the_cart_is_never_touched(): void {
		self::assertTrue( ( new WC_Discounts( new WC_Cart() ) )->is_coupon_valid( new WC_Coupon( self::PAYOUT_CARD ) ), 'The plugin records the order on the card at checkout.' );
		self::assertSame( array(), $this->lookups, 'Not even looked up.' );
	}

	public function test_an_already_invalid_coupon_and_foreign_arguments_pass_through_unchanged(): void {
		self::assertFalse( $this->guard->coupon_is_valid( false, new WC_Coupon( self::PAYOUT_CARD ), new WC_Discounts( self::order( 503 ) ) ) );
		self::assertSame( 'x', $this->guard->coupon_is_valid( 'x', 'not a coupon', new WC_Discounts( self::order( 503 ) ) ) );
		self::assertTrue( $this->guard->coupon_is_valid( true, new WC_Coupon( self::PAYOUT_CARD ), null ) );
		self::assertTrue( $this->guard->coupon_is_valid( true, new WC_Coupon( '  ' ), new WC_Discounts( self::order( 503 ) ) ) );
		self::assertSame( array(), $this->lookups );
	}

	public function test_repeated_validation_gives_the_same_answer_and_keeps_no_state(): void {
		$discounts = new WC_Discounts( self::order( 504 ) );

		for ( $i = 0; $i < 3; $i++ ) {
			self::assertInstanceOf( WP_Error::class, $discounts->is_coupon_valid( new WC_Coupon( self::PAYOUT_CARD ) ) );
			self::assertTrue( $discounts->is_coupon_valid( new WC_Coupon( 'SUMMER10' ) ) );
		}

		self::assertSame( array( self::PAYOUT_CARD, 'SUMMER10', self::PAYOUT_CARD, 'SUMMER10', self::PAYOUT_CARD, 'SUMMER10' ), $this->lookups, 'Looked up every time; nothing cached.' );
	}

	public function test_a_failing_check_refuses_the_coupon_and_logs_without_the_code(): void {
		$guard                             = $this->guard(
			static function (): bool {
				throw new RuntimeException( 'database gone' );
			}
		);
		$GLOBALS['wc_test_coupon_filters'] = array( array( $guard, 'coupon_is_valid' ) );

		$result = ( new WC_Discounts( self::order( 505 ) ) )->is_coupon_valid( new WC_Coupon( 'ANY-CODE' ) );

		self::assertInstanceOf( WP_Error::class, $result, 'Fail closed.' );
		self::assertSame( array( 'error', 'warning' ), array_column( self::entries( $this->logger ), 0 ) );
		self::assertSame( array( 'order_id' => 505, 'error' => RuntimeException::class ), self::entries( $this->logger )[0][2] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( 'ANY-CODE', var_export( $this->logger->entries, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test assertion.
	}

	/**
	 * The guard with a lookup.
	 *
	 * @param callable(string): bool $lookup Lookup.
	 */
	private function guard( callable $lookup ): PayoutGiftCardCouponGuard {
		return new PayoutGiftCardCouponGuard( \Closure::fromCallable( $lookup ), $this->logger );
	}

	/**
	 * An order stand-in.
	 *
	 * @param int $id Order id.
	 */
	private static function order( int $id ): WC_Order {
		WcOrderStorage::add_order( $id, 'pending' );
		$order = wc_get_order( $id );
		self::assertInstanceOf( WC_Order::class, $order );

		return $order;
	}

	/**
	 * Logged entries as [level, message, context].
	 *
	 * @param RecordingLogger $logger Logger.
	 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private static function entries( RecordingLogger $logger ): array {
		return array_map( static fn( array $entry ): array => array( $entry['level'], $entry['message'], $entry['context'] ), $logger->entries );
	}
}
