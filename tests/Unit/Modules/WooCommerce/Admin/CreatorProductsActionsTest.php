<?php
/**
 * Tests for the product link requests on the creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Admin;

use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsActions;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\WooCommerce\ProductLinkSource;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreatorProductsActions::class )]
final class CreatorProductsActionsTest extends TestCase {

	use CreatorAdminEnvironment;

	private FakeProductStore $products;

	private RecordingLogger $logger;

	private CreatorProductsActions $actions;

	/**
	 * Product ids the current user may edit (edit_product).
	 *
	 * @var list<int>
	 */
	private array $editable = array( 10, 11, 12, 13, 20, 21, 30 );

	private int $alpha;

	private int $beta;

	private int $inactive;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();

		$this->alpha    = $this->service->create( 'Alpha', CreatorStatus::Active, null )->id;
		$this->beta     = $this->service->create( 'Beta', CreatorStatus::Active, null )->id;
		$this->inactive = $this->service->create( 'Resting', CreatorStatus::Inactive, null )->id;

		// merchboard_manage_creators plus edit_product for the editable products.
		Functions\when( 'current_user_can' )->alias(
			function ( string $capability, ...$args ): bool {
				if ( Capabilities::EDIT_PRODUCT === $capability ) {
					return in_array( $args[0] ?? null, $this->editable, true );
				}

				return in_array( $capability, $this->caps, true );
			}
		);

		$this->products = new FakeProductStore();
		$this->products->add( 10 );
		$this->products->add( 11 );
		$this->products->add( 12 );
		$this->products->add_linked( 13, $this->beta );
		$this->products->add( 20 );
		$this->products->add( 21, array(), 'publish', 20 );
		$this->products->add( 30, array(), 'trash' );

		$this->logger  = new RecordingLogger();
		$this->actions = new CreatorProductsActions(
			new ProductCreatorLink(
				$this->products,
				new CreatorRepository( $this->db ),
				new AuditTrail( new AuditRepository( $this->db ) ),
				new Transaction( $this->db, new TransactionSupport( $this->db ) ),
				new ProtectedProductMeta( new RecordingLogger() )
			),
			$this->logger
		);

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce, string $action ): int|false => 'valid:' . $action === $nonce ? 1 : false );
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title = '', array $args = array() ): void {
				throw new WpDie( $message, (int) ( $args['response'] ?? 500 ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( strip_tags( $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'absint' )->alias( static fn( mixed $v ): int => abs( (int) $v ) );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( mixed ...$args ): string {
				[ $params, $url ] = $args;
				return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( $params );
			}
		);
	}

	public function test_selected_products_are_linked_through_the_service_with_audit(): void {
		$url = $this->actions->link( $this->link_form( array( 10, 11 ) ) );

		self::assertSame( array( '10:' . $this->alpha, '11:' . $this->alpha ), $this->products->writes );
		self::assertSame(
			array(
				'10' => 'linked',
				'11' => 'linked',
			),
			self::results( $url )
		);
		self::assertStringContainsString( 'action=edit&creator=' . $this->alpha, $url );
		self::assertStringEndsWith( '#merchboard-products', $url );

		$audit = $this->product_audit();
		self::assertCount( 2, $audit );
		self::assertSame( 'product_creator_linked', $audit[0]['action'] );
		self::assertSame( 'admin', $audit[0]['context']['source'] );
	}

	public function test_a_product_already_linked_to_this_creator_is_unchanged(): void {
		$this->products->add_linked( 10, $this->alpha );

		self::assertSame( array( '10' => 'unchanged' ), self::results( $this->actions->link( $this->link_form( array( 10 ) ) ) ) );
		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_a_product_of_another_creator_is_not_taken_over_without_explicit_choice(): void {
		$url = $this->actions->link( $this->link_form( array( 13 ) ) );

		self::assertSame( array( '13' => 'already_linked' ), self::results( $url ) );
		self::assertSame( $this->beta, ( new ProductCreatorLink( $this->products, new CreatorRepository( $this->db ), new AuditTrail( new AuditRepository( $this->db ) ), new Transaction( $this->db, new TransactionSupport( $this->db ) ), new ProtectedProductMeta( new RecordingLogger() ) ) )->creator_id( 13 ) );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_take_over_is_passed_as_replace_existing_for_exactly_that_product(): void {
		$this->products->add_linked( 12, $this->beta );

		// Take over ticked for 13 only; 12 belongs to Beta as well but is not taken over.
		$url = $this->actions->link( $this->link_form( array( 12, 13 ), array( 13 ) ) );

		self::assertSame(
			array(
				'12' => 'already_linked',
				'13' => 'replaced',
			),
			self::results( $url )
		);
		self::assertSame( array( '13:' . $this->alpha ), $this->products->writes );
		self::assertSame( array( 'product_creator_unlinked', 'product_creator_linked' ), array_column( $this->product_audit(), 'action' ) );
	}

	public function test_take_over_without_selecting_the_product_does_nothing(): void {
		$url = $this->actions->link( $this->link_form( array(), array( 13 ) ) );

		self::assertSame( array( '0' => 'no_selection' ), self::results( $url ) );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_service_rules_are_reported_per_product_without_hiding_failures(): void {
		$this->editable = array( 10, 20, 21, 30 );
		$this->products->add( 40 );

		$url = $this->actions->link( $this->link_form( array( 10, 21, 30, 40, 999 ) ) );

		self::assertSame(
			array(
				'10'  => 'linked',
				'21'  => 'variation',
				'30'  => 'product_not_supported',
				'40'  => 'not_authorized',
				'999' => 'product_not_found',
			),
			self::results( $url )
		);
		self::assertSame( array( '10:' . $this->alpha ), $this->products->writes );
	}

	public function test_an_inactive_creator_gets_no_new_products(): void {
		$url = $this->actions->link( $this->link_form( array( 10 ), array(), $this->inactive ) );

		self::assertSame( array( '10' => 'creator_inactive' ), self::results( $url ) );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_unlink_removes_the_link_through_the_service(): void {
		$this->products->add_linked( 10, $this->alpha );

		$url = $this->actions->unlink( $this->unlink_form( 10 ) );

		self::assertSame( array( '10' => 'unlinked' ), self::results( $url ) );
		self::assertSame( array( '10:null' ), $this->products->writes );
		self::assertSame( array( 'product_creator_unlinked' ), array_column( $this->product_audit(), 'action' ) );
		self::assertSame( 'admin', $this->product_audit()[0]['context']['source'] );
	}

	public function test_unlink_refuses_a_product_that_belongs_to_another_creator(): void {
		$url = $this->actions->unlink( $this->unlink_form( 13 ) );

		self::assertSame( array( '13' => 'linked_elsewhere' ), self::results( $url ) );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_unlink_repairs_an_invalid_link_shown_on_the_page(): void {
		$this->products->add( 10, array( array( 'key' => '_Merchboard_Creator_ID', 'value' => (string) $this->alpha ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( array( '10' => 'unlinked' ), self::results( $this->actions->unlink( $this->unlink_form( 10 ) ) ) );
	}

	public function test_without_edit_product_nothing_is_unlinked(): void {
		$this->products->add_linked( 11, $this->alpha );
		$this->editable = array();

		self::assertSame( array( '11' => 'not_authorized' ), self::results( $this->actions->unlink( $this->unlink_form( 11 ) ) ) );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_creator_management_capability_is_required(): void {
		$this->caps = array();

		foreach ( array( fn() => $this->actions->link( $this->link_form( array( 10 ) ) ), fn() => $this->actions->unlink( $this->unlink_form( 10 ) ) ) as $request ) {
			try {
				$request();
				self::fail( 'Expected WpDie.' );
			} catch ( WpDie $e ) {
				self::assertSame( 403, $e->response );
			}
		}

		self::assertSame( array(), $this->products->writes );
	}

	public function test_nonce_is_bound_to_the_creator(): void {
		$form             = $this->link_form( array( 10 ) );
		$form['_wpnonce'] = 'valid:' . CreatorProductsActions::link_nonce_action( $this->beta );

		$this->expectException( WpDie::class );

		$this->actions->link( $form );
	}

	public function test_ids_are_sanitized_and_limited(): void {
		$form             = $this->link_form( array() );
		$form['products'] = array_merge( array( '10', '10', 'x', '0', array( 12 ) ), range( 100, 200 ) );

		$this->actions->link( $form );

		self::assertSame( '10:' . $this->alpha, $this->products->writes[0] ?? null );
		self::assertCount( 1, $this->products->writes, 'Unknown ids are not written; duplicates and non-scalars are dropped.' );
		self::assertCount( CreatorProductsActions::MAX_PRODUCTS, CreatorProductsActions::parse_results( (string) ( self::query( $this->actions->link( $form ) )[ CreatorProductsActions::RESULTS_ARG ] ?? '' ) ) );
	}

	public function test_keep_as_manual_keeps_the_creator_and_changes_only_the_origin(): void {
		$this->products->add_linked( 11, $this->alpha, 'category', array( 16 ) );

		self::assertSame( array( '11' => 'kept_manual' ), self::results( $this->actions->keep_as_manual( $this->keep_form( 11 ) ) ) );

		$product = $this->products->find( 11 );
		self::assertNotNull( $product );
		self::assertSame( $this->alpha, $product->creator_id(), 'Creator kept.' );
		self::assertSame( array( array( 'key' => ProductCreatorLink::SOURCE_META_KEY, 'value' => 'admin' ) ), $product->source_entries ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 16 ), $product->category_ids, 'Categories untouched.' );

		$audit = $this->product_audit();
		self::assertCount( 1, $audit );
		self::assertSame( array( 'product_creator_linked', '7', (string) $this->alpha ), array( $audit[0]['action'], $audit[0]['actor_user_id'], $audit[0]['creator_id'] ) );
		self::assertSame(
			array(
				'product_id'          => 11,
				'previous_creator_id' => $this->alpha,
				'source'              => 'admin',
				'previous_source'     => 'category',
			),
			$audit[0]['context']
		);

		self::assertSame( array( '11' => 'unchanged' ), self::results( $this->actions->keep_as_manual( $this->keep_form( 11 ) ) ), 'Idempotent.' );
		self::assertCount( 1, $this->products->writes );
	}

	public function test_remove_of_a_category_managed_link_is_refused_server_side(): void {
		$this->products->add_linked( 11, $this->alpha, 'category', array( 16 ) );
		$before = array( $this->products->entries( 11 ), $this->products->sources( 11 ) );
		Functions\expect( 'as_enqueue_async_action' )->never();

		self::assertSame( array( '11' => 'category_managed' ), self::results( $this->actions->unlink( $this->unlink_form( 11 ) ) ) );

		// Whatever else the request carries, the origin stored on the product decides.
		$forged = $this->unlink_form( 11 ) + array(
			'source'  => 'admin',
			'choice'  => 'remove_links',
			'confirm' => '1',
			'replace' => array( '11' ),
			'force'   => '1',
		);
		self::assertSame( array( '11' => 'category_managed' ), self::results( $this->actions->unlink( $forged ) ) );

		self::assertSame( $before, array( $this->products->entries( 11 ), $this->products->sources( 11 ) ), 'Creator id and origin unchanged.' );
		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_keep_as_manual_then_remove_works(): void {
		$this->products->add_linked( 11, $this->alpha, 'category' );

		self::assertSame( array( '11' => 'kept_manual' ), self::results( $this->actions->keep_as_manual( $this->keep_form( 11 ) ) ) );
		self::assertSame( array( '11' => 'unlinked' ), self::results( $this->actions->unlink( $this->unlink_form( 11 ) ) ) );

		self::assertFalse( $this->products->find( 11 )?->has_link() );
		self::assertSame( array( 'product_creator_linked', 'product_creator_unlinked' ), array_column( $this->product_audit(), 'action' ) );
	}

	public function test_keep_as_manual_needs_the_explicit_confirmation(): void {
		$this->products->add_linked( 11, $this->alpha, 'category' );

		foreach ( array( '', '0', 'yes' ) as $confirm ) {
			$form            = $this->keep_form( 11 );
			$form['confirm'] = $confirm;
			self::assertSame( array( '11' => 'confirmation_required' ), self::results( $this->actions->keep_as_manual( $form ) ) );
		}

		$form = $this->keep_form( 11 );
		unset( $form['confirm'] );
		self::assertSame( array( '11' => 'confirmation_required' ), self::results( $this->actions->keep_as_manual( $form ) ) );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_keep_as_manual_refuses_manual_foreign_and_unknown_links(): void {
		$this->products->add_linked( 11, $this->alpha, 'admin' );
		$this->products->add_linked( 12, $this->alpha );
		$this->products->add_linked( 13, $this->beta, 'category' ); // Replaces fixture 13 (beta, manual).

		self::assertSame( array( '11' => 'unchanged' ), self::results( $this->actions->keep_as_manual( $this->keep_form( 11 ) ) ), 'Already manual.' );
		self::assertSame( array( '12' => 'unchanged' ), self::results( $this->actions->keep_as_manual( $this->keep_form( 12 ) ) ), 'No origin counts as manual.' );
		self::assertSame( array( '13' => 'linked_elsewhere' ), self::results( $this->actions->keep_as_manual( $this->keep_form( 13 ) ) ) );
		self::assertSame( array( '10' => 'linked_elsewhere' ), self::results( $this->actions->keep_as_manual( $this->keep_form( 10 ) ) ), 'Not linked at all.' );
		self::assertSame( array( '0' => 'no_selection' ), self::results( $this->actions->keep_as_manual( $this->keep_form( 0 ) ) ) );
		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_keep_as_manual_needs_edit_product_and_reports_failures_without_success(): void {
		$this->products->add_linked( 11, $this->alpha, 'category' );
		$this->editable = array();

		self::assertSame( array( '11' => 'not_authorized' ), self::results( $this->actions->keep_as_manual( $this->keep_form( 11 ) ) ) );

		$this->editable             = array( 11 );
		$this->products->fail_write = true;
		self::assertSame( array( '11' => 'error' ), self::results( $this->actions->keep_as_manual( $this->keep_form( 11 ) ) ) );
		self::assertTrue( $this->products->find( 11 )?->is_category_managed(), 'Nothing half-written.' );
		self::assertSame( array(), $this->product_audit(), 'Audit rolled back.' );
		self::assertSame( 'Product link administration failed.', $this->logger->at( 'error' )[0]['message'] );
	}

	public function test_keep_as_manual_needs_the_capability_and_a_nonce_for_this_creator(): void {
		$this->products->add_linked( 11, $this->alpha, 'category' );

		$form             = $this->keep_form( 11 );
		$form['_wpnonce'] = 'valid:' . CreatorProductsActions::keep_nonce_action( $this->beta );
		$this->expect_forbidden( fn() => $this->actions->keep_as_manual( $form ) );

		$form['_wpnonce'] = 'valid:' . CreatorProductsActions::unlink_nonce_action( $this->alpha );
		$this->expect_forbidden( fn() => $this->actions->keep_as_manual( $form ) );

		$this->caps = array( Capabilities::ACCESS_DASHBOARD ); // A creator: no self-service.
		$this->expect_forbidden( fn() => $this->actions->keep_as_manual( $this->keep_form( 11 ) ) );

		self::assertSame( array(), $this->products->writes );
	}

	public function test_results_are_parsed_strictly(): void {
		self::assertSame(
			array(
				10 => 'linked',
				0  => 'no_selection',
			),
			CreatorProductsActions::parse_results( '10:linked,<b>:x,0:no_selection,11:Linked,12:' )
		);
	}

	public function test_the_search_term_is_kept_for_the_page(): void {
		$form                   = $this->link_form( array( 10 ) );
		$form['product_search'] = '<b>Tee</b>';

		self::assertSame( 'Tee', self::query( $this->actions->link( $form ) )['product_search'] ?? null );
	}

	public function test_the_admin_source_is_the_only_one_besides_backfill(): void {
		self::assertSame( array( 'admin', 'category', 'designer' ), array_map( static fn( ProductLinkSource $s ): string => $s->value, ProductLinkSource::cases() ) );
	}

	/**
	 * A valid link request.
	 *
	 * @param int[]    $products   Selected product ids.
	 * @param int[]    $replace    Product ids with "take over" ticked.
	 * @param int|null $creator_id Creator (default Alpha).
	 * @return array<string, mixed>
	 */
	private function link_form( array $products, array $replace = array(), ?int $creator_id = null ): array {
		$creator_id ??= $this->alpha;

		return array(
			'creator_id' => (string) $creator_id,
			'_wpnonce'   => 'valid:' . CreatorProductsActions::link_nonce_action( $creator_id ),
			'products'   => array_map( 'strval', $products ),
			'replace'    => array_map( 'strval', $replace ),
		);
	}

	/**
	 * A valid unlink request for Alpha.
	 *
	 * @param int $product_id Product id.
	 * @return array<string, string>
	 */
	private function unlink_form( int $product_id ): array {
		return array(
			'creator_id' => (string) $this->alpha,
			'_wpnonce'   => 'valid:' . CreatorProductsActions::unlink_nonce_action( $this->alpha ),
			'product_id' => (string) $product_id,
		);
	}

	/**
	 * A confirmed "Keep as manual" request for Alpha.
	 *
	 * @param int $product_id Product id.
	 * @return array<string, string>
	 */
	private function keep_form( int $product_id ): array {
		return array(
			'creator_id' => (string) $this->alpha,
			'_wpnonce'   => 'valid:' . CreatorProductsActions::keep_nonce_action( $this->alpha ),
			'product_id' => (string) $product_id,
			'confirm'    => '1',
		);
	}

	/**
	 * Asserts that the request stops with 403.
	 *
	 * @param callable $request Request.
	 */
	private function expect_forbidden( callable $request ): void {
		try {
			$request();
			self::fail( 'Expected WpDie.' );
		} catch ( WpDie $e ) {
			self::assertSame( 403, $e->response );
		}
	}

	/**
	 * Per-product outcomes of a redirect URL.
	 *
	 * @param string $url Redirect URL.
	 * @return array<int, string> Product id (0 = request) => outcome code.
	 */
	private static function results( string $url ): array {
		$value = self::query( $url )[ CreatorProductsActions::RESULTS_ARG ] ?? '';

		return CreatorProductsActions::parse_results( is_string( $value ) ? $value : '' );
	}

	/**
	 * Query arguments of a URL.
	 *
	 * @param string $url URL.
	 * @return array<mixed>
	 */
	private static function query( string $url ): array {
		$without_fragment = explode( '#', $url, 2 )[0];
		parse_str( (string) parse_url( $without_fragment, PHP_URL_QUERY ), $query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test helper.

		return $query;
	}

	/**
	 * Audit records of product links.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function product_audit(): array {
		return array_values( array_filter( $this->audit_rows(), static fn( array $row ): bool => str_starts_with( (string) $row['action'], 'product_' ) ) );
	}
}
