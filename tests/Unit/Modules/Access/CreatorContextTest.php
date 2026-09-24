<?php
/**
 * Tests for the creator access policy.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Access;

use Brain\Monkey\Functions;
use MerchBoard\Core\Container;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Access\AccessModule;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use wpdb;

#[CoversClass( CreatorContext::class )]
#[CoversClass( AccessModule::class )]
final class CreatorContextTest extends TestCase {

	private FakeDatabase $db;

	private CreatorRepository $creators;

	private RecordingLogger $logger;

	/**
	 * Capabilities per existing user id (users not listed do not exist).
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $user_caps = array();

	private int $current_user_id = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->db       = new FakeDatabase();
		$this->creators = new CreatorRepository( $this->db );
		$this->logger   = new RecordingLogger();

		Functions\when( 'current_time' )->justReturn( '2026-09-24 12:00:00' );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current_user_id );
		// Like WordPress: false for users that do not exist.
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool => in_array( $capability, $this->user_caps[ $user_id ] ?? array(), true )
		);
	}

	/**
	 * The access matrix: capability × creator record × status.
	 *
	 * @return array<string, array{list<string>, CreatorStatus|null, bool}>
	 */
	public static function matrix(): array {
		$admin = array( Capabilities::MANAGE, Capabilities::MANAGE_CREATORS, Capabilities::MANAGE_SETTINGS, 'manage_options' );

		return array(
			'subscriber without creator'           => array( array( 'read' ), null, false ),
			'administrator without creator'        => array( $admin, null, false ),
			'capability without creator record'    => array( array( 'read', Capabilities::ACCESS_DASHBOARD ), null, false ),
			'active creator record, no capability' => array( array( 'read' ), CreatorStatus::Active, false ),
			'administrator with active creator'    => array( $admin, CreatorStatus::Active, false ),
			'capability and inactive creator'      => array( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Inactive, false ),
			'capability and active creator'        => array( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active, true ),
		);
	}

	/**
	 * Access for the current user in every combination.
	 *
	 * @param array<int, string> $caps     Capabilities of the user.
	 * @param CreatorStatus|null $status   Status of the linked creator; null = no record.
	 * @param bool               $expected Whether access is granted.
	 */
	#[DataProvider( 'matrix' )]
	public function test_access_matrix( array $caps, ?CreatorStatus $status, bool $expected ): void {
		$this->user_caps[5]    = $caps;
		$this->current_user_id = 5;
		$creator               = null === $status ? null : $this->creators->create( 'Nova', $status, 5 );
		$context               = $this->context();

		self::assertSame( $expected, $context->current_user_has_access() );
		self::assertSame( $expected, $context->user_has_access( 5 ) );
		self::assertSame( $expected ? $creator?->id : null, $context->current()?->id );
		self::assertSame( array(), $this->logger->entries );
	}

	public function test_logged_out_visitor_has_no_access_and_nothing_is_queried(): void {
		$this->current_user_id = 0;

		self::assertFalse( $this->context()->current_user_has_access() );
		self::assertNull( $this->context()->current() );
	}

	public function test_deleted_or_unknown_user_has_no_access_even_with_a_linked_active_record(): void {
		// A record still pointing at a user id that no longer exists.
		$this->creators->create( 'Orphan', CreatorStatus::Active, 99 );

		self::assertFalse( $this->context()->user_has_access( 99 ) );
		self::assertFalse( $this->context()->user_has_access( 0 ) );
		self::assertFalse( $this->context()->user_has_access( -3 ) );
	}

	public function test_deactivation_removes_access_immediately_without_role_changes(): void {
		$this->user_caps[5]    = array( Capabilities::ACCESS_DASHBOARD );
		$this->current_user_id = 5;
		$creator               = $this->creators->create( 'Nova', CreatorStatus::Active, 5 );
		$context               = $this->context();

		self::assertTrue( $context->current_user_has_access() );

		$this->creators->set_status( $creator->id, CreatorStatus::Inactive );
		self::assertFalse( $context->current_user_has_access(), 'Not memoized: the same instance sees the change.' );

		$this->creators->set_status( $creator->id, CreatorStatus::Active );
		self::assertTrue( $context->current_user_has_access() );

		$this->creators->unlink_user( $creator->id );
		self::assertFalse( $context->current_user_has_access() );
	}

	public function test_the_creator_is_resolved_from_the_user_not_from_request_data(): void {
		$this->user_caps[5]    = array( Capabilities::ACCESS_DASHBOARD );
		$this->user_caps[6]    = array( Capabilities::ACCESS_DASHBOARD );
		$this->current_user_id = 5;
		$mine                  = $this->creators->create( 'Mine', CreatorStatus::Active, 5 );
		$this->creators->create( 'Other', CreatorStatus::Active, 6 );
		$_GET['creator_id'] = '2'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Proves request data is ignored.

		try {
			self::assertSame( $mine->id, $this->context()->current()?->id );
		} finally {
			unset( $_GET['creator_id'] );
		}
	}

	public function test_database_failure_is_logged_and_denies_access(): void {
		$this->user_caps[5] = array( Capabilities::ACCESS_DASHBOARD );
		$this->creators->create( 'Nova', CreatorStatus::Active, 5 );
		$this->db->data['wp_merchboard_creators'][1]['status'] = 'corrupted';

		self::assertFalse( $this->context()->user_has_access( 5 ) );
		self::assertSame( array( 'error' ), array_column( $this->logger->entries, 'level' ) );
		self::assertSame( 5, $this->logger->entries[0]['context']['user_id'] );
	}

	public function test_module_registers_the_policy(): void {
		$container = new Container();
		$container->set( CreatorRepository::class, fn(): CreatorRepository => $this->creators );
		$container->set( Logger::class, fn(): Logger => $this->logger );
		$container->set( wpdb::class, fn(): wpdb => $this->db );

		( new AccessModule() )->register( $container );

		self::assertSame( 'access', ( new AccessModule() )->id() );
		self::assertInstanceOf( CreatorContext::class, $container->get( CreatorContext::class ) );
	}

	private function context(): CreatorContext {
		return new CreatorContext( $this->creators, $this->logger );
	}
}
