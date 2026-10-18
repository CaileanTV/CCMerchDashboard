<?php
/**
 * Admin pricing on the creator's edit page, on a real database (ADR-049).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CreatorPricingActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorPricingSection;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorPricingSettings;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatHistoryRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupSource;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use DateTimeImmutable;
use RuntimeException;
use WP_User;

/**
 * No WooCommerce is loaded (ADR-038 Q4): prices live in the real
 * `wp_postmeta` rows. The admin is user 1; `edit_product` is mapped like
 * WooCommerce' product post type does. Requests are handled by
 * CreatorPricingActions::handle() with real nonces; wp_die() throws.
 */
final class CreatorPricingAdminTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private MarkupResolver $resolver;

	private CreatorPricingActions $actions;

	private CreatorPricingSection $section;

	private SalesLinePricingRecorder $recorder;

	private int $alpha;

	private int $beta;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		wp_get_current_user()->get_role_caps();
		add_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10, 2 );
		add_filter( 'wp_die_handler', array( self::class, 'die_handler' ) );

		$creators    = new CreatorRepository( $this->wpdb );
		$this->alpha = $creators->create( 'Pricing Alpha', CreatorStatus::Active )->id;
		$this->beta  = $creators->create( 'Pricing Beta', CreatorStatus::Active )->id;

		$audit          = $this->service( AuditTrail::class );
		$transaction    = $this->service( Transaction::class );
		$store          = new PostMetaPricingProductStore();
		$this->posts    = new ProductPosts( $this->wpdb );
		$link_store     = new PostMetaProductStore();
		$links          = new ProductCreatorLink( $link_store, $creators, $audit, $transaction, $this->service( ProtectedProductMeta::class ) );
		$markups        = new CreatorMarkupRepository( $this->wpdb );
		$products       = new ProductMarkupRepository( $this->wpdb );
		$vat            = new CreatorVatRepository( $this->wpdb );
		$this->resolver = new MarkupResolver( $links, $markups, $products );
		$this->actions  = new CreatorPricingActions(
			new CreatorPricingSettings( $creators, $markups, $products, $links, $store, $audit, $transaction, $vat, new CreatorVatHistoryRepository( $this->wpdb ) ),
			new RecordingLogger()
		);
		$this->section  = new CreatorPricingSection( $link_store, $store, $this->resolver, $markups, $vat, new CreatorVatHistoryRepository( $this->wpdb ) );
		$this->recorder = new SalesLinePricingRecorder( $store, $this->resolver, new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), $vat );
	}

	protected function tearDown(): void {
		remove_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10 );
		remove_filter( 'wp_die_handler', array( self::class, 'die_handler' ) );
		$_GET = array();
		$this->posts->remove_all();
		parent::tearDown();
	}

	/**
	 * Maps `edit_product` like WooCommerce' product post type (without WooCommerce).
	 *
	 * @param mixed $caps Primitive capabilities.
	 * @param mixed $cap  Requested capability.
	 * @return mixed
	 */
	public static function product_caps( mixed $caps, mixed $cap ): mixed {
		return 'edit_product' === $cap ? array( 'edit_posts' ) : $caps;
	}

	/**
	 * A wp_die() handler that throws.
	 *
	 * @return callable
	 */
	public static function die_handler(): callable {
		return static function ( mixed $message ): never {
			throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
		};
	}

	public function test_users_without_the_pricing_capability_see_nothing_and_are_refused(): void {
		$product = $this->product( $this->alpha, '120' );
		$editor  = $this->create_user( 'mb-t22-editor' );
		( new WP_User( $editor ) )->set_role( 'editor' );
		wp_set_current_user( $editor );

		self::assertSame( '', $this->render( $this->alpha ) );
		$this->assert_dies( 'not allowed to manage creator pricing', $this->signed( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $product ) + array( 'product_markup' => '10' ) );

		// An administrator whose pricing capability is denied for the user.
		wp_set_current_user( 1 );
		$admin = wp_get_current_user();
		$admin->add_cap( Capabilities::MANAGE_CREATOR_PRICING, false );

		try {
			self::assertSame( '', $this->render( $this->alpha ) );
			$this->assert_dies( 'not allowed to manage creator pricing', $this->signed( CreatorPricingActions::MARKUP_ACTION, $this->alpha ) + array( 'markup' => '10' ) );
		} finally {
			$admin->remove_cap( Capabilities::MANAGE_CREATOR_PRICING );
		}

		self::assertSame( 0, ( new CreatorMarkupRepository( $this->wpdb ) )->find( $this->alpha )?->bps );
		self::assertSame( 0, $this->count_rows( Tables::PRICING_OVERRIDES ) );
	}

	public function test_a_missing_or_foreign_nonce_and_withdrawn_actions_are_refused(): void {
		$this->assert_dies( 'expired', array( 'action' => CreatorPricingActions::MARKUP_ACTION, 'creator_id' => $this->alpha, 'markup' => '10' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assert_dies( 'expired', array( '_wpnonce' => wp_create_nonce( CreatorPricingActions::nonce_action( CreatorPricingActions::MARKUP_ACTION, $this->beta ) ) ) + array( 'action' => CreatorPricingActions::MARKUP_ACTION, 'creator_id' => $this->alpha, 'markup' => '10' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// The price-writing actions of the withdrawn model no longer exist.
		foreach ( array( 'merchboard_apply_creator_price', 'merchboard_apply_creator_price_over_drift', 'merchboard_save_fixed_profit' ) as $withdrawn ) {
			$this->assert_dies( 'expired', $this->signed( $withdrawn, $this->alpha ) );
			self::assertFalse( has_action( 'admin_post_' . $withdrawn ), $withdrawn );
		}

		self::assertSame( 0, ( new CreatorMarkupRepository( $this->wpdb ) )->find( $this->alpha )?->bps );
	}

	public function test_a_product_markup_needs_edit_product_for_the_product(): void {
		$product = $this->product( $this->alpha, '120' );
		remove_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10 );

		self::assertSame( array( $product => 'not_authorized' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $product, array( 'product_markup' => '25' ) ) );
		self::assertSame( array( $product => 'not_authorized' ), $this->post( CreatorPricingActions::REMOVE_PRODUCT_MARKUP_ACTION, $this->alpha, $product ) );
		self::assertSame( 0, $this->count_rows( Tables::PRICING_OVERRIDES ) );
	}

	public function test_the_creator_markup_is_0_and_can_be_set_between_0_and_100(): void {
		self::assertStringContainsString( 'name="markup" value="0.00"', $this->render( $this->alpha ) );

		self::assertSame( array( 0 => 'markup_saved' ), $this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => '16,67' ) ) );
		self::assertSame( 1667, ( new CreatorMarkupRepository( $this->wpdb ) )->find( $this->alpha )?->bps );
		self::assertSame( array( 0 => 'markup_unchanged' ), $this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => '16.67' ) ) );
		self::assertSame( array( 0 => 'markup_saved' ), $this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => '100' ) ) );

		foreach ( array( '101', '-1', 'abc', '1.234', '' ) as $invalid ) {
			self::assertSame( array( 0 => 'invalid_markup' ), $this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => $invalid ) ), $invalid );
		}

		self::assertSame( 10000, ( new CreatorMarkupRepository( $this->wpdb ) )->find( $this->alpha )?->bps );
		$audit = $this->audit_rows( 'creator_markup_changed' );
		self::assertCount( 2, $audit );
		self::assertSame( array( 'previous_markup_bps' => 0, 'markup_bps' => 1667 ), json_decode( (string) $audit[0]['context'], true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_the_page_explains_the_model_and_shows_examples_at_the_current_price(): void {
		$this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => '20' ) );
		$this->product( $this->alpha, '100' );

		$html = $this->render( $this->alpha );

		foreach ( array(
			'The WooCommerce sale price is the final customer price and already includes the creator profit and VAT. Creator profit = sale price × markup %. Merch Dashboard never adds anything to the price and never changes WooCommerce prices.',
			'Yes: the creator is paid the full creator profit, including VAT.',
			'No: the creator is paid the same share of the sale price excluding VAT; the VAT of the sale is not paid out.',
			'Active: 100.00 DKK',
			'20.00 %',
			'Creator markup',
		) as $text ) {
			self::assertStringContainsString( esc_html( $text ), $html, $text );
		}

		self::assertStringContainsString( '<td>20.00 DKK</td><td><span class="description">Excluding VAT; calculated per order line</span></td>', $html, 'Example: profit 20.00; not VAT registered, the payout excl. VAT is never guessed from the product price (ADR-064).' );

		$this->post( CreatorPricingActions::VAT_ACTION, $this->alpha, 0, self::vat( '1' ) );
		self::assertStringContainsString( '<td>20.00 DKK</td><td>20.00 DKK</td>', $this->render( $this->alpha ), 'VAT registered: payout = profit.' );

		foreach ( array( 'Apply', 'Base price', 'Creator price', 'drift', 'Fixed profit' ) as $withdrawn ) {
			self::assertStringNotContainsString( $withdrawn, $html, $withdrawn );
		}
	}

	public function test_an_active_sale_price_is_the_example_price_and_is_never_changed(): void {
		$this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => '10' ) );
		$product = $this->product( $this->alpha, '250', '200' );

		self::assertStringContainsString( '<td>20.00 DKK</td><td><span class="description">Excluding VAT; calculated per order line</span></td>', $this->render( $this->alpha ), '200.00 × 10 % = 20.00; not VAT registered (ADR-064).' );
		self::assertSame( array( '250', '200', '200' ), $this->prices_of( $product ) );
	}

	public function test_no_configuration_change_ever_writes_a_woocommerce_price(): void {
		$product   = $this->product( $this->alpha, '120', '96' );
		$parent    = $this->product( $this->alpha, '', '', 'variable' );
		$variation = $this->variation( $parent, '100' );
		$before    = array( $this->prices_of( $product ), $this->prices_of( $variation ) );

		$this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => '50' ) );
		$this->post( CreatorPricingActions::VAT_ACTION, $this->alpha, 0, self::vat( '1' ) );
		$this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $product, array( 'product_markup' => '100' ) );
		$this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $parent, array( 'product_markup' => '25' ) );
		$this->post( CreatorPricingActions::REMOVE_PRODUCT_MARKUP_ACTION, $this->alpha, $product );
		$this->render( $this->alpha );
		$this->recorder->record( 801, 800, $product, 0, 'DKK' );

		self::assertSame( $before, array( $this->prices_of( $product ), $this->prices_of( $variation ) ), 'Regular, sale and active price unchanged.' );
		self::assertSame( array(), $this->audit_rows( 'creator_pricing_' ), 'No price is ever applied.' );
	}

	public function test_a_product_markup_is_created_updated_and_removed(): void {
		$this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => '19' ) );
		$product = $this->product( $this->alpha, '120' );

		self::assertSame( array( $product => 'product_markup_saved' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $product, array( 'product_markup' => '25' ) ) );
		self::assertSame( MarkupSource::Product, $this->resolver->resolve( $product )?->source, 'The product markup replaces the creator markup; never both.' );
		self::assertSame( 2500, $this->resolver->resolve( $product )->markup->bps );

		self::assertSame( array( $product => 'product_markup_saved' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $product, array( 'product_markup' => '12,5' ) ) );
		self::assertSame( array( $product => 'product_markup_unchanged' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $product, array( 'product_markup' => '12.50' ) ) );

		foreach ( array( '101', '-1', '1e3', '1.005', '' ) as $invalid ) {
			self::assertSame( array( $product => 'invalid_markup' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $product, array( 'product_markup' => $invalid ) ), $invalid );
		}

		self::assertStringContainsString( esc_html( 'Product markup' ), $this->render( $this->alpha ) );
		self::assertSame( array( $product => 'product_markup_removed' ), $this->post( CreatorPricingActions::REMOVE_PRODUCT_MARKUP_ACTION, $this->alpha, $product ) );
		self::assertSame( MarkupSource::Creator, $this->resolver->resolve( $product )?->source, 'The creator markup applies again.' );
		self::assertSame( array( $product => 'product_markup_none' ), $this->post( CreatorPricingActions::REMOVE_PRODUCT_MARKUP_ACTION, $this->alpha, $product ) );

		self::assertSame( array( 'product_markup_set', 'product_markup_set', 'product_markup_removed' ), array_column( $this->audit_rows( 'product_markup_' ), 'action' ) );
		self::assertSame( array( 'product_id' => $product, 'markup_bps' => 1250, 'previous_markup_bps' => 2500 ), json_decode( (string) $this->audit_rows( 'product_markup_set' )[1]['context'], true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'product_id' => $product, 'previous_markup_bps' => 1250 ), json_decode( (string) $this->audit_rows( 'product_markup_removed' )[0]['context'], true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_products_of_another_creator_are_refused_and_not_listed(): void {
		$beta_product = $this->product( $this->beta, '120' );

		self::assertSame( array( $beta_product => 'not_owned' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $beta_product, array( 'product_markup' => '5' ) ) );
		self::assertSame( array( $beta_product => 'not_owned' ), $this->post( CreatorPricingActions::REMOVE_PRODUCT_MARKUP_ACTION, $this->alpha, $beta_product ) );
		self::assertStringContainsString( 'No products are linked to this creator yet.', $this->render( $this->alpha ) );
		self::assertSame( 0, $this->count_rows( Tables::PRICING_OVERRIDES ) );
	}

	public function test_unsupported_products_and_variations_cannot_get_a_product_markup(): void {
		$grouped   = $this->product( $this->alpha, '120', '', 'grouped' );
		$parent    = $this->product( $this->alpha, '', '', 'variable' );
		$variation = $this->variation( $parent, '100' );
		$orphan    = $this->product( 0, '120' );

		self::assertSame( array( $grouped => 'unsupported_product' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $grouped, array( 'product_markup' => '5' ) ) );
		self::assertSame( array( $variation => 'unsupported_product' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $variation, array( 'product_markup' => '5' ) ) );
		self::assertSame( array( $orphan => 'no_owner' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $orphan, array( 'product_markup' => '5' ) ) );
		self::assertSame( array( 999999 => 'product_not_found' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, 999999, array( 'product_markup' => '5' ) ) );
		self::assertSame( 0, $this->count_rows( Tables::PRICING_OVERRIDES ) );
	}

	public function test_variations_use_the_parent_markup_and_are_listed_per_variation(): void {
		$this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => '10' ) );
		$parent = $this->product( $this->alpha, '', '', 'variable' );
		$this->variation( $parent, '100' );
		$this->variation( $parent, '200', '150' );

		self::assertSame( array( $parent => 'product_markup_saved' ), $this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $parent, array( 'product_markup' => '20' ) ) );

		$html = $this->render( $this->alpha );
		self::assertStringContainsString( 'Variable product: one markup for all variations', $html );
		self::assertSame( 2, substr_count( $html, 'Parent markup' ) );
		self::assertSame( 1, substr_count( $html, '<summary>Edit markup</summary>' ), 'The markup is edited on the parent only.' );
		self::assertStringContainsString( '<td>20.00 DKK</td><td><span class="description">Excluding VAT; calculated per order line</span></td>', $html, '100.00 × 20 %.' );
		self::assertStringContainsString( '<td>30.00 DKK</td><td><span class="description">Excluding VAT; calculated per order line</span></td>', $html, 'Sale price 150.00 × 20 %.' );
		self::assertStringContainsString( 'Sale: 150.00 DKK', $html );
	}

	public function test_a_product_markup_of_a_previous_owner_is_ignored(): void {
		$this->post( CreatorPricingActions::MARKUP_ACTION, $this->beta, 0, array( 'markup' => '10' ) );
		$product = $this->product( $this->alpha, '100' );
		$this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $product, array( 'product_markup' => '50' ) );

		$this->wpdb->update( $this->wpdb->postmeta, array( 'meta_value' => (string) $this->beta ), array( 'post_id' => $product, 'meta_key' => ProductCreatorLink::META_KEY ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		wp_cache_flush();

		$markup = $this->resolver->resolve( $product );
		self::assertSame( $this->beta, $markup?->creator_id );
		self::assertSame( 1000, $markup->markup->bps, 'Alpha\'s product markup is not used for Beta.' );
		self::assertStringContainsString( 'A product markup set for a previous creator is ignored.', $this->render( $this->beta ) );
		self::assertSame( array( '100', '', '100' ), $this->prices_of( $product ) );
	}

	public function test_configuration_changes_never_change_snapshots_or_the_ledger(): void {
		$this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => '19' ) );
		$product = $this->product( $this->alpha, '120' );
		$this->recorder->record( 801, 800, $product, 0, 'DKK' );
		$snapshot = $this->snapshot_row( 801 );

		$this->post( CreatorPricingActions::MARKUP_ACTION, $this->alpha, 0, array( 'markup' => '50' ) );
		$this->post( CreatorPricingActions::VAT_ACTION, $this->alpha, 0, self::vat( '1' ) );
		$this->post( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $this->alpha, $product, array( 'product_markup' => '99' ) );

		self::assertSame( $snapshot, $this->snapshot_row( 801 ) );
		self::assertIsArray( $snapshot );
		self::assertSame( '1900', $snapshot['share_bps'] );
		self::assertSame( '0', $snapshot['vat_registered'] );
		self::assertSame( 1, $this->count_rows( Tables::SALES_LINE_PRICING ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
	}

	public function test_the_vat_status_is_registered_in_its_history_through_the_form(): void {
		self::assertStringContainsString( esc_html( 'No VAT status has been registered in the history yet. The current status was set before the history was introduced; earlier changes are in the audit log.' ), $this->render( $this->alpha ) );

		$fields = self::vat(
			'1',
			array(
				'documentation_reference' => 'CVR <b>12345678</b>',
				'reason'                  => "Registered at SKAT.\nChecked in the CVR register.",
			)
		);
		self::assertSame( array( 0 => 'vat_saved' ), $this->post( CreatorPricingActions::VAT_ACTION, $this->alpha, 0, $fields ) );
		self::assertTrue( ( new CreatorVatRepository( $this->wpdb ) )->find( $this->alpha ) );

		$html = $this->render( $this->alpha );
		self::assertStringContainsString( 'VAT status history', $html );
		self::assertStringContainsString( 'Applies today', $html );
		self::assertStringContainsString( 'CVR 12345678', $html, 'Tags are removed by sanitize_text_field().' );
		self::assertStringNotContainsString( '<b>12345678</b>', $html );
		self::assertStringContainsString( 'Registered at SKAT.<br />', $html, 'Line breaks of the reason are kept and shown.' );
		self::assertStringContainsString( esc_html( 'Current VAT status: Yes. New order lines get this status.' ), $html );

		self::assertSame( array( 0 => 'vat_unchanged' ), $this->post( CreatorPricingActions::VAT_ACTION, $this->alpha, 0, $fields ), 'A double submit records nothing.' );

		$tomorrow = ( new DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
		$refused  = array(
			'invalid_vat_status'         => self::vat( '2' ),
			'invalid_vat_effective_date' => self::vat( '1', array( 'effective_from' => '2026-02-30' ) ),
			'invalid_vat_check_date'     => self::vat( '1', array( 'checked_on' => $tomorrow ) ),
			'invalid_vat_reference'      => self::vat( '1', array( 'documentation_reference' => '' ) ),
			'invalid_vat_reason'         => self::vat( '1', array( 'reason' => '   ' ) ),
		);

		foreach ( $refused as $code => $input ) {
			self::assertSame( array( 0 => $code ), $this->post( CreatorPricingActions::VAT_ACTION, $this->alpha, 0, $input ), $code );
			self::assertNotNull( CreatorPricingSection::result_message( $code ), 'A message for ' . $code );
		}

		self::assertSame( 1, $this->count_rows( Tables::CREATOR_VAT_HISTORY ), 'Refused registrations write nothing.' );
		self::assertCount( 1, $this->audit_rows( 'creator_vat_history_recorded' ) );

		// Without the pricing capability: refused before anything is read.
		$editor = $this->create_user( 'mb-k06-editor' );
		( new WP_User( $editor ) )->set_role( 'editor' );
		wp_set_current_user( $editor );
		$this->assert_dies( 'not allowed to manage creator pricing', $this->signed( CreatorPricingActions::VAT_ACTION, $this->alpha ) + self::vat( '0' ) );
		self::assertSame( '', $this->render( $this->alpha ) );
		self::assertSame( 1, $this->count_rows( Tables::CREATOR_VAT_HISTORY ) );
	}

	public function test_no_price_filters_and_no_pricing_rest_routes(): void {
		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_regular_price', 'woocommerce_product_variation_get_sale_price' ) as $filter ) {
			self::assertFalse( has_filter( $filter ), $filter );
		}

		do_action( 'rest_api_init', rest_get_server() );

		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			self::assertStringNotContainsString( 'pricing', (string) $route );
		}
	}

	/**
	 * The fields of a VAT status registration (ADR-062 K06) with past dates.
	 *
	 * @param string               $value    "1" or "0".
	 * @param array<string, mixed> $override Fields to replace.
	 * @return array<string, mixed>
	 */
	private static function vat( string $value, array $override = array() ): array {
		return array_merge(
			array(
				'vat_registered'          => $value,
				'effective_from'          => '2026-01-01',
				'checked_on'              => '2026-01-02',
				'documentation_reference' => 'CVR 12345678',
				'reason'                  => 'Registered at SKAT.',
			),
			$override
		);
	}

	/**
	 * Renders the section for a creator.
	 *
	 * @param int $creator_id Creator.
	 */
	private function render( int $creator_id ): string {
		$creator = ( new CreatorRepository( $this->wpdb ) )->find( $creator_id );
		self::assertInstanceOf( Creator::class, $creator );

		ob_start();
		$this->section->render( $creator );

		return (string) ob_get_clean();
	}

	/**
	 * Posts a pricing request with a valid nonce and returns the outcomes.
	 *
	 * @param string               $action     Action.
	 * @param int                  $creator_id Creator of the page.
	 * @param int                  $product_id Product (0 for none).
	 * @param array<string, mixed> $fields     Extra fields.
	 * @return array<int, string>
	 */
	private function post( string $action, int $creator_id, int $product_id = 0, array $fields = array() ): array {
		$url = $this->actions->handle( $fields + $this->signed( $action, $creator_id, $product_id ) );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return CreatorPricingActions::parse_results( is_string( $query[ CreatorPricingActions::RESULTS_ARG ] ?? null ) ? $query[ CreatorPricingActions::RESULTS_ARG ] : '' );
	}

	/**
	 * Action, creator, product and a valid nonce.
	 *
	 * @param string $action     Action.
	 * @param int    $creator_id Creator.
	 * @param int    $product_id Product (0 for none).
	 * @return array<string, mixed>
	 */
	private function signed( string $action, int $creator_id, int $product_id = 0 ): array {
		return array(
			'action'     => $action,
			'creator_id' => (string) $creator_id,
			'product_id' => (string) $product_id,
			'_wpnonce'   => wp_create_nonce( CreatorPricingActions::nonce_action( $action, $creator_id ) ),
		);
	}

	/**
	 * Asserts that a request is refused with wp_die().
	 *
	 * @param string               $message Part of the message.
	 * @param array<string, mixed> $input   Request.
	 */
	private function assert_dies( string $message, array $input ): void {
		try {
			$this->actions->handle( $input );
			self::fail( 'The request should be refused.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( $message, $e->getMessage() );
		}
	}

	/**
	 * Audit records whose action starts with a prefix.
	 *
	 * @param string $prefix Action prefix.
	 * @return list<array<string, string|null>>
	 */
	private function audit_rows( string $prefix ): array {
		return array_values( array_filter( $this->rows( Tables::AUDIT_LOG ), static fn( array $row ): bool => str_starts_with( (string) $row['action'], $prefix ) ) );
	}

	/**
	 * The stored snapshot row.
	 *
	 * @param int $order_item_id Order item.
	 * @return array<string, mixed>|null
	 */
	private function snapshot_row( int $order_item_id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE order_item_id = %d', $this->table( Tables::SALES_LINE_PRICING ), $order_item_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Regular, sale and active price as stored.
	 *
	 * @param int $id Product or variation.
	 * @return array{string, string, string}
	 */
	private function prices_of( int $id ): array {
		wp_cache_flush();

		return array( (string) get_post_meta( $id, '_regular_price', true ), (string) get_post_meta( $id, '_sale_price', true ), (string) get_post_meta( $id, '_price', true ) );
	}

	/**
	 * A parent product with prices and an owner (0 for none).
	 *
	 * @param int    $owner   Creator, 0 for none.
	 * @param string $regular Regular price.
	 * @param string $sale    Sale price.
	 * @param string $type    Product type.
	 */
	private function product( int $owner, string $regular, string $sale = '', string $type = 'simple' ): int {
		$id = $this->posts->add( 'MB-T22F product', 'publish', $owner > 0 ? array( (string) $owner ) : array() );
		$this->prices( $id, $regular, $sale );

		if ( 'simple' !== $type ) {
			$this->posts->meta( $id, PostMetaPricingProductStore::TYPE_KEY, $type );
		}

		return $id;
	}

	/**
	 * A variation with prices.
	 *
	 * @param int    $parent_id Parent.
	 * @param string $regular   Regular price.
	 * @param string $sale      Sale price.
	 */
	private function variation( int $parent_id, string $regular, string $sale = '' ): int {
		$id = $this->posts->add( 'MB-T22F variation', 'publish', array(), 'product_variation', $parent_id );
		$this->prices( $id, $regular, $sale );

		return $id;
	}

	/**
	 * WooCommerce' price meta.
	 *
	 * @param int    $id      Post id.
	 * @param string $regular Regular price.
	 * @param string $sale    Sale price.
	 */
	private function prices( int $id, string $regular, string $sale ): void {
		$this->posts->meta( $id, '_regular_price', $regular );
		$this->posts->meta( $id, '_sale_price', $sale );
		$this->posts->meta( $id, '_price', '' === $sale ? $regular : $sale );
	}
}
