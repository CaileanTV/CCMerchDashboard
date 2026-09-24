<?php
/**
 * Tests for the creator admin request handlers.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators\Admin;

use Brain\Monkey\Functions;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorAdminActions;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( CreatorAdminActions::class )]
#[CoversClass( CreatorsAdminPage::class )]
final class CreatorAdminActionsTest extends TestCase {

	use CreatorAdminEnvironment;

	private RecordingLogger $logger;

	private CreatorAdminActions $actions;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();
		$this->logger  = new RecordingLogger();
		$this->actions = new CreatorAdminActions( $this->service, $this->users, $this->logger );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce, string $action ): int|false => 'valid:' . $action === $nonce ? 1 : false );
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title = '', array $args = array() ): void {
				throw new WpDie( $message, (int) ( $args['response'] ?? 500 ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( strip_tags( $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		// Like WordPress (non-strict): drops tags and characters not allowed in logins.
		Functions\when( 'sanitize_user' )->alias( static fn( string $s ): string => trim( (string) preg_replace( '|[^a-z0-9 _.\-@]|i', '', strip_tags( $s ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'sanitize_key' )->alias( static fn( string $s ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ) );
		Functions\when( 'absint' )->alias( static fn( mixed $v ): int => abs( (int) $v ) );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( mixed ...$args ): string {
				if ( is_array( $args[0] ) ) {
					[ $params, $url ] = $args;
				} else {
					[ $key, $value, $url ] = $args;
					$params                = array( $key => $value );
				}
				return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( $params );
			}
		);
	}


	public function test_valid_create_request_creates_creator_and_redirects_to_edit(): void {
		$url = $this->actions->save( $this->form( 0, array( 'user' => 'nova' ) ) );

		self::assertStringContainsString( 'action=edit&creator=1&merchboard_notice=created', $url );
		self::assertSame( 'Studio Nova', $this->db->rows( 'merchboard_creators' )[0]['display_name'] );
		self::assertSame( array( 'creator_created' ), $this->audit_actions() );
	}

	public function test_valid_edit_request_updates_and_reports_no_changes_when_identical(): void {
		$this->actions->save( $this->form( 0 ) );

		self::assertStringContainsString( 'merchboard_notice=updated', $this->actions->save( $this->form( 1, array( 'display_name' => 'Renamed' ) ) ) );
		self::assertStringContainsString( 'merchboard_notice=no_changes', $this->actions->save( $this->form( 1, array( 'display_name' => 'Renamed' ) ) ) );
	}

	public function test_status_row_action_deactivates_and_activates(): void {
		$this->actions->save( $this->form( 0, array( 'status' => 'active' ) ) );

		self::assertStringContainsString( 'merchboard_notice=deactivated', $this->actions->set_status( $this->status_link( 1, 'inactive' ) ) );
		self::assertStringContainsString( 'merchboard_notice=activated', $this->actions->set_status( $this->status_link( 1, 'active' ) ) );
		self::assertSame( array( 'creator_created', 'creator_deactivated', 'creator_activated' ), $this->audit_actions() );
	}


	/**
	 * Users that must be stopped with 403 before any processing.
	 *
	 * @return array<string, array{list<string>}>
	 */
	public static function unauthorized_users(): array {
		return array(
			'creator'    => array( array( 'read', Capabilities::ACCESS_DASHBOARD ) ),
			'subscriber' => array( array( 'read' ) ),
			'no caps'    => array( array() ),
		);
	}

	/**
	 * Unauthorized users are stopped even with a valid nonce.
	 *
	 * @param string[] $caps Capabilities.
	 */
	#[DataProvider( 'unauthorized_users' )]
	public function test_unauthorized_users_are_stopped_for_every_action( array $caps ): void {
		$this->actions->save( $this->form( 0 ) );
		$this->caps = $caps;

		foreach ( array(
			fn() => $this->actions->save( $this->form( 0 ) ),
			fn() => $this->actions->save( $this->form( 1, array( 'display_name' => 'Hijacked' ) ) ),
			fn() => $this->actions->set_status( $this->status_link( 1, 'active' ) ),
		) as $request ) {
			$this->assert_dies( 403, $request );
		}

		self::assertCount( 1, $this->db->rows( 'merchboard_creators' ) );
		self::assertSame( 'Studio Nova', $this->db->rows( 'merchboard_creators' )[0]['display_name'] );
		self::assertContains( Capabilities::MANAGE_CREATORS, $this->capability_checks );
	}


	public function test_missing_or_invalid_nonce_stops_the_request(): void {
		$this->assert_dies( 403, fn() => $this->actions->save( array_diff_key( $this->form( 0 ), array( '_wpnonce' => true ) ) ) );
		$this->assert_dies( 403, fn() => $this->actions->save( array( '_wpnonce' => 'forged' ) + $this->form( 0 ) ) );
		$this->assert_dies( 403, fn() => $this->actions->set_status( array( '_wpnonce' => '' ) + $this->status_link( 1, 'active' ) ) );

		self::assertSame( array(), $this->db->rows( 'merchboard_creators' ) );
	}

	public function test_nonce_is_bound_to_the_creator_id_so_ids_cannot_be_swapped(): void {
		$this->actions->save( $this->form( 0 ) );
		$this->actions->save( $this->form( 0, array( 'display_name' => 'Second' ) ) );

		// Valid form nonce for creator 1, but the creator_id was changed to 2.
		$tampered               = $this->form( 1, array( 'display_name' => 'Hijacked' ) );
		$tampered['creator_id'] = '2';
		$this->assert_dies( 403, fn() => $this->actions->save( $tampered ) );

		// Valid status link for creator 1, re-targeted to creator 2.
		$link            = $this->status_link( 1, 'inactive' );
		$link['creator'] = '2';
		$this->assert_dies( 403, fn() => $this->actions->set_status( $link ) );

		self::assertSame( array( 'Studio Nova', 'Second' ), array_column( $this->db->rows( 'merchboard_creators' ), 'display_name' ) );
	}


	public function test_invalid_status_is_rejected_without_changes(): void {
		$url = $this->actions->save( $this->form( 0, array( 'status' => 'superuser' ) ) );

		self::assertStringContainsString( 'merchboard_notice=invalid_status', $url );
		self::assertSame( array(), $this->db->rows( 'merchboard_creators' ) );
	}

	public function test_unknown_or_invalid_users_are_rejected(): void {
		foreach ( array( 'nobody', '#999', '#0', '#abc', '42', 'Nova (nova)', 'nova@example.test' ) as $value ) {
			self::assertStringContainsString( 'merchboard_notice=user_not_found', $this->actions->save( $this->form( 0, array( 'user' => $value ) ) ), $value );
		}

		self::assertSame( array(), $this->db->rows( 'merchboard_creators' ) );
	}

	public function test_user_can_be_given_by_login_or_hash_id(): void {
		$this->actions->save( $this->form( 0, array( 'user' => ' nova ' ) ) );
		$this->actions->save( $this->form( 0, array( 'user' => '#43' ) ) );

		self::assertSame( array( '42', '43' ), array_column( $this->db->rows( 'merchboard_creators' ), 'user_id' ) );
	}

	public function test_unchanged_link_to_a_deleted_user_can_be_saved_as_hash_id(): void {
		$this->actions->save( $this->form( 0, array( 'user' => 'nova' ) ) );
		unset( $this->users->users[42] );

		$url = $this->actions->save( $this->form( 1, array( 'user' => '#42', 'display_name' => 'Renamed' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertStringContainsString( 'merchboard_notice=updated', $url );
		self::assertSame( '42', $this->db->rows( 'merchboard_creators' )[0]['user_id'], 'Kept, not silently unlinked.' );
	}

	public function test_empty_user_means_no_user(): void {
		$this->actions->save( $this->form( 0, array( 'user' => '' ) ) );

		self::assertNull( $this->db->rows( 'merchboard_creators' )[0]['user_id'] );
	}

	public function test_display_name_is_sanitized(): void {
		$this->actions->save( $this->form( 0, array( 'display_name' => '<script>alert(1)</script>Nova' ) ) );

		self::assertStringNotContainsString( '<script', (string) $this->db->rows( 'merchboard_creators' )[0]['display_name'] );
	}

	public function test_non_scalar_input_is_treated_as_empty(): void {
		$url = $this->actions->save( $this->form( 0, array( 'display_name' => array( 'x' ) ) ) );

		self::assertStringContainsString( 'merchboard_notice=invalid_display_name', $url );
	}

	public function test_request_cannot_set_actor_timestamp_or_ids(): void {
		$this->actions->save(
			$this->form(
				0,
				array(
					'actor_user_id' => '1',
					'created_at'    => '1999-01-01 00:00:00',
					'id'            => '500',
				)
			)
		);

		$audit   = $this->db->rows( 'merchboard_audit_log' )[0];
		$creator = $this->db->rows( 'merchboard_creators' )[0];
		self::assertSame( '7', $audit['actor_user_id'] );
		self::assertSame( $this->now, $audit['created_at'] );
		self::assertSame( '1', $creator['id'] );
		self::assertSame( $this->now, $creator['created_at'] );
	}

	public function test_status_change_for_unknown_creator_reports_not_found(): void {
		self::assertStringContainsString( 'merchboard_notice=not_found', $this->actions->set_status( $this->status_link( 404, 'active' ) ) );
	}


	public function test_technical_failure_is_logged_once_and_shown_neutrally(): void {
		$this->actions->save( $this->form( 0 ) );
		$this->db->fail_writes_to = array( 'merchboard_audit_log' );

		$url = $this->actions->save( $this->form( 1, array( 'display_name' => 'Renamed' ) ) );

		self::assertStringContainsString( 'merchboard_notice=error', $url );
		self::assertStringNotContainsString( 'Forced', $url );
		self::assertSame( array( 'error' ), array_column( $this->logger->entries, 'level' ) );
		self::assertSame( 'save', $this->logger->entries[0]['context']['operation'] );
		self::assertSame( 1, $this->logger->entries[0]['context']['creator_id'] );
		self::assertSame( 'Studio Nova', $this->db->rows( 'merchboard_creators' )[0]['display_name'], 'Rolled back.' );
	}


	public function test_only_whitelisted_notice_codes_have_messages(): void {
		foreach ( array( 'created', 'updated', 'activated', 'deactivated', 'no_changes', 'not_found', 'not_authorized', 'invalid_display_name', 'invalid_status', 'user_not_found', 'user_already_linked', 'error' ) as $code ) {
			self::assertNotNull( CreatorsAdminPage::notice_message( $code ), $code );
		}

		self::assertNull( CreatorsAdminPage::notice_message( '<script>' ) );
		self::assertNull( CreatorsAdminPage::notice_message( 'anything_else' ) );
	}

	public function test_error_messages_contain_no_technical_details(): void {
		$message = CreatorsAdminPage::notice_message( 'error' );

		self::assertNotNull( $message );
		self::assertDoesNotMatchRegularExpression( '/SQL|SELECT|INSERT|wpdb|\.php|Exception/i', $message[0] );
	}

	/**
	 * A valid form submission.
	 *
	 * @param int                  $creator_id Creator id (0 = new).
	 * @param array<string, mixed> $overrides  Field overrides.
	 * @return array<string, mixed>
	 */
	private function form( int $creator_id, array $overrides = array() ): array {
		return array_merge(
			array(
				'_wpnonce'     => 'valid:' . CreatorAdminActions::save_nonce_action( $creator_id ),
				'creator_id'   => (string) $creator_id,
				'display_name' => 'Studio Nova',
				'status'       => CreatorStatus::Inactive->value,
				'user'         => '',
			),
			$overrides
		);
	}

	/**
	 * A valid status row-action link.
	 *
	 * @param int    $creator_id Creator id.
	 * @param string $status     Target status.
	 * @return array<string, string>
	 */
	private function status_link( int $creator_id, string $status ): array {
		return array(
			'_wpnonce' => 'valid:' . CreatorAdminActions::status_nonce_action( $creator_id ),
			'creator'  => (string) $creator_id,
			'status'   => $status,
		);
	}

	/**
	 * Asserts that the request is stopped with wp_die().
	 *
	 * @param int      $status  Expected HTTP status.
	 * @param callable $request Request.
	 */
	private function assert_dies( int $status, callable $request ): void {
		try {
			$request();
			self::fail( 'Expected the request to be stopped.' );
		} catch ( WpDie $e ) {
			self::assertSame( $status, $e->response );
		}
	}
}
