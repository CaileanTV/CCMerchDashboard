<?php
/**
 * Tests for the creator repository's write operations and list.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreatorRepository::class )]
final class CreatorRepositoryWriteTest extends TestCase {

	private FakeDatabase $db;

	private CreatorRepository $repository;

	private string $now = '2026-09-24 12:00:00';

	protected function setUp(): void {
		parent::setUp();
		$this->db         = new FakeDatabase();
		$this->repository = new CreatorRepository( $this->db );
		Functions\when( 'current_time' )->alias( fn(): string => $this->now );
	}

	public function test_rename_status_link_and_unlink_update_only_their_column_and_updated_at(): void {
		$this->repository->create( 'Nova', CreatorStatus::Active );
		$this->now = '2026-09-25 09:00:00';

		self::assertSame( 'Studio Nova', $this->repository->rename( 1, '  Studio Nova ' )->display_name );
		self::assertSame( CreatorStatus::Inactive, $this->repository->set_status( 1, CreatorStatus::Inactive )->status );
		self::assertSame( 42, $this->repository->link_user( 1, 42 )->user_id );
		$creator = $this->repository->unlink_user( 1 );

		self::assertNull( $creator->user_id );
		self::assertSame( 'Studio Nova', $creator->display_name );
		self::assertSame( '2026-09-24T12:00:00+00:00', $creator->created_at->format( DATE_ATOM ) );
		self::assertSame( '2026-09-25T09:00:00+00:00', $creator->updated_at->format( DATE_ATOM ) );
	}

	public function test_unique_user_link_is_enforced_by_the_database_as_last_safety_net(): void {
		$this->repository->create( 'First', CreatorStatus::Active, 42 );
		$this->repository->create( 'Second', CreatorStatus::Active );

		try {
			$this->repository->link_user( 2, 42 );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not update creator.', $e->getMessage() );
			self::assertStringNotContainsString( 'Duplicate', $e->getMessage() );
		}

		self::assertNull( $this->repository->find( 2 )?->user_id );
	}

	public function test_updating_a_missing_creator_fails(): void {
		$this->expectException( DatabaseException::class );

		$this->repository->rename( 99, 'Ghost' );
	}

	public function test_invalid_ids_and_names_are_rejected_before_writing(): void {
		$this->repository->create( 'Nova', CreatorStatus::Active );

		foreach ( array(
			fn() => $this->repository->rename( 0, 'X' ),
			fn() => $this->repository->rename( 1, '' ),
			fn() => $this->repository->set_status( -1, CreatorStatus::Active ),
			fn() => $this->repository->link_user( 1, 0 ),
			fn() => $this->repository->unlink_user( 0 ),
		) as $operation ) {
			try {
				$operation();
				self::fail( 'Expected InvalidArgumentException.' );
			} catch ( InvalidArgumentException ) {
				self::assertSame( 'Nova', $this->repository->find( 1 )?->display_name );
			}
		}
	}

	public function test_paginate_filters_sorts_and_counts(): void {
		foreach ( array( array( 'Bravo', CreatorStatus::Active ), array( 'Alpha', CreatorStatus::Inactive ), array( 'Charlie', CreatorStatus::Active ) ) as [ $name, $status ] ) {
			$this->repository->create( $name, $status );
		}

		$all = $this->repository->paginate( 1, 20, null, 'display_name', 'asc' );
		self::assertSame( 3, $all['total'] );
		self::assertSame( array( 'Alpha', 'Bravo', 'Charlie' ), $this->names( $all['items'] ) );

		$active = $this->repository->paginate( 1, 20, CreatorStatus::Active, 'display_name', 'desc' );
		self::assertSame( 2, $active['total'] );
		self::assertSame( array( 'Charlie', 'Bravo' ), $this->names( $active['items'] ) );

		$page2 = $this->repository->paginate( 2, 2, null, 'display_name', 'asc' );
		self::assertSame( array( 'Charlie' ), $this->names( $page2['items'] ) );
	}

	public function test_paginate_whitelists_sorting_and_bounds_the_page_size(): void {
		$wpdb       = new \MerchBoard\Tests\Unit\Support\RecordingWpdb();
		$repository = new CreatorRepository( $wpdb );

		$repository->paginate( -3, 5000, null, 'user_pass; DROP TABLE wp_users', 'sideways' );

		$select = $wpdb->prepared[1];
		self::assertStringEndsWith( 'ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d', $select['query'] );
		self::assertSame( array( 'wp_merchboard_creators', 'created_at', 100, 0 ), $select['args'] );
	}

	public function test_linked_user_ids(): void {
		$this->repository->create( 'A', CreatorStatus::Active, 42 );
		$this->repository->create( 'B', CreatorStatus::Active );
		$this->repository->create( 'C', CreatorStatus::Active, 43 );

		self::assertSame( array( 42, 43 ), $this->repository->linked_user_ids() );
	}

	/**
	 * Display names.
	 *
	 * @param Creator[] $creators Creators.
	 * @return string[]
	 */
	private function names( array $creators ): array {
		return array_map( static fn( Creator $c ): string => $c->display_name, $creators );
	}
}
