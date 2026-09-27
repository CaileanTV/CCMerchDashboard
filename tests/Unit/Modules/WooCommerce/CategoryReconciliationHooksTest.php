<?php
/**
 * Tests for the events that queue the category reconciliation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationHooks;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationScheduler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\MappingRemovalChoice;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_Post;
use WP_Term;

/**
 * Separate processes: the WP_Term and WP_Post stand-ins define global classes.
 */
#[CoversClass( CategoryReconciliationHooks::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CategoryReconciliationHooksTest extends TestCase {

	use CreatorAdminEnvironment;

	private const ALPHA_CAT   = 16;
	private const BETA_CAT    = 17;
	private const GENERAL_CAT = 19;

	private const PRODUCT   = 10;
	private const VARIATION = 11;
	private const PAGE      = 12;

	private FakeJobQueue $queue;

	private CreatorCategoryService $mappings;

	private CategoryReconciliationHooks $hooks;

	private int $alpha;

	/**
	 * Existing `product_cat` terms: id => term_taxonomy_id.
	 *
	 * @var array<int, int>
	 */
	private array $terms = array(
		self::ALPHA_CAT   => 116,
		self::BETA_CAT    => 117,
		self::GENERAL_CAT => 119,
	);

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/wp-term.php';
		require_once dirname( __DIR__, 3 ) . '/Fixtures/wp-post.php';
		$this->set_up_creator_admin();

		$this->alpha = $this->service->create( 'Alpha', CreatorStatus::Active, null )->id;

		$this->mappings = new CreatorCategoryService(
			new CreatorCategoryRepository( $this->db ),
			new CreatorRepository( $this->db ),
			new AuditTrail( new AuditRepository( $this->db ) ),
			new Transaction( $this->db, new TransactionSupport( $this->db ) )
		);
		$this->queue    = new FakeJobQueue();
		$logger         = new RecordingLogger();
		$this->hooks    = new CategoryReconciliationHooks( new CategoryReconciliationScheduler( $this->queue, $logger ), $this->mappings, $logger );

		Functions\when( 'get_term' )->alias(
			fn( int $id ): ?WP_Term => isset( $this->terms[ $id ] )
				? new WP_Term(
					(object) array(
						'term_id'          => $id,
						'taxonomy'         => 'product_cat',
						'term_taxonomy_id' => $this->terms[ $id ],
					)
				)
				: null
		);
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_post_type' )->alias(
			static fn( int $id ): string|false => match ( $id ) {
				self::PRODUCT   => 'product',
				self::VARIATION => 'product_variation',
				self::PAGE      => 'page',
				default         => false,
			}
		);
	}

	public function test_the_handlers_are_hooked_exactly_once(): void {
		foreach ( array( 'set_object_terms', 'deleted_term_relationships', 'transition_post_status', 'pre_delete_term', 'delete_term', CreatorCategoryService::LINKED_ACTION, CreatorCategoryService::UNLINKED_ACTION, CreatorService::STATUS_CHANGED_ACTION ) as $hook ) {
			Actions\expectAdded( $hook )->once();
		}

		$this->hooks->register();
		$this->hooks->register();
	}

	public function test_a_product_added_to_a_category_is_queued_with_the_user(): void {
		$this->hooks->terms_set( self::PRODUCT, array(), array( 116 ), 'product_cat', false, array() );

		self::assertSame(
			array(
				array(
					'product_id'      => self::PRODUCT,
					'trigger'         => 'category_changed',
					'trigger_user_id' => 7,
					'attempt'         => 0,
				),
			),
			$this->queue->pending_payloads()
		);
		self::assertSame( array( CategoryReconciliationScheduler::PRODUCT_HOOK ), $this->queue->pending_hooks() );
	}

	public function test_unchanged_categories_other_taxonomies_and_other_post_types_queue_nothing(): void {
		$this->hooks->terms_set( self::PRODUCT, array(), array( 116, 119 ), 'product_cat', false, array( '119', 116 ) );
		$this->hooks->terms_set( self::PRODUCT, array(), array( 5 ), 'product_tag', false, array() );
		$this->hooks->terms_set( self::VARIATION, array(), array( 116 ), 'product_cat', false, array() );
		$this->hooks->terms_set( self::PAGE, array(), array( 116 ), 'product_cat', false, array() );
		$this->hooks->terms_removed( self::VARIATION, array( 116 ), 'product_cat' );
		$this->hooks->terms_removed( self::PRODUCT, array( 5 ), 'product_tag' );

		self::assertSame( array(), $this->queue->queued );
	}

	public function test_a_product_removed_from_a_category_is_queued(): void {
		$this->hooks->terms_removed( self::PRODUCT, array( 116 ), 'product_cat' );

		self::assertCount( 1, $this->queue->pending );
		self::assertSame( 'category_changed', $this->queue->pending_payloads()[0]['trigger'] ?? null );
	}

	public function test_a_product_moved_between_categories_is_queued_once(): void {
		// wp_set_object_terms() replacing a category fires both actions.
		$this->hooks->terms_removed( self::PRODUCT, array( 116 ), 'product_cat' );
		$this->hooks->terms_set( self::PRODUCT, array(), array( 117 ), 'product_cat', false, array( 116 ) );
		$this->hooks->terms_set( self::PRODUCT, array(), array( 117 ), 'product_cat', false, array( 116 ) );

		self::assertCount( 1, $this->queue->queued );
	}

	public function test_restoring_or_leaving_auto_draft_is_queued_and_trashing_is_not(): void {
		$product = new WP_Post( (object) array( 'ID' => self::PRODUCT, 'post_type' => 'product' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$page    = new WP_Post( (object) array( 'ID' => self::PAGE, 'post_type' => 'page' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->hooks->status_changed( 'trash', 'publish', $product );
		$this->hooks->status_changed( 'publish', 'draft', $product );
		$this->hooks->status_changed( 'publish', 'trash', $page );
		self::assertSame( array(), $this->queue->queued );

		$this->hooks->status_changed( 'draft', 'trash', $product );
		self::assertCount( 1, $this->queue->queued, 'Restored.' );

		$this->queue->pending = array();
		$this->hooks->status_changed( 'publish', 'auto-draft', $product );
		self::assertCount( 2, $this->queue->queued, 'Left auto-draft.' );
	}

	public function test_a_mapping_created_queues_only_its_category(): void {
		Actions\expectDone( CreatorCategoryService::LINKED_ACTION )->once()->whenHappen( array( $this->hooks, 'mapping_created' ) );

		$this->mappings->link( $this->alpha, self::ALPHA_CAT );

		self::assertSame(
			array(
				array(
					'run'             => null,
					'scope'           => array(
						'scope'   => 'category',
						'term_id' => self::ALPHA_CAT,
					),
					'trigger'         => 'mapping_created',
					'trigger_user_id' => 7,
					'attempt'         => 0,
				),
			),
			$this->queue->pending_payloads(),
			'Bounded to the category: no full backfill.'
		);
	}

	public function test_a_mapping_removed_queues_its_category_and_the_creators_category_links(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		Actions\expectDone( CreatorCategoryService::UNLINKED_ACTION )->once()->whenHappen( array( $this->hooks, 'mapping_removed' ) );

		$this->mappings->unlink( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks );

		$payload = $this->queue->pending_payloads()[0];
		self::assertSame(
			array(
				'scope'      => 'mapping',
				'term_id'    => self::ALPHA_CAT,
				'creator_id' => $this->alpha,
			),
			$payload['scope'] ?? null
		);
		self::assertSame( 'mapping_removed', $payload['trigger'] ?? null );
	}

	public function test_a_deleted_mapped_category_removes_its_mapping_and_queues_term_deleted_work(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$this->caps = array(); // A shop manager may delete categories.
		Actions\expectDone( CreatorCategoryService::UNLINKED_ACTION )->once()->whenHappen( array( $this->hooks, 'mapping_removed' ) );

		$this->hooks->term_deleting( self::ALPHA_CAT, 'product_cat' );
		$this->hooks->terms_removed( self::PRODUCT, array( 116 ), 'product_cat' );
		unset( $this->terms[ self::ALPHA_CAT ] );
		$this->hooks->term_deleted( self::ALPHA_CAT, 116, 'product_cat' );

		self::assertNull( $this->mappings->for_category( self::ALPHA_CAT ) );
		$payloads = $this->queue->pending_payloads();
		self::assertSame( 'term_deleted', $payloads[0]['trigger'] ?? null, 'The product left a category being deleted.' );
		self::assertSame(
			array(
				'scope'      => 'mapping',
				'creator_id' => $this->alpha,
			),
			$payloads[1]['scope'] ?? null,
			'The creator\'s remaining category links, wherever they are.'
		);
		self::assertSame( 'term_deleted', $payloads[1]['trigger'] ?? null );
		$rows = $this->audit_rows();
		self::assertSame( array( 'creator_category_unlinked', null ), array( $rows[ count( $rows ) - 1 ]['action'], $rows[ count( $rows ) - 1 ]['actor_user_id'] ) );
	}

	public function test_deleting_an_unmapped_category_is_an_ordinary_category_change(): void {
		$this->hooks->term_deleting( self::GENERAL_CAT, 'product_cat' );
		$this->hooks->terms_removed( self::PRODUCT, array( 119 ), 'product_cat' );
		unset( $this->terms[ self::GENERAL_CAT ] );
		$this->hooks->term_deleted( self::GENERAL_CAT, 119, 'product_cat' );

		self::assertSame( array( 'category_changed' ), array_column( $this->queue->pending_payloads(), 'trigger' ) );
	}

	public function test_a_reactivated_creator_queues_each_of_its_categories_and_deactivation_nothing(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$this->queue->pending = array();
		$this->queue->queued  = array();

		$this->hooks->creator_status_changed( $this->alpha, 'inactive', 'active' );
		self::assertSame( array(), $this->queue->queued );

		$this->hooks->creator_status_changed( $this->alpha, 'active', 'inactive' );
		$payload = $this->queue->pending_payloads()[0];
		self::assertSame(
			array(
				'scope'   => 'category',
				'term_id' => self::ALPHA_CAT,
			),
			$payload['scope'] ?? null
		);
		self::assertSame( 'creator_activated', $payload['trigger'] ?? null );
	}

	public function test_handlers_never_write_a_product_link(): void {
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'add_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$this->hooks->terms_set( self::PRODUCT, array(), array( 116 ), 'product_cat', false, array() );
		$this->hooks->terms_removed( self::PRODUCT, array( 116 ), 'product_cat' );
		$this->hooks->creator_status_changed( $this->alpha, 'active', 'inactive' );

		self::assertCount( 1, $this->queue->queued, 'Only queued work.' );
	}
}
