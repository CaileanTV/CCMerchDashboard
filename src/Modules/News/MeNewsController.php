<?php
/**
 * The creator's news: /me/news.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Creator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/me/news` and `POST /me/news/{id}/seen|closed`
 * (ADR-072). Only the published messages the creator sees (published at or
 * after the creator was created), newest first, with when the creator saw
 * and closed each; the body is cleaned again (NewsHtml). The creator is
 * always the one CreatorContext resolves (ADR-007); another message is 404.
 */
final class MeNewsController extends RestController {

	private const ROUTE = '/me/news';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext $context Creator access policy.
	 * @param NewsRepository $news    Messages.
	 * @param Logger         $logger  Technical log.
	 */
	public function __construct( private CreatorContext $context, private NewsRepository $news, Logger $logger ) {
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
					'callback'            => array( $this, 'list_news' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);

		foreach ( array( 'seen', 'closed' ) as $what ) {
			register_rest_route(
				self::NAMESPACE,
				self::ROUTE . '/(?P<id>\d+)/' . $what,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'mark_' . $what ),
						'permission_callback' => array( $this, 'permission' ),
						'args'                => array(),
					),
				)
			);
		}
	}

	/**
	 * Only an active creator.
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * The creator's news.
	 */
	public function list_news(): WP_REST_Response|WP_Error {
		return $this->with_creator( self::ROUTE, fn( Creator $creator ): WP_REST_Response => $this->answer( $creator ) );
	}

	/**
	 * Notes that the creator saw a message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function mark_seen( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->mark( $request, false );
	}

	/**
	 * Notes that the creator closed a message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function mark_closed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->mark( $request, true );
	}

	/**
	 * Notes a read of a message the creator sees.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $closed  Closed (else seen).
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private function mark( WP_REST_Request $request, bool $closed ): WP_REST_Response|WP_Error {
		$id = absint( $request->get_param( 'id' ) );

		return $this->with_creator(
			self::ROUTE . '/{id}',
			function ( Creator $creator ) use ( $id, $closed ): WP_REST_Response|WP_Error {
				$visible = array_filter( $this->news->published_since( $creator->created_at ), static fn( NewsMessage $message ): bool => $message->id === $id );

				if ( array() === $visible ) {
					return self::error( 'merchboard_not_found', __( 'The news message was not found.', 'merchboard' ), 404 );
				}

				if ( $closed ) {
					$this->news->mark_closed( $id, $creator->id );
				} else {
					$this->news->mark_seen( $id, $creator->id );
				}

				return $this->answer( $creator );
			}
		);
	}

	/**
	 * Runs a callback for the current creator (resolved again).
	 *
	 * @param string                                         $route    Route for the log.
	 * @param callable(Creator): (WP_REST_Response|WP_Error) $callback Work.
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
	 * The answer of every route.
	 *
	 * @param Creator $creator Creator.
	 */
	private function answer( Creator $creator ): WP_REST_Response {
		$reads = $this->news->reads_of( $creator->id );
		$items = array_map(
			static fn( NewsMessage $message ): array => array(
				'id'           => $message->id,
				'title'        => $message->title,
				'body'         => $message->body,
				'published_at' => null === $message->published_at ? null : $message->published_at->format( DATE_ATOM ),
				'seen_at'      => self::time( $reads[ $message->id ]['seen_at'] ?? null ),
				'closed_at'    => self::time( $reads[ $message->id ]['closed_at'] ?? null ),
			),
			$this->news->published_since( $creator->created_at )
		);

		$response = new WP_REST_Response( array( 'items' => $items ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * A stored UTC time as ISO 8601, or null.
	 *
	 * @param string|null $time `Y-m-d H:i:s` (UTC).
	 */
	private static function time( ?string $time ): ?string {
		return null === $time ? null : ( new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) ) )->format( DATE_ATOM );
	}
}
