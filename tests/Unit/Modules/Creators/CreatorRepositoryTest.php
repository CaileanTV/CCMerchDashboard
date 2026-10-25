<?php
/**
 * Tests for the creator repository.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( CreatorRepository::class )]
final class CreatorRepositoryTest extends TestCase {

	private const NOW = '2026-09-24 10:15:30';

	private RecordingWpdb $wpdb;

	private CreatorRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb       = new RecordingWpdb();
		$this->repository = new CreatorRepository( $this->wpdb );
		Functions\when( 'current_time' )->justReturn( self::NOW );
	}

	public function test_find_uses_a_prepared_query_with_whitelisted_table_and_int_id(): void {
		$this->wpdb->rows[] = $this->row();

		$this->repository->find( 7 );

		self::assertCount( 1, $this->wpdb->prepared );
		self::assertSame(
			'SELECT * FROM %i WHERE id = %d',
			$this->wpdb->prepared[0]['query']
		);
		self::assertSame( array( 'wp_merchboard_creators', 7 ), $this->wpdb->prepared[0]['args'] );
		self::assertSame( array( 'PREPARED#0' ), $this->wpdb->executed, 'Only prepared SQL may be executed.' );
	}

	public function test_find_hydrates_typed_creator_with_utc_timestamps(): void {
		$this->wpdb->rows[] = $this->row(
			array(
				'id'         => '7',
				'user_id'    => '42',
				'created_at' => '2026-01-02 03:04:05',
			)
		);

		$creator = $this->repository->find( 7 );

		self::assertNotNull( $creator );
		self::assertSame( 7, $creator->id );
		self::assertSame( 42, $creator->user_id );
		self::assertSame( 'Studio Nova', $creator->display_name );
		self::assertSame( CreatorStatus::Active, $creator->status );
		self::assertSame( 'UTC', $creator->created_at->getTimezone()->getName() );
		self::assertSame( '2026-01-02T03:04:05+00:00', $creator->created_at->format( DATE_ATOM ) );
	}

	public function test_find_returns_null_for_unknown_id(): void {
		self::assertNull( $this->repository->find( 99 ) );
	}

	public function test_null_user_id_is_hydrated_as_null(): void {
		$this->wpdb->rows[] = $this->row( array( 'user_id' => null ) );

		self::assertNull( $this->repository->find( 7 )?->user_id );
	}

	/**
	 * Ids that can never exist.
	 *
	 * @return array<string, array{int}>
	 */
	public static function invalid_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
			'min int'  => array( PHP_INT_MIN ),
		);
	}

	#[DataProvider( 'invalid_ids' )]
	public function test_find_rejects_invalid_ids_before_querying( int $id ): void {
		try {
			$this->repository->find( $id );
			self::fail( 'Expected InvalidArgumentException.' );
		} catch ( InvalidArgumentException ) {
			self::assertSame( array(), $this->wpdb->prepared );
		}
	}

	#[DataProvider( 'invalid_ids' )]
	public function test_find_by_user_id_rejects_invalid_ids_before_querying( int $id ): void {
		try {
			$this->repository->find_by_user_id( $id );
			self::fail( 'Expected InvalidArgumentException.' );
		} catch ( InvalidArgumentException ) {
			self::assertSame( array(), $this->wpdb->prepared );
		}
	}

	public function test_find_by_user_id_queries_the_user_relation(): void {
		$this->wpdb->rows[] = $this->row( array( 'user_id' => '42' ) );

		$creator = $this->repository->find_by_user_id( 42 );

		self::assertSame( 7, $creator?->id );
		self::assertSame(
			'SELECT * FROM %i WHERE user_id = %d',
			$this->wpdb->prepared[0]['query']
		);
		self::assertSame( array( 'wp_merchboard_creators', 42 ), $this->wpdb->prepared[0]['args'] );
	}

	public function test_unknown_status_in_database_is_rejected(): void {
		$this->wpdb->rows[] = $this->row( array( 'status' => '1' ) );

		$this->expectException( DatabaseException::class );

		$this->repository->find( 7 );
	}

	public function test_invalid_timestamp_in_database_is_rejected(): void {
		$this->wpdb->rows[] = $this->row( array( 'updated_at' => '0000-00-00' ) );

		$this->expectException( DatabaseException::class );

		$this->repository->find( 7 );
	}

	public function test_create_inserts_typed_values_with_utc_timestamps(): void {
		$this->wpdb->insert_id = 7;
		$this->wpdb->rows[]    = $this->row( array( 'user_id' => '42' ) );

		$creator = $this->repository->create( '  Studio Nova  ', CreatorStatus::Active, 42 );

		self::assertSame(
			array(
				'table'  => 'wp_merchboard_creators',
				'data'   => array(
					'user_id'      => 42,
					'display_name' => 'Studio Nova',
					'status'       => 'active',
					'created_at'   => self::NOW,
					'updated_at'   => self::NOW,
				),
				'format' => array( '%d', '%s', '%s', '%s', '%s' ),
			),
			$this->wpdb->inserts[0]
		);
		self::assertSame( 7, $creator->id, 'Creator id comes from the insert, not from the user id.' );
		self::assertSame( 42, $creator->user_id );
	}

	public function test_create_requests_gmt_time_from_wordpress(): void {
		$calls = array();
		Functions\when( 'current_time' )->alias(
			static function ( string $type, bool $gmt = false ) use ( &$calls ): string {
				$calls[] = array( $type, $gmt );
				return self::NOW;
			}
		);
		$this->wpdb->insert_id = 7;
		$this->wpdb->rows[]    = $this->row();

		$this->repository->create( 'Studio Nova', CreatorStatus::Active );

		self::assertSame( array( array( 'mysql', true ) ), $calls, 'Timestamps must be UTC (gmt = true), independent of the site time zone.' );
	}

	public function test_create_without_user_stores_null(): void {
		$this->wpdb->insert_id = 8;
		$this->wpdb->rows[]    = $this->row(
			array(
				'id'      => '8',
				'user_id' => null,
			)
		);

		$creator = $this->repository->create( 'Studio Nova', CreatorStatus::Inactive );

		self::assertNull( $this->wpdb->inserts[0]['data']['user_id'] );
		self::assertSame( 'inactive', $this->wpdb->inserts[0]['data']['status'] );
		self::assertNull( $creator->user_id );
	}

	/**
	 * Names violating the column constraints.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_names(): array {
		return array(
			'empty'      => array( '' ),
			'whitespace' => array( "  \t " ),
			'too long'   => array( str_repeat( 'a', 192 ) ),
		);
	}

	#[DataProvider( 'invalid_names' )]
	public function test_create_rejects_invalid_display_names( string $name ): void {
		$this->expectException( InvalidArgumentException::class );

		$this->repository->create( $name, CreatorStatus::Active );
	}

	public function test_create_accepts_191_multibyte_characters(): void {
		$this->wpdb->insert_id = 7;
		$this->wpdb->rows[]    = $this->row();

		$this->repository->create( str_repeat( 'ø', 191 ), CreatorStatus::Active );

		self::assertCount( 1, $this->wpdb->inserts );
	}

	public function test_create_rejects_invalid_user_id_without_inserting(): void {
		try {
			$this->repository->create( 'Studio Nova', CreatorStatus::Active, 0 );
			self::fail( 'Expected InvalidArgumentException.' );
		} catch ( InvalidArgumentException ) {
			self::assertSame( array(), $this->wpdb->inserts );
		}
	}

	public function test_insert_failure_raises_generic_error_without_database_details(): void {
		$this->wpdb->insert_result = false;
		$this->wpdb->last_error    = "Duplicate entry '42' for key 'user_id' in wp_merchboard_creators";

		try {
			$this->repository->create( 'Studio Nova', CreatorStatus::Active, 42 );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not create creator.', $e->getMessage() );
			self::assertStringNotContainsString( 'Duplicate', $e->getMessage() );
		}
	}

	/**
	 * A stored row as returned by $wpdb (all values are strings or null).
	 *
	 * @param array<string, string|null> $overrides Column overrides.
	 * @return array<string, string|null>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => '7',
				'user_id'      => '42',
				'display_name' => 'Studio Nova',
				'status'       => 'active',
				'created_at'   => self::NOW,
				'updated_at'   => self::NOW,
			),
			$overrides
		);
	}
}
