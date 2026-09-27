<?php
/**
 * Tests for the link origin and "Keep as manual" in the creator's product
 * section.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Admin;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsSection;
use MerchBoard\Modules\WooCommerce\CategoryLinkStatus;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\PreloadedMappings;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_Term;

/**
 * Separate processes: the WP_Term stand-in defines a global class.
 */
#[CoversClass( CreatorProductsSection::class )]
#[CoversClass( CategoryLinkStatus::class )]
#[CoversClass( PreloadedMappings::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CreatorProductsOriginTest extends TestCase {

	use CreatorAdminEnvironment;

	private const ALPHA_CAT = 16;
	private const BETA_CAT  = 17;
	private const GONE_CAT  = 18;

	private FakeProductStore $products;

	private CreatorCategoryRepository $mappings;

	private CreatorProductsSection $section;

	private CategoryLinkStatus $status;

	private Creator $alpha;

	private Creator $beta;

	/**
	 * Existing `product_cat` terms: id => name.
	 *
	 * @var array<int, string>
	 */
	private array $terms = array(
		self::ALPHA_CAT => 'Alpha <merch>',
		self::BETA_CAT  => 'Beta merch',
	);

	/**
	 * Calls to get_terms().
	 *
	 * @var int
	 */
	private int $term_queries = 0;

	/**
	 * Saved $_GET.
	 *
	 * @var array<mixed>
	 */
	private array $saved_get = array();

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/wp-term.php';
		$this->set_up_creator_admin();
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture.

		$this->alpha = $this->service->create( 'Alpha', CreatorStatus::Active, null );
		$this->beta  = $this->service->create( 'Beta', CreatorStatus::Active, null );
		$this->caps  = array( Capabilities::MANAGE_CREATORS, Capabilities::EDIT_PRODUCTS );

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
		Functions\when( 'get_post_status_object' )->justReturn( (object) array( 'label' => 'Published' ) );
		Functions\when( 'get_terms' )->alias(
			function ( array $args ): array {
				++$this->term_queries;
				$terms = array();

				foreach ( $args['include'] as $id ) {
					if ( isset( $this->terms[ $id ] ) ) {
						$terms[] = new WP_Term(
							(object) array(
								'term_id'  => $id,
								'taxonomy' => 'product_cat',
								'name'     => $this->terms[ $id ],
							)
						);
					}
				}

				return $terms;
			}
		);

		$this->products = new FakeProductStore();
		$this->mappings = new CreatorCategoryRepository( $this->db );
		$creators       = new CreatorRepository( $this->db );
		$logger         = new RecordingLogger();
		$link           = new ProductCreatorLink( $this->products, $creators, new AuditTrail( new AuditRepository( $this->db ) ), new Transaction( $this->db, new TransactionSupport( $this->db ) ), new ProtectedProductMeta( new RecordingLogger() ) );
		$this->status   = new CategoryLinkStatus( $this->products, $this->mappings, $creators, $link, $logger );
		$this->section  = new CreatorProductsSection( $this->products, $creators, $this->status );
	}

	protected function tearDown(): void {
		$_GET = $this->saved_get;
		parent::tearDown();
	}

	public function test_manual_and_missing_origins_show_as_manual_without_keep_as_manual(): void {
		$this->products->add_linked( 10, $this->alpha->id, 'admin', array( self::ALPHA_CAT ) );
		$this->products->add_linked( 11, $this->alpha->id, null );
		$this->products->add_linked( 12, $this->alpha->id, 'unknown' );

		$html = $this->render();

		self::assertSame( 3, substr_count( $html, '<td>Manual</td>' ) );
		self::assertStringNotContainsString( CreatorProductsActions::KEEP_ACTION, $html, 'Manual links offer no "Keep as manual".' );
	}

	public function test_a_category_link_shows_its_origin_and_the_mapped_category(): void {
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		$this->products->add_linked( 10, $this->alpha->id, 'category', array( self::ALPHA_CAT ) );

		$html = $this->render();

		self::assertStringContainsString( '<td>Product category<br><span class="description">Via: Alpha &lt;merch&gt;</span></td>', $html, 'Justified: no warning.' );
	}

	public function test_keep_as_manual_is_offered_only_for_category_links_with_a_confirmation(): void {
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		$this->products->add_linked( 10, $this->alpha->id, 'category', array( self::ALPHA_CAT ) );
		$this->products->add_linked( 11, $this->alpha->id, 'admin', array( self::ALPHA_CAT ) );

		$html = $this->render();

		self::assertSame( 1, substr_count( $html, 'value="' . CreatorProductsActions::KEEP_ACTION . '"' ) );
		self::assertStringContainsString( 'name="product_id" value="10"', $html );
		self::assertStringContainsString( 'nonce:' . CreatorProductsActions::keep_nonce_action( $this->alpha->id ), $html );
		self::assertStringContainsString( '<input type="checkbox" name="confirm" value="1" required> Keep the product with this creator as a manual link.', $html );
	}

	public function test_a_category_managed_link_offers_no_remove_and_a_manual_link_does(): void {
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		$this->products->add_linked( 10, $this->alpha->id, 'category', array( self::ALPHA_CAT ) );
		$this->products->add_linked( 11, $this->alpha->id, 'admin' );

		$html = $this->render();

		self::assertSame( 1, substr_count( $html, 'value="' . CreatorProductsActions::UNLINK_ACTION . '"' ), 'Only the manual link.' );
		self::assertMatchesRegularExpression( '#value="' . CreatorProductsActions::UNLINK_ACTION . '">.{0,200}name="product_id" value="11"#s', $html );
		self::assertStringContainsString( 'Managed by the product category: remove the product from the category, or keep the link as manual first to remove it here.', $html );
		self::assertStringContainsString( 'value="' . CreatorProductsActions::KEEP_ACTION . '"', $html );
	}

	public function test_a_link_the_mappings_no_longer_justify_is_reported_from_the_reconciliation_rules(): void {
		$this->mappings->insert( self::BETA_CAT, $this->beta->id, 7 );
		$this->products->add_linked( 10, $this->alpha->id, 'category', array() );                 // Left its category.
		$this->products->add_linked( 11, $this->alpha->id, 'category', array( self::BETA_CAT ) ); // Would move to beta.

		$html   = $this->render();
		$status = $this->status->describe( $this->alpha->id, array_values( array_filter( array( $this->products->find( 10 ), $this->products->find( 11 ) ) ) ) );

		self::assertSame( array( ReconciliationOutcome::Unlinked, ReconciliationOutcome::Moved ), array( $status[10]['outcome'], $status[11]['outcome'] ) );
		self::assertStringContainsString( 'No mapped category justifies this link any more; the reconciliation will remove it.', $html );
		self::assertStringContainsString( 'are now mapped to another creator; the reconciliation will move this link.', $html );
		self::assertSame( 2, substr_count( $html, 'value="' . CreatorProductsActions::KEEP_ACTION . '"' ), 'Still an explicit admin choice (ADR-033 point 4).' );
	}

	public function test_a_deleted_mapped_category_is_shown_robustly(): void {
		$this->mappings->insert( self::GONE_CAT, $this->alpha->id, 7 );
		$this->products->add_linked( 10, $this->alpha->id, 'category', array( self::GONE_CAT ) );

		$html = $this->render();

		self::assertStringContainsString( 'Via: Deleted category (#18)', $html );
		self::assertCount( 1, $this->db->rows( Tables::CREATOR_CATEGORIES ), 'Nothing cleaned up.' );
	}

	public function test_rendering_writes_nothing_and_queues_nothing(): void {
		$this->mappings->insert( self::BETA_CAT, $this->beta->id, 7 );
		$this->products->add_linked( 10, $this->alpha->id, 'category', array() );
		$this->products->add_linked( 11, $this->alpha->id, 'category', array( self::BETA_CAT ) );
		$tables = array_map( fn( string $t ): array => $this->db->rows( $t ), Tables::all() );
		Actions\expectDone( 'merchboard_creator_category_unlinked' )->never();
		Functions\expect( 'as_enqueue_async_action' )->never();

		$this->render();

		self::assertSame( array(), $this->products->writes, 'The pending reconciliation is only reported.' );
		self::assertSame( $tables, array_map( fn( string $t ): array => $this->db->rows( $t ), Tables::all() ) );
	}

	public function test_the_categories_of_all_rows_are_named_with_one_query(): void {
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		for ( $id = 20; $id < 30; $id++ ) {
			$this->products->add_linked( $id, $this->alpha->id, 'category', array( self::ALPHA_CAT ) );
		}

		$this->render();

		self::assertSame( 1, $this->term_queries );
	}

	/**
	 * Renders the section for Alpha.
	 */
	private function render(): string {
		$_GET = array();

		ob_start();
		$this->section->render( $this->alpha );

		return (string) ob_get_clean();
	}
}
