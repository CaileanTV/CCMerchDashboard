<?php
/**
 * Tests for the collections' hooks, the tab's forms and the saving of a draft.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Collections;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Admin\CollectionRequestActions;
use MerchBoard\Modules\WooCommerce\Collections\CollectionCartGuard;
use MerchBoard\Modules\WooCommerce\Collections\CollectionFulfilment;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestException;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequests;
use MerchBoard\Modules\WooCommerce\Collections\CollectionWatcher;
use MerchBoard\Modules\WooCommerce\Collections\CollectionWriter;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use WC_Product;

#[CoversClass( CollectionCartGuard::class )]
#[CoversClass( CollectionWatcher::class )]
#[CoversClass( CollectionRequestActions::class )]
#[CoversClass( CollectionWriter::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CollectionShopTest extends TestCase {

	private const DRAFT = array(
		'name'       => 'Nova Tee',
		'members'    => array(
			array(
				'id'    => 21,
				'label' => 'Red',
			),
		),
		'categories' => array( 4 ),
		'tags'       => array( 5 ),
		'image'      => 6,
		'token'      => 'abc',
	);

	public function test_the_guard_is_in_the_forms_in_code_and_in_the_cart_check(): void {
		$guard = new CollectionCartGuard( $this->createStub( DesignCollections::class ) );

		$guard->register();

		self::assertSame( 5, has_filter( 'woocommerce_add_to_cart_validation', array( $guard, 'validate' ) ) );
		self::assertSame( 100, has_filter( 'woocommerce_add_to_cart_quantity', array( $guard, 'quantity' ) ) );
		self::assertNotFalse( has_action( 'woocommerce_check_cart_items', array( $guard, 'check_cart' ) ) );
	}

	public function test_the_watcher_sees_publishing_and_the_plugins_marks(): void {
		$watcher = ( new ReflectionClass( CollectionWatcher::class ) )->newInstanceWithoutConstructor();

		$watcher->register();

		self::assertSame( 50, has_action( 'transition_post_status', array( $watcher, 'status_changed' ) ) );
		self::assertNotFalse( has_action( 'added_post_meta', array( $watcher, 'meta_changed' ) ) );
		self::assertNotFalse( has_action( 'updated_post_meta', array( $watcher, 'meta_changed' ) ) );
	}

	public function test_the_tabs_forms_do_nothing_without_their_own_nonce(): void {
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( mixed $value ): int => abs( (int) $value ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce, string $action ): int|false => $nonce === $action ? 1 : false );
		Functions\when( 'wp_die' )->alias(
			static function ( string $text ): never {
				throw new RuntimeException( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Never shown.
			}
		);

		// Without their constructors: a step that ran would fail instead of dying on the nonce.
		$actions = new CollectionRequestActions(
			( new ReflectionClass( CollectionRequests::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( CollectionFulfilment::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( CollectionWatcher::class ) )->newInstanceWithoutConstructor(),
			new RecordingLogger()
		);
		$forms   = array(
			CollectionRequestActions::APPROVE,
			CollectionRequestActions::RETRY,
			CollectionRequestActions::ADD_NOW,
			CollectionRequestActions::CHECK,
			CollectionRequestActions::DECLINE,
			CollectionRequestActions::RELEASE,
			CollectionRequestActions::PARTLY,
		);

		foreach ( $forms as $action ) {
			$other = CollectionRequestActions::CHECK === $action ? CollectionRequestActions::DECLINE : CollectionRequestActions::CHECK;

			// None, another request's and another step's.
			foreach ( array( '', CollectionRequestActions::nonce_action( $action, 6 ), CollectionRequestActions::nonce_action( $other, 5 ) ) as $nonce ) {
				try {
					$actions->process(
						array(
							'action'     => $action,
							'request_id' => '5',
							'_wpnonce'   => $nonce,
						)
					);
					self::fail( 'The form was taken: ' . $action );
				} catch ( RuntimeException $died ) {
					self::assertStringContainsString( 'expired', $died->getMessage() );
				}
			}
		}
	}

	public function test_without_the_plugins_class_no_draft_is_saved(): void {
		$this->expectException( CollectionRequestException::class );

		( new ReflectionMethod( CollectionWriter::class, 'save_draft' ) )->invoke( null, self::DRAFT );
	}

	public function test_a_draft_is_saved_as_the_plugins_type_with_its_designs_and_without_a_designs_text(): void {
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-product.php';

		$class = new class() extends WC_Product {
			/**
			 * What was set on the product.
			 *
			 * @var array<string, mixed>
			 */
			public static array $set = array();

			/**
			 * An unsaved product.
			 */
			public function __construct() {
			}

			public function set_name( $name ) {
				self::$set['name'] = $name;
			}

			public function set_status( $status ) {
				self::$set['status'] = $status;
			}

			/**
			 * Records the meta.
			 *
			 * @param string $key     Key.
			 * @param mixed  $value   Value.
			 * @param int    $meta_id Meta id.
			 * @return void
			 */
			public function update_meta_data( $key, $value, $meta_id = 0 ) {
				self::$set[ $key ] = $value;
			}

			public function set_category_ids( $ids ) {
				self::$set['categories'] = $ids;
			}

			public function set_tag_ids( $ids ) {
				self::$set['tags'] = $ids;
			}

			public function set_image_id( $id = '' ) {
				self::$set['image'] = $id;
			}

			public function save() {
				self::$set['filtered'] = has_filter( 'woocommerce_new_product_data' );

				return 44;
			}
		};
		class_alias( $class::class, DesignCollections::PRODUCT_CLASS );

		self::assertSame( 44, ( new ReflectionMethod( CollectionWriter::class, 'save_draft' ) )->invoke( null, self::DRAFT ) );
		self::assertSame(
			array(
				'name'                         => 'Nova Tee',
				'status'                       => 'draft',
				DesignCollections::MEMBERS_KEY => self::DRAFT['members'],
				'categories'                   => array( 4 ),
				'tags'                         => array( 5 ),
				'image'                        => 6,
				'filtered'                     => true,
			),
			$class::$set
		);
		self::assertFalse( has_filter( 'woocommerce_new_product_data' ), 'No other product gets the draft\'s data.' );
	}
}
