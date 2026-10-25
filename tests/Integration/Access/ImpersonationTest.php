<?php
/**
 * "View as creator" (phase 27, step 27.2, ADR-075).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Access;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Access\Impersonation;
use MerchBoard\Modules\Access\ImpersonationActions;
use MerchBoard\Modules\Access\ImpersonationException;
use MerchBoard\Modules\Access\ImpersonationGuard;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Dashboard\DashboardSections;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * An administrator with `merchboard_manage_creators` views and acts as an
 * active creator for at most an hour; start and end are audited, changes
 * are marked, and what must stay the creator's own is refused.
 */
#[CoversClass( Impersonation::class )]
#[CoversClass( ImpersonationGuard::class )]
#[CoversClass( ImpersonationSession::class )]
final class ImpersonationTest extends IntegrationTestCase {

	private int $nova;

	private int $nova_user;

	/**
	 * Stand-in REST routes (the real ones need WooCommerce or more setup).
	 *
	 * @var callable|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->nova_user = $this->create_user( 'nova' );
		$this->nova      = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->nova_user )->id;

		$this->register = static function (): void {
			$routes = array(
				array( '/me/payouts', 'POST' ),
				array( '/me/payouts/(?P<id>\d+)/cancel', 'POST' ),
				array( '/me/payouts/(?P<id>\d+)/gift-card', 'GET' ),
				array( '/me/payouts/(?P<id>\d+)/settlement-document', 'GET' ),
				array( '/me/alerts/chatbot/(?P<platform>twitch|kick|youtube)/connect', 'POST' ),
				array( '/me/alerts/chatbot/(?P<platform>twitch|kick|youtube)/terms', 'POST' ),
				array( '/me/alerts/chatbot/(?P<platform>twitch|kick|youtube)/test', 'POST' ),
				array( '/me/alerts/api/keys', 'POST' ),
				array( '/me/alerts/api/webhooks', 'POST' ),
				array( '/me/alerts/api/webhooks/(?P<id>\d+)/secret', 'POST' ),
				array( '/me/alerts/api/webhooks/(?P<id>\d+)', 'PATCH' ),
			);

			foreach ( $routes as [ $route, $method ] ) {
				register_rest_route(
					'merchboard/v1',
					$route,
					array(
						'methods'             => $method,
						'callback'            => static fn(): WP_REST_Response => new WP_REST_Response( array( 'ok' => true ), 200 ),
						'permission_callback' => '__return_true',
					)
				);
			}
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		delete_user_meta( 1, ImpersonationSession::META );
		parent::tearDown();
	}

	public function test_an_administrator_views_as_the_creator_until_it_ends(): void {
		$context = $this->service( CreatorContext::class );
		self::assertNull( $this->service( CreatorContext::class )->current(), 'The administrator is no creator.' );

		$this->impersonation()->start( $this->nova );

		self::assertSame( $this->nova, $context->current()?->id );
		self::assertSame( $this->nova, ImpersonationSession::creator_id() );
		$session = ImpersonationSession::stored( 1 );
		self::assertNotNull( $session );
		self::assertSame( ImpersonationSession::TTL, $session['expires_at'] - $session['started_at'] );
		self::assertNotSame( array(), $this->service( DashboardSections::class )->for_current_user(), 'The creator\'s menu, although the administrator lacks the dashboard capability.' );

		// A change made as the creator is the administrator's, marked.
		$this->service( AuditTrail::class )->record( AuditAction::CreatorUpdated, $this->nova, array( 'changed_fields' => array( 'bio' ) ) );
		$last = $this->last_row();
		self::assertSame( array( 'creator_updated', '1', $this->nova ), array( $last['action'], (string) $last['actor_user_id'], json_decode( (string) $last['context'], true )['impersonating'] ?? null ) );

		self::assertSame( $this->nova, $this->impersonation()->end( Impersonation::ENDED_BY_ADMIN ) );
		self::assertNull( $context->current() );
		self::assertSame( '', get_user_meta( 1, ImpersonationSession::META, true ) );
		self::assertSame( array( 'creator_impersonation_started', 'creator_impersonation_ended' ), array_values( array_filter( array_column( $this->audit_rows(), 'action' ), static fn( string $action ): bool => str_starts_with( $action, 'creator_impersonation' ) ) ) );
		self::assertSame( array( 'reason' => 'stopped' ), json_decode( (string) $this->last_row()['context'], true ) );
		self::assertNull( $this->impersonation()->end( Impersonation::ENDED_BY_ADMIN ), 'Ending again does nothing.' );
	}

	public function test_only_administrators_and_only_active_creators_with_a_user(): void {
		wp_set_current_user( $this->nova_user );
		self::assertSame( ImpersonationException::NOT_AUTHORIZED, $this->refusal( $this->nova ), 'A creator can never view as another creator.' );

		wp_set_current_user( 1 );
		$no_user = $this->service( CreatorService::class )->create( 'Orion', CreatorStatus::Active, null )->id;
		self::assertSame( ImpersonationException::UNAVAILABLE, $this->refusal( $no_user ) );
		self::assertSame( ImpersonationException::UNAVAILABLE, $this->refusal( 999999 ) );
		$this->service( CreatorService::class )->set_status( $this->nova, CreatorStatus::Inactive );
		self::assertSame( ImpersonationException::UNAVAILABLE, $this->refusal( $this->nova ) );
		self::assertNull( ImpersonationSession::stored( 1 ) );

		// A session of a user without the capability counts for nothing.
		update_user_meta( $this->nova_user, ImpersonationSession::META, array( 'creator_id' => $this->nova, 'started_at' => time(), 'expires_at' => time() + 60 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		wp_set_current_user( $this->nova_user );
		self::assertNull( ImpersonationSession::creator_id() );
		delete_user_meta( $this->nova_user, ImpersonationSession::META );
	}

	public function test_a_deactivated_creator_or_an_expired_session_ends_the_view(): void {
		$context = $this->service( CreatorContext::class );
		$this->impersonation()->start( $this->nova );
		$this->service( CreatorService::class )->set_status( $this->nova, CreatorStatus::Inactive );
		self::assertNull( $context->current(), 'Fail closed: an inactive creator is never shown.' );
		$this->service( CreatorService::class )->set_status( $this->nova, CreatorStatus::Active );

		update_user_meta( 1, ImpersonationSession::META, array( 'creator_id' => $this->nova, 'started_at' => time() - 3700, 'expires_at' => time() - 100 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertNull( ImpersonationSession::creator_id() );
		self::assertNull( $context->current() );

		$this->impersonation()->expire();
		self::assertSame( '', get_user_meta( 1, ImpersonationSession::META, true ) );
		self::assertSame( array( 'reason' => 'expired' ), json_decode( (string) $this->last_row()['context'], true ) );
	}

	public function test_viewing_another_creator_ends_the_first_session(): void {
		$orion_user = $this->create_user( 'orion' );
		$orion      = $this->service( CreatorService::class )->create( 'Orion', CreatorStatus::Active, $orion_user )->id;
		$this->impersonation()->start( $this->nova );
		$this->impersonation()->start( $orion );

		self::assertSame( $orion, ImpersonationSession::creator_id() );
		$ended = array_values( array_filter( $this->audit_rows(), static fn( array $row ): bool => 'creator_impersonation_ended' === $row['action'] ) );
		self::assertSame( array( array( $this->nova, array( 'reason' => 'switched' ) ) ), array_map( static fn( array $row ): array => array( (int) $row['creator_id'], json_decode( (string) $row['context'], true ) ), $ended ) );
	}

	public function test_what_must_stay_the_creators_own_is_refused(): void {
		$routes  = array(
			array( 'POST', '/merchboard/v1/me/payouts', array() ),
			array( 'POST', '/merchboard/v1/me/payouts/5/cancel', array() ),
			array( 'GET', '/merchboard/v1/me/payouts/5/gift-card', array() ),
			array( 'POST', '/merchboard/v1/me/alerts/chatbot/twitch/connect', array() ),
			array( 'POST', '/merchboard/v1/me/alerts/chatbot/youtube/terms', array() ),
			array( 'POST', '/merchboard/v1/me/alerts/api/keys', array() ),
			array( 'POST', '/merchboard/v1/me/alerts/api/webhooks/3/secret', array() ),
			array( 'POST', '/merchboard/v1/me/alerts/api/webhooks', array( 'format' => 'json', 'secret' => '' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
		$allowed = array(
			array( 'GET', '/merchboard/v1/me/payouts/5/settlement-document', array() ),
			array( 'POST', '/merchboard/v1/me/alerts/chatbot/twitch/test', array() ),
			array( 'PATCH', '/merchboard/v1/me/alerts/api/webhooks/3', array() ),
			array( 'POST', '/merchboard/v1/me/alerts/api/webhooks', array( 'format' => 'json', 'secret' => 'from-sparkybot' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'POST', '/merchboard/v1/me/alerts/api/webhooks', array( 'format' => 'discord' ) ),
		);

		foreach ( $routes as [ $method, $route, $body ] ) {
			self::assertSame( 200, $this->call( $method, $route, $body )->get_status(), 'Without a session: ' . $route );
		}

		$this->impersonation()->start( $this->nova );

		foreach ( $routes as [ $method, $route, $body ] ) {
			$response = $this->call( $method, $route, $body );
			self::assertSame( array( 403, ImpersonationGuard::BLOCKED ), array( $response->get_status(), $response->as_error()?->get_error_code() ), $method . ' ' . $route );
		}

		foreach ( $allowed as [ $method, $route, $body ] ) {
			self::assertSame( 200, $this->call( $method, $route, $body )->get_status(), 'Allowed as the creator: ' . $method . ' ' . $route );
		}
	}

	public function test_the_admin_links_start_and_end_with_a_nonce(): void {
		$actions = $this->service( ImpersonationActions::class );
		parse_str( (string) wp_parse_url( ImpersonationActions::start_url( $this->nova ), PHP_URL_QUERY ), $start );

		self::assertSame( $this->service( DashboardRoute::class )->url(), $actions->start( $start ) );
		self::assertSame( $this->nova, ImpersonationSession::creator_id() );

		parse_str( (string) wp_parse_url( ImpersonationActions::end_url(), PHP_URL_QUERY ), $end );
		self::assertStringContainsString( CreatorsAdminPage::NOTICE_ARG . '=' . ImpersonationActions::ENDED_NOTICE, $actions->end( $end ) );
		self::assertNull( ImpersonationSession::creator_id() );

		$start['creator']  = (string) $this->service( CreatorService::class )->create( 'Ghost', CreatorStatus::Active, null )->id;
		$start['_wpnonce'] = wp_create_nonce( ImpersonationActions::START . '_' . $start['creator'] );
		self::assertStringContainsString( CreatorsAdminPage::NOTICE_ARG . '=' . ImpersonationException::UNAVAILABLE, $actions->start( $start ) );
	}

	/**
	 * The impersonation service.
	 */
	private function impersonation(): Impersonation {
		return $this->service( Impersonation::class );
	}

	/**
	 * Why starting was refused.
	 *
	 * @param int $creator_id Creator.
	 */
	private function refusal( int $creator_id ): string {
		try {
			$this->impersonation()->start( $creator_id );
		} catch ( ImpersonationException $e ) {
			return $e->reason();
		}

		self::fail( 'Not refused.' );
	}

	/**
	 * The audit log, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function audit_rows(): array {
		return $this->rows( Tables::AUDIT_LOG );
	}

	/**
	 * The newest audit record.
	 *
	 * @return array<string, mixed>
	 */
	private function last_row(): array {
		$rows = $this->audit_rows();
		self::assertNotSame( array(), $rows );

		return $rows[ count( $rows ) - 1 ];
	}

	/**
	 * Dispatches a request with a JSON body.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $body   JSON body.
	 */
	private function call( string $method, string $route, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}
}
