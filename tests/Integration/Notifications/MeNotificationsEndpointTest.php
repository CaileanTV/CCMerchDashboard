<?php
/**
 * The creator's bell and e-mail choices over REST (phase 29, ADR-079).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Notifications;

use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Access\Impersonation;
use MerchBoard\Modules\Access\ImpersonationGuard;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Notifications\MeNotificationsController;
use MerchBoard\Modules\Notifications\Notification;
use MerchBoard\Modules\Notifications\NotificationPreferences;
use MerchBoard\Modules\Notifications\NotificationRepository;
use MerchBoard\Modules\Notifications\NotificationSettings;
use MerchBoard\Modules\Notifications\NotificationType;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/me/notifications` and `/me/notification-preferences`: always the
 * creator's own, nothing marked read while an administrator views as the
 * creator, and only the e-mails the creator may turn off.
 */
#[CoversClass( MeNotificationsController::class )]
final class MeNotificationsEndpointTest extends IntegrationTestCase {

	private NotificationRepository $notifications;

	private int $nova;

	private int $nova_user;

	private int $orion;

	/**
	 * The REST routes' registration.
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( NotificationSettings::OPTION );

		$this->notifications = new NotificationRepository( $this->wpdb );
		$this->nova_user     = $this->create_user( 'nova' );
		$this->nova          = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->nova_user )->id;
		$this->orion         = $this->service( CreatorService::class )->create( 'Orion', CreatorStatus::Active, $this->create_user( 'orion' ) )->id;
		// Without the welcomes the creations fired (29.5).
		$this->execute( 'DELETE FROM %i', $this->table( Tables::NOTIFICATIONS ) );

		$controller     = $this->service( MeNotificationsController::class );
		$this->register = static function () use ( $controller ): void {
			$controller->register_routes();
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
		delete_option( NotificationSettings::OPTION );
		parent::tearDown();
	}

	public function test_the_creator_lists_their_own_notifications_as_plain_text_newest_first(): void {
		$first  = $this->store(
			'payout_completed',
			$this->nova,
			array(
				'amount'   => 250.0,
				'currency' => 'DKK',
			)
		);
		$second = $this->store( 'proposal_rejected', $this->nova, array( 'field' => '<b>Bio</b>' ) );
		$this->store(
			'payout_completed',
			$this->orion,
			array(
				'amount'   => 999.0,
				'currency' => 'DKK',
			)
		);
		$this->store( 'admin_payout_requested', null, array() );

		wp_set_current_user( $this->nova_user );
		$response = $this->call( 'GET', '/merchboard/v1/me/notifications', array( 'per_page' => 1 ) );
		$data     = $this->data( $response );

		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? null );
		self::assertSame( array( 2, 2, 2 ), array( $data['unread'], $data['total'], $data['pages'] ), 'Only Nova\'s, never another creator\'s or the administrator\'s.' );
		self::assertIsArray( $data['items'] );
		self::assertCount( 1, $data['items'] );
		$item = $data['items'][0];
		self::assertIsArray( $item );
		self::assertSame( array( 'id', 'type', 'text', 'link', 'created_at', 'read' ), array_keys( $item ) );
		self::assertSame( array( $second, 'proposal_rejected', 'profile', false ), array( $item['id'], $item['type'], $item['link'], $item['read'] ) );
		self::assertIsString( $item['text'] );
		self::assertNotSame( '', $item['text'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $item['created_at'] );

		$older = $this->data(
			$this->call(
				'GET',
				'/merchboard/v1/me/notifications',
				array(
					'page'     => 2,
					'per_page' => 1,
				)
			)
		);
		self::assertIsArray( $older['items'] );
		self::assertSame( $first, $older['items'][0]['id'] ?? null );
		self::assertStringContainsString( number_format_i18n( 250, 2 ), (string) ( $older['items'][0]['text'] ?? '' ) );

		wp_set_current_user( 0 );
		self::assertSame( 401, $this->call( 'GET', '/merchboard/v1/me/notifications' )->get_status() );
		wp_set_current_user( $this->create_user( 'no-creator' ) );
		self::assertSame( 403, $this->call( 'GET', '/merchboard/v1/me/notifications' )->get_status() );
	}

	public function test_marking_read_touches_only_the_creators_own_and_not_while_viewed_as(): void {
		$own    = $this->store( 'sale_new', $this->nova, array( 'order_number' => '1' ) );
		$other  = $this->store( 'sale_new', $this->nova, array( 'order_number' => '2' ) );
		$theirs = $this->store( 'sale_new', $this->orion, array( 'order_number' => '3' ) );

		wp_set_current_user( $this->nova_user );
		self::assertSame( 400, $this->call( 'POST', '/merchboard/v1/me/notifications/read', array() )->get_status() );
		self::assertSame( array( 'unread' => 1 ), $this->data( $this->call( 'POST', '/merchboard/v1/me/notifications/read', array( 'ids' => array( $own, $theirs ) ) ) ) );
		self::assertNotNull( $this->notifications->find( $own )?->read_at );
		self::assertNull( $this->notifications->find( $theirs )?->read_at, 'Another creator\'s id is ignored.' );

		wp_set_current_user( 1 );
		$this->service( Impersonation::class )->start( $this->nova );
		// 2: the one left and the notice that an administrator viewed the dashboard.
		self::assertSame( array( 'unread' => 2 ), $this->data( $this->call( 'POST', '/merchboard/v1/me/notifications/read', array( 'all' => true ) ) ) );
		self::assertNull( $this->notifications->find( $other )?->read_at, 'Viewing as the creator leaves the bell as it was.' );
		$listed = $this->data( $this->call( 'GET', '/merchboard/v1/me/notifications' ) );
		self::assertSame( array( 2, 'impersonation_started' ), array( $listed['unread'], $listed['items'][0]['type'] ?? null ) );

		delete_user_meta( 1, ImpersonationSession::META );
		ImpersonationSession::act( false );
		wp_set_current_user( $this->nova_user );
		self::assertSame( array( 'unread' => 0 ), $this->data( $this->call( 'POST', '/merchboard/v1/me/notifications/read', array( 'all' => true ) ) ) );
		self::assertNull( $this->notifications->find( $theirs )?->read_at );
	}

	public function test_the_creator_turns_off_only_emails_that_are_not_important(): void {
		wp_set_current_user( $this->nova_user );
		$data = $this->data( $this->call( 'GET', '/merchboard/v1/me/notification-preferences' ) );
		self::assertTrue( $data['has_user'] );
		self::assertIsArray( $data['items'] );
		$keys = array_column( $data['items'], 'key' );

		self::assertContains( 'sale_new', $keys );
		self::assertNotContains( 'payout_completed', $keys, 'Important e-mails are always sent.' );
		self::assertNotContains( 'news_published', $keys, 'Off for e-mail by default.' );
		self::assertNotContains( 'admin_payout_requested', $keys );
		self::assertSame( array( true ), array_values( array_unique( array_column( $data['items'], 'enabled' ) ) ) );

		$saved = $this->data( $this->call( 'PATCH', '/merchboard/v1/me/notification-preferences', array( 'off' => array( 'sale_new' ) ) ) );
		self::assertIsArray( $saved['items'] );
		self::assertFalse( array_column( $saved['items'], 'enabled', 'key' )['sale_new'] ?? null );
		self::assertSame( array( 'sale_new' ), ( new NotificationPreferences() )->email_off( $this->nova_user ) );

		foreach ( array( array( 'off' => array( 'payout_completed' ) ), array( 'off' => array( 'nope' ) ), array( 'off' => 'sale_new' ), array() ) as $body ) {
			self::assertSame( 400, $this->call( 'PATCH', '/merchboard/v1/me/notification-preferences', $body )->get_status(), (string) wp_json_encode( $body ) );
		}

		self::assertSame( array( 'sale_new' ), ( new NotificationPreferences() )->email_off( $this->nova_user ), 'A refused change keeps the choices.' );
	}

	public function test_a_choice_for_an_email_the_administrator_turned_off_is_kept(): void {
		update_user_meta( $this->nova_user, NotificationPreferences::META, array( 'sale_new', 'proposal_approved' ) );
		update_option( NotificationSettings::OPTION, array( 'sale_new' => array( NotificationSettings::EMAIL => false ) ) );

		wp_set_current_user( $this->nova_user );
		$data = $this->data( $this->call( 'GET', '/merchboard/v1/me/notification-preferences' ) );
		self::assertIsArray( $data['items'] );
		self::assertNotContains( 'sale_new', array_column( $data['items'], 'key' ) );

		$this->data( $this->call( 'PATCH', '/merchboard/v1/me/notification-preferences', array( 'off' => array() ) ) );
		self::assertSame( array( 'sale_new' ), ( new NotificationPreferences() )->email_off( $this->nova_user ) );
	}

	public function test_viewing_as_the_creator_reads_but_never_changes_the_email_choices(): void {
		wp_set_current_user( 1 );
		$this->service( Impersonation::class )->start( $this->nova );

		self::assertSame( 200, $this->call( 'GET', '/merchboard/v1/me/notification-preferences' )->get_status() );
		$response = $this->call( 'PATCH', '/merchboard/v1/me/notification-preferences', array( 'off' => array( 'sale_new' ) ) );
		self::assertSame( array( 403, ImpersonationGuard::BLOCKED ), array( $response->get_status(), $response->as_error()?->get_error_code() ) );
		self::assertSame( array(), ( new NotificationPreferences() )->email_off( $this->nova_user ) );
	}

	/**
	 * Stores a notification for the bell.
	 *
	 * @param string               $type       Type.
	 * @param int|null             $creator_id Creator (null = administrator).
	 * @param array<string, mixed> $data       Data.
	 */
	private function store( string $type, ?int $creator_id, array $data ): int {
		$id = $this->notifications->insert( $type, null === $creator_id ? NotificationType::ADMIN : NotificationType::CREATOR, $creator_id, $data, '', null !== $creator_id, Notification::EMAIL_NONE );
		self::assertNotNull( $id );

		return $id;
	}

	/**
	 * Dispatches a request; GET parameters go in the query, others in a JSON body.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Parameters.
	 */
	private function call( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} elseif ( array() !== $params ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * The data of a 200 answer.
	 *
	 * @param WP_REST_Response $response Answer.
	 * @return array<string, mixed>
	 */
	private function data( WP_REST_Response $response ): array {
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		self::assertIsArray( $data );

		return $data;
	}
}
