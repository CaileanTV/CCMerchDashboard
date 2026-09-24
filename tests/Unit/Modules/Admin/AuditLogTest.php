<?php
/**
 * Tests for the read-only audit log: repository reads, filters, formatter,
 * access and menu.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Admin;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditEntry;
use MerchBoard\Core\Audit\AuditFilter;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AuditLogFormatter;
use MerchBoard\Modules\Admin\AuditLogPage;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( AuditRepository::class )]
#[CoversClass( AuditFilter::class )]
#[CoversClass( AuditLogPage::class )]
#[CoversClass( AuditLogFormatter::class )]
final class AuditLogTest extends TestCase {

	use CreatorAdminEnvironment;

	private AuditRepository $audit;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();
		$this->audit = new AuditRepository( $this->db );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'sanitize_key' )->alias( static fn( string $s ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ) );
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title = '', array $args = array() ): void {
				throw new WpDie( $message, (int) ( $args['response'] ?? 500 ) );
			}
		);
	}

	public function test_paginate_returns_the_requested_page_newest_first_with_the_total(): void {
		$this->seed( 25 );

		$first = $this->audit->paginate( new AuditFilter(), 1, 20 );
		$last  = $this->audit->paginate( new AuditFilter(), 2, 20 );

		self::assertSame( 25, $first['total'] );
		self::assertCount( 20, $first['items'] );
		self::assertSame( 25, $first['items'][0]->id, 'Newest first.' );
		self::assertSame( array( 5, 4, 3, 2, 1 ), array_map( static fn( AuditEntry $e ): int => $e->id, $last['items'] ) );
		self::assertSame( array(), $this->audit->paginate( new AuditFilter(), 99, 20 )['items'], 'Beyond the last page: empty, no error.' );
	}

	public function test_paginate_bounds_page_and_page_size_and_never_selects_the_whole_table(): void {
		$this->seed( 3 );

		self::assertCount( 1, $this->audit->paginate( new AuditFilter(), -5, 0 )['items'], 'Page >= 1, size >= 1.' );
		self::assertCount( 3, $this->audit->paginate( new AuditFilter(), 1, 100000 )['items'] );

		$wpdb = new \MerchBoard\Tests\Unit\Support\RecordingWpdb();
		( new AuditRepository( $wpdb ) )->paginate( new AuditFilter(), 3, 100000 );
		self::assertStringEndsWith( 'ORDER BY id DESC LIMIT %d OFFSET %d', $wpdb->prepared[1]['query'] );
		self::assertSame( array( 'wp_merchboard_audit_log', 100, 200 ), $wpdb->prepared[1]['args'], 'Capped at 100 per page, offset from the page.' );
	}

	public function test_filters_are_applied_as_placeholders(): void {
		$this->seed( 4 );
		$this->db->data['wp_merchboard_audit_log'][4]['action']     = 'creator_deactivated';
		$this->db->data['wp_merchboard_audit_log'][4]['created_at'] = '2026-09-25 10:00:00';

		self::assertSame( 1, $this->audit->paginate( new AuditFilter( AuditAction::CreatorDeactivated ), 1, 20 )['total'] );
		self::assertSame( 2, $this->audit->paginate( new AuditFilter( null, 2 ), 1, 20 )['total'] );
		self::assertSame( 1, $this->audit->paginate( new AuditFilter( null, null, '2026-09-25 00:00:00', '2026-09-26 00:00:00' ), 1, 20 )['total'] );
		self::assertSame( 0, $this->audit->paginate( new AuditFilter( AuditAction::SettingsUpdated, 1 ), 1, 20 )['total'] );

		$wpdb = new \MerchBoard\Tests\Unit\Support\RecordingWpdb();
		( new AuditRepository( $wpdb ) )->paginate( new AuditFilter( AuditAction::CreatorCreated, 7, '2026-01-01 00:00:00', '2026-02-01 00:00:00' ), 1, 20 );
		self::assertSame( 'SELECT COUNT(*) FROM %i WHERE 1=1 AND action = %s AND creator_id = %d AND created_at >= %s AND created_at < %s', $wpdb->prepared[0]['query'] );
		self::assertSame( array( 'wp_merchboard_audit_log', 'creator_created', 7, '2026-01-01 00:00:00', '2026-02-01 00:00:00' ), $wpdb->prepared[0]['args'] );
	}

	public function test_filter_rejects_invalid_shapes(): void {
		foreach ( array( array( null, 0 ), array( null, -1 ), array( null, null, "2026-01-01' OR 1=1" ), array( null, null, null, '2026-01-01' ) ) as $args ) {
			try {
				new AuditFilter( ...$args );
				self::fail( 'Expected InvalidArgumentException.' );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * Request values that must be ignored.
	 *
	 * @return array<string, array{array<string, string>, string}>
	 */
	public static function invalid_requests(): array {
		return array(
			'unknown event'         => array( array( 'event' => 'creator_deleted' ), 'event' ),
			'sql in event'          => array( array( 'event' => "creator_created' OR 1=1 --" ), 'event' ),
			'wildcard event'        => array( array( 'event' => 'creator_%' ), 'event' ),
			'creator zero'          => array( array( 'creator' => '0' ), 'creator' ),
			'negative creator'      => array( array( 'creator' => '-3' ), 'creator' ),
			'sql in creator'        => array( array( 'creator' => '1 OR 1=1' ), 'creator' ),
			'wildcard creator'      => array( array( 'creator' => '%' ), 'creator' ),
			'overflow date'         => array( array( 'from' => '2026-02-31' ), 'from' ),
			'other date format'     => array( array( 'to' => '24.09.2026' ), 'to' ),
			'sql in date'           => array( array( 'from' => "2026-01-01' OR '1'='1" ), 'from' ),
			'period in wrong order' => array(
				array(
					'from' => '2026-09-25',
					'to'   => '2026-09-24',
				),
				'period',
			),
		);
	}

	/**
	 * Invalid values are dropped and reported, never used.
	 *
	 * @param array<string, string> $input   Request.
	 * @param string                $invalid Reported argument.
	 */
	#[DataProvider( 'invalid_requests' )]
	public function test_invalid_filter_values_are_ignored( array $input, string $invalid ): void {
		$request = AuditLogPage::filter_from_request( $input, new DateTimeZone( 'UTC' ) );

		self::assertTrue( $request['filter']->is_empty() );
		self::assertSame( array(), $request['values'] );
		self::assertContains( $invalid, $request['invalid'] );
	}

	public function test_valid_filters_and_day_boundaries_in_the_site_time_zone(): void {
		$request = AuditLogPage::filter_from_request(
			array(
				'event'   => 'creator_activated',
				'creator' => '12',
				'from'    => '2026-09-24',
				'to'      => '2026-09-24',
				'ignored' => 'x',
			),
			new DateTimeZone( 'Europe/Copenhagen' )
		);

		self::assertSame( array(), $request['invalid'] );
		self::assertSame( AuditAction::CreatorActivated, $request['filter']->action );
		self::assertSame( 12, $request['filter']->creator_id );
		self::assertSame( '2026-09-23 22:00:00', $request['filter']->from, 'Local midnight (UTC+2) in UTC.' );
		self::assertSame( '2026-09-24 22:00:00', $request['filter']->until, 'Exclusive end: the next local midnight.' );
		self::assertSame(
			array(
				'event'   => 'creator_activated',
				'creator' => '12',
				'from'    => '2026-09-24',
				'to'      => '2026-09-24',
			),
			$request['values']
		);
	}

	public function test_non_scalar_input_is_ignored(): void {
		$request = AuditLogPage::filter_from_request( array( 'event' => array( 'x' ), 'creator' => array( 1 ), 'from' => array() ), new DateTimeZone( 'UTC' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertTrue( $request['filter']->is_empty() );
	}

	public function test_every_action_has_a_label(): void {
		foreach ( AuditAction::cases() as $action ) {
			self::assertNotSame( '', AuditLogFormatter::action_label( $action ) );
		}
	}

	public function test_details_are_readable_and_never_raw_json(): void {
		$entry = $this->entry(
			AuditAction::CreatorUserUnlinked,
			array(
				'user_id'         => 42,
				'reason'          => 'user_deleted',
				'previous_status' => 'active',
				'changed_fields'  => array( 'display_name' ),
				'unknown <b>key'  => str_repeat( 'x', 150 ),
				'nested'          => array( 'email' => 'someone@example.test' ),
			)
		);

		$lines = AuditLogFormatter::details( $entry, array( 42 => 'Nova (nova)' ) );

		self::assertSame( 'WordPress user: Nova (nova)', $lines[0] );
		self::assertSame( 'Reason: the WordPress user was deleted', $lines[1] );
		self::assertSame( 'Previous status: Active', $lines[2] );
		self::assertSame( 'Changed: display name', $lines[3] );
		self::assertSame( 'unknownbkey: ' . str_repeat( 'x', 100 ) . '…', $lines[4], 'Unknown keys sanitized, values truncated.' );
		self::assertSame( 'nested: …', $lines[5], 'Nested data is not shown.' );
		self::assertStringNotContainsString( '@', implode( ' ', $lines ) );
		self::assertStringNotContainsString( '{', implode( ' ', $lines ) );
	}

	public function test_deleted_users_and_settings_changes_are_described(): void {
		self::assertSame(
			array( 'WordPress user: #7 (no longer exists)' ),
			AuditLogFormatter::details( $this->entry( AuditAction::CreatorUserLinked, array( 'user_id' => 7 ) ), array() )
		);
		self::assertSame(
			array( 'Setting: delete all data when the plugin is deleted', 'New value: enabled' ),
			AuditLogFormatter::details(
				$this->entry(
					AuditAction::SettingsUpdated,
					array(
						'setting' => 'delete_data_on_uninstall',
						'enabled' => true,
					)
				),
				array()
			)
		);
		self::assertSame( array( 'WordPress user: none', 'Status: Inactive' ), AuditLogFormatter::details( $this->entry( AuditAction::CreatorCreated, array( 'user_id' => null, 'status' => 'inactive' ) ), array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_page_requires_merchboard_view_logs(): void {
		foreach ( array( array( Capabilities::MANAGE ), array( Capabilities::MANAGE_CREATORS ), array( Capabilities::MANAGE_SETTINGS ), array( Capabilities::ACCESS_DASHBOARD ), array( 'manage_options', 'manage_woocommerce' ) ) as $caps ) {
			$this->caps = $caps;

			try {
				$this->page()->render();
				self::fail( 'Expected WpDie for ' . implode( ',', $caps ) );
			} catch ( WpDie $e ) {
				self::assertSame( 403, $e->response );
			}
		}
	}

	public function test_menu_entry_uses_merchboard_view_logs(): void {
		$registered = array();
		Functions\when( 'add_submenu_page' )->alias(
			static function ( string $parent_slug, string $page_title, string $menu_title, string $capability, string $slug ) use ( &$registered ): string {
				$registered = array( $parent_slug, $capability, $slug );
				return 'hook';
			}
		);

		$this->page()->add_menu();

		self::assertSame( array( 'merchboard', Capabilities::VIEW_LOGS, 'merchboard-audit-log' ), $registered );
	}

	/**
	 * Adds audit records with increasing time (creator 1 for odd, 2 for even ids).
	 *
	 * @param int $count Number of records.
	 */
	private function seed( int $count ): void {
		for ( $i = 1; $i <= $count; $i++ ) {
			$this->audit->append( AuditAction::CreatorCreated, 1, 1 === $i % 2 ? 1 : 2, array(), sprintf( '2026-09-24 10:%02d:00', $i ) );
		}
	}

	/**
	 * An in-memory audit entry.
	 *
	 * @param AuditAction          $action  Action.
	 * @param array<string, mixed> $context Context.
	 */
	private function entry( AuditAction $action, array $context ): AuditEntry {
		return new AuditEntry( 1, $action, 1, 1, $context, new DateTimeImmutable( '2026-09-24 10:00:00', new DateTimeZone( 'UTC' ) ) );
	}

	private function page(): AuditLogPage {
		return new AuditLogPage( $this->audit, new CreatorRepository( $this->db ), $this->users, new RecordingLogger() );
	}
}
