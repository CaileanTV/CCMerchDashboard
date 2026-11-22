<?php
/**
 * Tests for the reader of the design collections.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Collections;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DesignCollections::class )]
final class DesignCollectionsTest extends TestCase {

	private const COLLECTION = 10;

	private const DESIGN = 21;

	private const OTHER = 22;

	private const HIDDEN = array( 'exclude-from-catalog', 'exclude-from-search' );

	/**
	 * Products as the collection plugin leaves them.
	 *
	 * @var array<int, array{post_type: string, status: string, type: string, visibility: list<string>, meta: array<string, mixed>}>
	 */
	private array $products = array();

	protected function setUp(): void {
		parent::setUp();

		$this->products = array();

		$this->product(
			self::COLLECTION,
			DesignCollections::TYPE,
			array(),
			array(
				DesignCollections::MEMBERS_KEY => array(
					array(
						'id'    => self::DESIGN,
						'label' => 'Red',
					),
					array(
						'id'    => self::OTHER,
						'label' => 'Blue',
					),
				),
			)
		);

		foreach ( array( self::DESIGN, self::OTHER ) as $id ) {
			$this->product(
				$id,
				'variable',
				self::HIDDEN,
				array(
					DesignCollections::GROUP_KEY => (string) self::COLLECTION,
					DesignCollections::PREVIOUS_VISIBILITY_KEY => 'visible',
				)
			);
		}

		Functions\when( 'get_post_type' )->alias( fn( int $id ): string|false => $this->products[ $id ]['post_type'] ?? false );
		Functions\when( 'get_post_status' )->alias( fn( int $id ): string|false => $this->products[ $id ]['status'] ?? false );
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ): mixed => $this->products[ $id ]['meta'][ $key ] ?? '' );
		Functions\when( 'get_permalink' )->alias( fn( int $id ): string|false => isset( $this->products[ $id ] ) ? 'https://shop.test/product/' . $id . '/' : false );
		Functions\when( 'add_query_arg' )->alias( static fn( string $key, int $value, string $url ): string => $url . '?' . $key . '=' . $value );
		Functions\when( 'get_the_terms' )->alias(
			function ( int $id, string $taxonomy ): array|false {
				$product = $this->products[ $id ] ?? null;

				if ( null === $product ) {
					return false;
				}

				$slugs = 'product_type' === $taxonomy ? array_filter( array( $product['type'] ) ) : $product['visibility'];

				return array() === $slugs ? false : array_map( static fn( string $slug ): object => (object) array( 'slug' => $slug ), $slugs );
			}
		);
	}

	/**
	 * Stores a published product.
	 *
	 * @param int                  $id         Product.
	 * @param string               $type       Product type slug.
	 * @param string[]             $visibility Visibility term slugs.
	 * @param array<string, mixed> $meta       Meta.
	 */
	private function product( int $id, string $type, array $visibility = array(), array $meta = array() ): void {
		$this->products[ $id ] = array(
			'post_type'  => 'product',
			'status'     => 'publish',
			'type'       => $type,
			'visibility' => array_values( $visibility ),
			'meta'       => $meta,
		);
	}

	private function collections( bool $active = true ): DesignCollections {
		return new DesignCollections( static fn(): bool => $active );
	}

	public function test_a_collection_is_known_by_its_type_also_without_designs(): void {
		$this->product( 30, DesignCollections::TYPE );

		self::assertTrue( $this->collections()->is_collection( self::COLLECTION ) );
		self::assertTrue( $this->collections()->is_collection( 30 ) );
		self::assertFalse( $this->collections()->is_collection( self::DESIGN ) );
		self::assertFalse( $this->collections()->is_collection( 0 ) );
		self::assertFalse( $this->collections()->is_collection( 999 ) );
	}

	public function test_a_collection_that_lost_its_type_still_counts(): void {
		foreach ( array( '', 'simple' ) as $type ) {
			$this->products[ self::COLLECTION ]['type'] = $type;

			self::assertTrue( $this->collections( false )->is_collection( self::COLLECTION ), $type );
		}
	}

	public function test_designs_left_over_on_another_type_make_no_collection(): void {
		$this->products[ self::COLLECTION ]['type'] = 'variable';

		self::assertFalse( $this->collections()->is_collection( self::COLLECTION ) );
		self::assertNull( $this->collections()->collection_of( self::DESIGN ) );
	}

	public function test_something_else_than_a_product_is_no_collection(): void {
		$this->products[ self::COLLECTION ]['post_type'] = 'page';

		self::assertFalse( $this->collections()->is_collection( self::COLLECTION ) );
	}

	public function test_designs_are_read_in_order_without_broken_rows(): void {
		$this->products[ self::COLLECTION ]['meta'][ DesignCollections::MEMBERS_KEY ] = array(
			array(
				'id'    => '22',
				'label' => 'Blue',
			),
			'broken',
			array( 'id' => 0 ),
			array( 'id' => self::COLLECTION ),
			array(
				'id'    => 22,
				'label' => 'Twice',
			),
			array(
				'id'    => 21,
				'label' => array( 'no text' ),
			),
		);

		self::assertSame(
			array(
				array(
					'id'    => 22,
					'label' => 'Blue',
				),
				array(
					'id'    => 21,
					'label' => '',
				),
			),
			$this->collections()->members( self::COLLECTION )
		);
		self::assertSame( array(), $this->collections()->members( self::DESIGN ) );
	}

	public function test_a_design_is_sold_from_the_published_collection_that_lists_it(): void {
		self::assertSame( self::COLLECTION, $this->collections()->collection_of( self::DESIGN ) );
	}

	public function test_an_unpublished_collection_sells_nothing(): void {
		foreach ( array( 'draft', 'private', 'trash' ) as $status ) {
			$this->products[ self::COLLECTION ]['status'] = $status;

			self::assertNull( $this->collections()->collection_of( self::DESIGN ), $status );
		}
	}

	public function test_a_copied_products_mark_is_not_enough(): void {
		$this->product( 40, 'variable', self::HIDDEN, $this->products[ self::DESIGN ]['meta'] );

		self::assertNull( $this->collections()->collection_of( 40 ) );
		self::assertFalse( $this->collections()->shown_in_channels( 40 ) );
	}

	public function test_a_design_hidden_by_its_collection_is_shown_in_the_channels(): void {
		self::assertTrue( $this->collections()->shown_in_channels( self::DESIGN ) );
		self::assertTrue( $this->collections( false )->shown_in_channels( self::DESIGN ) );
	}

	public function test_what_an_administrator_hid_stays_hidden(): void {
		$this->product( 50, 'simple', self::HIDDEN );
		$this->products[ self::DESIGN ]['meta'][ DesignCollections::PREVIOUS_VISIBILITY_KEY ] = 'hidden';
		unset( $this->products[ self::OTHER ]['meta'][ DesignCollections::PREVIOUS_VISIBILITY_KEY ] );

		self::assertFalse( $this->collections()->shown_in_channels( 50 ) );
		self::assertFalse( $this->collections()->shown_in_channels( self::DESIGN ) );
		self::assertFalse( $this->collections()->shown_in_channels( self::OTHER ) );
	}

	public function test_visible_products_are_shown_but_collections_and_drafts_are_not(): void {
		$this->product( 60, 'simple' );
		$this->product( 61, 'simple', array( 'exclude-from-search' ) );
		$this->product( 62, 'simple' );
		$this->products[62]['status'] = 'draft';

		self::assertTrue( $this->collections()->shown_in_channels( 60 ) );
		self::assertTrue( $this->collections()->shown_in_channels( 61 ) );
		self::assertFalse( $this->collections()->shown_in_channels( 62 ) );
		self::assertFalse( $this->collections()->shown_in_channels( self::COLLECTION ) );
	}

	public function test_only_visible_products_outside_collections_can_be_collected(): void {
		$this->product( 70, 'variable' );
		$this->product( 71, 'variable', self::HIDDEN );
		$this->product( 72, 'variable', array(), $this->products[ self::DESIGN ]['meta'] );
		$this->products[ self::DESIGN ]['visibility'] = array();

		self::assertTrue( $this->collections()->collectable( 70 ) );
		self::assertFalse( $this->collections()->collectable( 71 ) );
		self::assertTrue( $this->collections()->collectable( 72 ), 'A stale mark does not block.' );
		self::assertFalse( $this->collections()->collectable( self::DESIGN ) );
		self::assertFalse( $this->collections()->collectable( self::COLLECTION ) );
	}

	public function test_a_saved_selection_gets_a_collections_own_designs_in_its_place(): void {
		$this->product( 80, 'simple' );
		$owns = static fn( int $id ): bool => self::DESIGN === $id;

		self::assertSame( array( self::DESIGN, 80 ), $this->collections()->as_designs( array( self::COLLECTION, 80, self::DESIGN ), $owns ), 'Never another creator' . chr( 39 ) . 's design, and each once.' );
		self::assertSame( array( 80 ), $this->collections()->as_designs( array( self::COLLECTION, 80 ) ), 'A discount leaves the collection out.' );
	}

	public function test_a_collected_design_is_bought_on_the_collections_page(): void {
		self::assertSame( 'https://shop.test/product/10/?design=21', $this->collections()->design_url( self::DESIGN ) );
	}

	public function test_without_plugin_or_collection_a_product_is_bought_on_its_own_page(): void {
		self::assertSame( 'https://shop.test/product/21/', $this->collections( false )->design_url( self::DESIGN ) );

		$this->products[ self::COLLECTION ]['status'] = 'draft';

		self::assertSame( 'https://shop.test/product/21/', $this->collections()->design_url( self::DESIGN ) );
		self::assertSame( '', $this->collections()->design_url( 999 ) );
	}
}
