<?php
/**
 * Tests for the optional username field in the WooCommerce checkout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use Brain\Monkey\Functions;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Checkout\CheckoutUsername;
use MerchBoard\Modules\Alerts\Checkout\CheckoutUsernameField;
use MerchBoard\Modules\Alerts\WooCommercePaidOrderReader;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\WcOrderStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WC_Cart;
use WC_Order;
use WP_Error;

/**
 * Step 25.9.1 (ADR-065 pkt. 9): a checkbox (whose text says the name is
 * shown publicly) shows the field, only while alerts and the field are
 * switched on and the cart holds a creator's product, in the classic and the
 * block checkout; the username is used only with the box ticked, cleaned,
 * limited and saved as order meta through WooCommerce CRUD.
 */
#[CoversClass( CheckoutUsernameField::class )]
#[CoversClass( CheckoutUsername::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CheckoutUsernameFieldTest extends TestCase {

	/**
	 * Product 5 is a creator's, every other product is not.
	 */
	private const CREATOR_PRODUCT = 5;

	private InMemoryOptions $options;

	/**
	 * Options of the registered block checkout fields.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $registered = array();

	/**
	 * Arguments of the registered Store API endpoint data.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $endpoint_data = array();

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-order.php';
		require_once dirname( __DIR__, 3 ) . '/Fixtures/wp-rest.php';
		require_once dirname( __DIR__, 3 ) . '/Fixtures/wpdb.php';
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-coupon.php';
		WcOrderStorage::reset();
		$this->options = InMemoryOptions::install();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	public function test_the_classic_checkbox_and_field_are_shown_only_when_on_and_with_a_creators_product(): void {
		$field  = self::field();
		$fields = array( 'order' => array( 'order_comments' => array( 'type' => 'textarea' ) ) );

		self::assertSame( $fields, $field->add_classic_field( $fields ), 'Off by default.' );

		$this->switch_on( false );
		self::assertSame( $fields, $field->add_classic_field( $fields ), 'Not while alerts are off.' );

		$this->switch_on();
		$this->cart( 9 );
		self::assertSame( $fields, $field->add_classic_field( $fields ), 'Not without a creator\'s product.' );

		$this->cart( 9, self::CREATOR_PRODUCT );
		$shown = self::group( $field->add_classic_field( $fields ) );
		self::assertSame( array( 'order_comments', CheckoutUsername::CLASSIC_SHOW_FIELD, CheckoutUsername::CLASSIC_FIELD ), array_keys( $shown ) );
		self::assertSame(
			array(
				'type'     => 'checkbox',
				'label'    => CheckoutUsername::default_help(),
				'required' => false,
				'class'    => array( 'form-row-wide' ),
				'priority' => 110,
			),
			$shown[ CheckoutUsername::CLASSIC_SHOW_FIELD ] ?? null
		);
		self::assertSame(
			array(
				'type'      => 'text',
				'label'     => 'Stream username',
				'required'  => false,
				'class'     => array( 'form-row-wide' ),
				'priority'  => 111,
				'maxlength' => 50,
			),
			$shown[ CheckoutUsername::CLASSIC_FIELD ] ?? null
		);

		$this->options->options[ Settings::OPTION ] = array(
			Settings::ALERTS_ENABLED            => true,
			Settings::CHECKOUT_USERNAME_ENABLED => true,
			Settings::CHECKOUT_USERNAME_LABEL   => 'Twitch username',
			Settings::CHECKOUT_USERNAME_HELP    => 'Show my name on stream.',
		);
		$custom                                     = self::group( self::field()->add_classic_field( $fields ) );
		self::assertSame( 'Show my name on stream.', self::label( $custom, CheckoutUsername::CLASSIC_SHOW_FIELD ) );
		self::assertSame( 'Twitch username', self::label( $custom, CheckoutUsername::CLASSIC_FIELD ) );
	}

	public function test_the_cart_without_a_creators_product_is_told_apart_and_no_cart_hides_nothing(): void {
		$field = self::field();
		self::assertTrue( $field->cart_has_creator_products(), 'No cart to look at: nothing is hidden.' );

		$this->cart();
		self::assertFalse( $field->cart_has_creator_products() );
		$this->cart( 9, 11 );
		self::assertFalse( $field->cart_has_creator_products() );
		$this->cart( 9, self::CREATOR_PRODUCT );
		self::assertTrue( $field->cart_has_creator_products() );
	}

	public function test_the_classic_username_is_used_only_with_the_box_ticked_cleaned_validated_and_saved_through_crud(): void {
		$this->switch_on();
		$field = self::field();
		WcOrderStorage::add_order( 7, 'pending' );
		$order = wc_get_order( 7 );
		self::assertInstanceOf( WC_Order::class, $order );

		$field->save_classic_field( $order, array( CheckoutUsername::CLASSIC_FIELD => 'Nova' ) );
		self::assertSame( '', $order->get_meta( WooCommercePaidOrderReader::USERNAME_META ), 'Nothing without the box ticked.' );

		$field->save_classic_field( $order, self::ticked_with( " <b>Nova</b>\n\t Fan " ) );
		self::assertSame( 'Nova Fan', $order->get_meta( WooCommercePaidOrderReader::USERNAME_META ) );

		WcOrderStorage::add_order( 8, 'pending' );
		$empty = wc_get_order( 8 );
		self::assertInstanceOf( WC_Order::class, $empty );
		$field->save_classic_field( $empty, self::ticked_with( '   ' ) );
		$field->save_classic_field( $empty, self::ticked_with( str_repeat( 'x', 51 ) ) );
		self::assertSame( '', $empty->get_meta( WooCommercePaidOrderReader::USERNAME_META ), 'Nothing for an empty or too long name.' );

		$errors = new WP_Error();
		$field->validate_classic_field( array( CheckoutUsername::CLASSIC_FIELD => str_repeat( 'x', 99 ) ), $errors );
		self::assertSame( '', $errors->get_error_code(), 'Not checked without the box ticked.' );
		$field->validate_classic_field( self::ticked_with( str_repeat( 'x', 50 ) ), $errors );
		self::assertSame( '', $errors->get_error_code(), '50 characters are allowed.' );
		$field->validate_classic_field( self::ticked_with( str_repeat( 'æ', 51 ) ), $errors );
		self::assertSame( 'merchboard_alert_username', $errors->get_error_code(), 'The customer sees an error.' );

		$this->switch_on( false );
		$off = new WP_Error();
		$field->validate_classic_field( self::ticked_with( str_repeat( 'x', 99 ) ), $off );
		self::assertSame( '', $off->get_error_code(), 'Nothing while the field is off.' );
	}

	public function test_the_block_checkbox_and_field_are_registered_with_their_visibility_rules(): void {
		Functions\when( 'woocommerce_register_additional_checkout_field' )->alias(
			function ( array $options ): void {
				$this->registered[] = $options;
			}
		);
		$field = self::field();

		$field->register_block_fields();
		self::assertSame( array(), $this->registered, 'Not while the field is off.' );

		$this->switch_on();
		$field->register_block_fields();
		self::assertCount( 2, $this->registered );
		$checkbox = $this->registered[0] ?? array();
		$text     = $this->registered[1] ?? array();

		self::assertSame(
			array( 'merchboard/alert-username-show', CheckoutUsername::default_help(), 'order', 'checkbox', false ),
			array( $checkbox['id'] ?? null, $checkbox['label'] ?? null, $checkbox['location'] ?? null, $checkbox['type'] ?? null, $checkbox['required'] ?? null )
		);
		$no_creator = $checkbox['hidden'] ?? null;
		self::assertIsArray( $no_creator );
		self::assertSame( array( 'const' => false ), $no_creator['properties']['cart']['properties']['extensions']['properties']['merchboard']['properties']['has_creator_products'] ?? null );
		self::assertSame( array( 'has_creator_products' ), $no_creator['properties']['cart']['properties']['extensions']['properties']['merchboard']['required'] ?? null, 'Hidden only when the cart data says so.' );

		self::assertSame(
			array( 'merchboard/alert-username', 'Stream username', 'order', 'text', false, array( 'maxLength' => 50 ) ),
			array( $text['id'] ?? null, $text['label'] ?? null, $text['location'] ?? null, $text['type'] ?? null, $text['required'] ?? null, $text['attributes'] ?? null )
		);
		$hidden = $text['hidden']['anyOf'] ?? null;
		self::assertIsArray( $hidden );
		self::assertSame( $no_creator, $hidden[0] ?? null );
		self::assertSame(
			array( 'not' => array( 'enum' => array( true, '1', 1 ) ) ),
			$hidden[1]['properties']['checkout']['properties']['additional_fields']['properties']['merchboard/alert-username-show'] ?? null,
			'Hidden until the box is ticked.'
		);

		$sanitize = $text['sanitize_callback'] ?? null;
		$validate = $text['validate_callback'] ?? null;
		self::assertIsCallable( $sanitize );
		self::assertIsCallable( $validate );
		self::assertSame( 'Nova Fan', $sanitize( "<i>Nova</i>\n Fan" ) );
		self::assertTrue( $validate( 'Nova' ) );
		self::assertInstanceOf( WP_Error::class, $validate( str_repeat( 'x', 51 ) ) );
	}

	public function test_the_cart_data_tells_the_block_checkout_whether_a_creators_product_is_in_it(): void {
		Functions\when( 'woocommerce_store_api_register_endpoint_data' )->alias(
			function ( array $args ): void {
				$this->endpoint_data[] = $args;
			}
		);
		$field = self::field();
		$field->register_cart_data();
		self::assertCount( 1, $this->endpoint_data );
		$args = $this->endpoint_data[0] ?? array();
		self::assertSame( array( 'cart', 'merchboard', ARRAY_A ), array( $args['endpoint'] ?? null, $args['namespace'] ?? null, $args['schema_type'] ?? null ) );

		$data   = $args['data_callback'] ?? null;
		$schema = $args['schema_callback'] ?? null;
		self::assertIsCallable( $data );
		self::assertIsCallable( $schema );
		$this->cart( 9 );
		self::assertSame( array( 'has_creator_products' => false ), $data() );
		$this->cart( self::CREATOR_PRODUCT );
		self::assertSame( array( 'has_creator_products' => true ), $data() );
		self::assertSame( array( 'has_creator_products' ), array_keys( (array) $schema() ) );
	}

	public function test_the_cart_data_is_registered_at_boot_when_woocommerce_blocks_already_loaded(): void {
		Functions\when( 'woocommerce_store_api_register_endpoint_data' )->alias(
			function ( array $args ): void {
				$this->endpoint_data[] = $args;
			}
		);

		self::field()->register();
		self::assertSame( array(), $this->endpoint_data, 'Waits for woocommerce_blocks_loaded.' );

		do_action( 'woocommerce_blocks_loaded' );
		self::field()->register();
		self::assertCount( 1, $this->endpoint_data, 'WooCommerce fires it before the modules boot.' );
	}

	/**
	 * The field with product 5 as the only creator's product.
	 */
	private static function field(): CheckoutUsernameField {
		return new CheckoutUsernameField( static fn( int $product_id ): ?int => self::CREATOR_PRODUCT === $product_id ? 3 : null );
	}

	/**
	 * A cart with these product ids, through WC().
	 *
	 * @param int ...$product_ids Product ids.
	 */
	private function cart( int ...$product_ids ): void {
		$items = array();

		foreach ( $product_ids as $index => $product_id ) {
			$items[ 'key' . $index ] = array( 'product_id' => $product_id );
		}

		$cart                = new WC_Cart();
		$cart->cart_contents = $items;

		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
	}

	/**
	 * Posted checkout data with the box ticked and this username.
	 *
	 * @param string $username Username.
	 * @return array<string, string>
	 */
	private static function ticked_with( string $username ): array {
		return array(
			CheckoutUsername::CLASSIC_SHOW_FIELD => '1',
			CheckoutUsername::CLASSIC_FIELD      => $username,
		);
	}

	/**
	 * The label of a field in a group.
	 *
	 * @param array<mixed> $group Fields.
	 * @param string       $key   Field key.
	 */
	private static function label( array $group, string $key ): mixed {
		$field = $group[ $key ] ?? null;

		return is_array( $field ) ? ( $field['label'] ?? null ) : null;
	}

	/**
	 * The "order" group of a filtered checkout fields array.
	 *
	 * @param mixed $fields Fields by group.
	 * @return array<mixed>
	 */
	private static function group( mixed $fields ): array {
		$order = is_array( $fields ) ? ( $fields['order'] ?? null ) : null;

		return is_array( $order ) ? $order : array();
	}

	/**
	 * Switches alerts and the field on (or alerts off).
	 *
	 * @param bool $alerts Whether alerts are on.
	 */
	private function switch_on( bool $alerts = true ): void {
		$this->options->options[ Settings::OPTION ] = array(
			Settings::ALERTS_ENABLED            => $alerts,
			Settings::CHECKOUT_USERNAME_ENABLED => true,
		);
	}
}
