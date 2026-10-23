<?php
/**
 * Integration tests for the news to the creators.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\News;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\News\Admin\NewsAdminActions;
use MerchBoard\Modules\News\Admin\NewsAdminPage;
use MerchBoard\Modules\News\MeNewsController;
use MerchBoard\Modules\News\NewsException;
use MerchBoard\Modules\News\NewsMessage;
use MerchBoard\Modules\News\NewsRepository;
use MerchBoard\Modules\News\NewsService;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Step 25.11 (ADR-072): the administrator writes, publishes, corrects and
 * withdraws messages (audited, never the text); every active creator sees
 * the published messages from after their creation, and their seen and
 * closed times are kept per creator.
 */
#[CoversClass( NewsService::class )]
#[CoversClass( NewsRepository::class )]
#[CoversClass( MeNewsController::class )]
#[CoversClass( NewsAdminActions::class )]
final class NewsTest extends IntegrationTestCase {

	private NewsRepository $news;
	private NewsService $service;

	/**
	 * The REST routes' registration.
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		wp_set_current_user( 1 );

		$this->news     = new NewsRepository( $this->wpdb );
		$this->service  = new NewsService( $this->news, $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$controller     = new MeNewsController( $this->service( CreatorContext::class ), $this->news, $this->service( Logger::class ) );
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

		parent::tearDown();
	}

	public function test_a_message_is_written_published_corrected_withdrawn_and_audited_without_its_text(): void {
		$id    = $this->service->create( "  What's   new\n", '<p>New <strong>alerts</strong>!</p><script>alert(1)</script><a href="javascript:x()" onclick="x()">x</a><img src="x.png">' );
		$draft = $this->news->find( $id );
		self::assertNotNull( $draft );
		self::assertSame( array( "What's new", NewsMessage::DRAFT, null ), array( $draft->title, $draft->status, $draft->published_at ) );
		self::assertSame( '<p>New <strong>alerts</strong>!</p>alert(1)<a>x</a>', $draft->body, 'Only simple formatting and safe links.' );

		foreach ( array( array( '', '<p>x</p>', NewsException::INVALID_TITLE ), array( 'Title', '<p> </p>', NewsException::INVALID_BODY ) ) as [ $title, $body, $reason ] ) {
			$this->refused( fn() => $this->service->create( $title, $body ), $reason );
		}

		$this->service->update( $id, 'Draft', '<p>Draft</p>' );
		$this->service->publish( $id );
		$published = $this->news->find( $id );
		self::assertNotNull( $published );
		self::assertNotNull( $published->published_at );
		$this->refused( fn() => $this->service->publish( $id ), NewsException::NOT_DRAFT );
		$this->refused( fn() => $this->service->delete( $id ), NewsException::NOT_DRAFT );

		$this->service->update( $id, 'Fixed', '<p>Fixed</p>' );
		self::assertEquals( $published->published_at, $this->news->find( $id )?->published_at, 'A correction keeps the publication time.' );

		$this->service->withdraw( $id );
		self::assertSame( NewsMessage::WITHDRAWN, $this->news->find( $id )?->status );
		$this->refused( fn() => $this->service->withdraw( $id ), NewsException::NOT_PUBLISHED );

		$other = $this->service->create( 'Other', '<p>Other</p>' );
		$this->service->delete( $other );
		self::assertNull( $this->news->find( $other ) );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'news_published', 'news_updated', 'news_withdrawn', 'news_deleted' ), array_column( $audit, 'action' ), 'A draft\'s changes are not audited.' );
		self::assertStringNotContainsString( 'Fixed', (string) wp_json_encode( $audit ), 'Never the text.' );

		wp_set_current_user( $this->create_user( 'subscriber-news' ) );
		$this->refused( fn() => $this->service->create( 'x', '<p>x</p>' ), NewsException::NOT_AUTHORIZED );
	}

	public function test_a_creator_sees_the_published_messages_from_after_their_creation_and_closes_them(): void {
		$old = $this->service->create( 'Old', '<p>Before you</p>' );
		$this->service->publish( $old );
		$this->wpdb->update( $this->table( Tables::NEWS ), array( 'published_at' => '2020-01-01 00:00:00' ), array( 'id' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test setup.

		$user = $this->create_user( 'nova' );
		$this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $user );
		$draft = $this->service->create( 'Draft', '<p>Not yet</p>' );
		$first = $this->service->create( 'First', '<p>One</p>' );
		$this->service->publish( $first );
		$second = $this->service->create( 'Second', '<p>Two <a href="https://example.com">link</a></p>' );
		$this->service->publish( $second );
		$this->wpdb->update( $this->table( Tables::NEWS ), array( 'published_at' => '2099-01-01 00:00:00' ), array( 'id' => $second ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test setup: newest.
		$gone = $this->service->create( 'Gone', '<p>Withdrawn</p>' );
		$this->service->publish( $gone );
		$this->service->withdraw( $gone );

		wp_set_current_user( $user );
		$items = $this->data( $this->call( 'GET', '/merchboard/v1/me/news' ) )['items'];
		self::assertSame( array( 'Second', 'First' ), array_column( $items, 'title' ), 'Newest first; not older, draft or withdrawn messages.' );
		self::assertSame( '<p>Two <a href="https://example.com">link</a></p>', $items[0]['body'] );
		self::assertSame( array( null, null ), array( $items[0]['seen_at'], $items[0]['closed_at'] ) );

		$seen = $this->data( $this->call( 'POST', '/merchboard/v1/me/news/' . $second . '/seen' ) )['items'][0];
		self::assertIsString( $seen['seen_at'] );
		self::assertNull( $seen['closed_at'] );
		$closed = $this->data( $this->call( 'POST', '/merchboard/v1/me/news/' . $second . '/closed' ) )['items'][0];
		self::assertSame( $seen['seen_at'], $closed['seen_at'], 'The first time stays.' );
		self::assertIsString( $closed['closed_at'] );
		$this->call( 'POST', '/merchboard/v1/me/news/' . $second . '/closed' );
		self::assertCount( 1, $this->rows_where( $second ) );

		foreach ( array( $old, $draft, $gone, 999999 ) as $hidden ) {
			self::assertSame( 404, $this->call( 'POST', '/merchboard/v1/me/news/' . $hidden . '/seen' )->get_status() );
		}

		wp_set_current_user( $this->create_user( 'not-a-creator' ) );
		self::assertSame( 403, $this->call( 'GET', '/merchboard/v1/me/news' )->get_status() );
	}

	public function test_the_admin_actions_save_publish_and_keep_a_refused_text(): void {
		$actions = new NewsAdminActions( $this->service, $this->service( Logger::class ) );
		$input   = array( '_wpnonce' => wp_create_nonce( NewsAdminActions::ACTION ) );

		$url = $actions->handle( array_merge( $input, array( 'do' => 'save', 'title' => 'Hello', 'body' => '<p>World</p>' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
		self::assertStringContainsString( NewsAdminPage::RESULT_ARG . '=saved', $url );
		$id = (int) ( $this->rows( Tables::NEWS )[0]['id'] ?? 0 );

		self::assertStringContainsString( 'confirm_required', $actions->handle( array_merge( $input, array( 'do' => 'publish', 'news_id' => (string) $id ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
		self::assertSame( NewsMessage::DRAFT, $this->news->find( $id )?->status );
		self::assertStringContainsString( '=published', $actions->handle( array_merge( $input, array( 'do' => 'publish', 'news_id' => (string) $id, 'confirm' => '1' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
		self::assertTrue( $this->news->find( $id )?->is_published() );

		$refused = $actions->handle( array_merge( $input, array( 'do' => 'save', 'title' => '', 'body' => '<p>Typed text</p>' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
		self::assertStringContainsString( NewsException::INVALID_TITLE, $refused );
		self::assertSame( '<p>Typed text</p>', get_transient( 'merchboard_news_input_1' )['body'] ?? null, 'The typed text is shown again.' );
		delete_transient( 'merchboard_news_input_1' );
	}

	/**
	 * The reads of a message.
	 *
	 * @param int $news_id Message.
	 * @return list<array<mixed>>
	 */
	private function rows_where( int $news_id ): array {
		return array_values( array_filter( $this->rows_by( Tables::NEWS_READS ), static fn( array $row ): bool => (int) $row['news_id'] === $news_id ) );
	}

	/**
	 * Every row of a table without an id column.
	 *
	 * @param string $table Tables constant.
	 * @return list<array<mixed>>
	 */
	private function rows_by( string $table ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i', $this->table( $table ) ), ARRAY_A ) ?? array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test.

		return $rows;
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 */
	private function call( string $method, string $route ): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( $method, $route ) );
	}

	/**
	 * The data of a 200 answer.
	 *
	 * @param WP_REST_Response $response Answer.
	 * @return array<mixed>
	 */
	private function data( WP_REST_Response $response ): array {
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );

		return $data;
	}

	/**
	 * Asserts a refused change.
	 *
	 * @param callable $call   Call.
	 * @param string   $reason Expected reason.
	 */
	private function refused( callable $call, string $reason ): void {
		try {
			$call();
			self::fail( 'Expected NewsException.' );
		} catch ( NewsException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}
}
