<?php
/**
 * The creator's product selection against the real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\ProductPosts;

final class CreatorProductsTest extends IntegrationTestCase {

	private CreatorProducts $products;

	private ProductPosts $posts;

	protected function setUp(): void {
		parent::setUp();
		$this->posts = new ProductPosts( $this->wpdb );
		$this->posts->remove_all();
		$this->products = new CreatorProducts( $this->wpdb );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_only_published_and_private_products_are_selected(): void {
		$publish = $this->posts->add( 'Publish', 'publish', array( '12' ) );
		$private = $this->posts->add( 'Private', 'private', array( '12' ) );

		foreach ( array( 'draft', 'pending', 'trash' ) as $status ) {
			$id = $this->posts->add( ucfirst( $status ), $status, array( '12' ) );
			self::assertSame( $status, get_post_status( $id ) );
		}

		self::assertSame( array( $private, $publish ), $this->page( 12 ) );
		self::assertSame( 2, $this->products->count( 12 ) );
	}

	public function test_variations_and_other_post_types_are_never_selected(): void {
		$parent = $this->posts->add( 'Hoodie', 'publish', array( '12' ) );
		$this->posts->add( 'Hoodie - S', 'publish', array( '12' ), 'product_variation', $parent );
		$this->posts->add( 'A post', 'publish', array( '12' ), 'post' );
		$this->posts->add( 'A page', 'publish', array( '12' ), 'page' );

		self::assertSame( array( $parent ), $this->page( 12 ) );
		self::assertSame( 1, $this->products->count( 12 ) );
	}

	public function test_only_exactly_one_link_entry_with_exactly_the_creator_id_counts(): void {
		$mine = $this->posts->add( 'Mine', 'publish', array( '12' ) );
		$this->posts->add( 'Other creator', 'publish', array( '13' ) );
		$this->posts->add( 'Unlinked', 'publish', array() );
		$this->posts->add( 'Two entries, same id', 'publish', array( '12', '12' ) );
		$this->posts->add( 'Two entries, two ids', 'publish', array( '12', '13' ) );
		$this->posts->add( 'Leading zero', 'publish', array( '012' ) );
		$this->posts->add( 'Trailing space', 'publish', array( '12 ' ) );
		$this->posts->add( 'Plus sign', 'publish', array( '+12' ) );
		$spelled = $this->posts->add( 'Second spelling', 'publish', array( '12' ) );
		$this->posts->meta( $spelled, '_Merchboard_Creator_ID', '12' );

		self::assertSame( array( $mine ), $this->page( 12 ) );
		self::assertSame( 1, $this->products->count( 12 ) );
	}

	public function test_another_creators_products_are_never_selected(): void {
		$mine   = $this->posts->add( 'Mine', 'publish', array( '12' ) );
		$theirs = $this->posts->add( 'Theirs', 'publish', array( '13' ) );

		self::assertSame( array( $mine ), $this->page( 12 ) );
		self::assertSame( array( $theirs ), $this->page( 13 ) );
		self::assertSame( array(), $this->page( 14 ) );
		self::assertSame( 0, $this->products->count( 14 ) );
	}

	public function test_no_creator_id_selects_nothing(): void {
		$this->posts->add( 'Zero', 'publish', array( '0' ) );

		self::assertSame( array(), $this->page( 0 ) );
		self::assertSame( 0, $this->products->count( 0 ) );
	}

	public function test_products_are_sorted_by_name_then_id(): void {
		$tee_first  = $this->posts->add( 'Tee', 'publish', array( '12' ) );
		$cap        = $this->posts->add( 'Cap', 'private', array( '12' ) );
		$tee_second = $this->posts->add( 'Tee', 'publish', array( '12' ) );
		$hoodie     = $this->posts->add( 'Hoodie', 'publish', array( '12' ) );

		self::assertLessThan( $tee_second, $tee_first );
		self::assertSame( array( $cap, $hoodie, $tee_first, $tee_second ), $this->page( 12 ) );
	}

	public function test_pages_follow_the_order_and_a_page_after_the_last_is_empty(): void {
		$ids = array();

		foreach ( array( 'E', 'A', 'D', 'B', 'C' ) as $name ) {
			$ids[ $name ] = $this->posts->add( $name, 'publish', array( '12' ) );
		}

		self::assertSame( array( $ids['A'], $ids['B'] ), $this->page( 12, 1, 2 ) );
		self::assertSame( array( $ids['C'], $ids['D'] ), $this->page( 12, 2, 2 ) );
		self::assertSame( array( $ids['E'] ), $this->page( 12, 3, 2 ) );
		self::assertSame( array(), $this->page( 12, 4, 2 ) );
		self::assertSame( 5, $this->products->count( 12 ) );
	}

	/**
	 * Ids of one page.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $page       Page.
	 * @param int $per_page   Page size.
	 * @return list<int>
	 */
	private function page( int $creator_id, int $page = 1, int $per_page = 20 ): array {
		return $this->products->ids( $creator_id, new Pagination( $page, $per_page ) );
	}
}
