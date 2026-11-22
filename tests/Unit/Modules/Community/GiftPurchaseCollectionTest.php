<?php
/**
 * Tests for buying a gift on a collection's page, also with free choice.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Community\Gifting\FreeChoiceDesigns;
use MerchBoard\Modules\Community\Gifting\FreeChoiceLock;
use MerchBoard\Modules\Community\Gifting\GiftingSettings;
use MerchBoard\Modules\Community\Gifting\GiftingSettingsRepository;
use MerchBoard\Modules\Community\Gifting\GiftPurchase;
use MerchBoard\Modules\Community\Gifting\GiftRepository;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionClass;
use RuntimeException;
use wpdb;

#[CoversClass( GiftPurchase::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class GiftPurchaseCollectionTest extends TestCase {

	private const COLLECTION = 10;

	private const CREATOR = 7;

	private wpdb $wpdb;

	/**
	 * Notices shown.
	 *
	 * @var list<string>
	 */
	private array $notices = array();

	/**
	 * Lines added to the cart.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $added = array();

	/**
	 * The cart's items.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $items = array();

	/**
	 * Whether the collection can be given with free choice now.
	 *
	 * @var bool
	 */
	private bool $offered = true;

	private bool $live = true;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb = new RecordingWpdb();

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_option' )->justReturn( array( Settings::COMMUNITY_TWITCH_GIFTING_ENABLED => true ) );
		Functions\when( 'get_post_field' )->justReturn( 'Nova Tee' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.test/' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.test/checkout/' );
		Functions\when( 'absint' )->alias( static fn( mixed $value ): int => abs( (int) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'good' );
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce ): int|false => 'good' === $nonce ? 1 : false );
		Functions\when( 'wp_parse_str' )->justReturn( null );
		Functions\when( 'wp_parse_url' )->justReturn( '' );
		Functions\when( 'wc_add_notice' )->alias(
			function ( string $text ): void {
				$this->notices[] = $text;
			}
		);
		// The redirect ends the request.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ): never {
				throw new RuntimeException( $url ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Never shown.
			}
		);
		Functions\when( 'wc_get_product' )->alias( fn( int $id ): object => $this->product( $id ) );

		$cart = Mockery::mock( 'WC_Cart' );
		$cart->allows( 'get_cart' )->andReturnUsing( fn(): array => $this->items );
		$cart->allows( 'is_empty' )->andReturnUsing( fn(): bool => array() === $this->items );
		$cart->allows( 'add_to_cart' )->andReturnUsing(
			function ( mixed ...$line ): string {
				$this->added[] = array_values( $line );

				return 'key';
			}
		);
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
	}

	protected function tearDown(): void {
		$_GET = array(); // phpcs:ignore WordPress.Security.NonceVerification -- Reset.

		parent::tearDown();
	}

	private function product( int $id ): object {
		$product = Mockery::mock( 'WC_Product' );
		$product->allows(
			array(
				'get_id'         => $id,
				'get_parent_id'  => 0,
				'get_status'     => 'publish',
				'get_name'       => 'Design ' . $id,
				'is_purchasable' => true,
				'is_in_stock'    => true,
			)
		);

		return $product;
	}

	private static function lock(): FreeChoiceLock {
		$design = array(
			'creator'  => self::CREATOR,
			'variants' => array(
				array(
					'attributes' => array(),
					'price'      => 2000000,
					'base'       => 1500000,
				),
			),
		);

		return new FreeChoiceLock( self::COLLECTION, 'Nova Tee', array( 21 => $design, 22 => $design ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
	}

	private function purchase(): GiftPurchase {
		$collections = $this->createStub( DesignCollections::class );
		$collections->method( 'is_collection' )->willReturnCallback( static fn( int $id ): bool => self::COLLECTION === $id );
		$collections->method( 'design_url' )->willReturn( 'https://shop.test/p/' );
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
			)
		);

		$free = $this->createStub( FreeChoiceDesigns::class );
		$free->method( 'offer' )->willReturnCallback(
			fn( int $id ): ?array => $this->offered && self::COLLECTION === $id ? array(
				'lock'       => self::lock(),
				'creator'    => self::CREATOR,
				'product_id' => 22,
			) : null
		);

		$class    = new ReflectionClass( GiftingSettingsRepository::class );
		$settings = $class->newInstanceWithoutConstructor();
		$class->getProperty( 'cache' )->setValue( $settings, array( self::CREATOR => GiftingSettings::from_stored( array( 'enabled' => true ) ) ) );

		return new GiftPurchase(
			$settings,
			new GiftRepository( $this->wpdb, ( new ReflectionClass( Transaction::class ) )->newInstanceWithoutConstructor() ),
			// As the module: a collection has no creator.
			static fn( int $id ): ?int => in_array( $id, array( 21, 22 ), true ) ? self::CREATOR : null,
			fn(): bool => $this->live,
			static function (): void {},
			new RecordingLogger(),
			null,
			$collections,
			$free
		);
	}

	private function add( int $id, string $nonce = 'good' ): string {
		$_GET = array(
			'merchboard_gift'          => (string) $id,
			'merchboard_gift_quantity' => '3',
			'_wpnonce'                 => $nonce,
		);

		try {
			$this->purchase()->add();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	private function box(): string {
		$GLOBALS['product'] = $this->product( self::COLLECTION ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WooCommerce's global.

		ob_start();
		$this->purchase()->collection_box();

		return (string) ob_get_clean();
	}

	public function test_the_box_offers_free_choice_and_each_design_and_preselects_none(): void {
		$box = $this->box();

		self::assertStringContainsString( '<option value="10">Free choice: the winner chooses the design</option>', $box );
		self::assertStringContainsString( '<option value="21">Design 21</option>', $box );
		self::assertStringContainsString( '<option value="22">Design 22</option>', $box );
		self::assertStringNotContainsString( ' selected', $box );
	}

	public function test_with_other_prices_the_box_offers_only_the_designs(): void {
		$this->offered = false;

		$box = $this->box();

		// The number of gifts has an option 10 too.
		self::assertStringNotContainsString( 'Free choice', $box );
		self::assertStringContainsString( '<option value="21">', $box );
	}

	public function test_there_is_no_box_while_the_creator_is_not_live(): void {
		$this->live = false;

		self::assertSame( '', $this->box() );
	}

	public function test_free_choice_puts_the_design_it_is_booked_on_in_the_cart(): void {
		self::assertSame( 'https://shop.test/checkout/', $this->add( self::COLLECTION ) );
		self::assertSame( array( array( 22, 3, 0, array(), array( GiftPurchase::ITEM_KEY => true, GiftPurchase::FREE_KEY => self::COLLECTION ) ) ), $this->added ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact expectation.
	}

	public function test_a_chosen_design_is_that_design_alone(): void {
		$this->add( 21 );

		self::assertSame( array( array( 21, 3, 0, array(), array( GiftPurchase::ITEM_KEY => true ) ) ), $this->added );
	}

	public function test_a_collection_without_free_choice_is_never_put_in_the_cart(): void {
		$this->offered = false;

		$this->add( self::COLLECTION );

		self::assertSame( array(), $this->added );
		self::assertCount( 1, $this->notices );
	}

	public function test_a_bad_nonce_a_product_of_no_creator_and_a_creator_not_live_add_nothing(): void {
		$this->add( self::COLLECTION, 'bad' );
		$this->add( 99 );

		$this->live = false;

		$this->add( self::COLLECTION );

		self::assertSame( array(), $this->added );
		self::assertCount( 3, $this->notices );
	}

	/**
	 * A cart with the gift of a design with free choice.
	 *
	 * @param int $design The design in the cart.
	 * @return array<string, mixed> The cart item.
	 */
	private function in_cart( int $design ): array {
		$item = array(
			'quantity'             => 1,
			'data'                 => $this->product( $design ),
			GiftPurchase::ITEM_KEY => true,
			GiftPurchase::FREE_KEY => self::COLLECTION,
		);

		$this->items = array( 'key' => $item );

		return $item;
	}

	public function test_the_checkout_refuses_free_choice_that_no_longer_holds(): void {
		$this->in_cart( 22 );
		$this->purchase()->check_cart();

		self::assertSame( array(), $this->notices );

		$this->offered = false;
		$this->purchase()->check_cart();

		self::assertCount( 1, $this->notices );
	}

	/**
	 * What the checkout writes on the order line.
	 *
	 * @param int $design The line's product.
	 * @return array<string, mixed>
	 */
	private function line_meta( int $design ): array {
		$written = array();
		$line    = Mockery::mock( 'WC_Order_Item_Product' );
		$line->allows( 'get_product_id' )->andReturn( $design );
		$line->allows( 'update_meta_data' )->andReturnUsing(
			static function ( string $key, mixed $value ) use ( &$written ): void {
				$written[ $key ] = $value;
			}
		);

		$this->purchase()->line_meta( $line, 'key', $this->in_cart( $design ) );

		return $written;
	}

	public function test_the_checkout_locks_the_designs_and_prices_on_the_line(): void {
		$written = $this->line_meta( 22 );

		self::assertSame( '7', $written[ GiftPurchase::LINE_META ] );
		self::assertSame( 'Nova Tee', $written['Free choice'] );
		self::assertEquals( self::lock(), FreeChoiceLock::from_stored( $written[ GiftPurchase::FREE_META ] ) );
	}

	public function test_without_an_offer_the_line_is_a_gift_of_its_one_design(): void {
		$this->offered = false;

		self::assertSame( array( GiftPurchase::LINE_META => '7' ), $this->line_meta( 22 ) );
	}

	/**
	 * The lock stored with each gift of a paid order.
	 *
	 * @param int   $design The line's product.
	 * @param mixed $meta   The line's lock.
	 * @return list<array{product: mixed, price: mixed, lock: mixed}>
	 */
	private function paid( int $design, mixed $meta ): array {
		$line = Mockery::mock( 'WC_Order_Item_Product' );
		$line->allows(
			array(
				'get_product_id' => $design,
				'get_total'      => '320',
				'get_total_tax'  => '80',
				'get_quantity'   => 2,
			)
		);
		$line->allows( 'get_meta' )->andReturnUsing( static fn( string $key ): mixed => GiftPurchase::LINE_META === $key ? '7' : ( GiftPurchase::FREE_META === $key ? $meta : '' ) );

		$order = Mockery::mock( 'WC_Order' );
		$order->allows(
			array(
				'get_id'             => 501,
				'get_items'          => array( $line ),
				'get_shipping_total' => '80',
				'get_shipping_tax'   => '20',
				'get_currency'       => 'DKK',
				'get_meta'           => '',
			)
		);
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$stored = array();
		$wpdb   = Mockery::mock( wpdb::class );
		self::assertInstanceOf( wpdb::class, $wpdb );

		$wpdb->prefix = 'wp_';
		$wpdb->allows( 'prepare' )->andReturnUsing(
			static function ( string $query, mixed ...$args ) use ( &$stored ): string {
				$stored[] = array(
					'product' => $args[4],
					'price'   => $args[6],
					'lock'    => $args[5],
				);

				return $query;
			}
		);
		// As if each was stored already: only what would be inserted matters here.
		$wpdb->allows( 'query' )->andReturn( 0 );
		$this->wpdb = $wpdb;

		$this->purchase()->paid( 501 );

		return $stored;
	}

	public function test_each_paid_gift_is_booked_on_the_design_with_the_lock(): void {
		$gift = array(
			'product' => 22,
			'price'   => '200.0000',
			'lock'    => self::lock()->to_json(),
		);

		self::assertSame( array( $gift, $gift ), $this->paid( 22, self::lock()->to_array() ) );
	}

	public function test_a_lock_on_another_product_or_an_unreadable_one_widens_nothing(): void {
		self::assertSame( array( '', '' ), array_column( $this->paid( 99, self::lock()->to_array() ), 'lock' ) );
		self::assertSame( array( '', '' ), array_column( $this->paid( 22, 'broken' ), 'lock' ) );
	}
}
