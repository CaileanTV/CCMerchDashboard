<?php
/**
 * Tests for the "Products" section on the creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Admin;

use Brain\Monkey\Functions;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsSection;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreatorProductsSection::class )]
final class CreatorProductsSectionTest extends TestCase {

	use CreatorAdminEnvironment;

	private FakeProductStore $products;

	private CreatorProductsSection $section;

	private Creator $alpha;

	private Creator $beta;

	/**
	 * Saved $_GET.
	 *
	 * @var array<mixed>
	 */
	private array $saved_get = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture.

		$this->alpha = $this->service->create( 'Alpha', CreatorStatus::Active, null );
		$this->beta  = $this->service->create( 'Beta <Studio>', CreatorStatus::Active, null );
		$this->caps  = array( Capabilities::MANAGE_CREATORS, Capabilities::EDIT_PRODUCTS );

		$this->products = new FakeProductStore();
		$this->products->add_linked( 10, $this->alpha->id );
		$this->products->add( 11, array(), 'draft', 0, 'Tee <b>bold</b>', 'TEE-1' );
		$this->products->add( 12, array(), 'publish', 0, 'Tee classic', 'TEE-2' );
		$this->products->add_linked( 13, $this->beta->id );
		$this->products->add( 14, array( array( 'key' => '_Merchboard_Creator_ID', 'value' => 'x' ) ), 'publish', 0, 'Tee broken' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->section = new CreatorProductsSection( $this->products, new CreatorRepository( $this->db ) );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( strip_tags( $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action ): void {
				echo '<input type="hidden" name="_wpnonce" value="nonce:' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function ( string $text ): void {
				echo '<button type="submit">' . $text . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'get_post_status_object' )->alias(
			static function ( string $status ): ?object {
				$labels = array(
					'publish' => 'Published',
					'draft'   => 'Draft',
				);

				return isset( $labels[ $status ] ) ? (object) array( 'label' => $labels[ $status ] ) : null;
			}
		);
	}

	protected function tearDown(): void {
		$_GET = $this->saved_get;
		parent::tearDown();
	}

	public function test_lists_the_creators_linked_products_with_a_bound_remove_form(): void {
		$html = $this->render();

		self::assertStringContainsString( 'id="merchboard-products"', $html );
		self::assertStringContainsString( 'Product 10 (#10)', $html );
		self::assertStringNotContainsString( 'Product 13', $html, 'Other creators\' products are not listed.' );
		self::assertStringContainsString( 'name="action" value="' . CreatorProductsActions::UNLINK_ACTION . '"', $html );
		self::assertStringContainsString( 'nonce:' . CreatorProductsActions::unlink_nonce_action( $this->alpha->id ), $html );
		self::assertSame( array(), $this->products->searches, 'No search without a term.' );
	}

	public function test_search_results_can_be_selected_and_show_the_current_creator(): void {
		$html = $this->render( array( CreatorProductsActions::SEARCH_ARG => 'Tee' ) );

		self::assertSame( array( 'Tee' ), $this->products->searches );
		self::assertStringContainsString( 'name="action" value="' . CreatorProductsActions::LINK_ACTION . '"', $html );
		self::assertStringContainsString( 'nonce:' . CreatorProductsActions::link_nonce_action( $this->alpha->id ), $html );
		self::assertStringContainsString( 'name="products[]" value="11"', $html );
		self::assertStringContainsString( 'TEE-1', $html );
		self::assertStringContainsString( 'Draft', $html );
		self::assertStringContainsString( 'Tee &lt;b&gt;bold&lt;/b&gt; (#11)', $html, 'Product names are escaped.' );
		self::assertStringNotContainsString( '<b>bold</b>', $html );
		self::assertStringContainsString( 'name="product_search" value="Tee"', $html );
		self::assertStringContainsString( 'Search by product name, SKU or category', $html, 'The field says it also accepts a category.' );
	}

	public function test_products_of_another_creator_need_an_explicit_take_over(): void {
		$html = $this->render( array( CreatorProductsActions::SEARCH_ARG => 'Product 13' ) );

		self::assertStringContainsString( 'Linked to Beta &lt;Studio&gt;', $html );
		self::assertStringContainsString( 'name="replace[]" value="13"', $html );
		self::assertStringContainsString( 'Take over the product from Beta &lt;Studio&gt;', $html );
		self::assertStringNotContainsString( 'value="13" checked', $html, 'Take over is never preselected.' );
	}

	public function test_an_invalid_link_offers_an_explicit_repair(): void {
		$html = $this->render( array( CreatorProductsActions::SEARCH_ARG => 'broken' ) );

		self::assertStringContainsString( 'Invalid link', $html );
		self::assertStringContainsString( 'name="replace[]" value="14"', $html );
	}

	public function test_a_product_already_linked_to_this_creator_cannot_be_selected_again(): void {
		$html = $this->render( array( CreatorProductsActions::SEARCH_ARG => 'Product 10' ) );

		self::assertStringContainsString( 'Already linked to this creator', $html );
		self::assertStringContainsString( 'name="products[]" value="10" disabled', $html );
		self::assertStringNotContainsString( 'name="replace[]" value="10"', $html );
	}

	public function test_per_product_results_are_whitelisted_and_escaped(): void {
		$html = $this->render( array( CreatorProductsActions::RESULTS_ARG => '11:linked,13:already_linked,99:bogus,0:no_selection' ) );

		self::assertStringContainsString( 'Tee &lt;b&gt;bold&lt;/b&gt; (#11): Linked to this creator.', $html );
		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'Tick &quot;Take over&quot;', $html );
		self::assertStringContainsString( 'Select at least one product.', $html );
		self::assertStringNotContainsString( 'bogus', $html );
	}

	public function test_product_names_need_the_product_capability(): void {
		$this->caps = array( Capabilities::MANAGE_CREATORS );

		$html = $this->render( array( CreatorProductsActions::SEARCH_ARG => 'Tee' ) );

		self::assertStringContainsString( 'You need permission to edit WooCommerce products', $html );
		self::assertStringNotContainsString( 'Product 10', $html );
		self::assertSame( array(), $this->products->searches );
	}

	public function test_nothing_without_the_creator_capability(): void {
		$this->caps = array( Capabilities::EDIT_PRODUCTS );

		self::assertSame( '', $this->render() );
	}

	public function test_registers_only_on_the_creator_edit_page(): void {
		$this->section->register();

		self::assertSame( 10, has_action( 'merchboard_creators_edit_sections', array( $this->section, 'render' ) ) );
	}

	/**
	 * Renders the section for Alpha with the given query arguments.
	 *
	 * @param array<string, string> $get Query arguments.
	 */
	private function render( array $get = array() ): string {
		$_GET = $get;

		ob_start();
		$this->section->render( $this->alpha );

		return (string) ob_get_clean();
	}
}
