<?php
/**
 * Tests for the full category reconciliation view and its request.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Admin;

use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CategorySyncActions;
use MerchBoard\Modules\WooCommerce\Admin\CategorySyncScreen;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationBatch;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationJobs;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationScheduler;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationTrigger;
use MerchBoard\Modules\WooCommerce\CreatorCategoryReconciler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
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
#[CoversClass( CategorySyncActions::class )]
#[CoversClass( CategorySyncScreen::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CategorySyncAdminTest extends TestCase {

	use CreatorAdminEnvironment;

	private const ALPHA_CAT = 16;
	private const BETA_CAT  = 17;
	private const GAMMA_CAT = 18;

	private FakeProductStore $products;

	private FakeJobQueue $queue;

	private CreatorCategoryRepository $mappings;

	private CategoryReconciliationBatch $batch;

	private CategorySyncActions $actions;

	private CategorySyncScreen $screen;

	private CategoryReconciliationJobs $jobs;

	private int $alpha;

	private int $beta;

	private int $gamma;

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

		$this->alpha = $this->service->create( 'Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $this->service->create( 'Beta <Studio>', CreatorStatus::Active, null )->id;
		$this->gamma = $this->service->create( 'Gamma', CreatorStatus::Inactive, null )->id;

		$this->mappings = new CreatorCategoryRepository( $this->db );
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha, 7 );
		$this->mappings->insert( self::BETA_CAT, $this->beta, 7 );
		$this->mappings->insert( self::GAMMA_CAT, $this->gamma, 7 );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( string $s ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ) );
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
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_edit_post_link' )->alias( static fn( int $id ): string => 'https://shop.test/wp-admin/post.php?post=' . $id . '&action=edit' );
		Functions\when( 'get_terms' )->alias(
			static fn( array $args ): array => array_map(
				static fn( int $id ): WP_Term => new WP_Term(
					(object) array(
						'term_id'  => $id,
						'taxonomy' => 'product_cat',
						'name'     => 'Cat ' . $id,
					)
				),
				$args['include']
			)
		);

		$this->products = new FakeProductStore();
		$this->queue    = new FakeJobQueue();
		$creators       = new CreatorRepository( $this->db );
		$audit          = new AuditTrail( new AuditRepository( $this->db ) );
		$logger         = new RecordingLogger();
		$link           = new ProductCreatorLink( $this->products, $creators, $audit, new Transaction( $this->db, new TransactionSupport( $this->db ) ), new ProtectedProductMeta( new RecordingLogger() ) );
		$reconciler     = new CreatorCategoryReconciler( $this->products, $this->mappings, $creators, $link, $logger );
		$candidates     = new FakeReconciliationCandidates(
			$this->products,
			fn(): array => array_map( 'intval', array_column( $this->db->rows( Tables::CREATOR_CATEGORIES ), 'term_id' ) )
		);
		$scheduler      = new CategoryReconciliationScheduler( $this->queue, $logger );
		$this->batch    = new CategoryReconciliationBatch( $candidates, $reconciler, $audit, $logger );
		$this->actions  = new CategorySyncActions( $this->batch, $scheduler, $logger );
		$this->screen   = new CategorySyncScreen( $this->batch, $this->products, $this->mappings, $creators );
		$this->jobs     = new CategoryReconciliationJobs( $reconciler, $this->batch, $scheduler, $logger );

		$this->seed();
	}

	protected function tearDown(): void {
		$_GET = $this->saved_get;
		parent::tearDown();
	}

	public function test_the_request_needs_the_capability_and_a_nonce(): void {
		$state = $this->state();

		$this->caps = array( Capabilities::ACCESS_DASHBOARD ); // A creator: no access.
		$this->expect_forbidden( fn() => $this->actions->start( $this->form() ) );

		$this->caps = array( Capabilities::MANAGE_CREATORS );
		$this->expect_forbidden( fn() => $this->actions->start( array( '_wpnonce' => 'valid:other' ) + $this->form() ) );
		$form = $this->form();
		unset( $form['_wpnonce'] );
		$this->expect_forbidden( fn() => $this->actions->start( $form ) );

		self::assertSame( $state, $this->state() );
		self::assertSame( array(), $this->queue->queued );
	}

	public function test_nothing_starts_without_the_explicit_confirmation(): void {
		foreach ( array( array( 'confirm' => '' ), array( 'confirm' => 'yes' ), array( 'fingerprint' => 'forged' ), array( 'fingerprint' => '' ) ) as $override ) {
			$url = $this->actions->start( $override + $this->form() );
			self::assertStringContainsString( CategorySyncActions::RESULT_ARG . '=confirmation_required', $url );
		}

		$form = $this->form();
		unset( $form['confirm'] );
		self::assertStringContainsString( '=confirmation_required', $this->actions->start( $form ) );
		self::assertSame( array(), $this->queue->queued );
	}

	public function test_a_stale_dry_run_is_not_taken_as_authoritative(): void {
		$form = $this->form();
		$this->products->add( 50, array(), 'publish', 0, '', '', array(), array( self::ALPHA_CAT ) ); // Changed after the preview.

		$url = $this->actions->start( $form );

		self::assertStringContainsString( '=preview_outdated', $url );
		self::assertStringContainsString( CategorySyncActions::PREVIEW_ARG . '=1', $url, 'Back to a new dry run.' );
		self::assertSame( array(), $this->queue->queued );
	}

	public function test_a_confirmed_run_is_queued_in_the_background_and_writes_nothing_itself(): void {
		$state = $this->state();

		$url = $this->actions->start( $this->form() );

		self::assertStringContainsString( '=sync_started', $url );
		self::assertSame( $state, $this->state(), 'The request itself writes nothing.' );
		self::assertSame( array( CategoryReconciliationScheduler::RUN_HOOK ), $this->queue->pending_hooks() );
		self::assertSame(
			array(
				'run'             => null,
				'scope'           => array( 'scope' => 'all' ),
				'trigger'         => 'full_sync',
				'trigger_user_id' => 7,
				'attempt'         => 0,
			),
			$this->queue->pending_payloads()[0]
		);

		$this->actions->start( $this->form() );
		self::assertCount( 1, $this->queue->pending, 'A second confirmation while pending is deduplicated.' );
	}

	public function test_the_background_run_follows_the_reconciliation_rules_and_completes_once(): void {
		$this->actions->start( $this->form() );
		$this->queue->drain( fn( string $hook, array $args ) => CategoryReconciliationScheduler::PRODUCT_HOOK === $hook ? $this->jobs->run_product( ...$args ) : $this->jobs->run_page( ...$args ) );

		self::assertSame( $this->alpha, $this->products->find( 10 )?->creator_id(), 'Linked.' );
		self::assertSame( $this->beta, $this->products->find( 11 )?->creator_id(), 'Moved.' );
		self::assertNull( $this->products->find( 12 )?->creator_id(), 'Removed: no category justifies it.' );
		self::assertSame( $this->beta, $this->products->find( 13 )?->creator_id(), 'Manual: untouched.' );
		self::assertNull( $this->products->find( 14 )?->creator_id(), 'Ambiguous: no link.' );
		self::assertNull( $this->products->find( 15 )?->creator_id(), 'Inactive creator: no new link.' );

		$completed = array_values( array_filter( $this->audit_rows(), static fn( array $row ): bool => 'category_sync_completed' === $row['action'] ) );
		self::assertCount( 1, $completed );
		self::assertSame( array( 'full_sync', 'all', 1, 1, 1 ), array( $completed[0]['context']['trigger'], $completed[0]['context']['scope'], $completed[0]['context']['linked'], $completed[0]['context']['ambiguous'], $completed[0]['context']['skipped_inactive'] ) );
		self::assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', (string) $completed[0]['context']['run_id'] );
		self::assertSame( array( $completed[0]['context']['run_id'] ), array_values( array_unique( array_column( array_column( array_filter( $this->audit_rows(), static fn( array $row ): bool => str_starts_with( (string) $row['action'], 'product_' ) ), 'context' ), 'run_id' ) ) ), 'One run id for every change.' );
	}

	public function test_without_action_scheduler_nothing_starts(): void {
		$this->queue->is_available = false;

		self::assertStringContainsString( '=queue_unavailable', $this->actions->start( $this->form() ) );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_the_view_shows_the_dry_run_attention_and_changes_and_writes_nothing(): void {
		$state = $this->state();

		$html = $this->render( array( CategorySyncActions::PREVIEW_ARG => '1' ) );

		foreach ( array( 'Linked to the creator', 'Moved from another creator&#039;s category link', 'Category link removed', 'Unchanged', 'Manual link, never changed', 'Ambiguous: in categories of several creators, no category link', 'Creator inactive, no new link', 'Could not be evaluated' ) as $label ) {
			self::assertStringContainsString( '<tr><td>' . $label . '</td><td>', $html, $label );
		}
		self::assertStringContainsString( '<tr><th scope="row">Total</th><td>6</td></tr>', $html );

		self::assertStringContainsString( 'Ambiguous: the product is directly in categories mapped to several creators', $html );
		self::assertStringContainsString( 'Cat 16 → Alpha; Cat 17 → Beta &lt;Studio&gt;', $html );
		self::assertStringContainsString( 'The only mapped creator is inactive', $html );
		self::assertStringContainsString( 'Cat 18 → Gamma (inactive)', $html );
		self::assertStringNotContainsString( 'post.php?post=14', $html, 'No edit link without edit_product.' );
		$this->caps[] = Capabilities::EDIT_PRODUCT;
		self::assertStringContainsString( '<a href="https://shop.test/wp-admin/post.php?post=14&action=edit">Mixed (#14)</a>', $this->render( array( CategorySyncActions::PREVIEW_ARG => '1' ) ), 'Product link for editors (esc_url is a pass-through stub here).' );

		self::assertStringContainsString( '<td>Alpha</td><td>Beta &lt;Studio&gt;</td>', $html, 'The expected move.' );
		self::assertStringContainsString( 'value="' . CategorySyncActions::ACTION . '"', $html );
		self::assertStringContainsString( 'name="confirm" value="1" required', $html );
		self::assertStringContainsString( 'nonce:' . CategorySyncActions::ACTION, $html );
		self::assertMatchesRegularExpression( '/name="fingerprint" value="[0-9a-f]{64}"/', $html );

		self::assertSame( $state, $this->state(), 'No meta, mapping, creator or audit change.' );
		self::assertSame( array(), $this->queue->queued, 'No job.' );
	}

	public function test_manual_links_that_deviate_from_their_categories_need_attention(): void {
		$this->manual( 20, $this->alpha, 'Legacy', 'LEG-1', array( self::GAMMA_CAT ) );              // Inactive other creator.
		$this->manual( 21, $this->alpha, 'Split', '', array( self::ALPHA_CAT, self::BETA_CAT ) );    // Ambiguous.
		$this->manual( 22, $this->alpha, 'Own', '', array( self::ALPHA_CAT ) );                      // Same creator.
		$this->manual( 23, $this->alpha, 'Free', '', array() );                                       // No mapped category.
		$state = $this->state();

		$html = $this->render( array( CategorySyncActions::PREVIEW_ARG => '1' ) );

		self::assertStringContainsString( '<tr><td>Product 13 (#13)</td><td>Manual link to another creator than the product&#039;s mapped category. The manual link wins and is not changed.</td><td>Beta &lt;Studio&gt;<br><span class="description">Manual</span></td><td>Cat 16 → Alpha</td></tr>', $html );
		self::assertStringContainsString( '<tr><td>Legacy (#20)<br><span class="description">LEG-1</span></td><td>Manual link to another creator than the product&#039;s mapped category, whose creator is inactive. The manual link wins and is not changed.</td><td>Alpha<br><span class="description">Manual</span></td><td>Cat 18 → Gamma (inactive)</td></tr>', $html );
		self::assertStringContainsString( '<tr><td>Split (#21)</td><td>Manual link, but the product is directly in categories mapped to several creators. The manual link wins and is not changed.</td><td>Alpha<br><span class="description">Manual</span></td><td>Cat 16 → Alpha; Cat 17 → Beta &lt;Studio&gt;</td></tr>', $html );
		self::assertStringContainsString( '<tr><td>Mixed (#14)</td><td>Ambiguous: the product is directly in categories mapped to several creators, so it gets no category link.</td><td>–</td>', $html, 'Other rows show the current creator too.' );
		self::assertStringNotContainsString( '(#22)', $html, 'A manual link to the mapped creator is no conflict.' );
		self::assertStringNotContainsString( '(#23)', $html, 'A manual link without a mapped category is no conflict.' );
		self::assertStringContainsString( '<tr><td>Manual link, never changed</td><td>5</td></tr>', $html, 'Counted as manual, nothing else.' );
		self::assertStringNotContainsString( 'post.php?post=20', $html, 'No edit link without edit_product.' );
		self::assertStringContainsString( '<a href="https://shop.test/wp-admin/post.php?post=20&action=edit">Legacy (#20)</a>', $this->render_as_editor(), 'Product link for editors.' );

		self::assertSame( $state, $this->state(), 'No meta, mapping, creator or audit change.' );
		self::assertSame( array(), $this->queue->queued, 'No job.' );
	}

	public function test_a_dry_run_with_other_manual_conflicts_is_stale(): void {
		$form = $this->form();
		// Same counts and changes; only the manual link's conflict differs.
		$this->manual( 13, $this->beta, 'Product 13', '', array( self::GAMMA_CAT ) );

		self::assertStringContainsString( '=preview_outdated', $this->actions->start( $form ) );
		self::assertSame( array(), $this->queue->queued );
	}

	public function test_products_that_cannot_be_evaluated_need_attention(): void {
		$this->db->fail_reads_from = array( Tables::CREATOR_CATEGORIES );

		$html = $this->render( array( CategorySyncActions::PREVIEW_ARG => '1' ) );

		self::assertStringContainsString( 'Could not be evaluated. Check the product; details are in the log.', $html );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_without_a_dry_run_only_the_start_is_shown_and_creators_see_nothing(): void {
		$html = $this->render();

		self::assertStringContainsString( 'Run a dry run', $html );
		self::assertStringNotContainsString( CategorySyncActions::ACTION, $html, 'No confirmation without a dry run.' );

		$this->caps = array( Capabilities::ACCESS_DASHBOARD );
		self::assertSame( '', $this->render( array( CategorySyncActions::PREVIEW_ARG => '1' ) ) );
		ob_start();
		$this->screen->render_link();
		self::assertSame( '', (string) ob_get_clean() );
	}

	public function test_outcome_messages_come_from_the_whitelist(): void {
		self::assertStringContainsString( 'Nothing started: the products or mappings changed since your dry run.', $this->render( array( CategorySyncActions::RESULT_ARG => 'preview_outdated' ) ) );
		self::assertStringNotContainsString( 'notice-', $this->render( array( CategorySyncActions::RESULT_ARG => '<b>bogus' ) ) );
	}

	/**
	 * One product per interesting outcome.
	 */
	private function seed(): void {
		$this->products->add( 10, array(), 'publish', 0, 'Tee', 'TEE-1', array(), array( self::ALPHA_CAT ) );              // Linked.
		$this->products->add_linked( 11, $this->alpha, 'category', array( self::BETA_CAT ) );                            // Moved.
		$this->products->add_linked( 12, $this->alpha, 'category', array() );                                            // Removed.
		$this->products->add_linked( 13, $this->beta, 'admin', array( self::ALPHA_CAT ) );                               // Manual.
		$this->products->add( 14, array(), 'publish', 0, 'Mixed', '', array(), array( self::ALPHA_CAT, self::BETA_CAT ) ); // Ambiguous.
		$this->products->add( 15, array(), 'publish', 0, 'Resting', '', array(), array( self::GAMMA_CAT ) );              // Inactive.
	}

	/**
	 * Adds a product with a manual (`admin`) link.
	 *
	 * @phpstan-param list<int> $categories
	 *
	 * @param int    $id         Product id.
	 * @param int    $creator_id Linked creator.
	 * @param string $name       Product name.
	 * @param string $sku        SKU.
	 * @param int[]  $categories Direct product_cat term ids.
	 */
	private function manual( int $id, int $creator_id, string $name, string $sku, array $categories ): void {
		$this->products->add(
			$id,
			array(
				array(
					'key'   => ProductCreatorLink::META_KEY,
					'value' => (string) $creator_id,
				),
			),
			'publish',
			0,
			$name,
			$sku,
			array(
				array(
					'key'   => ProductCreatorLink::SOURCE_META_KEY,
					'value' => 'admin',
				),
			),
			$categories
		);
	}

	/**
	 * The dry run view for a user who may also edit products.
	 */
	private function render_as_editor(): string {
		$this->caps[] = Capabilities::EDIT_PRODUCT;

		return $this->render( array( CategorySyncActions::PREVIEW_ARG => '1' ) );
	}

	/**
	 * A confirmed request for the current state.
	 *
	 * @return array<string, string>
	 */
	private function form(): array {
		return array(
			'_wpnonce'    => 'valid:' . CategorySyncActions::ACTION,
			'confirm'     => '1',
			'fingerprint' => $this->batch->preview( CategoryReconciliationTrigger::FullSync, 7 )->fingerprint(),
		);
	}

	/**
	 * Renders the view.
	 *
	 * @param array<string, string> $get Query arguments.
	 */
	private function render( array $get = array() ): string {
		$_GET = $get;
		ob_start();
		$this->screen->render();

		return (string) ob_get_clean();
	}

	/**
	 * Products and every plugin table.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		$state = array( 'products' => array_map( fn( int $id ): mixed => $this->products->find( $id ), $this->products->ids() ) );

		foreach ( Tables::all() as $table ) {
			$state[ $table ] = $this->db->rows( $table );
		}

		return $state;
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
}
