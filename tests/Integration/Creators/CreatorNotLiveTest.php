<?php
/**
 * Integration tests: nothing of a creator who signed up reaches the shop
 * before going live (ADR-091).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Creators;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CreatorCategoryException;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeService;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductCreatorLinkException;
use MerchBoard\Modules\WooCommerce\ProductLinkSource;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeCouponIssuer;
use MerchBoard\Tests\Integration\Support\FakeCouponLookup;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A creator who signed up is active (they use the dashboard) but not live:
 * no category, no product link and no discount code until an administrator
 * approves going live. Existing creators and those an administrator
 * creates are live as before.
 */
#[CoversClass( CreatorRepository::class )]
#[CoversClass( CreatorCategoryService::class )]
#[CoversClass( ProductCreatorLink::class )]
#[CoversClass( CreatorCodeService::class )]
final class CreatorNotLiveTest extends IntegrationTestCase {

	private CreatorRepository $creators;

	private int $term = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		wp_set_current_user( 1 );
		register_taxonomy( 'product_cat', 'product' );

		$term       = wp_insert_term( 'MB-NOTLIVE category', 'product_cat' );
		$this->term = is_array( $term ) ? (int) $term['term_id'] : 0;

		$this->creators = $this->service( CreatorRepository::class );
	}

	protected function tearDown(): void {
		wp_delete_term( $this->term, 'product_cat' );
		parent::tearDown();
	}

	public function test_a_signed_up_creator_is_active_but_not_live_until_going_live_once(): void {
		$admin  = $this->creators->create( 'Made by admin', CreatorStatus::Active );
		$signed = $this->creators->create( 'Signed up', CreatorStatus::Active, null, true );

		self::assertTrue( $admin->is_live() );
		self::assertFalse( $admin->agreement_required, 'Existing and admin-made creators accept nothing (ADR-092).' );
		self::assertTrue( $signed->is_active() );
		self::assertFalse( $signed->is_live() );
		self::assertTrue( $signed->agreement_required );

		self::assertTrue( $this->creators->go_live( $signed->id ) );
		self::assertTrue( $this->creators->find( $signed->id )?->is_live() );
		self::assertFalse( $this->creators->go_live( $signed->id ), 'Only once.' );
		self::assertFalse( $this->creators->go_live( $admin->id ), 'A live creator is left as is.' );
	}

	public function test_no_category_product_link_or_discount_code_before_going_live(): void {
		$signed     = $this->creators->create( 'Not live', CreatorStatus::Active, null, true );
		$audit      = $this->service( AuditTrail::class );
		$tx         = $this->service( Transaction::class );
		$categories = new CreatorCategoryService( new CreatorCategoryRepository( $this->wpdb ), $this->creators, $audit, $tx );
		$links      = new ProductCreatorLink( new PostMetaProductStore(), $this->creators, $audit, $tx, $this->service( ProtectedProductMeta::class ) );
		$codes      = new CreatorCodeService( $this->creators, new CreatorCodeRepository( $this->wpdb ), new FakeCouponLookup(), new FakeCouponIssuer( new FakeCouponLookup() ), $tx, $audit );
		$product    = ( new ProductPosts( $this->wpdb ) )->add( 'MB-NOTLIVE product', 'publish', array() );

		$this->refused( CreatorCategoryException::CREATOR_NOT_LIVE, fn() => $categories->link( $signed->id, $this->term ) );
		$this->refused( ProductCreatorLinkException::CREATOR_NOT_LIVE, fn() => $links->link( $product, $signed->id, ProductLinkSource::Admin ) );
		$this->refused( CreatorCodeException::NOT_LIVE, fn() => $codes->issue( $signed->id, CreatorCodeService::SOURCE_ADMIN ) );

		self::assertSame( array(), ( new CreatorCategoryRepository( $this->wpdb ) )->for_creator( $signed->id ) );
		self::assertSame( '', (string) get_post_meta( $product, ProductCreatorLink::META_KEY, true ) );
		self::assertNull( ( new CreatorCodeRepository( $this->wpdb ) )->active_for_creator( $signed->id ) );

		$this->creators->go_live( $signed->id );

		self::assertTrue( $categories->link( $signed->id, $this->term ), 'Live: the category can be mapped.' );
		$links->link( $product, $signed->id, ProductLinkSource::Admin );
		self::assertSame( (string) $signed->id, (string) get_post_meta( $product, ProductCreatorLink::META_KEY, true ) );
	}

	/**
	 * Expects a refusal with a reason.
	 *
	 * @param string   $reason    Reason.
	 * @param callable $operation Operation.
	 */
	private function refused( string $reason, callable $operation ): void {
		try {
			$operation();
			self::fail( 'Expected a refusal: ' . $reason );
		} catch ( CreatorCategoryException | ProductCreatorLinkException | CreatorCodeException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}
}
