<?php
/**
 * Tests for the deny-by-default guard of the protected product meta.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Security;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use InvalidArgumentException;
use LogicException;
use MerchBoard\Core\Security\ProtectedMetaPurpose;
use MerchBoard\Core\Security\ProtectedMetaWrite;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass( ProtectedProductMeta::class )]
#[CoversClass( ProtectedMetaWrite::class )]
final class ProtectedProductMetaTest extends TestCase {

	private const LINK   = ProtectedProductMeta::LINK_KEY;
	private const SOURCE = ProtectedProductMeta::SOURCE_KEY;

	private RecordingLogger $logger;

	private ProtectedProductMeta $guard;

	/**
	 * Meta rows by id, as get_metadata_by_mid() returns them.
	 *
	 * @var array<int, object>
	 */
	private array $rows = array();

	protected function setUp(): void {
		parent::setUp();
		$this->logger = new RecordingLogger();
		$this->guard  = new ProtectedProductMeta( $this->logger );
		$this->rows   = array(
			100 => (object) array(
				'post_id'  => 10,
				'meta_key' => self::LINK, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture row.
			),
			101 => (object) array(
				'post_id'  => 10,
				'meta_key' => '_price', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture row.
			),
			102 => (object) array(
				'post_id'  => 11,
				'meta_key' => ' _MerchBoard_Creator_Link_Source ', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture row.
			),
		);

		Functions\when( 'get_metadata_by_mid' )->alias( fn( string $type, int $id ): object|false => 'post' === $type ? ( $this->rows[ $id ] ?? false ) : false );
	}

	/**
	 * Protected keys in any spelling MySQL treats as equal.
	 *
	 * @return array<string, array{string}>
	 */
	public static function protected_keys(): array {
		return array(
			'link key'           => array( self::LINK ),
			'source key'         => array( self::SOURCE ),
			'link key, cased'    => array( '_Merchboard_Creator_ID' ),
			'source key, padded' => array( ' _MERCHBOARD_CREATOR_LINK_SOURCE ' ),
		);
	}

	#[DataProvider( 'protected_keys' )]
	public function test_generic_add_update_and_delete_of_a_protected_key_are_denied( string $key ): void {
		self::assertTrue( ProtectedProductMeta::is_protected_key( $key ) );
		self::assertFalse( $this->guard->filter_add( null, 10, $key ) );
		self::assertFalse( $this->guard->filter_update( null, 10, $key ) );
		self::assertFalse( $this->guard->filter_delete( null, 10, $key ) );
		self::assertFalse( $this->guard->filter_delete( null, 0, $key, '', true ), 'Deleting the key from every post.' );
		self::assertCount( 4, $this->logger->entries );
		self::assertSame( 'warning', $this->logger->entries[0]['level'] );
		self::assertSame( strtolower( trim( $key ) ), $this->logger->entries[0]['context']['key'] );
		self::assertArrayNotHasKey( 'value', $this->logger->entries[0]['context'], 'Values are never logged.' );
	}

	public function test_other_meta_is_left_alone(): void {
		foreach ( array( '_price', '_sku', 'merchboard_creator_id', '_merchboard_creator', '_merchboard_creator_id_x', '' ) as $key ) {
			self::assertFalse( ProtectedProductMeta::is_protected_key( $key ), $key );
			self::assertNull( $this->guard->filter_add( null, 10, $key ) );
			self::assertNull( $this->guard->filter_update( null, 10, $key ) );
			self::assertNull( $this->guard->filter_delete( null, 10, $key ) );
		}

		self::assertTrue( $this->guard->filter_update( true, 10, '_price' ), 'Another plugin\'s short-circuit is kept.' );
		self::assertFalse( ProtectedProductMeta::is_protected_key( null ) );
		self::assertSame( array(), $this->logger->entries );
	}

	public function test_writes_by_meta_id_check_the_stored_and_the_new_key(): void {
		self::assertFalse( $this->guard->filter_update_by_mid( null, 100, '5' ), 'Changing a stored link.' );
		self::assertFalse( $this->guard->filter_update_by_mid( null, 100, '5', '_other' ), 'Renaming a link away.' );
		self::assertFalse( $this->guard->filter_update_by_mid( null, 101, '5', self::LINK ), 'Renaming other meta into a link.' );
		self::assertFalse( $this->guard->filter_delete_by_mid( null, 100 ) );
		self::assertFalse( $this->guard->filter_delete_by_mid( null, 102 ), 'Stored in another spelling.' );

		self::assertNull( $this->guard->filter_update_by_mid( null, 101, '5' ) );
		self::assertNull( $this->guard->filter_update_by_mid( null, 101, '5', '_regular_price' ) );
		self::assertNull( $this->guard->filter_delete_by_mid( null, 101 ) );
		self::assertNull( $this->guard->filter_delete_by_mid( null, 999 ), 'Unknown rows are WordPress\' business.' );
	}

	public function test_a_write_authorizes_exactly_its_product_and_only_while_it_runs(): void {
		$inside = $this->guard->run(
			ProtectedMetaWrite::for_manual_link( 10 ),
			fn(): array => array(
				$this->guard->filter_add( null, 10, self::LINK ),
				$this->guard->filter_update( null, 10, self::SOURCE ),
				$this->guard->filter_delete( null, 10, '_Merchboard_Creator_ID' ),
				$this->guard->filter_update_by_mid( null, 100, '3' ),
				$this->guard->filter_delete_by_mid( null, 100 ),
				$this->guard->filter_update( null, 11, self::LINK ),
				$this->guard->filter_delete_by_mid( null, 102 ),
				$this->guard->filter_delete( null, 0, self::LINK, '', true ),
			)
		);

		self::assertSame( array( null, null, null, null, null, false, false, false ), $inside );
		self::assertFalse( $this->guard->filter_update( null, 10, self::LINK ), 'Nothing is authorized afterwards.' );
	}

	public function test_a_write_is_cleared_when_the_operation_fails(): void {
		try {
			$this->guard->run(
				ProtectedMetaWrite::for_manual_link( 10 ),
				static function (): void {
					throw new RuntimeException( 'write failed' );
				}
			);
		} catch ( RuntimeException $e ) {
			self::assertSame( 'write failed', $e->getMessage() );
		}

		self::assertFalse( $this->guard->filter_update( null, 10, self::LINK ) );
	}

	public function test_a_write_cannot_be_reused(): void {
		$write = ProtectedMetaWrite::for_manual_link( 10 );
		$this->guard->run( $write, static fn(): bool => true );

		$this->expectException( LogicException::class );
		$this->guard->run( $write, static fn(): bool => true );
	}

	public function test_writes_cannot_be_nested(): void {
		$outer = $this->guard->run(
			ProtectedMetaWrite::for_manual_link( 10 ),
			function (): string {
				try {
					$this->guard->run( ProtectedMetaWrite::for_manual_link( 11 ), static fn(): bool => true );
				} catch ( LogicException ) {
					return 'rejected';
				}

				return 'nested';
			}
		);

		self::assertSame( 'rejected', $outer );
		self::assertFalse( $this->guard->filter_update( null, 11, self::LINK ), 'The rejected write authorized nothing.' );
	}

	public function test_the_system_write_needs_no_current_user(): void {
		Functions\expect( 'current_user_can' )->never();
		Functions\expect( 'get_current_user_id' )->never();

		$write  = ProtectedMetaWrite::for_category_reconciliation( 10 );
		$result = $this->guard->run( $write, fn(): mixed => $this->guard->filter_update( null, 10, self::LINK ) );

		self::assertNull( $result );
		self::assertSame( ProtectedMetaPurpose::CategoryReconciliation, $write->purpose );
		self::assertSame( ProtectedMetaPurpose::ManualLink, ProtectedMetaWrite::for_manual_link( 1 )->purpose );
	}

	public function test_a_write_needs_a_product(): void {
		$this->expectException( InvalidArgumentException::class );
		ProtectedMetaWrite::for_manual_link( 0 );
	}

	public function test_a_permanently_deleted_post_takes_its_protected_meta_along(): void {
		$this->guard->deleting_post( 10 );

		self::assertNull( $this->guard->filter_delete_by_mid( null, 100 ), 'Its own rows.' );
		self::assertFalse( $this->guard->filter_delete_by_mid( null, 102 ), 'Not another post\'s rows.' );
		self::assertFalse( $this->guard->filter_update( null, 10, self::LINK ), 'Only deletion.' );

		$this->guard->deleted_post( 10 );

		self::assertFalse( $this->guard->filter_delete_by_mid( null, 100 ) );
	}

	public function test_registers_on_the_metadata_api_after_other_plugins(): void {
		$this->guard->register();

		self::assertSame( 1000, has_filter( 'add_post_metadata', array( $this->guard, 'filter_add' ) ) );
		self::assertSame( 1000, has_filter( 'update_post_metadata', array( $this->guard, 'filter_update' ) ) );
		self::assertSame( 1000, has_filter( 'delete_post_metadata', array( $this->guard, 'filter_delete' ) ) );
		self::assertSame( 1000, has_filter( 'update_post_metadata_by_mid', array( $this->guard, 'filter_update_by_mid' ) ) );
		self::assertSame( 1000, has_filter( 'delete_post_metadata_by_mid', array( $this->guard, 'filter_delete_by_mid' ) ) );
		self::assertSame( 0, has_action( 'before_delete_post', array( $this->guard, 'deleting_post' ) ) );
		self::assertSame( PHP_INT_MAX, has_action( 'after_delete_post', array( $this->guard, 'deleted_post' ) ) );
	}

	public function test_registering_twice_adds_each_hook_once(): void {
		Filters\expectAdded( 'update_post_metadata' )->once();
		Actions\expectAdded( 'before_delete_post' )->once();

		$this->guard->register();
		$this->guard->register();
	}

	public function test_core_owns_the_unchanged_key_names(): void {
		self::assertSame( '_merchboard_creator_id', ProtectedProductMeta::LINK_KEY );
		self::assertSame( '_merchboard_creator_link_source', ProtectedProductMeta::SOURCE_KEY );
		self::assertSame( array( ProtectedProductMeta::LINK_KEY, ProtectedProductMeta::SOURCE_KEY ), ProtectedProductMeta::KEYS );
	}
}
