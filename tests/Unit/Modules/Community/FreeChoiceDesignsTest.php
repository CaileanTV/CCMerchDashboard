<?php
/**
 * Tests for the collections given with free choice and their lock.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Community\Gifting\FreeChoiceDesigns;
use MerchBoard\Modules\Community\Gifting\FreeChoiceLock;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( FreeChoiceDesigns::class )]
#[CoversClass( FreeChoiceLock::class )]
final class FreeChoiceDesignsTest extends TestCase {

	private const COLLECTION = 10;

	/**
	 * What the prices' reader answers, by product.
	 *
	 * @var array<int, array{design: array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}, pays: int|null}>
	 */
	private array $read = array();

	/**
	 * Post statuses by id.
	 *
	 * @var array<int, string>
	 */
	private array $status = array();

	protected function setUp(): void {
		parent::setUp();

		$this->read   = array(
			21 => self::read_design( 200 ),
			22 => self::read_design( 200 ),
			23 => self::read_design( 200 ),
		);
		$this->status = array(
			self::COLLECTION => 'publish',
			21               => 'publish',
			22               => 'publish',
			23               => 'publish',
		);

		Functions\when( 'get_post_status' )->alias( fn( int $id ): string|false => $this->status[ $id ] ?? false );
		Functions\when( 'get_post_field' )->justReturn( 'Nova &amp; Tee' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * A design at one price.
	 *
	 * @param int      $price Customer price.
	 * @param int|null $pays  What its gift would pay.
	 * @return array{design: array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}, pays: int|null}
	 */
	private static function read_design( int $price, ?int $pays = 200 ): array {
		return array(
			'design' => array(
				'creator'  => 7,
				'variants' => array(
					array(
						'attributes' => array( 'size' => 'M' ),
						'price'      => $price,
						'base'       => 150,
					),
				),
			),
			'pays'   => $pays,
		);
	}

	private function designs(): FreeChoiceDesigns {
		$collections = $this->createStub( DesignCollections::class );
		$collections->method( 'is_collection' )->willReturnCallback( static fn( int $id ): bool => self::COLLECTION === $id );
		$collections->method( 'members' )->willReturn(
			array(
				array(
					'id'    => 21,
					'label' => '',
				),
				array(
					'id'    => 22,
					'label' => '',
				),
				array(
					'id'    => 23,
					'label' => '',
				),
			)
		);

		return new FreeChoiceDesigns( $collections, fn( int $id ): ?array => $this->read[ $id ] ?? null );
	}

	public function test_a_collection_that_costs_the_same_is_locked_with_its_designs(): void {
		$offer = $this->designs()->offer( self::COLLECTION );

		self::assertNotNull( $offer );
		self::assertSame( 21, $offer['product_id'], 'The first of equals.' );
		self::assertSame( 7, $offer['creator'] );
		self::assertSame( 'Nova & Tee', $offer['lock']->name );
		self::assertSame( array( 21, 22, 23 ), $offer['lock']->product_ids() );
	}

	public function test_the_purchase_is_booked_on_a_design_that_is_in_stock(): void {
		$this->read[21] = self::read_design( 200, null );

		self::assertSame( 22, $this->designs()->offer( self::COLLECTION )['product_id'] ?? null );
	}

	public function test_another_price_gives_no_offer(): void {
		$this->read[23] = self::read_design( 250 );

		self::assertNull( $this->designs()->offer( self::COLLECTION ) );
	}

	public function test_a_design_that_cannot_be_priced_gives_no_offer(): void {
		unset( $this->read[22] );

		self::assertNull( $this->designs()->offer( self::COLLECTION ) );
	}

	public function test_an_unpublished_design_is_not_on_the_page_and_does_not_count(): void {
		$this->status[23] = 'draft';

		$offer = $this->designs()->offer( self::COLLECTION );
		self::assertNotNull( $offer );

		self::assertSame( array( 21, 22 ), $offer['lock']->product_ids() );
	}

	public function test_an_unpublished_collection_or_another_product_gives_no_offer(): void {
		self::assertNull( $this->designs()->offer( 21 ) );

		$this->status[ self::COLLECTION ] = 'draft';

		self::assertNull( $this->designs()->offer( self::COLLECTION ) );
	}

	public function test_the_winner_chooses_among_those_still_sold_at_the_price(): void {
		$offer = $this->designs()->offer( self::COLLECTION );
		self::assertNotNull( $offer );

		$this->read[22]   = self::read_design( 260 );
		$this->status[23] = 'trash';

		self::assertSame( array( 21 ), $this->designs()->allowed( $offer['lock'] ) );
	}

	public function test_the_lock_is_stored_and_read_unchanged(): void {
		$offer = $this->designs()->offer( self::COLLECTION );
		self::assertNotNull( $offer );

		$read = FreeChoiceLock::from_stored( $offer['lock']->to_json() );

		self::assertNotNull( $read );
		self::assertSame( self::COLLECTION, $read->collection_id );
		self::assertSame( 'Nova & Tee', $read->name );
		self::assertSame( $offer['lock']->designs, $read->designs );
	}

	public function test_an_unreadable_lock_is_a_gift_of_one_product(): void {
		foreach ( array( null, '', '{', '[]', '{"collection":10,"name":"Tee","designs":[]}', '{"collection":10,"name":"Tee","designs":[{"id":21,"creator":7,"variants":[{"price":"200"}]}]}' ) as $stored ) {
			self::assertNull( FreeChoiceLock::from_stored( $stored ) );
		}
	}
}
