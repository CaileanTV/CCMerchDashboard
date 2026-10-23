<?php
/**
 * Integration tests for the creators' webhooks.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Admin\AlertApiActions;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Api\AlertApiEvents;
use MerchBoard\Modules\Alerts\Api\AlertApiFeed;
use MerchBoard\Modules\Alerts\Api\AlertApiKeyRepository;
use MerchBoard\Modules\Alerts\Api\AlertApiKeys;
use MerchBoard\Modules\Alerts\Api\AlertApiQueue;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotFormat;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessages;
use MerchBoard\Modules\Alerts\History\AlertHistory;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;
use MerchBoard\Modules\Alerts\Rest\MeAlertsApiController;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhook;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhookRepository;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhooks;
use MerchBoard\Modules\Alerts\Webhooks\WebhookDelivery;
use MerchBoard\Modules\Alerts\Webhooks\WebhookDeliveryRepository;
use MerchBoard\Modules\Alerts\Webhooks\WebhookDispatcher;
use MerchBoard\Modules\Alerts\Webhooks\WebhookException;
use MerchBoard\Modules\Alerts\Webhooks\WebhookPayloads;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;
use wpdb;

/**
 * Step 25.12.2 (ADR-073): webhooks in SparkyBot's format (one signed event
 * per paid order) or Discord's, one attempt per delivery, paused after 10
 * failures in a row, tests and resends marked, never the address or the
 * secret in plain text or in the audit log. No real HTTP requests: every
 * request is answered by a `pre_http_request` stub.
 */
#[CoversClass( AlertWebhooks::class )]
#[CoversClass( AlertWebhookRepository::class )]
#[CoversClass( WebhookDeliveryRepository::class )]
#[CoversClass( WebhookDispatcher::class )]
#[CoversClass( WebhookPayloads::class )]
#[CoversClass( MeAlertsApiController::class )]
final class AlertWebhooksTest extends IntegrationTestCase {

	private const SECRET = 'sparky_secret_0123456789';

	private const URL = 'https://hooks.sparkybot.example/merch/abc';

	private const DISCORD = 'https://discord.com/api/webhooks/123/token-abcdef';

	private AlertApiSwitch $api;

	private AlertWebhooks $webhooks;

	private WebhookDispatcher $dispatcher;

	private FakeJobQueue $queue;

	private AlertEventRepository $events;

	private int $user;

	private int $creator;

	/**
	 * The requests the stub answered.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * The stub's status code; 0 for a network error.
	 *
	 * @var int
	 */
	private int $status = 204;

	/**
	 * Registers the routes on `rest_api_init` (null when not registered).
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		Settings::save( array_merge( Settings::all(), array( Settings::ALERTS_ENABLED => true ) ) );

		$this->events     = new AlertEventRepository( $this->wpdb );
		$this->api        = new AlertApiSwitch( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$this->queue      = new FakeJobQueue();
		$this->webhooks   = self::webhooks( $this->wpdb, $this->api, $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$this->dispatcher = self::dispatcher( $this->wpdb, $this->api, $this->webhooks, $this->queue, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( Logger::class ) );

		wp_set_current_user( 1 );
		$this->api->set( true );
		$this->user    = $this->create_user( 'nova_hooks' );
		$this->creator = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->user )->id;
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );

		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		delete_option( AlertApiSwitch::OPTION );
		delete_option( ChatbotFormat::OPTION );
		parent::tearDown();
	}

	/**
	 * The webhook service with a test salt.
	 *
	 * @param wpdb           $wpdb        Database.
	 * @param AlertApiSwitch $api         Switch.
	 * @param AuditTrail     $audit       Audit.
	 * @param Transaction    $transaction Transaction.
	 */
	public static function webhooks( wpdb $wpdb, AlertApiSwitch $api, AuditTrail $audit, Transaction $transaction ): AlertWebhooks {
		return new AlertWebhooks( new AlertWebhookRepository( $wpdb ), $api, $audit, $transaction, new ChatCipher( 'webhooks', 'test-salt' ) );
	}

