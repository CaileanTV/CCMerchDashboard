<?php
/**
 * The discount of a creator code: the creator's profit (ADR-078).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeService;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorProfitCoupon;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfitDiscount;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkup;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeCouponIssuer;
use MerchBoard\Tests\Integration\Support\FakeCouponLookup;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * One code handles different profits: Nova's default share is 20 % (a
 * T-shirt) and a mug has its own 25 %; Orion's product in the same cart gets
 * nothing. Only the active registration's coupon applies. WooCommerce is not
 * loaded: the coupon's id and code are passed as WooCommerce' filters do.
 */
#[CoversClass( CreatorProfitCoupon::class )]
#[CoversClass( CreatorProfitDiscount::class )]
final class CreatorProfitCouponTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private int $nova;

	private int $shirt;

	private int $mug;

	private int $foreign;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators    = new CreatorRepository( $this->wpdb );
		$this->nova  = $creators->create( 'Nova', CreatorStatus::Active )->id;
		$orion       = $creators->create( 'Orion', CreatorStatus::Active )->id;
		$this->posts = new ProductPosts( $this->wpdb );

		$this->shirt   = $this->posts->add( 'T-shirt', 'publish', array( (string) $this->nova ) );
		$this->mug     = $this->posts->add( 'Mug', 'publish', array( (string) $this->nova ) );
		$this->foreign = $this->posts->add( 'Cap', 'publish', array( (string) $orion ) );

		( new CreatorMarkupRepository( $this->wpdb ) )->save( $this->nova, new MarkupBps( 2000 ) );
		( new CreatorMarkupRepository( $this->wpdb ) )->save( $orion, new MarkupBps( 3000 ) );
		( new ProductMarkupRepository( $this->wpdb ) )->save( new ProductMarkup( $this->mug, $this->nova, new MarkupBps( 2500 ) ) );

		// Nova's code "nova10" with coupon 501.
		$coupons = new FakeCouponLookup();
		( new CreatorCodeService(
			$creators,
			new CreatorCodeRepository( $this->wpdb ),
			$coupons,
			new FakeCouponIssuer( $coupons ),
			$this->service( Transaction::class ),
			$this->service( AuditTrail::class ),
			static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			static fn(): string => 'nova10'
		) )->issue( $this->nova, CreatorCodeService::SOURCE_ADMIN );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_one_code_gives_each_of_the_creators_products_its_own_profit(): void {
		$coupon = $this->coupon();

		self::assertSame( 20.0, $coupon->unit_discount( 501, 'NOVA10', $this->shirt, 100.0 ), 'The creator\'s default 20 %.' );
		self::assertSame( 20.0, $coupon->unit_discount( 501, 'nova10', $this->mug, 80.0 ), 'The mug\'s own 25 %.' );
		self::assertSame( 24.9975, $coupon->unit_discount( 501, 'nova10', $this->mug, 99.99 ), 'Exact to 4 decimals; WooCommerce rounds the line.' );
		self::assertSame( 0.0, $coupon->unit_discount( 501, 'nova10', $this->foreign, 100.0 ), 'Another creator\'s profit stays.' );
		self::assertSame( 0.0, $coupon->unit_discount( 501, 'nova10', $this->shirt, 0.0 ) );

		self::assertTrue( $coupon->applies( 501, 'nova10', $this->shirt ) );
		self::assertTrue( $coupon->applies( 501, 'nova10', $this->mug ) );
		self::assertFalse( $coupon->applies( 501, 'nova10', $this->foreign ) );
		self::assertFalse( $coupon->applies( 501, 'nova10', 999999 ), 'A product without owner.' );
	}

	public function test_only_the_active_registrations_coupon_applies(): void {
		$coupon = $this->coupon();

		self::assertFalse( $coupon->applies( 777, 'nova10', $this->shirt ), 'Another coupon with the code (e.g. re-created by hand).' );
		self::assertSame( 0.0, $coupon->unit_discount( 777, 'nova10', $this->shirt, 100.0 ) );
		self::assertFalse( $coupon->applies( 502, 'other', $this->shirt ), 'A coupon of the type that is no registration.' );

		( new CreatorCodeRepository( $this->wpdb ) )->end( (int) ( new CreatorCodeRepository( $this->wpdb ) )->active_for_creator( $this->nova )?->id, 1, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		self::assertFalse( $this->coupon()->applies( 501, 'nova10', $this->shirt ), 'An ended code gives no discount.' );
	}

	public function test_an_order_keeps_the_registration_that_covered_it(): void {
		$codes   = new CreatorCodeRepository( $this->wpdb );
		$active  = $codes->active_for_creator( $this->nova ) ?? self::fail( 'No active code.' );
		$created = $active->valid_from->modify( '+1 minute' );
		$before  = $active->valid_from->modify( '-1 minute' );

		$codes->end( $active->id, 1, $created->modify( '+1 hour' ) );
		$coupon = $this->coupon();

		self::assertFalse( $coupon->applies( 501, 'nova10', $this->shirt ), 'A cart: the code has ended.' );
		self::assertTrue( $coupon->applies( 501, 'nova10', $this->shirt, $created ), 'An order created while the code was active (e.g. "Recalculate" later).' );
		self::assertTrue( $coupon->applies( 0, 'NOVA10', $this->shirt, $created ), 'Also when the coupon was trashed (WooCommerce rebuilds it without id).' );
		self::assertSame( 20.0, $coupon->unit_discount( 0, 'nova10', $this->shirt, 100.0, $created ) );
		self::assertFalse( $coupon->applies( 501, 'nova10', $this->foreign, $created ), 'Still only the creator\'s products.' );
		self::assertFalse( $coupon->applies( 501, 'nova10', $this->shirt, $before ), 'An order from before the code.' );
		self::assertSame( 0.0, $coupon->unit_discount( 501, 'nova10', $this->shirt, 100.0, $created->modify( '+2 hours' ) ), 'An order from after the code ended.' );
	}

	public function test_the_stored_type_of_an_order_coupon_is_read_from_coupon_info_or_coupon_data(): void {
		self::assertTrue( CreatorProfitCoupon::stored_type_is_this( '[703,"nova-7k3qxp","merchboard_creator_profit",0]', '' ) );
		self::assertFalse( CreatorProfitCoupon::stored_type_is_this( '[55,"summer","percent",10]', '' ) );
		self::assertTrue( CreatorProfitCoupon::stored_type_is_this( '', array( 'discount_type' => 'merchboard_creator_profit' ) ) );
		self::assertFalse( CreatorProfitCoupon::stored_type_is_this( '', array( 'discount_type' => 'fixed_cart' ) ) );
		self::assertFalse( CreatorProfitCoupon::stored_type_is_this( 'not json', '' ) );
		self::assertFalse( CreatorProfitCoupon::stored_type_is_this( '', '' ) );
	}

	public function test_a_product_whose_owner_changed_follows_the_new_owner(): void {
		$cap = $this->posts->add( 'Hoodie', 'publish', array( (string) $this->nova ) );
		self::assertTrue( $this->coupon()->applies( 501, 'nova10', $cap ) );

		$this->posts->remove_all();
		self::assertFalse( $this->coupon()->applies( 501, 'nova10', $cap ), 'Without the link the product is nobody\'s.' );
	}

	/**
	 * The coupon type as the module builds it.
	 */
	private function coupon(): CreatorProfitCoupon {
		$creators = new CreatorRepository( $this->wpdb );
		$links    = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );

		return new CreatorProfitCoupon(
			new CreatorCodeRepository( $this->wpdb ),
			new CreatorProfitDiscount( new MarkupResolver( $links, new CreatorMarkupRepository( $this->wpdb ), new ProductMarkupRepository( $this->wpdb ) ) ),
			$this->service( Logger::class )
		);
	}
}
