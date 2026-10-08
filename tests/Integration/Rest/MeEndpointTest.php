<?php
/**
 * GET /me, GET and PATCH /me/profile and PATCH /me/preferences against the
 * real WordPress REST server, users, roles and database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Rest;

use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Dashboard\ThemePreference;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

final class MeEndpointTest extends IntegrationTestCase {

	private CreatorService $creators;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->creators            = $this->service( CreatorService::class );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	protected function tearDown(): void {
		$this->wpdb->query( 'DROP TRIGGER IF EXISTS merchboard_test_fail' );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		parent::tearDown();
	}

	public function test_routes_are_registered_with_permission_callbacks(): void {
		$routes = rest_get_server()->get_routes( 'merchboard/v1' );

		self::assertSame( array( '/merchboard/v1', '/merchboard/v1/me', '/merchboard/v1/me/profile', '/merchboard/v1/me/preferences' ), array_keys( $routes ) );

		$methods = array();

		foreach ( array( '/merchboard/v1/me', '/merchboard/v1/me/profile', '/merchboard/v1/me/preferences' ) as $route ) {
			foreach ( $routes[ $route ] as $endpoint ) {
				self::assertIsCallable( $endpoint['permission_callback'] ?? null, $route );
				self::assertNotSame( '__return_true', $endpoint['permission_callback'] );
				$methods[ $route ][] = implode( ',', array_keys( array_filter( $endpoint['methods'] ) ) );
			}
		}

		self::assertSame(
			array(
				'/merchboard/v1/me'             => array( 'GET' ),
				'/merchboard/v1/me/profile'     => array( 'GET', 'PATCH' ),
				'/merchboard/v1/me/preferences' => array( 'PATCH' ),
			),
			$methods
		);
	}

	/**
	 * The six themes of ADR-043.
	 *
	 * @return array<string, array{string}>
	 */
	public static function themes(): array {
		return array_combine( ThemePreference::THEMES, array_map( static fn( string $theme ): array => array( $theme ), ThemePreference::THEMES ) );
	}

	/**
	 * Each theme is saved in the user's meta and echoed back.
	 *
	 * @param string $theme Theme.
	 */
	#[DataProvider( 'themes' )]
	public function test_patch_preferences_saves_each_theme( string $theme ): void {
		$user = $this->creator_user( 'theme_creator', 'Nova', CreatorStatus::Active );
		wp_set_current_user( $user );
		$audit = $this->count_rows( Tables::AUDIT_LOG );

		$response = $this->request( 'PATCH', '/me/preferences', array( 'theme' => $theme ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'theme' => $theme ), $response->get_data() );
		self::assertSame( $theme, get_user_meta( $user, ThemePreference::META_KEY, true ) );
		self::assertSame( $audit, $this->count_rows( Tables::AUDIT_LOG ), 'No audit record for a UI preference.' );
	}

	public function test_null_resets_to_the_system_preference(): void {
		$user = $this->creator_user( 'theme_creator', 'Nova', CreatorStatus::Active );
		wp_set_current_user( $user );
		update_user_meta( $user, ThemePreference::META_KEY, 'dark-red' );

		$response = $this->request( 'PATCH', '/me/preferences', array( 'theme' => null ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'theme' => null ), $response->get_data() );
		self::assertSame( array(), get_user_meta( $user, ThemePreference::META_KEY, false ), 'The meta row is deleted, nothing about the system is stored.' );

		self::assertSame( 200, $this->request( 'PATCH', '/me/preferences', array( 'theme' => null ) )->get_status(), 'Resetting again is fine.' );
	}

	/**
	 * Bodies that must be refused with 400.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function invalid_preferences(): array {
		return array(
			'unknown theme'  => array( array( 'theme' => 'dark-purple' ) ),
			'system guess'   => array( array( 'theme' => 'dark' ) ),
			'different case' => array( array( 'theme' => 'Dark-Blue' ) ),
			'empty string'   => array( array( 'theme' => '' ) ),
			'number'         => array( array( 'theme' => 3 ) ),
			'boolean'        => array( array( 'theme' => true ) ),
			'array'          => array( array( 'theme' => array( 'dark-blue' ) ) ),
			'markup'         => array( array( 'theme' => 'light"><script>' ) ),
			'missing theme'  => array( array( 'other' => 'dark-blue' ) ),
		);
	}

	/**
	 * Invalid input gives 400 and changes nothing.
	 *
	 * @param array<string, mixed> $body Body.
	 */
	#[DataProvider( 'invalid_preferences' )]
	public function test_invalid_preferences_give_400_and_change_nothing( array $body ): void {
		$user = $this->creator_user( 'theme_creator', 'Nova', CreatorStatus::Active );
		wp_set_current_user( $user );
		update_user_meta( $user, ThemePreference::META_KEY, 'dark-green' );

		$response = $this->request( 'PATCH', '/me/preferences', $body );

		self::assertSame( 400, $response->get_status() );
		self::assertContains( ( (array) $response->get_data() )['code'] ?? null, array( 'rest_invalid_param', 'merchboard_invalid_preferences' ) );
		self::assertSame( 'dark-green', get_user_meta( $user, ThemePreference::META_KEY, true ) );
		self::assertStringNotContainsString( '<script>', (string) wp_json_encode( $response->get_data() ), 'Input is not echoed.' );
	}

	public function test_an_empty_body_or_a_query_parameter_is_not_a_preference(): void {
		$user = $this->creator_user( 'theme_creator', 'Nova', CreatorStatus::Active );
		wp_set_current_user( $user );

		self::assertSame( 400, $this->request( 'PATCH', '/me/preferences' )->get_status() );
		self::assertSame( 400, $this->request( 'PATCH', '/me/preferences', array(), array( 'theme' => 'dark-blue' ) )->get_status(), 'Only the body counts.' );
		self::assertSame( '', get_user_meta( $user, ThemePreference::META_KEY, true ) );
	}

	/**
	 * Logged out and users without creator access are refused.
	 *
	 * @param string $kind   Kind of user.
	 * @param int    $status Expected status.
	 * @param string $code   Expected code.
	 */
	#[DataProvider( 'denied' )]
	public function test_preferences_require_creator_access( string $kind, int $status, string $code ): void {
		$user = $this->user_of_kind( $kind );
		wp_set_current_user( $user );

		$response = $this->request( 'PATCH', '/me/preferences', array( 'theme' => 'dark-blue' ) );

		self::assertSame( $status, $response->get_status() );
		self::assertSame( $code, ( (array) $response->get_data() )['code'] ?? null );

		if ( $user > 0 ) {
			self::assertSame( '', get_user_meta( $user, ThemePreference::META_KEY, true ) );
		}
	}

	public function test_preferences_change_only_the_current_users_theme(): void {
		$other = $this->creator_user( 'theme_other', 'Other', CreatorStatus::Active );
		update_user_meta( $other, ThemePreference::META_KEY, 'light' );
		$mine = $this->creator_user( 'theme_mine', 'Mine', CreatorStatus::Active );
		wp_set_current_user( $mine );

		$response = $this->request(
			'PATCH',
			'/me/preferences',
			array(
				'theme'      => 'dark-pink',
				'user_id'    => $other,
				'creator_id' => $this->creator_id( $other ),
			),
			array(
				'user_id'    => $other,
				'creator_id' => $this->creator_id( $other ),
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'theme' => 'dark-pink' ), $response->get_data() );
		self::assertSame( 'dark-pink', get_user_meta( $mine, ThemePreference::META_KEY, true ) );
		self::assertSame( 'light', get_user_meta( $other, ThemePreference::META_KEY, true ), 'Another user is unchanged.' );
	}

	public function test_there_is_no_get_preferences_route(): void {
		wp_set_current_user( $this->creator_user( 'theme_creator', 'Nova', CreatorStatus::Active ) );

		self::assertSame( 404, $this->request( 'GET', '/me/preferences' )->get_status(), 'ADR-044: the current choice comes from the server-rendered data-theme.' );
	}

	public function test_active_creator_gets_only_the_allowed_fields(): void {
		$user = $this->creator_user( 'active_creator', 'Nova <b>Studio</b>', CreatorStatus::Active );
		wp_set_current_user( $user );

		$me = $this->request( 'GET', '/me' );
		self::assertSame( 200, $me->get_status() );
		self::assertSame(
			array(
				'display_name' => 'Nova <b>Studio</b>',
				'status'       => 'active',
			),
			$me->get_data()
		);

		$profile = $this->request( 'GET', '/me/profile' );
		self::assertSame( 200, $profile->get_status() );
		self::assertSame( array( 'display_name', 'bio', 'social_links' ), array_keys( (array) $profile->get_data() ) );
		self::assertSame( array( 'website', 'instagram', 'tiktok', 'youtube', 'x', 'facebook' ), array_keys( ( (array) $profile->get_data() )['social_links'] ) );

		$json = (string) wp_json_encode( array( $me->get_data(), $profile->get_data() ) );
		self::assertStringNotContainsString( '@example.org', $json, 'No e-mail.' );
		self::assertStringNotContainsString( '"id"', $json, 'No creator or user id.' );
		self::assertStringNotContainsString( 'user_', $json );
	}

	/**
	 * Users that must get no creator data, with the expected status and code.
	 *
	 * @return array<string, array{string, int, string}>
	 */
	public static function denied(): array {
		return array(
			'logged out'                   => array( 'logged_out', 401, 'merchboard_unauthenticated' ),
			'subscriber'                   => array( 'subscriber', 403, 'merchboard_forbidden' ),
			'administrator without record' => array( 'admin', 403, 'merchboard_forbidden' ),
			'inactive creator'             => array( 'inactive', 403, 'merchboard_forbidden' ),
			'creator role, no record'      => array( 'no_record', 403, 'merchboard_forbidden' ),
			'active record, no capability' => array( 'no_capability', 403, 'merchboard_forbidden' ),
		);
	}

	/**
	 * Every route denies every user without creator access.
	 *
	 * @param string $kind   User kind.
	 * @param int    $status Expected status.
	 * @param string $code   Expected error code.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'denied' )]
	public function test_users_without_creator_access_get_no_creator_data( string $kind, int $status, string $code ): void {
		$active = $this->creator_user( 'bystander', 'Bystander', CreatorStatus::Active );
		wp_set_current_user( $this->user_of_kind( $kind ) );

		foreach ( array( array( 'GET', '/me' ), array( 'GET', '/me/profile' ), array( 'PATCH', '/me/profile' ) ) as [ $method, $route ] ) {
			$response = $this->request( $method, $route, array( 'bio' => 'hacked' ) );

			self::assertSame( $status, $response->get_status(), "{$method} {$route}" );
			self::assertSame( $code, ( (array) $response->get_data() )['code'] ?? null, "{$method} {$route}" );
			self::assertStringNotContainsString( 'Bystander', (string) wp_json_encode( $response->get_data() ) );
		}

		self::assertSame( '', get_metadata( 'merchboard_creator', $this->creator_id( $active ), 'bio', true ), 'Nothing was written.' );
	}

	public function test_request_parameters_cannot_select_another_creator(): void {
		$mine  = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$other = $this->creator_user( 'other', 'Other', CreatorStatus::Active );
		wp_set_current_user( $mine );

		$params = array(
			'id'         => $this->creator_id( $other ),
			'creator_id' => $this->creator_id( $other ),
			'user_id'    => $other,
		);

		self::assertSame( 'Mine', ( (array) $this->request( 'GET', '/me', array(), $params )->get_data() )['display_name'] );
		self::assertSame( 'Mine', ( (array) $this->request( 'GET', '/me/profile', array(), $params )->get_data() )['display_name'] );

		$response = $this->request( 'PATCH', '/me/profile', array_merge( $params, array( 'bio' => 'Mine bio', 'status' => 'inactive' ) ), $params ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 200, $response->get_status() );

		self::assertSame( 'Mine bio', get_metadata( 'merchboard_creator', $this->creator_id( $mine ), 'bio', true ) );
		self::assertSame( '', get_metadata( 'merchboard_creator', $this->creator_id( $other ), 'bio', true ), 'The other creator is untouched.' );
		self::assertSame( 'active', $this->creator_row( $mine )['status'], 'Status is not creator-editable.' );
		self::assertSame( (string) $mine, $this->creator_row( $mine )['user_id'] );
	}

	public function test_patch_updates_the_profile_and_writes_the_audit_record(): void {
		$user = $this->creator_user( 'editor_of_self', 'Nova', CreatorStatus::Active );
		wp_set_current_user( $user );
		$audit_before = $this->count_rows( Tables::AUDIT_LOG );

		$response = $this->request(
			'PATCH',
			'/me/profile',
			array(
				'display_name' => 'Nova Studio',
				'bio'          => "Merch & music\n<script>alert(1)</script>Back\\slash",
				'social_links' => array(
					'instagram' => 'https://instagram.com/nova',
					'youtube'   => 'https://youtube.com/@nova',
				),
			)
		);

		self::assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		self::assertSame( 'Nova Studio', $data['display_name'] );
		self::assertSame( "Merch & music\nBack\\slash", $data['bio'], 'Tags removed, text, line breaks and backslashes kept.' );
		self::assertSame( 'https://youtube.com/@nova', $data['social_links']['youtube'] );
		self::assertSame( '', $data['social_links']['x'] );

		// Stored and readable again, from a cold cache.
		wp_cache_flush();
		self::assertSame( $data, $this->request( 'GET', '/me/profile' )->get_data() );
		self::assertSame( 'Nova Studio', ( (array) $this->request( 'GET', '/me' )->get_data() )['display_name'] );

		$audit = array_slice( $this->rows( Tables::AUDIT_LOG ), $audit_before );
		self::assertSame( array( 'creator_updated' ), array_column( $audit, 'action' ) );
		self::assertSame( (string) $user, $audit[0]['actor_user_id'] );
		self::assertSame( '{"changed_fields":["display_name","bio","social_links"]}', $audit[0]['context'] );

		$meta = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT meta_key FROM %i ORDER BY meta_key', $this->table( Tables::CREATOR_META ) ), ARRAY_A );
		self::assertSame( array( 'bio', 'social_links' ), array_column( (array) $meta, 'meta_key' ) );
	}

	public function test_invalid_input_gives_400_with_field_codes_and_saves_nothing(): void {
		$user = $this->creator_user( 'invalid_input', 'Nova', CreatorStatus::Active );
		wp_set_current_user( $user );
		$audit_before = $this->count_rows( Tables::AUDIT_LOG );

		$response = $this->request(
			'PATCH',
			'/me/profile',
			array(
				'display_name' => '',
				'bio'          => str_repeat( 'a', 1001 ),
				'social_links' => array( 'x' => 'javascript:alert(1)' ),
			)
		);

		self::assertSame( 400, $response->get_status() );
		$data = (array) $response->get_data();
		self::assertSame( 'merchboard_invalid_profile', $data['code'] );
		self::assertSame(
			array(
				'display_name'   => 'required',
				'bio'            => 'too_long',
				'social_links.x' => 'invalid_url',
			),
			$data['data']['fields']
		);
		self::assertStringNotContainsString( 'javascript', (string) wp_json_encode( $data ), 'Input is not echoed back.' );

		$types = $this->request( 'PATCH', '/me/profile', array( 'bio' => array( 'x' ), 'social_links' => array( 'myspace' => 'https://myspace.com' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 400, $types->get_status(), 'Type errors are rejected by the argument schema.' );

		self::assertSame( 'Nova', $this->creator_row( $user )['display_name'] );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_META ) );
		self::assertSame( $audit_before, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_deactivation_takes_effect_on_the_next_request(): void {
		$user = $this->creator_user( 'deactivated', 'Nova', CreatorStatus::Active );
		wp_set_current_user( $user );
		self::assertSame( 200, $this->request( 'GET', '/me' )->get_status() );

		wp_set_current_user( 1 );
		$this->creators->set_status( $this->creator_id( $user ), CreatorStatus::Inactive );
		wp_set_current_user( $user );

		self::assertSame( 403, $this->request( 'GET', '/me' )->get_status() );
		self::assertSame( 403, $this->request( 'PATCH', '/me/profile', array( 'bio' => 'x' ) )->get_status() );
	}

	public function test_database_failure_denies_access_without_details(): void {
		$user = $this->creator_user( 'db_failure', 'Nova', CreatorStatus::Active );
		wp_set_current_user( $user );
		$this->execute( 'RENAME TABLE %i TO %i', $this->table( Tables::CREATORS ), $this->table( Tables::CREATORS ) . '_away' );
		$this->wpdb->suppress_errors( true );
		file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.

		try {
			$response = $this->request( 'GET', '/me' );
		} finally {
			$this->execute( 'RENAME TABLE %i TO %i', $this->table( Tables::CREATORS ) . '_away', $this->table( Tables::CREATORS ) );
			$this->forget_table_metadata();
		}

		self::assertSame( 403, $response->get_status() );
		self::assertStringNotContainsString( 'merchboard_creators', (string) wp_json_encode( $response->get_data() ) );
		self::assertStringContainsString( 'Creator access check failed; access denied.', (string) file_get_contents( MERCHBOARD_TEST_LOG ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.
	}

	public function test_failed_write_rolls_back_and_returns_a_generic_500(): void {
		$user = $this->creator_user( 'write_failure', 'Nova', CreatorStatus::Active );
		wp_set_current_user( $user );
		$this->execute( "CREATE TRIGGER merchboard_test_fail BEFORE INSERT ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'", $this->table( Tables::AUDIT_LOG ) );
		$this->wpdb->suppress_errors( true );
		file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.

		$response = $this->request( 'PATCH', '/me/profile', array( 'display_name' => 'Renamed', 'bio' => 'New bio' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( 500, $response->get_status() );
		self::assertSame( 'merchboard_internal_error', ( (array) $response->get_data() )['code'] );
		self::assertStringNotContainsString( 'forced failure', (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( 'Nova', $this->creator_row( $user )['display_name'], 'Rename rolled back.' );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_META ), 'Meta rolled back.' );
		self::assertStringContainsString( 'REST request failed.', (string) file_get_contents( MERCHBOARD_TEST_LOG ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.

		wp_cache_flush();
		self::assertSame( '', ( (array) $this->request( 'GET', '/me/profile' )->get_data() )['bio'] );
	}

	public function test_uninstall_list_includes_the_meta_table(): void {
		self::assertContains( Tables::CREATOR_META, Tables::all() );
		self::assertSame( $this->table( Tables::CREATOR_META ), $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::CREATOR_META ) ) ) );
	}

	/**
	 * Dispatches a REST request as the current user.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below the namespace.
	 * @param array<string, mixed> $body   JSON body.
	 * @param array<string, mixed> $query  Query parameters.
	 */
	private function request( string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/merchboard/v1' . $route );
		$request->set_query_params( $query );

		if ( array() !== $body && WP_REST_Server::READABLE !== $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * A user with a linked creator of the given status.
	 *
	 * @param string        $login  Login.
	 * @param string        $name   Creator name.
	 * @param CreatorStatus $status Status.
	 */
	private function creator_user( string $login, string $name, CreatorStatus $status ): int {
		$user = $this->create_user( $login );
		wp_set_current_user( 1 );
		$this->creators->create( $name, $status, $user );

		return $user;
	}

	/**
	 * A user id for a denied kind (0 = logged out).
	 *
	 * @param string $kind Kind.
	 */
	private function user_of_kind( string $kind ): int {
		switch ( $kind ) {
			case 'subscriber':
				return $this->create_user( 'plain_subscriber' );
			case 'admin':
				return 1;
			case 'inactive':
				return $this->creator_user( 'inactive_creator', 'Inactive', CreatorStatus::Inactive );
			case 'no_record':
				$user = $this->create_user( 'role_only' );
				( new WP_User( $user ) )->add_role( Capabilities::CREATOR_ROLE );
				return $user;
			case 'no_capability':
				$user = $this->creator_user( 'no_capability', 'No Cap', CreatorStatus::Active );
				( new WP_User( $user ) )->remove_role( Capabilities::CREATOR_ROLE );
				return $user;
			default:
				return 0;
		}
	}

	/**
	 * The creator id linked to a user.
	 *
	 * @param int $user_id User id.
	 */
	private function creator_id( int $user_id ): int {
		return (int) $this->creator_row( $user_id )['id'];
	}

	/**
	 * The creator row linked to a user.
	 *
	 * @param int $user_id User id.
	 * @return array<string, string|null>
	 */
	private function creator_row( int $user_id ): array {
		foreach ( $this->rows( Tables::CREATORS ) as $row ) {
			if ( (string) $user_id === $row['user_id'] ) {
				return $row;
			}
		}

		self::fail( 'No creator for user ' . $user_id );
	}
}
