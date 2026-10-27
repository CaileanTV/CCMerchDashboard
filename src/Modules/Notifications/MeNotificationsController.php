<?php
/**
 * Creator-scope REST routes of the notifications.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Creator;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's bell and e-mail choices (ADR-079 pkt. 7–8), always for the
 * creator CreatorContext resolves (ADR-007); no route takes a creator id.
 *
 * - `GET /me/notifications?page=&per_page=`: `{ items, unread, total,
 *   pages }`, newest first. Each item has its plain text (built on the
 *   server in the creator's language, rendered as text by the dashboard),
 *   the dashboard path it leads to, the time and whether it is read.
 * - `POST /me/notifications/read` with `{ ids: [..] }` or `{ all: true }`:
 *   marks them read and answers `{ unread }`. While an administrator views
 *   as the creator (ADR-075), nothing is marked.
 * - `GET /me/notification-preferences`: the e-mails the creator may turn
 *   off (not important, and on for e-mail by the administrator), each with
 *   whether it is on.
 * - `PATCH /me/notification-preferences` with `{ off: [keys] }`: saves them
 *   (blocked during "View as creator" by ImpersonationGuard).
 *
 * Every answer is `no-store`.
 */
final class MeNotificationsController extends RestController {

	private const ROUTE = '/me/notifications';

	private const PREFERENCES = '/me/notification-preferences';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext          $context       Creator access policy.
	 * @param NotificationRepository  $notifications Notifications.
	 * @param NotificationTypes       $types         Catalog.
	 * @param NotificationSettings    $settings      Administrator's choices.
	 * @param NotificationPreferences $preferences   Creators' choices.
	 * @param Logger                  $logger        Technical log.
	 */
	public function __construct(
		private CreatorContext $context,
		private NotificationRepository $notifications,
		private NotificationTypes $types,
		private NotificationSettings $settings,
		private NotificationPreferences $preferences,
		Logger $logger
	) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_notifications' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => self::pagination_args(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/read',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mark_read' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::PREFERENCES,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_preferences' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_preferences' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * A logged-in user with a creator.
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/notifications`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function list_notifications( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = absint( $request->get_param( 'per_page' ) );
		$per_page = 0 === $per_page ? 20 : min( 100, $per_page );

		return $this->with_creator(
			self::ROUTE,
			function ( Creator $creator ) use ( $page, $per_page ): WP_REST_Response {
				$total = $this->notifications->count_for_creator( $creator->id );
				$items = array();

				foreach ( $this->notifications->for_creator( $creator->id, $per_page, ( $page - 1 ) * $per_page ) as $notification ) {
					$type = $this->types->get( $notification->type );

					if ( null === $type ) {
						continue;
					}

					$items[] = array(
						'id'         => $notification->id,
						'type'       => $notification->type,
						'text'       => $type->text( new NotificationData( $notification->data ) ),
						'link'       => $type->link,
						'created_at' => self::time( $notification->created_at ),
						'read'       => null !== $notification->read_at,
					);
				}

				return self::no_store(
					array(
						'items'  => $items,
						'unread' => $this->notifications->count_for_creator( $creator->id, true ),
						'total'  => $total,
						'pages'  => max( 1, (int) ceil( $total / $per_page ) ),
					)
				);
			}
		);
	}

	/**
	 * `POST /me/notifications/read`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function mark_read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$all  = true === ( $body['all'] ?? false );
		$ids  = is_array( $body['ids'] ?? null ) ? array_values( array_map( 'absint', array_filter( $body['ids'], 'is_numeric' ) ) ) : array();

		return $this->with_creator(
			self::ROUTE . '/read',
			function ( Creator $creator ) use ( $all, $ids ): WP_REST_Response|WP_Error {
				if ( ! $all && array() === $ids ) {
					return self::error( 'merchboard_invalid_request', __( 'Nothing to mark as read.', 'merchboard' ), 400 );
				}

				// An administrator viewing as the creator leaves the bell as the creator left it.
				if ( null === ImpersonationSession::creator_id() ) {
					$this->notifications->mark_read( $creator->id, $all ? null : array_slice( $ids, 0, 100 ) );
				}

				return self::no_store( array( 'unread' => $this->notifications->count_for_creator( $creator->id, true ) ) );
			}
		);
	}

	/**
	 * `GET /me/notification-preferences`.
	 */
	public function get_preferences(): WP_REST_Response|WP_Error {
		return $this->with_creator( self::PREFERENCES, fn( Creator $creator ): WP_REST_Response => $this->preferences_of( $creator ) );
	}

	/**
	 * `PATCH /me/notification-preferences`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_preferences( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$off  = is_array( $body['off'] ?? null ) ? array_values( array_filter( $body['off'], 'is_string' ) ) : null;

		return $this->with_creator(
			self::PREFERENCES,
			function ( Creator $creator ) use ( $off ): WP_REST_Response|WP_Error {
				if ( null === $off || null === $creator->user_id ) {
					return self::error( 'merchboard_invalid_request', __( 'The e-mail choices are not valid.', 'merchboard' ), 400 );
				}

				$choosable = array_keys( $this->choosable() );
				$unknown   = array_diff( $off, $choosable );

				if ( array() !== $unknown ) {
					return self::error( 'merchboard_invalid_request', __( 'The e-mail choices are not valid.', 'merchboard' ), 400 );
				}

				// Keep earlier choices of types the administrator turned off for now.
				$kept = array_diff( $this->preferences->email_off( $creator->user_id ), $choosable );

				update_user_meta( $creator->user_id, NotificationPreferences::META, array_values( array_unique( array_merge( $kept, $off ) ) ) );

				return $this->preferences_of( $creator );
			}
		);
	}

	/**
	 * The creator's e-mail choices.
	 *
	 * @param Creator $creator Creator.
	 */
	private function preferences_of( Creator $creator ): WP_REST_Response {
		$off   = null === $creator->user_id ? array() : $this->preferences->email_off( $creator->user_id );
		$items = array();

		foreach ( $this->choosable() as $type ) {
			$items[] = array(
				'key'         => $type->key,
				'group'       => $type->group,
				'label'       => $type->label(),
				'description' => $type->description(),
				'enabled'     => ! in_array( $type->key, $off, true ),
			);
		}

		return self::no_store(
			array(
				'items'    => $items,
				'has_user' => null !== $creator->user_id,
			)
		);
	}

	/**
	 * The types whose e-mail a creator may turn off: creator types that are
	 * not important and that the administrator sends by e-mail.
	 *
	 * @return array<string, NotificationType>
	 */
	private function choosable(): array {
		return array_filter(
			$this->types->all(),
			fn( NotificationType $type ): bool => NotificationType::CREATOR === $type->audience && ! $type->important && $this->settings->enabled( $type, NotificationSettings::EMAIL )
		);
	}

	/**
	 * Runs a callback with the current creator.
	 *
	 * @param string                                         $route    Route (log).
	 * @param callable(Creator): (WP_REST_Response|WP_Error) $callback Callback.
	 */
	private function with_creator( string $route, callable $callback ): WP_REST_Response|WP_Error {
		return $this->run(
			$route,
			function () use ( $callback ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : $callback( $creator );
			}
		);
	}

	/**
	 * A no-store response.
	 *
	 * @param array<string, mixed> $data Body.
	 */
	private static function no_store( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * A stored UTC time as ISO 8601.
	 *
	 * @param string $time `Y-m-d H:i:s` (UTC).
	 */
	private static function time( string $time ): string {
		try {
			return ( new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) ) )->format( DATE_ATOM );
		} catch ( Throwable ) {
			return '';
		}
	}
}
