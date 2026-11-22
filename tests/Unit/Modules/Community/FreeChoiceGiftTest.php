<?php
/**
 * Tests for a winner's side of free choice: the code and the claim.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use MerchBoard\Modules\Community\Gifting\FreeChoiceDesigns;
use MerchBoard\Modules\Community\Gifting\FreeChoiceLock;
use MerchBoard\Modules\Community\Gifting\GiftClaim;
use MerchBoard\Modules\Community\Gifting\GiftCodes;
use MerchBoard\Modules\Community\Gifting\GiftRepository;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionClass;

#[CoversClass( GiftCodes::class )]
#[CoversClass( GiftClaim::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class FreeChoiceGiftTest extends TestCase {

	private const COUPON = 900;

	private const COLLECTION = 10;

	private const TOKEN = '0123456789abcdef0123456789abcdef';

	/**
	 * What the winner was told.
	 *
	 * @var list<string>
	 */
	private array $told = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.test/' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://shop.test/claim' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );
		Functions\when( 'wc_add_notice' )->alias(
			function ( string $text ): void {
				$this->told[] = $text;
			}
		);
	}

	/**
	 * A repository with one claimed giveaway link, booked on design 21.
	 *
	 * @param FreeChoiceLock|null $lock Its lock.
	 */
	private function gifts( ?FreeChoiceLock $lock ): GiftRepository {
		$wpdb       = new RecordingWpdb();
		$wpdb->rows = array_fill(
			0,
			4,
			array(
				'id'          => 5,
				'creator_id'  => 7,
				'product_id'  => 21,
				'unit_price'  => '200.0000',
				'status'      => 'claimed',
				'coupon_id'   => self::COUPON,
				'giveaway_id' => 3,
				'free_choice' => $lock?->to_json(),
			)
		);

		return new GiftRepository( $wpdb, ( new ReflectionClass( Transaction::class ) )->newInstanceWithoutConstructor() );
	}

	private static function lock(): FreeChoiceLock {
		$design = array(
			'creator'  => 7,
			'variants' => array(
				array(
					'attributes' => array(),
					'price'      => 2000000,
					'base'       => 1500000,
				),
			),
		);

		return new FreeChoiceLock( self::COLLECTION, 'Nova <Tee>', array( 21 => $design, 22 => $design, 23 => $design ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
	}

	/**
	 * Which products the gift's code is for.
	 *
	 * @param FreeChoiceLock|null    $lock    The gift's lock.
	 * @param FreeChoiceDesigns|null $designs The rule.
	 * @return list<int>
	 */
	private function valid( ?FreeChoiceLock $lock, ?FreeChoiceDesigns $designs ): array {
		$coupon = Mockery::mock( 'WC_Coupon' );
		$coupon->allows(
			array(
				'is_type'  => true,
				'get_id'   => self::COUPON,
				'get_meta' => '5',
			)
		);
		$codes = new GiftCodes( $this->gifts( $lock ), new RecordingLogger(), $designs );
		$valid = array();

		foreach ( array( 10, 21, 22, 23, 99 ) as $id ) {
			$product = Mockery::mock( 'WC_Product' );
			// A variation: the code is for its parent.
			$product->allows(
				array(
					'get_id'        => $id * 100,
					'get_parent_id' => $id,
				)
			);

			if ( $codes->valid_for_product( true, $product, $coupon ) ) {
				$valid[] = $id;
			}
		}

		return $valid;
	}

	public function test_a_code_with_free_choice_is_for_the_designs_the_winner_may_choose(): void {
		$designs = $this->createMock( FreeChoiceDesigns::class );
		$designs->expects( self::once() )->method( 'allowed' )->willReturn( array( 21, 23 ) );

		self::assertSame( array( 21, 23 ), $this->valid( self::lock(), $designs ), 'Never the collection, another product or a design with a new price.' );
	}

	public function test_a_code_without_free_choice_is_for_its_one_product(): void {
		$designs = $this->createMock( FreeChoiceDesigns::class );
		$designs->expects( self::never() )->method( 'allowed' );

		self::assertSame( array( 21 ), $this->valid( null, $designs ) );
	}

	public function test_without_the_rule_a_lock_widens_nothing(): void {
		self::assertSame( array( 21 ), $this->valid( self::lock(), null ) );
	}

	/**
	 * Where the winner is sent.
	 *
	 * @phpstan-param list<int> $to_choose
	 *
	 * @param bool  $page      Whether the collection's page is there.
	 * @param int[] $to_choose The designs left to open.
	 */
	private function redeem( bool $page, array $to_choose ): string {
		$designs = $this->createStub( FreeChoiceDesigns::class );
		$designs->method( 'has_page' )->willReturn( $page );
		$designs->method( 'to_choose' )->willReturn( $to_choose );

		$codes = $this->createStub( GiftCodes::class );
		$codes->method( 'code' )->willReturn( 'gave-abc' );

		$claim = new GiftClaim(
			$this->gifts( self::lock() ),
			$codes,
			( new ReflectionClass( TwitchCustomerSession::class ) )->newInstanceWithoutConstructor(),
			static fn( int $id ): array => array(
				'name'  => 'Design <' . $id . '>',
				'url'   => 'https://shop.test/p/' . $id,
				'image' => '',
			),
			static function (): void {},
			new RecordingLogger(),
			$designs
		);

		return $claim->redeem( self::TOKEN );
	}

	public function test_the_winner_is_sent_to_the_collection_and_hears_what_to_choose_between(): void {
		self::assertSame( 'https://shop.test/p/10', $this->redeem( true, array( 21, 23 ) ) );

		$told = implode( '', $this->told );

		self::assertStringContainsString( 'gave-abc', $told );
		self::assertStringContainsString( 'Nova &lt;Tee&gt;', $told );
		self::assertStringContainsString( '<a href="https://shop.test/p/21">Design &lt;21&gt;</a>', $told );
		self::assertStringContainsString( 'https://shop.test/p/23', $told );
		self::assertStringNotContainsString( 'https://shop.test/p/22', $told );
	}

	public function test_without_the_collections_page_the_winner_is_sent_to_a_design_that_counts(): void {
		self::assertSame( 'https://shop.test/p/23', $this->redeem( false, array( 23 ) ) );
	}

	public function test_with_nothing_left_to_open_the_winner_still_gets_the_code(): void {
		self::assertSame( 'https://shop.test/p/21', $this->redeem( false, array() ) );
		self::assertStringContainsString( 'gave-abc', implode( '', $this->told ) );
	}
}
