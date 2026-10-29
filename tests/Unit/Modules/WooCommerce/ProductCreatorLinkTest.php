<?php
/**
 * Tests for the authoritative product → creator link.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\WooCommerce\ProductCreatorLinkException;
use MerchBoard\Modules\WooCommerce\ProductLinkSource;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( ProductCreatorLink::class )]
final class ProductCreatorLinkTest extends TestCase {

	use CreatorAdminEnvironment;

	private FakeProductStore $products;

	private ProductCreatorLink $link;

	private ProtectedProductMeta $guard;

	/**
	 * Product ids the current user may edit.
	 *
	 * @var list<int>
	 */
	private array $editable = array( 10, 11, 12, 20, 21, 30, 31 );

	/**
	 * `edit_product` checks as product ids.
	 *
	 * @var list<mixed>
	 */
	private array $product_checks = array();

	/**
	 * Whether the user has `merchboard_manage_creators` (only keep_as_manual()
	 * checks it itself).
	 *
	 * @var bool
	 */
	private bool $manage = false;

	private int $alpha;

	private int $beta;

	private int $inactive;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();

		$this->alpha    = $this->service->create( 'Alpha', CreatorStatus::Active, null )->id;
		$this->beta     = $this->service->create( 'Beta', CreatorStatus::Active, null )->id;
		$this->inactive = $this->service->create( 'Resting', CreatorStatus::Inactive, null )->id;

		// Only product editing matters from here; the creator admin capability is not granted.
		Functions\when( 'current_user_can' )->alias(
			function ( string $capability, ...$args ): bool {
				if ( Capabilities::MANAGE_CREATORS === $capability ) {
					return $this->manage;
				}

				if ( Capabilities::EDIT_PRODUCT !== $capability ) {
					return false;
				}

				$this->product_checks[] = $args[0] ?? null;

				return in_array( $args[0] ?? null, $this->editable, true );
			}
		);

		$this->products = new FakeProductStore();
		$this->products->add( 10 );
		$this->products->add( 20 );
		$this->products->add( 21, array(), 'publish', 20 );
		$this->products->add( 30, array(), 'trash' );
		$this->products->add( 31, array(), 'auto-draft' );

		$this->guard = new ProtectedProductMeta( new RecordingLogger() );
		$this->link  = new ProductCreatorLink(
			$this->products,
			new CreatorRepository( $this->db ),
			new AuditTrail( new AuditRepository( $this->db ) ),
			new Transaction( $this->db, new TransactionSupport( $this->db ) ),
			$this->guard
		);
	}

	public function test_link_stores_the_creator_and_one_audit_record_atomically(): void {
		$changes = $this->link->link( 10, $this->alpha, ProductLinkSource::Admin );

		self::assertSame( array( AuditAction::ProductCreatorLinked ), $changes );
		self::assertSame( array( '10:' . $this->alpha ), $this->products->writes );
		self::assertSame(
			array(
				array(
					'created_at'    => $this->now,
					'action'        => 'product_creator_linked',
					'actor_user_id' => '7',
					'creator_id'    => (string) $this->alpha,
					'context'       => array(
						'product_id'          => 10,
						'previous_creator_id' => null,
						'source'              => 'admin',
					),
				),
			),
			$this->product_audit()
		);
		self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), array_slice( $this->db->transaction_log, -2 ) );
		self::assertSame( array( 10 ), $this->product_checks, 'edit_product is checked for the concrete product.' );
		self::assertSame( $this->alpha, $this->link->creator_id( 10 ) );
	}

	/**
	 * Link, take over and unlink: the store write runs inside a manual grant
	 * for exactly that product, and nothing is authorized before or after.
	 *
	 * @return array<string, array{callable(ProductCreatorLink, int): mixed, int}>
	 */
	public static function writes(): array {
		return array(
			'link'      => array( static fn( ProductCreatorLink $l, int $alpha ): mixed => $l->link( 10, $alpha, ProductLinkSource::Admin ), 10 ),
			'take over' => array( static fn( ProductCreatorLink $l, int $alpha ): mixed => $l->link( 12, $alpha, ProductLinkSource::Admin, true ), 12 ),
			'unlink'    => array( static fn( ProductCreatorLink $l ): mixed => $l->unlink( 12, ProductLinkSource::Admin ), 12 ),
		);
	}

	/**
	 * The guard's answers to a protected write while the store writes.
	 *
	 * @param callable(ProductCreatorLink, int): mixed $operation  Service call.
	 * @param int                                      $product_id Product it writes.
	 */
	#[DataProvider( 'writes' )]
	public function test_the_store_write_runs_inside_a_grant_for_exactly_that_product( callable $operation, int $product_id ): void {
		$this->products->add_linked( 12, $this->beta );
		$seen                     = array();
		$this->products->on_write = function ( int $id ) use ( &$seen ): void {
			$seen = array(
				'this product, link key'   => $this->guard->filter_update( null, $id, ProductCreatorLink::META_KEY ),
				'this product, source key' => $this->guard->filter_add( null, $id, ProductCreatorLink::SOURCE_META_KEY ),
				'another product'          => $this->guard->filter_update( null, 11, ProductCreatorLink::META_KEY ),
				'its variation'            => $this->guard->filter_update( null, 21, ProductCreatorLink::META_KEY ),
				'every product'            => $this->guard->filter_delete( null, 0, ProductCreatorLink::META_KEY, '', true ),
			);
		};

		self::assertFalse( $this->guard->filter_update( null, $product_id, ProductCreatorLink::META_KEY ), 'Denied before.' );

		$operation( $this->link, $this->alpha );

		self::assertSame(
			array(
				'this product, link key'   => null,
				'this product, source key' => null,
				'another product'          => false,
				'its variation'            => false,
				'every product'            => false,
			),
			$seen
		);
		self::assertFalse( $this->guard->filter_update( null, $product_id, ProductCreatorLink::META_KEY ), 'Denied again after.' );
	}

	public function test_a_failed_store_write_leaves_nothing_authorized(): void {
		$this->products->fail_write = true;

		try {
			$this->link->link( 10, $this->alpha, ProductLinkSource::Admin );
			self::fail( 'The write should fail.' );
		} catch ( DatabaseException ) {
			self::assertFalse( $this->guard->filter_update( null, 10, ProductCreatorLink::META_KEY ) );
		}
	}

	public function test_a_rejected_link_never_opens_the_guard(): void {
		$this->products->on_write = static function (): void {
			self::fail( 'No write for a rejected link.' );
		};
		$this->editable           = array();

		$this->expectException( ProductCreatorLinkException::class );
		$this->link->link( 10, $this->alpha, ProductLinkSource::Admin );
	}

	public function test_the_write_path_is_recorded(): void {
		$this->link->link( 10, $this->alpha, ProductLinkSource::Admin );

		self::assertSame( 'admin', $this->product_audit()[0]['context']['source'] );
	}

	public function test_linking_the_current_creator_changes_nothing(): void {
		$this->products->add_linked( 10, $this->alpha );

		self::assertSame( array(), $this->link->link( 10, $this->alpha, ProductLinkSource::Admin ) );
		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_saving_a_product_linked_to_a_now_inactive_creator_changes_nothing(): void {
		$this->products->add_linked( 10, $this->inactive );

		self::assertSame( array(), $this->link->link( 10, $this->inactive, ProductLinkSource::Admin ) );
		self::assertSame( array(), $this->link->link( 10, $this->inactive, ProductLinkSource::Admin, true ) );
		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->product_audit() );
		self::assertSame( $this->inactive, $this->link->creator_id( 10 ) );
		self::assertSame( array( 10, 10 ), $this->product_checks, 'The no-op is still authorized per product.' );
	}

	public function test_replacing_with_an_inactive_creator_is_rejected(): void {
		$this->products->add_linked( 10, $this->alpha );

		$this->expect_rejection( ProductCreatorLinkException::CREATOR_INACTIVE, fn() => $this->link->link( 10, $this->inactive, ProductLinkSource::Admin, true ) );
		self::assertSame( $this->alpha, $this->link->creator_id( 10 ) );
	}

	public function test_an_invalid_link_to_the_same_id_is_not_a_no_op(): void {
		$this->products->add(
			10,
			array(
				array(
					'key'   => '_Merchboard_Creator_ID',
					'value' => (string) $this->alpha,
				),
			)
		);

		$this->expect_rejection( ProductCreatorLinkException::ALREADY_LINKED, fn() => $this->link->link( 10, $this->alpha, ProductLinkSource::Admin ) );
		self::assertSame( array( AuditAction::ProductCreatorUnlinked, AuditAction::ProductCreatorLinked ), $this->link->link( 10, $this->alpha, ProductLinkSource::Admin, true ) );
	}

	public function test_an_existing_link_is_never_replaced_implicitly(): void {
		$this->products->add_linked( 10, $this->alpha );

		$this->expect_rejection( ProductCreatorLinkException::ALREADY_LINKED, fn() => $this->link->link( 10, $this->beta, ProductLinkSource::Admin ) );
		self::assertSame( $this->alpha, $this->link->creator_id( 10 ) );
	}

	public function test_an_explicit_replacement_records_both_sides(): void {
		$this->products->add_linked( 10, $this->alpha );

		$changes = $this->link->link( 10, $this->beta, ProductLinkSource::Admin, true );

		self::assertSame( array( AuditAction::ProductCreatorUnlinked, AuditAction::ProductCreatorLinked ), $changes );
		self::assertSame( $this->beta, $this->link->creator_id( 10 ) );

		$rows = $this->product_audit();
		self::assertSame( array( 'product_creator_unlinked', (string) $this->alpha ), array( $rows[0]['action'], $rows[0]['creator_id'] ) );
		self::assertSame(
			array(
				'product_id' => 10,
				'source'     => 'admin',
			),
			$rows[0]['context']
		);
		self::assertSame( array( 'product_creator_linked', (string) $this->beta ), array( $rows[1]['action'], $rows[1]['creator_id'] ) );
		self::assertSame( $this->alpha, $rows[1]['context']['previous_creator_id'] );
	}

	/**
	 * Stored entries that are not one valid link.
	 *
	 * @return array<string, array{list<array{key: string, value: string}>}>
	 */
	public static function invalid_links(): array {
		$key = ProductCreatorLink::META_KEY;

		return array(
			'other key spelling' => array( array( array( 'key' => '_Merchboard_Creator_ID', 'value' => '1' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'padded key'         => array( array( array( 'key' => ' ' . $key, 'value' => '1' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'two entries'        => array( array( array( 'key' => $key, 'value' => '1' ), array( 'key' => $key, 'value' => '2' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'not an id'          => array( array( array( 'key' => $key, 'value' => 'alpha' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'zero'               => array( array( array( 'key' => $key, 'value' => '0' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
	}

	/**
	 * An invalid link is kept unless replaced explicitly.
	 *
	 * @param list<array{key: string, value: string}> $entries Stored entries.
	 */
	#[DataProvider( 'invalid_links' )]
	public function test_an_invalid_link_counts_as_existing_and_is_not_replaced_implicitly( array $entries ): void {
		$this->products->add( 10, $entries );

		self::assertNull( $this->link->creator_id( 10 ) );
		$this->expect_rejection( ProductCreatorLinkException::ALREADY_LINKED, fn() => $this->link->link( 10, $this->alpha, ProductLinkSource::Admin ) );
		self::assertSame( $entries, $this->products->entries( 10 ) );
	}

	/**
	 * An explicit replacement repairs an invalid link.
	 *
	 * @param list<array{key: string, value: string}> $entries Stored entries.
	 */
	#[DataProvider( 'invalid_links' )]
	public function test_an_explicit_replacement_repairs_an_invalid_link( array $entries ): void {
		$this->products->add( 10, $entries );

		$this->link->link( 10, $this->alpha, ProductLinkSource::Admin, true );

		self::assertSame( $this->alpha, $this->link->creator_id( 10 ) );
		$rows = $this->product_audit();
		self::assertSame( array( 'product_creator_unlinked', null ), array( $rows[0]['action'], $rows[0]['creator_id'] ) );
		self::assertSame( 'invalid', $rows[0]['context']['previous_link'] );
		self::assertNull( $rows[1]['context']['previous_creator_id'] );
		self::assertSame( 'invalid', $rows[1]['context']['previous_link'] );
	}

	public function test_unlink_removes_the_link_with_audit(): void {
		$this->products->add_linked( 10, $this->alpha );

		self::assertSame( array( AuditAction::ProductCreatorUnlinked ), $this->link->unlink( 10, ProductLinkSource::Admin ) );
		self::assertSame( array(), $this->products->entries( 10 ) );
		self::assertSame( array( '10:null' ), $this->products->writes );
		self::assertSame( (string) $this->alpha, $this->product_audit()[0]['creator_id'] );
	}

	public function test_unlink_without_a_link_changes_nothing(): void {
		self::assertSame( array(), $this->link->unlink( 10, ProductLinkSource::Admin ) );
		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_unlink_removes_an_invalid_link(): void {
		$this->products->add( 10, array( array( 'key' => '_MERCHBOARD_CREATOR_ID', 'value' => 'x' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->link->unlink( 10, ProductLinkSource::Admin );

		self::assertSame( array(), $this->products->entries( 10 ) );
		self::assertNull( $this->product_audit()[0]['creator_id'] );
		self::assertSame( 'invalid', $this->product_audit()[0]['context']['previous_link'] );
	}

	public function test_a_link_to_a_creator_who_became_inactive_can_be_removed(): void {
		$this->products->add_linked( 10, $this->inactive );

		self::assertSame( array( AuditAction::ProductCreatorUnlinked ), $this->link->unlink( 10, ProductLinkSource::Admin ) );
	}

	public function test_without_edit_product_nothing_is_written(): void {
		$this->products->add( 11 );
		$this->editable = array();

		$this->expect_rejection( ProductCreatorLinkException::NOT_AUTHORIZED, fn() => $this->link->link( 11, $this->alpha, ProductLinkSource::Admin ) );
		$this->products->add_linked( 11, $this->alpha );
		$this->expect_rejection( ProductCreatorLinkException::NOT_AUTHORIZED, fn() => $this->link->unlink( 11, ProductLinkSource::Admin ) );
		self::assertSame( array( 11, 11 ), $this->product_checks );
	}

	public function test_creator_management_rights_alone_do_not_allow_a_link(): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $capability ): bool => Capabilities::MANAGE_CREATORS === $capability );

		$this->expect_rejection( ProductCreatorLinkException::NOT_AUTHORIZED, fn() => $this->link->link( 10, $this->alpha, ProductLinkSource::Admin ) );
	}

	public function test_unknown_products_are_rejected(): void {
		$this->expect_rejection( ProductCreatorLinkException::PRODUCT_NOT_FOUND, fn() => $this->link->link( 999, $this->alpha, ProductLinkSource::Admin ) );
		$this->expect_rejection( ProductCreatorLinkException::PRODUCT_NOT_FOUND, fn() => $this->link->link( 0, $this->alpha, ProductLinkSource::Admin ) );
		$this->expect_rejection( ProductCreatorLinkException::PRODUCT_NOT_FOUND, fn() => $this->link->unlink( -1, ProductLinkSource::Admin ) );
	}

	public function test_variations_are_not_ownership_objects(): void {
		$this->expect_rejection( ProductCreatorLinkException::VARIATION, fn() => $this->link->link( 21, $this->alpha, ProductLinkSource::Admin ) );
		$this->expect_rejection( ProductCreatorLinkException::VARIATION, fn() => $this->link->unlink( 21, ProductLinkSource::Admin ) );
	}

	public function test_a_variation_belongs_to_its_parents_creator(): void {
		$this->products->add_linked( 20, $this->beta );
		$this->products->add( 21, array(), 'publish', 20 );

		self::assertSame( $this->beta, $this->link->creator_id( 21 ) );
		self::assertNull( $this->link->creator_id( 999 ) );
	}

	public function test_trashed_and_auto_draft_products_are_not_supported(): void {
		$this->expect_rejection( ProductCreatorLinkException::PRODUCT_NOT_SUPPORTED, fn() => $this->link->link( 30, $this->alpha, ProductLinkSource::Admin ) );
		$this->expect_rejection( ProductCreatorLinkException::PRODUCT_NOT_SUPPORTED, fn() => $this->link->link( 31, $this->alpha, ProductLinkSource::Admin ) );
	}

	public function test_the_creator_must_exist_and_be_active(): void {
		$this->expect_rejection( ProductCreatorLinkException::CREATOR_NOT_FOUND, fn() => $this->link->link( 10, 999, ProductLinkSource::Admin ) );
		$this->expect_rejection( ProductCreatorLinkException::CREATOR_NOT_FOUND, fn() => $this->link->link( 10, 0, ProductLinkSource::Admin ) );
		$this->expect_rejection( ProductCreatorLinkException::CREATOR_INACTIVE, fn() => $this->link->link( 10, $this->inactive, ProductLinkSource::Admin ) );
	}

	public function test_an_audit_failure_keeps_the_product_unchanged(): void {
		$this->db->fail_writes_to = array( 'merchboard_audit_log' );

		try {
			$this->link->link( 10, $this->alpha, ProductLinkSource::Admin );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertSame( array(), $this->products->writes, 'The product is written after the audit record.' );
		}

		self::assertSame( array(), $this->products->entries( 10 ) );
		self::assertSame( array( 10 ), $this->products->forgotten );
		self::assertSame( 'ROLLBACK', end( $this->db->transaction_log ) );
	}

	public function test_a_product_write_failure_rolls_the_audit_record_back(): void {
		$this->products->fail_write = true;

		try {
			$this->link->link( 10, $this->alpha, ProductLinkSource::Admin );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertSame( array(), $this->product_audit() );
		}

		self::assertSame( array( 10 ), $this->products->forgotten );
		self::assertSame( 'ROLLBACK', end( $this->db->transaction_log ) );
	}

	public function test_the_link_key_is_matched_case_insensitively(): void {
		self::assertTrue( ProductCreatorLink::is_link_key( '_merchboard_creator_id' ) );
		self::assertTrue( ProductCreatorLink::is_link_key( ' _MerchBoard_Creator_ID ' ) );
		self::assertFalse( ProductCreatorLink::is_link_key( '_merchboard_creator' ) );
		self::assertFalse( ProductCreatorLink::is_link_key( 'product_category_id' ) );
	}

	/**
	 * Audit records of product links (creator setup writes its own), without ids.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function product_audit(): array {
		$rows = array_filter( $this->audit_rows(), static fn( array $row ): bool => str_starts_with( (string) $row['action'], 'product_' ) );

		return array_values( array_map( static fn( array $row ): array => array_diff_key( $row, array( 'id' => true ) ), $rows ) );
	}

	public function test_a_manual_link_stores_the_admin_origin(): void {
		$this->link->link( 10, $this->alpha, ProductLinkSource::Admin );

		self::assertSame( array( 'admin' ), $this->products->written_sources );
		self::assertFalse( $this->products->find( 10 )?->is_category_managed() );
	}

	public function test_the_manual_entry_points_refuse_the_category_source(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->link->link( 10, $this->alpha, ProductLinkSource::Category );
	}

	public function test_keep_as_manual_turns_a_category_link_into_a_manual_one_for_the_same_creator(): void {
		$this->manage = true;
		$this->products->add_linked( 12, $this->alpha, 'category' );
		$purpose                  = null;
		$guard                    = $this->guard;
		$this->products->on_write = static function () use ( &$purpose, $guard ): void {
			$active  = ( new \ReflectionProperty( ProtectedProductMeta::class, 'active' ) )->getValue( $guard );
			$purpose = $active?->purpose;
		};

		$changes = $this->link->keep_as_manual(
			12,
			$this->alpha,
			array(
				'term_id' => 16,
				'choice'  => 'keep_as_manual',
			)
		);

		self::assertSame( array( AuditAction::ProductCreatorLinked ), $changes );
		self::assertSame( \MerchBoard\Core\Security\ProtectedMetaPurpose::ManualLink, $purpose, 'Written through the manual grant.' );
		$product = $this->products->find( 12 );
		self::assertNotNull( $product );
		self::assertSame( $this->alpha, $product->creator_id(), 'Creator kept.' );
		self::assertTrue( $product->has_manual_link() );
		self::assertSame( array( 'admin' ), $this->products->written_sources );
		self::assertSame( array( 12 ), $this->product_checks, 'edit_product for the product.' );

		$audit = $this->product_audit()[0];
		self::assertSame( array( 'product_creator_linked', '7', (string) $this->alpha ), array( $audit['action'], $audit['actor_user_id'], $audit['creator_id'] ) );
		self::assertSame(
			array(
				'product_id'          => 12,
				'previous_creator_id' => $this->alpha,
				'source'              => 'admin',
				'previous_source'     => 'category',
				'term_id'             => 16,
				'choice'              => 'keep_as_manual',
			),
			$audit['context']
		);

		self::assertSame( array(), $this->link->keep_as_manual( 12, $this->alpha ), 'A repeat converts nothing.' );
		self::assertCount( 1, $this->products->writes );
		self::assertCount( 1, $this->product_audit() );
	}

	public function test_a_category_managed_link_cannot_be_removed_manually(): void {
		$this->products->add_linked( 12, $this->alpha, 'category', array( 16 ) );
		$before = array( $this->products->entries( 12 ), $this->products->sources( 12 ) );

		$this->expect_rejection( ProductCreatorLinkException::CATEGORY_MANAGED, fn() => $this->link->unlink( 12, ProductLinkSource::Admin ) );

		self::assertSame( $before, array( $this->products->entries( 12 ), $this->products->sources( 12 ) ), 'Creator id and origin unchanged.' );
		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_after_keep_as_manual_the_link_can_be_removed(): void {
		$this->manage = true;
		$this->products->add_linked( 12, $this->alpha, 'category' );

		$this->link->keep_as_manual( 12, $this->alpha );
		self::assertSame( array( AuditAction::ProductCreatorUnlinked ), $this->link->unlink( 12, ProductLinkSource::Admin ) );

		self::assertFalse( $this->products->find( 12 )?->has_link() );
		self::assertSame( array( 'product_creator_linked', 'product_creator_unlinked' ), array_column( $this->product_audit(), 'action' ) );
	}

	public function test_links_without_or_with_an_unknown_origin_are_manual_and_can_be_removed(): void {
		$this->products->add_linked( 11, $this->alpha );
		$this->products->add_linked( 12, $this->alpha, 'something' );

		self::assertSame( array( AuditAction::ProductCreatorUnlinked ), $this->link->unlink( 11, ProductLinkSource::Admin ) );
		self::assertSame( array( AuditAction::ProductCreatorUnlinked ), $this->link->unlink( 12, ProductLinkSource::Admin ) );
	}

	public function test_the_category_path_still_removes_and_moves_category_links(): void {
		$this->products->add_linked( 11, $this->alpha, 'category' );
		$this->products->add_linked( 12, $this->alpha, 'category' );

		$this->link->apply_category_link( 11, null, 'category_removed' );
		$this->link->apply_category_link( 12, $this->beta, 'category_moved' );

		self::assertFalse( $this->products->find( 11 )?->has_link() );
		self::assertSame( $this->beta, $this->products->find( 12 )?->creator_id() );
	}

	public function test_keep_as_manual_leaves_manual_links_alone_and_refuses_changed_links(): void {
		$this->manage = true;
		$this->products->add_linked( 11, $this->alpha, 'admin' );
		$this->products->add_linked( 12, $this->beta, 'category' );

		self::assertSame( array(), $this->link->keep_as_manual( 11, $this->alpha ), 'Already manual: no-op.' );
		$this->expect_rejection( ProductCreatorLinkException::NOT_CATEGORY_MANAGED, fn() => $this->link->keep_as_manual( 12, $this->alpha ) );
		$this->expect_rejection( ProductCreatorLinkException::NOT_CATEGORY_MANAGED, fn() => $this->link->keep_as_manual( 10, $this->alpha ) );

		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_keep_as_manual_needs_both_the_admin_and_the_product_capability(): void {
		$this->products->add_linked( 12, $this->alpha, 'category' );

		$this->expect_rejection( ProductCreatorLinkException::NOT_AUTHORIZED, fn() => $this->link->keep_as_manual( 12, $this->alpha ) );

		$this->manage   = true;
		$this->editable = array();
		$this->expect_rejection( ProductCreatorLinkException::NOT_AUTHORIZED, fn() => $this->link->keep_as_manual( 12, $this->alpha ) );

		self::assertTrue( $this->products->find( 12 )?->is_category_managed() );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_a_failed_keep_as_manual_write_leaves_the_link_and_its_audit_untouched(): void {
		$this->manage = true;
		$this->products->add_linked( 12, $this->alpha, 'category' );
		$this->products->fail_write = true;

		try {
			$this->link->keep_as_manual( 12, $this->alpha );
			self::fail( 'Expected a database failure.' );
		} catch ( DatabaseException ) {
			self::assertTrue( $this->products->find( 12 )?->is_category_managed(), 'Still the category link.' );
		}

		self::assertSame( array(), $this->product_audit(), 'Audit rolled back.' );
		self::assertSame( array( 12 ), $this->products->forgotten );
	}

	public function test_a_category_link_is_system_work_with_its_own_grant_and_audit(): void {
		$this->editable           = array();
		$purpose                  = null;
		$guard                    = $this->guard;
		$this->products->on_write = static function () use ( &$purpose, $guard ): void {
			$active  = ( new \ReflectionProperty( ProtectedProductMeta::class, 'active' ) )->getValue( $guard );
			$purpose = $active?->purpose;
		};

		$changes = $this->link->apply_category_link(
			10,
			$this->alpha,
			'category_added',
			array(
				'category_ids'    => array( 16 ),
				'trigger_user_id' => 5,
			)
		);

		self::assertSame( array( AuditAction::ProductCreatorLinked ), $changes );
		self::assertSame( \MerchBoard\Core\Security\ProtectedMetaPurpose::CategoryReconciliation, $purpose, 'Written through the category reconciliation grant.' );
		self::assertSame( array( 'category' ), $this->products->written_sources );
		self::assertTrue( $this->products->find( 10 )?->is_category_managed() );
		self::assertSame( array(), $this->product_checks, 'No capability check: system work.' );

		$audit = $this->product_audit()[0];
		self::assertNull( $audit['actor_user_id'], 'System record.' );
		self::assertSame( (string) $this->alpha, $audit['creator_id'] );
		self::assertSame(
			array(
				'product_id'          => 10,
				'previous_creator_id' => null,
				'source'              => 'category',
				'reason'              => 'category_added',
				'category_ids'        => array( 16 ),
				'trigger_user_id'     => 5,
			),
			$audit['context']
		);
	}

	public function test_a_category_move_records_both_sides_and_a_removal_records_the_reason(): void {
		$this->products->add_linked( 11, $this->alpha, 'category' );

		self::assertSame( array( AuditAction::ProductCreatorUnlinked, AuditAction::ProductCreatorLinked ), $this->link->apply_category_link( 11, $this->beta, 'category_moved' ) );
		self::assertSame( array( AuditAction::ProductCreatorUnlinked ), $this->link->apply_category_link( 11, null, 'category_removed' ) );
		self::assertSame( array(), $this->link->apply_category_link( 11, null, 'category_removed' ), 'Unchanged state: nothing.' );

		$audit = $this->product_audit();
		self::assertSame( array( 'product_creator_unlinked', 'product_creator_linked', 'product_creator_unlinked' ), array_column( $audit, 'action' ) );
		self::assertSame( array( (string) $this->alpha, (string) $this->beta, (string) $this->beta ), array_column( $audit, 'creator_id' ) );
		self::assertSame( array( 'category_moved', 'category_moved', 'category_removed' ), array_map( static fn( array $a ): string => $a['context']['reason'], $audit ) );
		self::assertSame( array( null, null, null ), array_column( $audit, 'actor_user_id' ) );
		self::assertSame( array(), $this->products->entries( 11 ) );
		self::assertSame( array(), $this->products->sources( 11 ), 'The origin goes with the link.' );
	}

	public function test_the_category_path_never_touches_a_manual_or_invalid_link(): void {
		$this->products->add_linked( 11, $this->alpha, 'admin' );
		$this->products->add_linked( 12, $this->alpha );
		$this->products->add( 13, array( array( 'key' => '_Merchboard_Creator_ID', 'value' => '1' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		foreach ( array( 11, 12, 13 ) as $id ) {
			$this->expect_rejection( ProductCreatorLinkException::MANUAL_LINK, fn() => $this->link->apply_category_link( $id, $this->beta, 'category_added' ) );
			$this->expect_rejection( ProductCreatorLinkException::MANUAL_LINK, fn() => $this->link->apply_category_link( $id, null, 'category_removed' ) );
		}
	}

	public function test_the_category_path_rejects_variations_unsupported_products_and_inactive_creators(): void {
		$this->expect_rejection( ProductCreatorLinkException::VARIATION, fn() => $this->link->apply_category_link( 21, $this->alpha, 'category_added' ) );
		$this->expect_rejection( ProductCreatorLinkException::PRODUCT_NOT_SUPPORTED, fn() => $this->link->apply_category_link( 30, $this->alpha, 'category_added' ) );
		$this->expect_rejection( ProductCreatorLinkException::PRODUCT_NOT_SUPPORTED, fn() => $this->link->apply_category_link( 31, $this->alpha, 'category_added' ) );
		$this->expect_rejection( ProductCreatorLinkException::PRODUCT_NOT_FOUND, fn() => $this->link->apply_category_link( 99, $this->alpha, 'category_added' ) );
		$this->expect_rejection( ProductCreatorLinkException::CREATOR_INACTIVE, fn() => $this->link->apply_category_link( 10, $this->inactive, 'category_added' ) );
	}

	public function test_the_designer_link_is_system_work_with_its_own_grant_origin_and_audit(): void {
		$this->editable           = array();
		$purpose                  = null;
		$guard                    = $this->guard;
		$this->products->on_write = static function () use ( &$purpose, $guard ): void {
			$active  = ( new \ReflectionProperty( ProtectedProductMeta::class, 'active' ) )->getValue( $guard );
			$purpose = $active?->purpose;
		};

		self::assertSame( array( AuditAction::ProductCreatorLinked ), $this->link->apply_designer_link( 10, $this->alpha, array( 'design_product_id' => 3 ) ) );
		self::assertSame( \MerchBoard\Core\Security\ProtectedMetaPurpose::DesignerLink, $purpose );
		self::assertSame( array( 'designer' ), $this->products->written_sources );
		$record = $this->products->find( 10 );
		self::assertNotNull( $record );
		self::assertTrue( $record->is_designer_linked() );
		self::assertTrue( $record->has_manual_link(), 'The category reconciliation never changes it.' );
		self::assertSame( array(), $this->product_checks, 'No capability check: system work.' );
		self::assertSame( array(), $this->link->apply_designer_link( 10, $this->alpha ), 'Repeated: unchanged.' );

		$audit = $this->product_audit();
		self::assertCount( 1, $audit );
		self::assertNull( $audit[0]['actor_user_id'] );
		self::assertSame( 'designer', $audit[0]['context']['source'] );
		self::assertSame( 3, $audit[0]['context']['design_product_id'] );
	}

	public function test_the_designer_link_replaces_a_category_link_but_never_another_creator_s_manual_link(): void {
		$this->products->add_linked( 11, $this->beta, 'category' );
		$this->products->add_linked( 12, $this->beta, 'admin' );

		self::assertSame( array( AuditAction::ProductCreatorLinked ), $this->link->apply_designer_link( 11, $this->alpha ) );
		self::assertSame( $this->alpha, $this->products->find( 11 )?->creator_id() );
		$this->expect_rejection( ProductCreatorLinkException::ALREADY_LINKED, fn() => $this->link->apply_designer_link( 12, $this->alpha ) );
		$this->expect_rejection( ProductCreatorLinkException::VARIATION, fn() => $this->link->apply_designer_link( 21, $this->alpha ) );
		$this->expect_rejection( ProductCreatorLinkException::PRODUCT_NOT_FOUND, fn() => $this->link->apply_designer_link( 99, $this->alpha ) );
		$this->expect_rejection( ProductCreatorLinkException::CREATOR_INACTIVE, fn() => $this->link->apply_designer_link( 10, $this->inactive ) );
	}

	/**
	 * Runs an operation that must be rejected without any write.
	 *
	 * @param string   $reason    Expected reason.
	 * @param callable $operation Operation.
	 */
	private function expect_rejection( string $reason, callable $operation ): void {
		$audit_before  = count( $this->db->rows( 'merchboard_audit_log' ) );
		$writes_before = $this->products->writes;

		try {
			$operation();
			self::fail( 'Expected ProductCreatorLinkException ' . $reason );
		} catch ( ProductCreatorLinkException $e ) {
			self::assertSame( $reason, $e->reason() );
		}

		self::assertCount( $audit_before, $this->db->rows( 'merchboard_audit_log' ) );
		self::assertSame( $writes_before, $this->products->writes );
	}
}
