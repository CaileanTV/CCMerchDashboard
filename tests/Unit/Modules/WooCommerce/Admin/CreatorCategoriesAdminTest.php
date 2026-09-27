<?php
/**
 * Tests for the product category section and its requests on the creator's
 * edit page.
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
use MerchBoard\Modules\WooCommerce\Admin\CreatorCategoriesActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorCategoriesSection;
use MerchBoard\Modules\WooCommerce\CategoryMappingPreview;
use MerchBoard\Modules\WooCommerce\CategoryMappingRemoval;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\FakeReconciliationCandidates;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_Term;

/**
 * Separate processes: the WP_Term stand-in defines a global class.
 */
#[CoversClass( CreatorCategoriesActions::class )]
#[CoversClass( CreatorCategoriesSection::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CreatorCategoriesAdminTest extends TestCase {

	use CreatorAdminEnvironment;

	private const ALPHA_CAT   = 16;
	private const BETA_CAT    = 17;
	private const GENERAL_CAT = 19;
	private const DEFAULT_CAT = 15;

	private FakeProductStore $products;

	private CreatorCategoryRepository $stored;

	private CreatorCategoryService $mappings;

	private CreatorCategoriesActions $actions;

	private CreatorCategoriesSection $section;

	private Creator $alpha;

	private Creator $beta;

	/**
	 * Existing `product_cat` terms: id => [name, count].
	 *
	 * @var array<int, array{string, int}>
	 */
	private array $terms = array(
		self::DEFAULT_CAT => array( 'Uncategorized', 3 ),
		self::ALPHA_CAT   => array( 'Alpha <merch>', 2 ),
		self::BETA_CAT    => array( 'Beta merch', 1 ),
		self::GENERAL_CAT => array( 'General', 4 ),
	);

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
		$this->beta  = $this->service->create( 'Beta <Studio>', CreatorStatus::Active, null );
		$this->caps  = array( Capabilities::MANAGE_CREATORS );

		Functions\when( 'current_user_can' )->alias(
			fn( string $capability ): bool => in_array( Capabilities::EDIT_PRODUCT === $capability ? Capabilities::MANAGE_CREATORS : $capability, $this->caps, true )
		);
		Functions\when( 'get_term' )->alias(
			fn( int $id ): ?WP_Term => isset( $this->terms[ $id ] ) ? $this->term( $id ) : null
		);
		Functions\when( 'get_terms' )->alias( fn(): array => array_map( fn( int $id ): WP_Term => $this->term( $id ), array_keys( $this->terms ) ) );
		Functions\when( 'get_option' )->alias(
			static fn( string $name, mixed $fallback = false ): mixed => match ( $name ) {
				'default_product_cat' => (string) self::DEFAULT_CAT,
				'date_format'         => 'Y-m-d',
				'time_format'         => 'H:i',
				default               => $fallback,
			}
		);
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $timestamp ): string => gmdate( $format, $timestamp ) );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( string $s ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( strip_tags( $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'absint' )->alias( static fn( mixed $v ): int => abs( (int) $v ) );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( mixed ...$args ): string {
				[ $params, $url ] = $args;
				return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( $params );
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce, string $action ): int|false => 'valid:' . $action === $nonce ? 1 : false );
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title = '', array $args = array() ): void {
				throw new WpDie( $message, (int) ( $args['response'] ?? 500 ) );
			}
		);
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
		Functions\when( 'selected' )->alias( static fn( mixed $a, mixed $b ): string => ( (string) $a === (string) $b ) ? ' selected' : '' );
		Functions\when( 'disabled' )->alias( static fn( mixed $a, mixed $b ): string => ( (string) $a === (string) $b ) ? ' disabled' : '' );

		$this->products = new FakeProductStore();
		$this->stored   = new CreatorCategoryRepository( $this->db );
		$creators       = new CreatorRepository( $this->db );
		$audit          = new AuditTrail( new AuditRepository( $this->db ) );
		$transaction    = new Transaction( $this->db, new TransactionSupport( $this->db ) );
		$link           = new ProductCreatorLink( $this->products, $creators, $audit, $transaction, new ProtectedProductMeta( new RecordingLogger() ) );
		$logger         = new RecordingLogger();
		$candidates     = new FakeReconciliationCandidates(
			$this->products,
			fn(): array => array_map( 'intval', array_column( $this->db->rows( Tables::CREATOR_CATEGORIES ), 'term_id' ) )
		);

		$this->mappings = new CreatorCategoryService( $this->stored, $creators, $audit, $transaction );
		$preview        = new CategoryMappingPreview( $this->mappings, $this->stored, $this->products, $creators, $link, $candidates, $audit, $logger );
		$this->actions  = new CreatorCategoriesActions( $this->mappings, new CategoryMappingRemoval( $this->mappings, $this->stored, $preview, $link, $logger ), $logger );
		$this->section  = new CreatorCategoriesSection( $this->mappings, $this->stored, $preview, $creators );
	}

	protected function tearDown(): void {
		$_GET = $this->saved_get;
		parent::tearDown();
	}

	public function test_requests_without_the_admin_capability_or_a_valid_nonce_are_refused(): void {
		$tables     = $this->tables();
		$this->caps = array( Capabilities::ACCESS_DASHBOARD ); // A creator: no self-service.

		foreach ( array( fn() => $this->actions->map( $this->map_form( self::ALPHA_CAT ) ), fn() => $this->actions->unmap( $this->unmap_form( self::ALPHA_CAT, 'keep_as_manual' ) ) ) as $request ) {
			$this->expect_die( 403, $request );
		}

		self::assertSame( '', $this->render( array( CreatorCategoriesActions::PREVIEW_ARG => (string) self::ALPHA_CAT ) ), 'Nothing is shown to a user without the capability.' );

		$this->caps = array( Capabilities::MANAGE_CREATORS );
		$form       = array( '_wpnonce' => 'valid:' . CreatorCategoriesActions::map_nonce_action( $this->beta->id ) ) + $this->map_form( self::ALPHA_CAT );
		$this->expect_die( 403, fn() => $this->actions->map( $form ) );

		self::assertSame( $tables, $this->tables() );
	}

	public function test_mapping_needs_the_explicit_confirmation(): void {
		Actions\expectDone( CreatorCategoryService::LINKED_ACTION )->never();

		$url = $this->actions->map( array( 'confirm' => '0' ) + $this->map_form( self::ALPHA_CAT ) );

		self::assertStringContainsString( CreatorCategoriesActions::RESULT_ARG . '=confirmation_required', $url );
		self::assertStringContainsString( CreatorCategoriesActions::PREVIEW_ARG . '=' . self::ALPHA_CAT, $url, 'Back to the preview.' );
		self::assertNull( $this->mappings->for_category( self::ALPHA_CAT ) );
	}

	public function test_a_confirmed_mapping_is_created_and_starts_the_reconciliation(): void {
		Actions\expectDone( CreatorCategoryService::LINKED_ACTION )->once()->with( self::ALPHA_CAT, $this->alpha->id, 7 );

		$url = $this->actions->map( $this->map_form( self::ALPHA_CAT ) );

		self::assertStringContainsString( CreatorCategoriesActions::RESULT_ARG . '=mapped', $url );
		self::assertStringEndsWith( '#merchboard-categories', $url );
		self::assertSame( $this->alpha->id, $this->mappings->for_category( self::ALPHA_CAT )?->creator_id );
		self::assertStringContainsString( '=unchanged', $this->actions->map( $this->map_form( self::ALPHA_CAT ) ), 'Mapping again changes nothing.' );
	}

	public function test_mappings_that_break_the_rules_are_refused(): void {
		$inactive = $this->service->create( 'Resting', CreatorStatus::Inactive, null );
		$this->stored->insert( self::BETA_CAT, $this->beta->id, 7 );

		self::assertStringContainsString( '=default_category', $this->actions->map( $this->map_form( self::DEFAULT_CAT ) ) );
		self::assertStringContainsString( '=category_mapped_elsewhere', $this->actions->map( $this->map_form( self::BETA_CAT ) ) );
		self::assertStringContainsString( '=creator_inactive', $this->actions->map( $this->map_form( self::GENERAL_CAT, $inactive->id ) ) );
		self::assertStringContainsString( '=term_not_found', $this->actions->map( $this->map_form( 999 ) ) );
		self::assertSame( array( (string) self::BETA_CAT ), array_column( $this->db->rows( Tables::CREATOR_CATEGORIES ), 'term_id' ) );
	}

	public function test_a_removal_without_a_choice_or_confirmation_changes_nothing(): void {
		$this->stored->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		$this->products->add_linked( 10, $this->alpha->id, 'category', array( self::ALPHA_CAT ) );
		$tables = $this->tables();

		foreach ( array( '', 'both', 'REMOVE_LINKS' ) as $choice ) {
			$url = $this->actions->unmap( $this->unmap_form( self::ALPHA_CAT, $choice ) );
			self::assertStringContainsString( '=choice_required', $url );
			self::assertStringContainsString( CreatorCategoriesActions::REMOVE_ARG . '=' . self::ALPHA_CAT, $url );
		}

		$without = $this->unmap_form( self::ALPHA_CAT, 'remove_links' );
		unset( $without['choice'] );
		self::assertStringContainsString( '=choice_required', $this->actions->unmap( $without ) );
		self::assertStringContainsString( '=confirmation_required', $this->actions->unmap( array( 'confirm' => '' ) + $this->unmap_form( self::ALPHA_CAT, 'keep_as_manual' ) ) );

		self::assertSame( $tables, $this->tables() );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_remove_links_removes_the_mapping_after_confirmation(): void {
		$this->stored->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		Actions\expectDone( CreatorCategoryService::UNLINKED_ACTION )->once();

		self::assertStringContainsString( '=removed', $this->actions->unmap( $this->unmap_form( self::ALPHA_CAT, 'remove_links' ) ) );
		self::assertNull( $this->mappings->for_category( self::ALPHA_CAT ) );
	}

	public function test_keep_as_manual_reports_converted_links_and_a_partial_run(): void {
		$this->stored->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		$this->products->add_linked( 10, $this->alpha->id, 'category', array( self::ALPHA_CAT ) );
		$this->products->add_linked( 11, $this->alpha->id, 'category', array() );
		$this->products->fail_write = true;

		$partial = $this->actions->unmap( $this->unmap_form( self::ALPHA_CAT, 'keep_as_manual' ) );

		self::assertStringContainsString( '=kept_partial', $partial );
		self::assertStringContainsString( CreatorCategoriesActions::COUNTS_ARG . '=0%2C2', $partial );
		self::assertNotNull( $this->mappings->for_category( self::ALPHA_CAT ), 'Kept while links are unconverted.' );

		$this->products->fail_write = false;
		$done                       = $this->actions->unmap( $this->unmap_form( self::ALPHA_CAT, 'keep_as_manual' ) );

		self::assertStringContainsString( '=kept_removed', $done );
		self::assertStringContainsString( CreatorCategoriesActions::COUNTS_ARG . '=2%2C0', $done );
		self::assertTrue( $this->products->find( 11 )?->has_manual_link() );
		self::assertNull( $this->mappings->for_category( self::ALPHA_CAT ) );
	}

	public function test_the_section_lists_the_mapping_and_says_it_is_not_ownership(): void {
		$this->stored->insert( self::ALPHA_CAT, $this->alpha->id, 7 );

		$html = $this->render();

		self::assertStringContainsString( 'id="merchboard-categories"', $html );
		self::assertStringContainsString( 'The mapping itself is not ownership', $html );
		self::assertStringContainsString( '<td>Alpha &lt;merch&gt;</td><td>16</td><td>Alpha</td><td>' . ( new \DateTimeImmutable( $this->now, new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i' ) . '</td><td>2</td>', $html );
		self::assertStringContainsString( CreatorCategoriesActions::REMOVE_ARG . '=16', $html );
		self::assertStringNotContainsString( 'Map a product category', $html, 'One category per creator.' );
	}

	public function test_an_orphaned_mapping_is_marked_and_creates_nothing(): void {
		$this->stored->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		unset( $this->terms[ self::ALPHA_CAT ] );

		$html = $this->render();

		self::assertStringContainsString( 'Deleted category', $html );
		self::assertStringContainsString( 'this mapping links no products', $html );
		self::assertStringContainsString( '<td>–</td>', $html );
	}

	public function test_the_category_choice_skips_the_default_and_disables_categories_of_other_creators(): void {
		$this->stored->insert( self::BETA_CAT, $this->beta->id, 7 );

		$html = $this->render();

		self::assertStringContainsString( 'Map a product category', $html );
		self::assertStringNotContainsString( 'Uncategorized', $html );
		self::assertStringContainsString( '<option value="17" disabled>Beta merch (mapped to Beta &lt;Studio&gt;)</option>', $html );
		self::assertStringContainsString( '<option value="16">Alpha &lt;merch&gt;</option>', $html );
	}

	public function test_a_preview_shows_the_dry_run_counts_and_asks_for_confirmation_without_changing_data(): void {
		$this->products->add( 10, array(), 'publish', 0, '', '', array(), array( self::ALPHA_CAT ) );
		$this->products->add_linked( 11, $this->beta->id, 'admin', array( self::ALPHA_CAT ) );
		$tables = $this->tables();

		$html = $this->render( array( CreatorCategoriesActions::PREVIEW_ARG => (string) self::ALPHA_CAT ) );

		self::assertStringContainsString( 'Preview: mapping &quot;Alpha &lt;merch&gt;&quot;', $html );
		self::assertStringContainsString( '<tr><td>Linked to the creator</td><td>1</td></tr>', $html );
		self::assertStringContainsString( '<tr><td>Manual link, never changed</td><td>1</td></tr>', $html );
		self::assertStringContainsString( '<tr><td>Could not be evaluated</td><td>0</td></tr>', $html );
		self::assertStringContainsString( 'value="' . CreatorCategoriesActions::MAP_ACTION . '"', $html );
		self::assertStringContainsString( 'name="confirm" value="1" required', $html );
		self::assertStringContainsString( 'nonce:' . CreatorCategoriesActions::map_nonce_action( $this->alpha->id ), $html );
		self::assertSame( $tables, $this->tables() );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_a_preview_of_a_forbidden_mapping_shows_the_reason_and_no_confirmation(): void {
		$this->stored->insert( self::BETA_CAT, $this->beta->id, 7 );

		$html = $this->render( array( CreatorCategoriesActions::PREVIEW_ARG => (string) self::BETA_CAT ) );

		self::assertStringContainsString( 'already mapped to another creator', $html );
		self::assertStringNotContainsString( CreatorCategoriesActions::MAP_ACTION, $html );
	}

	public function test_the_removal_shows_both_previews_and_requires_a_choice_and_confirmation(): void {
		$this->stored->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		$this->products->add_linked( 10, $this->alpha->id, 'category', array( self::ALPHA_CAT ) );
		$this->products->add_linked( 11, $this->alpha->id, 'category', array() );
		$this->products->add_linked( 12, $this->alpha->id, 'admin', array( self::ALPHA_CAT ) );
		$tables = $this->tables();

		$html = $this->render( array( CreatorCategoriesActions::REMOVE_ARG => (string) self::ALPHA_CAT ) );

		self::assertStringContainsString( '<tr><td>Category link removed</td><td>2</td></tr>', $html, 'Also the product that left the category.' );
		self::assertStringContainsString( '<tr><td>Manual link, never changed</td><td>1</td></tr>', $html );
		self::assertStringContainsString( '2 category-managed links become manual links', $html );
		self::assertStringContainsString( 'name="choice" value="remove_links" required>', $html );
		self::assertStringContainsString( 'name="choice" value="keep_as_manual" required>', $html );
		self::assertStringNotContainsString( 'checked', $html, 'No preselected choice.' );
		self::assertStringContainsString( 'name="confirm" value="1" required', $html );
		self::assertSame( $tables, $this->tables() );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_outcomes_are_shown_from_the_whitelist_only(): void {
		$html = $this->render(
			array(
				CreatorCategoriesActions::RESULT_ARG => 'kept_partial',
				CreatorCategoriesActions::COUNTS_ARG => '3,1',
			)
		);

		self::assertStringContainsString( 'Converted: 3. Not converted: 1.', $html );
		self::assertStringNotContainsString( 'notice-', $this->render( array( CreatorCategoriesActions::RESULT_ARG => 'bogus' ) ) );
	}

	/**
	 * A WP_Term stand-in.
	 *
	 * @param int $id Term id.
	 */
	private function term( int $id ): WP_Term {
		return new WP_Term(
			(object) array(
				'term_id'  => $id,
				'taxonomy' => 'product_cat',
				'name'     => $this->terms[ $id ][0],
				'count'    => $this->terms[ $id ][1],
			)
		);
	}

	/**
	 * A confirmed mapping request.
	 *
	 * @param int      $term_id    Term id.
	 * @param int|null $creator_id Creator (default Alpha).
	 * @return array<string, string>
	 */
	private function map_form( int $term_id, ?int $creator_id = null ): array {
		$creator_id ??= $this->alpha->id;

		return array(
			'creator_id' => (string) $creator_id,
			'term_id'    => (string) $term_id,
			'confirm'    => '1',
			'_wpnonce'   => 'valid:' . CreatorCategoriesActions::map_nonce_action( $creator_id ),
		);
	}

	/**
	 * A confirmed removal request for Alpha.
	 *
	 * @param int    $term_id Term id.
	 * @param string $choice  Choice.
	 * @return array<string, string>
	 */
	private function unmap_form( int $term_id, string $choice ): array {
		return array(
			'creator_id' => (string) $this->alpha->id,
			'term_id'    => (string) $term_id,
			'choice'     => $choice,
			'confirm'    => '1',
			'_wpnonce'   => 'valid:' . CreatorCategoriesActions::unmap_nonce_action( $this->alpha->id ),
		);
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

	/**
	 * Every plugin table.
	 *
	 * @return array<string, mixed>
	 */
	private function tables(): array {
		$tables = array();

		foreach ( Tables::all() as $table ) {
			$tables[ $table ] = $this->db->rows( $table );
		}

		return $tables;
	}

	/**
	 * Asserts that the request stops with the status.
	 *
	 * @param int      $status  HTTP status.
	 * @param callable $request Request.
	 */
	private function expect_die( int $status, callable $request ): void {
		try {
			$request();
			self::fail( 'Expected WpDie.' );
		} catch ( WpDie $e ) {
			self::assertSame( $status, $e->response );
		}
	}
}