	/**
	 * The dispatcher (alerts on, categories named "Nova merch").
	 *
	 * @param wpdb           $wpdb        Database.
	 * @param AlertApiSwitch $api         Switch.
	 * @param AlertWebhooks  $webhooks    Webhooks.
	 * @param FakeJobQueue   $queue       Jobs.
	 * @param AuditTrail     $audit       Audit.
	 * @param Transaction    $transaction Transaction.
	 * @param Logger         $logger      Log.
	 */
	public static function dispatcher( wpdb $wpdb, AlertApiSwitch $api, AlertWebhooks $webhooks, FakeJobQueue $queue, AuditTrail $audit, Transaction $transaction, Logger $logger ): WebhookDispatcher {
		$template = new AlertTemplate( static fn( int $term_id ): ?string => 77 === $term_id ? 'Nova merch' : null );
		$overlays = new AlertOverlayRepository( $wpdb );
		$events   = new AlertEventRepository( $wpdb );
		$feed     = new OverlayFeed( $overlays, $events, new CreatorRepository( $wpdb ), $template, static fn(): string => '', static function (): void {}, new OverlayTestQueue() );

		return new WebhookDispatcher(
			new AlertWebhookRepository( $wpdb ),
			new WebhookDeliveryRepository( $wpdb ),
			$webhooks,
			new WebhookPayloads( new AlertApiEvents( $template ), new ChatMessages( $template ), new ChatbotFormat( $audit, $transaction ) ),
			$events,
			new CreatorRepository( $wpdb ),
			$api,
			new AlertsAvailability( static fn(): bool => false ),
			$queue,
			new AlertOverlays( $overlays, $feed, new OverlayTestQueue(), new PostMetaProductStore(), $audit, $transaction, static fn(): int => 0 ),
			$logger,
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-23 18:05:00', new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * The `pre_http_request` stub.
	 *
	 * @param mixed                $preempt Preempt.
	 * @param array<string, mixed> $args    Request arguments.
	 * @param string               $url     Address.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function answer( mixed $preempt, array $args, string $url ): array|\WP_Error {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( 0 === $this->status ) {
			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out for ' . $url );
		}

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $this->status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	public function test_a_webhook_is_stored_encrypted_limited_and_audited_without_secrets(): void {
		$made = $this->webhooks->create( $this->creator, 'SparkyBot', AlertWebhook::JSON, self::URL, self::SECRET, '' );
		self::assertNull( $made['secret'], 'A pasted secret is not shown back.' );
		self::assertSame( 'hooks.sparkybot.example', $made['webhook']->url_host );
		self::assertSame( 'hooks.sparkybot.exa…/abc', $made['webhook']->url_hint, 'At most 19 characters of the host and the last 4 of the address.' );

		$generated = $this->webhooks->create( $this->creator, 'Own bot', AlertWebhook::JSON, 'https://bot.example.com/hook', '', '' );
		self::assertMatchesRegularExpression( '/^whsec_[0-9a-f]{64}$/', (string) $generated['secret'] );
		self::assertSame( $generated['secret'], $this->webhooks->secret_of( $generated['webhook'] ) );

		$this->webhooks->create( $this->creator, 'Discord', AlertWebhook::DISCORD, self::DISCORD, '', '' );
		$this->refused( fn() => $this->webhooks->create( $this->creator, 'Fourth', AlertWebhook::JSON, self::URL, self::SECRET, '' ), WebhookException::LIMIT );

		$stored = (string) wp_json_encode( $this->rows( Tables::ALERT_WEBHOOKS ) );
		$audit  = (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) );

		foreach ( array( self::URL, self::SECRET, self::DISCORD, (string) $generated['secret'] ) as $secret ) {
			self::assertStringNotContainsString( $secret, $stored, 'Encrypted at rest.' );
			self::assertStringNotContainsString( $secret, $audit, 'Never in the audit log.' );
		}

		self::assertSame( 3, substr_count( $audit, 'alert_webhook_created' ) );
		self::assertSame( self::URL, $this->webhooks->url_of( $made['webhook'] ) );
	}

	public function test_only_public_https_addresses_and_discords_own(): void {
		foreach ( array( 'http://bot.example.com/x', 'https://127.0.0.1/x', 'https://localhost/x', 'https://printer.local/x', 'https://user:pw@bot.example.com/x', 'https://bot.example.com:8443/x', 'https://[::1]/x', 'ftp://bot.example.com' ) as $url ) {
			$this->refused( fn() => $this->webhooks->create( $this->creator, 'Bad', AlertWebhook::JSON, $url, self::SECRET, '' ), WebhookException::INVALID_URL, $url );
		}

		$this->refused( fn() => $this->webhooks->create( $this->creator, 'Bad', AlertWebhook::DISCORD, self::URL, '', '' ), WebhookException::INVALID_URL );
		$this->refused( fn() => $this->webhooks->create( $this->creator, 'Bad', AlertWebhook::DISCORD, 'https://discord.com/channels/1/2', '', '' ), WebhookException::INVALID_URL );
		$this->refused( fn() => $this->webhooks->create( $this->creator, 'Bad', AlertWebhook::JSON, self::URL, 'short', '' ), WebhookException::INVALID_SECRET );
		$this->refused( fn() => $this->webhooks->create( $this->creator, 'Bad', 'xml', self::URL, self::SECRET, '' ), WebhookException::INVALID_FORMAT );
		$this->refused( fn() => $this->webhooks->create( $this->creator, '', AlertWebhook::JSON, self::URL, self::SECRET, '' ), WebhookException::INVALID_NAME );

		$this->api->set( false );
		$this->refused( fn() => $this->webhooks->create( $this->creator, 'Off', AlertWebhook::JSON, self::URL, self::SECRET, '' ), WebhookException::DISABLED );
	}

	public function test_a_paid_order_sends_one_signed_event_with_its_items_once(): void {
		$hook = $this->webhooks->create( $this->creator, 'SparkyBot', AlertWebhook::JSON, self::URL, self::SECRET, '' )['webhook'];
		$this->alert( $this->creator, 500, 1, 'Hoodie', 'Size: L', 2 );
		$this->alert( $this->creator, 500, 2, 'Mug', '', 1 );
		$other = ( new CreatorRepository( $this->wpdb ) )->create( 'Orion', CreatorStatus::Active )->id;
		$this->alert( $other, 500, 3, 'Orion tee', '', 1 );

		$this->dispatcher->order_paid( 500 );
		self::assertCount( 1, $this->queue->queued, 'One delivery per webhook and order; Orion has none.' );
		self::assertSame( array(), $this->requests, 'Nothing is sent during the checkout.' );

		$this->run_jobs();
		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$headers = $request['args']['headers'];
		$body    = (string) $request['args']['body'];
		self::assertIsArray( $headers );
		self::assertSame( self::URL, $request['url'] );
		self::assertSame( 0, $request['args']['redirection'] );
		self::assertSame( WebhookDispatcher::TIMEOUT, $request['args']['timeout'] );
		self::assertSame( (string) strtotime( '2026-10-23 18:05:00 UTC' ), $headers['X-MerchBoard-Timestamp'] );
		self::assertSame( 'sha256=' . hash_hmac( 'sha256', $headers['X-MerchBoard-Timestamp'] . '.' . $body, self::SECRET ), $headers['X-MerchBoard-Signature'], 'Verifiable with the pasted secret.' );

		$event = json_decode( $body, true );
		self::assertIsArray( $event );
		self::assertSame( $headers['X-MerchBoard-Event-Id'], $event['id'] );
		self::assertMatchesRegularExpression( '/^evt_[0-9a-f]{32}$/', $event['id'] );
		self::assertSame( 'purchase', $event['type'] );
		self::assertSame( AlertApiEvents::order_ref( $this->creator, 500 ), $event['order_ref'] );
		self::assertSame( array( 'nova_fan', 'nova_fan' ), array( $event['username'], $event['display_name'] ) );
		self::assertSame( array( '548.00', 'DKK', 3 ), array( $event['total'], $event['currency'], $event['item_count'] ) );
		self::assertSame(
			array(
				array(
					'product'    => 'Hoodie',
					'quantity'   => 2,
					'category'   => 'Nova merch',
					'variation'  => 'Size: L',
					'price'      => '199.00',
					'line_total' => '398.00',
				),
				array(
					'product'    => 'Mug',
					'quantity'   => 1,
					'category'   => 'Nova merch',
					'variation'  => '',
					'price'      => '199.00',
					'line_total' => '199.00',
				),
			),
			$event['items']
		);
		self::assertStringNotContainsString( '1500', $body, 'Never the order number.' );

		// The same order again (another status change): the same event id, so the receiver drops it.
		$this->dispatcher->order_paid( 500 );
		$this->run_jobs();
		$again = json_decode( (string) $this->requests[1]['args']['body'], true );
		self::assertIsArray( $again );
		self::assertSame( $event['id'], $again['id'] );

		$log = $this->webhooks->for_creator( $this->creator )[0];
		self::assertSame( 0, $log->failures );
		self::assertNotNull( $log->last_delivery_at );
		self::assertSame( $hook->id, $log->id );
	}

	public function test_discord_gets_one_message_per_order_without_mentions(): void {
		$this->webhooks->create( $this->creator, 'Discord', AlertWebhook::DISCORD, self::DISCORD, '', '$UserName bought $Product @everyone' );
		$this->alert( $this->creator, 600, 1, 'Hoodie' );
		$this->alert( $this->creator, 600, 2, 'Mug' );

		$this->dispatcher->order_paid( 600 );
		$this->run_jobs();

		$body = json_decode( (string) $this->requests[0]['args']['body'], true );
		self::assertIsArray( $body );
		self::assertSame( "nova_fan bought Hoodie @everyone\nnova_fan bought Mug @everyone", $body['content'], 'Line mode: the lines below each other in one message.' );
		self::assertSame( array( 'parse' => array() ), $body['allowed_mentions'], 'Mentions never ping.' );
		self::assertArrayNotHasKey( 'X-MerchBoard-Signature', $this->requests[0]['args']['headers'] );

		update_option( ChatbotFormat::OPTION, array( 'mode' => 'order' ), false );
		$this->webhooks->update( $this->creator, $this->webhooks->for_creator( $this->creator )[0]->id, array( 'template' => '$Name bought $Products' ) );
		$this->dispatcher->order_paid( 600 );
		$this->run_jobs();
		$body = json_decode( (string) $this->requests[1]['args']['body'], true );
		self::assertIsArray( $body );
		self::assertStringStartsWith( 'nova_fan bought ', $body['content'] );
		self::assertStringContainsString( 'Hoodie', $body['content'] );
		self::assertStringContainsString( 'Mug', $body['content'] );
	}

	public function test_one_attempt_failures_pause_after_ten_and_a_success_clears_them(): void {
		$hook = $this->webhooks->create( $this->creator, 'SparkyBot', AlertWebhook::JSON, self::URL, self::SECRET, '' )['webhook'];
		$this->alert( $this->creator, 700, 1 );
		$this->status = 500;

		$this->dispatcher->order_paid( 700 );
		$this->run_jobs();
		self::assertCount( 1, $this->requests, 'One attempt.' );
		self::assertSame( array(), $this->queue->pending, 'No retry is queued.' );
		$delivery = $this->deliveries()[0];
		self::assertSame( array( WebhookDelivery::FAILED, 500, 'http_500' ), array( $delivery['status'], (int) $delivery['http_status'], $delivery['error'] ) );

		$this->status = 0;
		$this->dispatcher->order_paid( 700 );
		$this->run_jobs();
		self::assertStringNotContainsString( 'sparkybot', (string) $this->deliveries()[1]['error'], 'Never the address in the log.' );

		$this->status = 204;
		$this->dispatcher->order_paid( 700 );
		$this->run_jobs();
		self::assertSame( 0, $this->find( $hook->id )->failures, 'A success clears the failures.' );

		$this->status = 503;

		for ( $i = 1; $i <= WebhookDispatcher::PAUSE_AFTER; $i++ ) {
			$this->dispatcher->order_paid( 700 );
			$this->run_jobs();
		}

		$paused = $this->find( $hook->id );
		self::assertNotNull( $paused->paused_at );
		self::assertSame( WebhookDispatcher::PAUSE_AFTER, $paused->failures );

		$sent = count( $this->requests );
		$this->dispatcher->order_paid( 700 );
		$this->run_jobs();
		self::assertCount( $sent, $this->requests, 'A paused webhook gets nothing.' );

		$resumed = $this->webhooks->update( $this->creator, $hook->id, array( 'resume' => true ) );
		self::assertNull( $resumed->paused_at );
		self::assertSame( 0, $resumed->failures );
	}

	public function test_nothing_is_sent_while_the_switches_or_the_creator_say_no(): void {
		$hook = $this->webhooks->create( $this->creator, 'SparkyBot', AlertWebhook::JSON, self::URL, self::SECRET, '' )['webhook'];
		$this->alert( $this->creator, 800, 1 );

		$this->api->set( false );
		$this->dispatcher->order_paid( 800 );
		$this->api->set( true );

		Settings::save( array_merge( Settings::all(), array( Settings::ALERTS_ENABLED => false ) ) );
		$this->dispatcher->order_paid( 800 );
		Settings::save( array_merge( Settings::all(), array( Settings::ALERTS_ENABLED => true ) ) );

		$this->webhooks->update( $this->creator, $hook->id, array( 'enabled' => false ) );
		$this->dispatcher->order_paid( 800 );
		self::assertSame( array(), $this->queue->queued );

		$this->webhooks->update( $this->creator, $hook->id, array( 'enabled' => true ) );
		$this->dispatcher->order_paid( 800 );
		$this->service( CreatorService::class )->set_status( $this->creator, CreatorStatus::Inactive );
		$this->run_jobs();
		self::assertSame( array(), $this->requests, 'Checked again when the job runs.' );
		self::assertSame( WebhookDelivery::SKIPPED, $this->deliveries()[0]['status'] );

		$this->queue->is_available = false;
		$this->service( CreatorService::class )->set_status( $this->creator, CreatorStatus::Active );
		$this->dispatcher->order_paid( 800 );
		self::assertSame( 'queue_unavailable', $this->deliveries()[1]['error'], 'Without Action Scheduler it fails at once; the checkout goes on.' );
	}

	public function test_tests_and_resends_are_marked_with_new_ids(): void {
		$hook         = $this->webhooks->create( $this->creator, 'SparkyBot', AlertWebhook::JSON, self::URL, self::SECRET, '' )['webhook'];
		$alert        = $this->alert( $this->creator, 900, 1 );
		$this->status = 500;

		$test = $this->dispatcher->send_test( $this->creator, $hook->id );
		self::assertSame( array( 'test', WebhookDelivery::FAILED ), array( $test->kind, $test->status ), 'Sent at once.' );
		self::assertSame( 0, $this->find( $hook->id )->failures, 'Tests never count towards the pause.' );
		$body = json_decode( (string) $this->requests[0]['args']['body'], true );
		self::assertIsArray( $body );
		self::assertSame( array( 'test', 'TestFan' ), array( $body['type'], $body['display_name'] ) );

		$this->status = 204;
		$history      = new AlertHistory( $this->events, new AlertOverlayRepository( $this->wpdb ), new OverlayFeed( new AlertOverlayRepository( $this->wpdb ), $this->events, new CreatorRepository( $this->wpdb ), new AlertTemplate( static fn(): ?string => null ), static fn(): string => '', static function (): void {}, new OverlayTestQueue() ), new OverlayTestQueue(), null, null, $this->dispatcher );
		$history->resend_alert( $this->creator, $alert );
		$history->resend_alert( $this->creator, $alert );
		$this->run_jobs();

		$first  = json_decode( (string) $this->requests[1]['args']['body'], true );
		$second = json_decode( (string) $this->requests[2]['args']['body'], true );
		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( array( 'resend', 'resend' ), array( $first['type'], $second['type'] ) );
		self::assertNotSame( $first['id'], $second['id'], 'A resend is a new event.' );
		self::assertSame( AlertApiEvents::order_ref( $this->creator, 900 ), $first['order_ref'] );

		$this->api->set( false );
		$this->refused( fn() => $this->dispatcher->send_test( $this->creator, $hook->id ), WebhookException::DISABLED );
	}

	public function test_the_log_keeps_the_newest_fifty_and_delete_removes_them(): void {
		$hook = $this->webhooks->create( $this->creator, 'SparkyBot', AlertWebhook::JSON, self::URL, self::SECRET, '' )['webhook'];
		$this->alert( $this->creator, 950, 1 );

		for ( $i = 0; $i < WebhookDeliveryRepository::KEEP + 3; $i++ ) {
			$this->dispatcher->order_paid( 950 );
		}

		self::assertCount( WebhookDeliveryRepository::KEEP, $this->deliveries() );

		$this->webhooks->delete( $this->creator, $hook->id );
		self::assertSame( array(), $this->deliveries() );
		self::assertSame( array(), $this->rows( Tables::ALERT_WEBHOOKS ) );
		self::assertStringContainsString( 'alert_webhook_deleted', (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ) );
	}

	public function test_the_creator_routes_and_the_administrators_switch_off(): void {
		$this->routes();
		wp_set_current_user( $this->user );

		$made = $this->call( 'POST', '/merchboard/v1/me/alerts/api/webhooks', array( 'name' => 'Own bot', 'format' => 'json', 'url' => 'https://bot.example.com/hook' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
		self::assertSame( 201, $made->get_status() );
		$data = $this->data( $made );
		self::assertMatchesRegularExpression( '/^whsec_[0-9a-f]{64}$/', $data['secret'] );
		self::assertSame( 'bot.example.com', $data['webhooks'][0]['host'] );
		self::assertArrayNotHasKey( 'url', $data['webhooks'][0] );
		self::assertSame( 3, $data['max_webhooks'] );

		$id = $data['webhooks'][0]['id'];
		self::assertArrayNotHasKey( 'secret', $this->data( $this->call( 'GET', '/merchboard/v1/me/alerts/api' ) ), 'Shown once.' );
		self::assertSame( 400, $this->call( 'POST', '/merchboard/v1/me/alerts/api/webhooks', array( 'name' => 'x', 'format' => 'json', 'url' => 'http://bot.example.com' ) )->get_status() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.

		$tested = $this->data( $this->call( 'POST', '/merchboard/v1/me/alerts/api/webhooks/' . $id . '/test' ) );
		self::assertSame( array( 'test', 'sent', 204 ), array( $tested['delivery']['kind'], $tested['delivery']['status'], $tested['delivery']['http_status'] ) );
		self::assertSame( 'sent', $tested['deliveries'][0]['status'] );

		$secret = $this->data( $this->call( 'POST', '/merchboard/v1/me/alerts/api/webhooks/' . $id . '/secret', array( 'secret' => self::SECRET ) ) );
		self::assertArrayNotHasKey( 'secret', $secret, 'A pasted secret is not shown back.' );

		self::assertFalse( $this->data( $this->call( 'PATCH', '/merchboard/v1/me/alerts/api/webhooks/' . $id, array( 'enabled' => false ) ) )['webhooks'][0]['enabled'] );

		$orion = ( new CreatorRepository( $this->wpdb ) )->create( 'Orion', CreatorStatus::Active )->id;
		$their = $this->webhooks->create( $orion, 'Theirs', AlertWebhook::JSON, self::URL, self::SECRET, '' )['webhook'];
		self::assertSame( 404, $this->call( 'DELETE', '/merchboard/v1/me/alerts/api/webhooks/' . $their->id )->get_status(), 'Another creator\'s webhook.' );
		self::assertSame( 404, $this->call( 'POST', '/merchboard/v1/me/alerts/api/webhooks/' . $their->id . '/test' )->get_status() );

		wp_set_current_user( 1 );
		$this->webhooks->update( $orion, $their->id, array( 'enabled' => true ) );
		$actions = new AlertApiActions( $this->api, new AlertApiKeys( new AlertApiKeyRepository( $this->wpdb ), $this->api, $this->service( AuditTrail::class ), $this->service( Transaction::class ) ), $this->service( Logger::class ), $this->webhooks );
		$input   = array(
			'_wpnonce'   => wp_create_nonce( AlertApiActions::ACTION ),
			'do'         => 'disable_webhooks',
			'creator_id' => (string) $orion,
		);
		self::assertStringContainsString( 'api_confirm_required', $actions->process( $input ) );
		self::assertStringContainsString( 'api_webhooks_disabled', $actions->process( array_merge( $input, array( 'confirm' => '1' ) ) ) );
		self::assertFalse( $this->find( $their->id )->enabled );

		wp_set_current_user( $this->user );
		self::assertSame( 200, $this->call( 'DELETE', '/merchboard/v1/me/alerts/api/webhooks/' . $id )->get_status() );
		wp_set_current_user( 1 );
		$this->api->set( false );
		wp_set_current_user( $this->user );
		self::assertSame( 403, $this->call( 'POST', '/merchboard/v1/me/alerts/api/webhooks', array( 'name' => 'Off', 'format' => 'discord', 'url' => self::DISCORD ) )->get_status() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
	}

	/**
	 * Runs the queued jobs.
	 */
	private function run_jobs(): void {
		$runner = function ( string $hook, array $args ): void {
			$this->dispatcher->run( $args[0] ?? 0 );
		};

		do {
			$ran = $this->queue->run_next( $runner );
		} while ( $ran );
	}

	/**
	 * A webhook as stored now.
	 *
	 * @param int $id Webhook.
	 */
	private function find( int $id ): AlertWebhook {
		$webhook = ( new AlertWebhookRepository( $this->wpdb ) )->find( $id );
		self::assertNotNull( $webhook );

		return $webhook;
	}

	/**
	 * The deliveries, oldest first.
	 *
	 * @return list<array<mixed>>
	 */
	private function deliveries(): array {
		return $this->rows( Tables::ALERT_WEBHOOK_DELIVERIES );
	}

	/**
	 * Asserts that a change is refused.
	 *
	 * @param callable $change  Change.
	 * @param string   $reason  WebhookException constant.
	 * @param string   $message Message.
	 */
	private function refused( callable $change, string $reason, string $message = '' ): void {
		try {
			$change();
			self::fail( 'Not refused: ' . $message );
		} catch ( WebhookException $e ) {
			self::assertSame( $reason, $e->reason(), $message );
		}
	}

	/**
	 * Registers the creator routes on a fresh REST server.
	 */
	private function routes(): void {
		$keys       = new AlertApiKeys( new AlertApiKeyRepository( $this->wpdb ), $this->api, $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$template   = new AlertTemplate( static fn(): ?string => null );
		$overlays   = new AlertOverlayRepository( $this->wpdb );
		$overlay    = new OverlayFeed( $overlays, $this->events, new CreatorRepository( $this->wpdb ), $template, static fn(): string => '', static function (): void {}, new OverlayTestQueue() );
		$feed       = new AlertApiFeed( $this->api, new AlertsAvailability( static fn(): bool => false ), new CreatorRepository( $this->wpdb ), $this->events, new AlertApiEvents( $template ), new AlertApiQueue( new AlertApiKeyRepository( $this->wpdb ) ), new AlertOverlays( $overlays, $overlay, new OverlayTestQueue(), new PostMetaProductStore(), $this->service( AuditTrail::class ), $this->service( Transaction::class ), static fn(): int => 0 ) );
		$controller = new MeAlertsApiController( $this->service( CreatorContext::class ), $keys, $this->api, $feed, $this->webhooks, $this->dispatcher, new WebhookDeliveryRepository( $this->wpdb ), $this->service( Logger::class ) );

		$this->register = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	/**
	 * Stores an alert of an order line (Nova's category, 199.00 each, 548.00 for the creator's order).
	 *
	 * @param int    $creator_id Creator.
	 * @param int    $order_id   Order.
	 * @param int    $item_id    Order line.
	 * @param string $product    Product name.
	 * @param string $variation  Variation.
	 * @param int    $quantity   Quantity.
	 * @return int The alert's id.
	 */
	private function alert( int $creator_id, int $order_id, int $item_id, string $product = 'Hoodie', string $variation = '', int $quantity = 1 ): int {
		$this->events->insert( new AlertEvent( $creator_id, $order_id, (string) ( 1000 + $order_id ), $item_id, 70, 0, $product, $variation, $quantity, 0, array( 77 ), 'nova_fan', new DateTimeImmutable( '2026-10-23 18:00:00', new DateTimeZone( 'UTC' ) ), 0, number_format( 199 * $quantity, 4, '.', '' ), '548.0000', 'DKK' ) );

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT MAX(id) FROM %i', $this->table( Tables::ALERT_EVENTS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
	}

	/**
	 * Dispatches a request with a JSON body.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $body   JSON body.
	 */
	private function call( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * A response's data as an array.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<string, mixed>
	 */
	private function data( WP_REST_Response $response ): array {
		$data = $response->get_data();
		self::assertIsArray( $data );

		/**
		 * The answer, checked above.
		 *
		 * @var array<string, mixed> $data
		 */
		return $data;
	}
}
