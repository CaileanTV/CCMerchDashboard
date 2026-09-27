<?php
/**
 * Tests for the creator ↔ product category mapping service and storage.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CreatorCategory;
use MerchBoard\Modules\WooCommerce\CreatorCategoryException;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\MappingRemovalChoice;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_Term;

/**
 * Separate processes: the WP_Term stand-in defines a global class.
 */
#[CoversClass( CreatorCategoryService::class )]
#[CoversClass( CreatorCategoryRepository::class )]
#[CoversClass( CreatorCategory::class )]
#[CoversClass( CreatorCategoryException::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CreatorCategoryServiceTest extends TestCase {

	use CreatorAdminEnvironment;

	private const ALPHA_CAT   = 16;
	private const BETA_CAT    = 17;
	private const GENERAL_CAT = 19;
	private const DEFAULT_CAT = 15;
	private const TAG         = 30;

	private CreatorCategoryService $mappings;

	private int $alpha;

	private int $beta;

	private int $inactive;

	/**
	 * Terms by id: taxonomy.
	 *
	 * @var array<int, string>
	 */
	private array $terms = array(
		self::DEFAULT_CAT => 'product_cat',
		self::ALPHA_CAT   => 'product_cat',
		self::BETA_CAT    => 'product_cat',
		self::GENERAL_CAT => 'product_cat',
		self::TAG         => 'product_tag',
	);

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/wp-term.php';
		$this->set_up_creator_admin();

		$this->alpha    = $this->service->create( 'Alpha', CreatorStatus::Active, null )->id;
		$this->beta     = $this->service->create( 'Beta', CreatorStatus::Active, null )->id;
		$this->inactive = $this->service->create( 'Resting', CreatorStatus::Inactive, null )->id;

		$this->mappings = new CreatorCategoryService(
			new CreatorCategoryRepository( $this->db ),
			new CreatorRepository( $this->db ),
			new AuditTrail( new AuditRepository( $this->db ) ),
			new Transaction( $this->db, new TransactionSupport( $this->db ) )
		);

		Functions\when( 'get_term' )->alias(
			fn( int $id ): ?WP_Term => isset( $this->terms[ $id ] )
				? new WP_Term(
					(object) array(
						'term_id'  => $id,
						'taxonomy' => $this->terms[ $id ],
					)
				)
				: null
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $name, mixed $fallback = false ): mixed {
				return 'default_product_cat' === $name ? (string) self::DEFAULT_CAT : $fallback;
			}
		);
	}

	public function test_committed_mapping_changes_fire_events_and_no_ops_do_not(): void {
		Actions\expectDone( CreatorCategoryService::LINKED_ACTION )->once()->with( self::ALPHA_CAT, $this->alpha, 7 );
		Actions\expectDone( CreatorCategoryService::UNLINKED_ACTION )->once()->with( self::ALPHA_CAT, $this->alpha, 'admin', 7 );

		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$this->mappings->unlink( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks );
		$this->mappings->unlink( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks );
	}

	public function test_the_removal_choice_is_recorded(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );

		self::assertTrue( $this->mappings->unlink( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::KeepAsManual ) );

		$rows = $this->audit_rows();
		self::assertSame(
			array(
				'term_id' => self::ALPHA_CAT,
				'reason'  => 'admin',
				'choice'  => 'keep_as_manual',
			),
			$rows[ count( $rows ) - 1 ]['context']
		);
	}

	public function test_a_mapping_is_checked_without_writing_anything(): void {
		$rows  = $this->db->rows( Tables::CREATOR_CATEGORIES );
		$audit = count( $this->audit_rows() );

		$this->mappings->check_link( $this->alpha, self::ALPHA_CAT );

		self::assertSame( $rows, $this->db->rows( Tables::CREATOR_CATEGORIES ) );
		self::assertCount( $audit, $this->audit_rows() );

		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$this->expect_reason( CreatorCategoryException::ALREADY_MAPPED, fn() => $this->mappings->check_link( $this->alpha, self::ALPHA_CAT ) );
		$this->expect_reason( CreatorCategoryException::CATEGORY_MAPPED_ELSEWHERE, fn() => $this->mappings->check_link( $this->beta, self::ALPHA_CAT ) );
		$this->expect_reason( CreatorCategoryException::DEFAULT_CATEGORY, fn() => $this->mappings->check_link( $this->beta, self::DEFAULT_CAT ) );
		$this->expect_reason( CreatorCategoryException::CREATOR_INACTIVE, fn() => $this->mappings->check_link( $this->inactive, self::BETA_CAT ) );
		$this->caps = array();
		$this->expect_reason( CreatorCategoryException::NOT_AUTHORIZED, fn() => $this->mappings->check_link( $this->beta, self::BETA_CAT ) );
	}

	public function test_an_orphaned_mapping_is_detected_and_removed_only_by_an_admin_with_a_choice(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$mapping = $this->mappings->for_category( self::ALPHA_CAT );
		self::assertNotNull( $mapping );
		self::assertFalse( CreatorCategoryService::is_orphaned( $mapping ) );
		self::assertFalse( $this->mappings->remove_orphaned( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks ), 'The category still exists.' );

		unset( $this->terms[ self::ALPHA_CAT ] );
		self::assertTrue( CreatorCategoryService::is_orphaned( $mapping ) );

		$this->caps = array();
		$this->expect_reason( CreatorCategoryException::NOT_AUTHORIZED, fn() => $this->mappings->remove_orphaned( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks ) );
		$this->caps = array( Capabilities::MANAGE_CREATORS );

		Actions\expectDone( CreatorCategoryService::UNLINKED_ACTION )->once()->with( self::ALPHA_CAT, $this->alpha, 'term_deleted', 7 );
		self::assertFalse( $this->mappings->remove_orphaned( $this->beta, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks ), 'Another creator\'s mapping.' );
		self::assertTrue( $this->mappings->remove_orphaned( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::KeepAsManual ) );

		self::assertNull( $this->mappings->for_category( self::ALPHA_CAT ) );
		$rows = $this->audit_rows();
		$last = $rows[ count( $rows ) - 1 ];
		self::assertSame( array( 'creator_category_unlinked', '7' ), array( $last['action'], $last['actor_user_id'] ) );
		self::assertSame(
			array(
				'term_id' => self::ALPHA_CAT,
				'reason'  => 'term_deleted',
				'choice'  => 'keep_as_manual',
			),
			$last['context']
		);
	}

	public function test_a_deleted_terms_mapping_is_removed_as_system_work_without_a_capability(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$this->caps = array();
		Actions\expectDone( CreatorCategoryService::UNLINKED_ACTION )->once()->with( self::ALPHA_CAT, $this->alpha, 'term_deleted', 9 );

		self::assertFalse( $this->mappings->remove_for_deleted_term( self::ALPHA_CAT, 9 ), 'The term still exists: never removed.' );
		unset( $this->terms[ self::ALPHA_CAT ] );
		self::assertTrue( $this->mappings->remove_for_deleted_term( self::ALPHA_CAT, 9 ) );
		self::assertFalse( $this->mappings->remove_for_deleted_term( self::ALPHA_CAT, 9 ), 'Idempotent.' );

		self::assertNull( $this->mappings->for_category( self::ALPHA_CAT ) );
		$rows = $this->audit_rows();
		$last = $rows[ count( $rows ) - 1 ];
		self::assertSame( array( 'creator_category_unlinked', null, (string) $this->alpha ), array( $last['action'], $last['actor_user_id'], $last['creator_id'] ) );
		self::assertSame(
			array(
				'term_id'         => self::ALPHA_CAT,
				'reason'          => 'term_deleted',
				'trigger_user_id' => 9,
			),
			$last['context']
		);
	}

	public function test_a_product_category_is_mapped_to_an_active_creator_with_one_audit_record(): void {
		$before = count( $this->audit_rows() );

		self::assertTrue( $this->mappings->link( $this->alpha, self::ALPHA_CAT ) );

		$mapping = $this->mappings->for_category( self::ALPHA_CAT );
		self::assertNotNull( $mapping );
		self::assertSame( array( self::ALPHA_CAT, $this->alpha, 7 ), array( $mapping->term_id, $mapping->creator_id, $mapping->created_by ) );
		self::assertSame( $this->now, $mapping->created_at->format( 'Y-m-d H:i:s' ) );
		self::assertSame( 'UTC', $mapping->created_at->getTimezone()->getName() );

		$audit = array_slice( $this->audit_rows(), $before );
		self::assertCount( 1, $audit );
		self::assertSame( 'creator_category_linked', $audit[0]['action'] );
		self::assertSame( (string) $this->alpha, $audit[0]['creator_id'] );
		self::assertSame( '7', $audit[0]['actor_user_id'] );
		self::assertSame( array( 'term_id' => self::ALPHA_CAT ), $audit[0]['context'] );
	}

	public function test_mappings_can_be_read_per_category_and_per_creator(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$this->mappings->link( $this->beta, self::BETA_CAT );

		self::assertSame( array( self::ALPHA_CAT ), array_map( static fn( CreatorCategory $m ): int => $m->term_id, $this->mappings->for_creator( $this->alpha ) ) );
		self::assertSame( $this->beta, $this->mappings->for_category( self::BETA_CAT )?->creator_id );
		self::assertNull( $this->mappings->for_category( self::GENERAL_CAT ) );
		self::assertSame( array(), $this->mappings->for_creator( $this->inactive ) );
		self::assertNull( $this->mappings->for_category( 0 ) );
		self::assertSame( array(), $this->mappings->for_creator( 0 ) );
	}

	public function test_mapping_the_same_pair_again_is_a_no_op_without_audit(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$audit = count( $this->audit_rows() );
		$rows  = $this->db->rows( Tables::CREATOR_CATEGORIES );

		self::assertFalse( $this->mappings->link( $this->alpha, self::ALPHA_CAT ) );

		self::assertCount( $audit, $this->audit_rows() );
		self::assertSame( $rows, $this->db->rows( Tables::CREATOR_CATEGORIES ) );
	}

	/**
	 * Rejected requests and their reasons.
	 *
	 * @return array<string, array{string, int, string}>
	 */
	public static function rejected(): array {
		return array(
			'unknown creator'      => array( 'unknown', self::ALPHA_CAT, CreatorCategoryException::CREATOR_NOT_FOUND ),
			'inactive creator'     => array( 'inactive', self::ALPHA_CAT, CreatorCategoryException::CREATOR_INACTIVE ),
			'unknown term'         => array( 'alpha', 999, CreatorCategoryException::TERM_NOT_FOUND ),
			'no term'              => array( 'alpha', 0, CreatorCategoryException::TERM_NOT_FOUND ),
			'another taxonomy'     => array( 'alpha', self::TAG, CreatorCategoryException::NOT_PRODUCT_CATEGORY ),
			'the default category' => array( 'alpha', self::DEFAULT_CAT, CreatorCategoryException::DEFAULT_CATEGORY ),
		);
	}

	#[DataProvider( 'rejected' )]
	public function test_invalid_mappings_are_rejected_and_change_nothing( string $who, int $term_id, string $reason ): void {
		$creator = match ( $who ) {
			'alpha'    => $this->alpha,
			'inactive' => $this->inactive,
			default    => 999,
		};
		$audit = count( $this->audit_rows() );

		try {
			$this->mappings->link( $creator, $term_id );
			self::fail( 'Expected a rejection.' );
		} catch ( CreatorCategoryException $e ) {
			self::assertSame( $reason, $e->reason() );
		}

		self::assertSame( array(), $this->db->rows( Tables::CREATOR_CATEGORIES ) );
		self::assertCount( $audit, $this->audit_rows() );
	}

	public function test_a_category_mapped_to_another_creator_is_not_moved(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );

		$this->expect_reason( CreatorCategoryException::CATEGORY_MAPPED_ELSEWHERE, fn() => $this->mappings->link( $this->beta, self::ALPHA_CAT ) );

		self::assertSame( $this->alpha, $this->mappings->for_category( self::ALPHA_CAT )?->creator_id );
	}

	public function test_phase_17_allows_one_category_per_creator(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );

		$this->expect_reason( CreatorCategoryException::CREATOR_HAS_CATEGORY, fn() => $this->mappings->link( $this->alpha, self::GENERAL_CAT ) );

		self::assertNull( $this->mappings->for_category( self::GENERAL_CAT ) );
	}

	public function test_moving_a_category_means_unlinking_first(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );

		self::assertTrue( $this->mappings->unlink( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks ) );
		self::assertTrue( $this->mappings->link( $this->beta, self::ALPHA_CAT ) );

		self::assertSame( $this->beta, $this->mappings->for_category( self::ALPHA_CAT )?->creator_id );
	}

	public function test_a_mapping_is_removed_with_one_audit_record(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$before = count( $this->audit_rows() );

		self::assertTrue( $this->mappings->unlink( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks ) );

		self::assertNull( $this->mappings->for_category( self::ALPHA_CAT ) );
		$audit = array_slice( $this->audit_rows(), $before );
		self::assertCount( 1, $audit );
		self::assertSame( 'creator_category_unlinked', $audit[0]['action'] );
		self::assertSame( (string) $this->alpha, $audit[0]['creator_id'] );
		self::assertSame(
			array(
				'term_id' => self::ALPHA_CAT,
				'reason'  => 'admin',
				'choice'  => 'remove_links',
			),
			$audit[0]['context']
		);
	}

	public function test_removing_a_mapping_that_does_not_exist_is_a_no_op_without_audit(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$audit = count( $this->audit_rows() );

		self::assertFalse( $this->mappings->unlink( $this->alpha, self::GENERAL_CAT, MappingRemovalChoice::RemoveLinks ), 'No mapping.' );
		self::assertFalse( $this->mappings->unlink( $this->beta, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks ), 'Mapped to another creator: left alone.' );
		self::assertFalse( $this->mappings->unlink( $this->alpha, 999, MappingRemovalChoice::RemoveLinks ), 'Unknown term.' );

		self::assertCount( $audit, $this->audit_rows() );
		self::assertSame( $this->alpha, $this->mappings->for_category( self::ALPHA_CAT )?->creator_id );
	}

	public function test_a_mapping_of_a_deleted_term_can_still_be_removed(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		unset( $this->terms[ self::ALPHA_CAT ] );

		self::assertTrue( $this->mappings->unlink( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks ) );
	}

	public function test_deactivation_keeps_the_mapping_and_reactivation_changes_nothing(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$mapping = $this->mappings->for_category( self::ALPHA_CAT );

		$this->service->set_status( $this->alpha, CreatorStatus::Inactive );
		self::assertEquals( $mapping, $this->mappings->for_category( self::ALPHA_CAT ), 'Kept while inactive.' );
		$this->expect_reason( CreatorCategoryException::CREATOR_INACTIVE, fn() => $this->mappings->link( $this->alpha, self::GENERAL_CAT ) );
		self::assertFalse( $this->mappings->link( $this->alpha, self::ALPHA_CAT ), 'The existing mapping is still a no-op.' );

		$this->service->set_status( $this->alpha, CreatorStatus::Active );
		self::assertEquals( $mapping, $this->mappings->for_category( self::ALPHA_CAT ), 'Unchanged after reactivation.' );
		self::assertSame( array( 'creator_category_linked' ), array_values( array_filter( $this->audit_actions(), static fn( ?string $a ): bool => str_starts_with( (string) $a, 'creator_category' ) ) ) );
	}

	public function test_an_inactive_creator_without_a_mapping_cannot_receive_one(): void {
		$this->expect_reason( CreatorCategoryException::CREATOR_INACTIVE, fn() => $this->mappings->link( $this->inactive, self::GENERAL_CAT ) );
	}

	public function test_without_the_creator_capability_nothing_changes(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$this->caps = array( Capabilities::EDIT_PRODUCTS );

		$this->expect_reason( CreatorCategoryException::NOT_AUTHORIZED, fn() => $this->mappings->link( $this->beta, self::BETA_CAT ) );
		$this->expect_reason( CreatorCategoryException::NOT_AUTHORIZED, fn() => $this->mappings->unlink( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks ) );

		self::assertSame( $this->alpha, $this->mappings->for_category( self::ALPHA_CAT )?->creator_id, 'Reads need no capability.' );
		self::assertNull( $this->mappings->for_category( self::BETA_CAT ) );
	}

	public function test_a_failed_audit_record_rolls_the_new_mapping_back(): void {
		$this->db->fail_writes_to = array( Tables::AUDIT_LOG );
		$audit                    = count( $this->audit_rows() );

		try {
			$this->mappings->link( $this->alpha, self::ALPHA_CAT );
			self::fail( 'Expected a database failure.' );
		} catch ( DatabaseException ) {
			self::assertSame( array(), $this->db->rows( Tables::CREATOR_CATEGORIES ) );
			self::assertCount( $audit, $this->audit_rows() );
		}
	}

	public function test_a_failed_audit_record_rolls_the_removal_back(): void {
		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$rows                     = $this->db->rows( Tables::CREATOR_CATEGORIES );
		$audit                    = count( $this->audit_rows() );
		$this->db->fail_writes_to = array( Tables::AUDIT_LOG );

		try {
			$this->mappings->unlink( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks );
			self::fail( 'Expected a database failure.' );
		} catch ( DatabaseException ) {
			self::assertSame( $rows, $this->db->rows( Tables::CREATOR_CATEGORIES ), 'The mapping is back.' );
			self::assertCount( $audit, $this->audit_rows() );
		}
	}

	public function test_a_failed_mapping_write_writes_no_audit(): void {
		$this->db->fail_writes_to = array( Tables::CREATOR_CATEGORIES );
		$audit                    = count( $this->audit_rows() );

		$this->expectException( DatabaseException::class );

		try {
			$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		} finally {
			self::assertCount( $audit, $this->audit_rows() );
		}
	}

	public function test_the_service_never_touches_product_meta(): void {
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'add_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();
		Functions\expect( 'wc_get_product' )->never();

		$this->mappings->link( $this->alpha, self::ALPHA_CAT );
		$this->mappings->unlink( $this->alpha, self::ALPHA_CAT, MappingRemovalChoice::RemoveLinks );
	}

	public function test_the_repository_rejects_a_second_creator_for_a_category(): void {
		$repository = new CreatorCategoryRepository( $this->db );
		$repository->insert( self::ALPHA_CAT, $this->alpha, 7 );

		$this->expectException( DatabaseException::class );
		$repository->insert( self::ALPHA_CAT, $this->beta, 7 );
	}

	public function test_the_repository_removes_only_the_exact_pair(): void {
		$repository = new CreatorCategoryRepository( $this->db );
		$repository->insert( self::ALPHA_CAT, $this->alpha, null );

		try {
			$repository->delete( self::ALPHA_CAT, $this->beta );
			self::fail( 'Expected a database failure.' );
		} catch ( DatabaseException ) {
			$mapping = $repository->find( self::ALPHA_CAT );
			self::assertNotNull( $mapping );
			self::assertSame( $this->alpha, $mapping->creator_id );
			self::assertNull( $mapping->created_by );
		}
	}

	/**
	 * Asserts a rejection with a reason.
	 *
	 * @param string   $reason    Expected reason.
	 * @param callable $operation Operation.
	 */
	private function expect_reason( string $reason, callable $operation ): void {
		try {
			$operation();
			self::fail( 'Expected rejection: ' . $reason );
		} catch ( CreatorCategoryException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}
}
