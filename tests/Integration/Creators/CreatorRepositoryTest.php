<?php
/**
 * Creator repository on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Creators;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CreatorRepositoryTest extends IntegrationTestCase {

	private CreatorRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->repository = new CreatorRepository( $this->wpdb );
	}

	protected function tearDown(): void {
		update_option( 'timezone_string', '' );
		$this->wpdb->query( "SET time_zone = 'SYSTEM'" );
		parent::tearDown();
	}

	public function test_create_and_find_return_typed_values(): void {
		$user_id = $this->create_user( 'nova' );
		$created = $this->repository->create( 'Nova', CreatorStatus::Active, $user_id );

		$found = $this->repository->find( $created->id );

		self::assertNotNull( $found );
		self::assertSame( $created->id, $found->id );
		self::assertSame( $user_id, $found->user_id );
		self::assertSame( CreatorStatus::Active, $found->status );
		self::assertEquals( $found, $this->repository->find_by_user_id( $user_id ) );
		self::assertNull( $this->repository->find( $created->id + 1000 ) );
		self::assertNull( $this->repository->find_by_user_id( $user_id + 1000 ) );
	}

	public function test_duplicate_user_is_rejected_by_the_database_and_many_unlinked_creators_are_allowed(): void {
		$user_id = $this->create_user( 'taken' );
		$this->repository->create( 'First', CreatorStatus::Active, $user_id );
		$this->repository->create( 'Unlinked A', CreatorStatus::Active );
		$this->repository->create( 'Unlinked B', CreatorStatus::Inactive );

		$this->wpdb->suppress_errors( true );

		try {
			$this->repository->create( 'Second', CreatorStatus::Active, $user_id );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not create creator.', $e->getMessage(), 'Generic message, no SQL or values.' );
		}

		$other = $this->repository->create( 'Other', CreatorStatus::Active );

		try {
			$this->repository->link_user( $other->id, $user_id );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertNull( $this->repository->find( $other->id )?->user_id );
		}

		self::assertSame( 4, $this->count_rows( Tables::CREATORS ) );
		self::assertSame( '3', (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id IS NULL', $this->table( Tables::CREATORS ) ) ) );
	}

	public function test_status_is_stored_as_the_enum_value(): void {
		$creator = $this->repository->create( 'Status', CreatorStatus::Inactive );
		self::assertSame( 'inactive', $this->rows( Tables::CREATORS )[0]['status'] );

		$this->repository->set_status( $creator->id, CreatorStatus::Active );
		self::assertSame( 'active', $this->rows( Tables::CREATORS )[0]['status'] );
	}

	public function test_invalid_status_in_the_database_is_rejected_on_read(): void {
		$creator = $this->repository->create( 'Tampered', CreatorStatus::Active );
		$this->wpdb->update( $this->table( Tables::CREATORS ), array( 'status' => 'root' ), array( 'id' => $creator->id ) );

		$this->expectException( DatabaseException::class );

		$this->repository->find( $creator->id );
	}

	public function test_timestamps_are_utc_regardless_of_site_and_database_session_time_zone(): void {
		update_option( 'timezone_string', 'Pacific/Auckland' );
		$this->wpdb->query( "SET time_zone = '+10:00'" );
		$before = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$creator = $this->repository->create( 'Clock', CreatorStatus::Active );

		$after  = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$stored = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $this->rows( Tables::CREATORS )[0]['created_at'], new DateTimeZone( 'UTC' ) );

		self::assertNotFalse( $stored );
		self::assertGreaterThanOrEqual( $before->getTimestamp() - 1, $stored->getTimestamp() );
		self::assertLessThanOrEqual( $after->getTimestamp() + 1, $stored->getTimestamp() );
		self::assertSame( 'UTC', $creator->created_at->getTimezone()->getName() );
		self::assertEquals( $creator->created_at, $creator->updated_at );
	}

	/**
	 * Display names that must round-trip byte for byte.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unicode_names(): array {
		return array(
			'Danish'             => array( 'Jørgen Ærø Åsen – “Blåbærgrød”' ),
			'emoji (4-byte)'     => array( '🎸🔥 Studio 🇩🇰' ),
			'CJK'                => array( '東京スタジオ 서울' ),
			'combining accent'   => array( "Zoe\u{0301} ß" ),
			'191 Danish letters' => array( str_repeat( 'ø', 191 ) ),
			'191 emoji'          => array( str_repeat( '🎸', 191 ) ),
		);
	}

	#[DataProvider( 'unicode_names' )]
	public function test_unicode_display_names_round_trip_exactly( string $name ): void {
		$creator = $this->repository->create( $name, CreatorStatus::Active );

		self::assertSame( $name, $this->repository->find( $creator->id )?->display_name );
		self::assertSame( $name, $this->rows( Tables::CREATORS )[0]['display_name'] );
	}

	public function test_names_longer_than_the_column_are_rejected_before_the_query(): void {
		$queries = $this->wpdb->num_queries;

		try {
			$this->repository->create( str_repeat( '🎸', 192 ), CreatorStatus::Active );
			self::fail( 'Expected InvalidArgumentException.' );
		} catch ( InvalidArgumentException ) {
			self::assertSame( $queries, $this->wpdb->num_queries );
		}
	}

	public function test_4_byte_characters_on_a_legacy_utf8mb3_table_fail_cleanly_without_corruption(): void {
		$this->execute( 'ALTER TABLE %i CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci', $this->table( Tables::CREATORS ) );
		$this->forget_table_metadata();

		$danish = $this->repository->create( 'Blåbærgrød', CreatorStatus::Active );
		self::assertSame( 'Blåbærgrød', $this->repository->find( $danish->id )?->display_name );

		try {
			$this->repository->create( 'Band 🎸', CreatorStatus::Active );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertSame( 1, $this->count_rows( Tables::CREATORS ), 'Rejected, not stored truncated or as "?".' );
		}

		self::assertSame( array( 'Blåbærgrød' ), array_column( $this->rows( Tables::CREATORS ), 'display_name' ) );
	}

	/**
	 * Values that would break unprepared SQL.
	 *
	 * @return array<string, array{string}>
	 */
	public static function injection_strings(): array {
		return array(
			'quote'         => array( "Robert'); DROP TABLE mbtest_merchboard_creators; --" ),
			'backslash'     => array( 'C:\\path\\\' OR 1=1 --' ),
			'percent'       => array( '100% %s %d %i %1$s' ),
			'null byte-ish' => array( "a\\0b" ),
		);
	}

	#[DataProvider( 'injection_strings' )]
	public function test_sql_in_values_is_stored_literally( string $name ): void {
		$creator = $this->repository->create( $name, CreatorStatus::Active );
		$this->repository->rename( $creator->id, $name . ' 2' );

		self::assertSame( $name . ' 2', $this->repository->find( $creator->id )?->display_name );
		self::assertSame( 1, $this->count_rows( Tables::CREATORS ), 'Table intact.' );
	}

	public function test_list_sorting_ignores_injected_column_and_direction(): void {
		$this->repository->create( 'B', CreatorStatus::Active );
		$this->repository->create( 'A', CreatorStatus::Active );

		$page = $this->repository->paginate( 1, 20, null, 'display_name; DROP TABLE x', 'asc; DELETE FROM x' );

		self::assertSame( 2, $page['total'] );
		self::assertCount( 2, $page['items'] );
		self::assertSame( 2, $this->count_rows( Tables::CREATORS ) );

		$sorted = $this->repository->paginate( 1, 20, CreatorStatus::Active, 'display_name', 'asc' );
		self::assertSame( array( 'A', 'B' ), array_map( static fn( $c ): string => $c->display_name, $sorted['items'] ) );
	}

	public function test_non_positive_ids_are_rejected_before_any_query(): void {
		$queries = $this->wpdb->num_queries;

		foreach ( array( 0, -1, PHP_INT_MIN ) as $id ) {
			try {
				$this->repository->find( $id );
				self::fail( 'Expected InvalidArgumentException for ' . $id );
			} catch ( InvalidArgumentException ) {
				self::assertSame( $queries, $this->wpdb->num_queries );
			}
		}
	}
}
