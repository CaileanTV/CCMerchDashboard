<?php
/**
 * Sends purchases to the creators' webhooks.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Webhooks;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Api\AlertApiEvents;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\OverlayRateLimiter;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers a creator's purchases to their webhooks (step 25.12.2, ADR-073).
 *
 * - **When:** after the order is paid and its alerts are recorded (the same
 *   hooks as alerts and the chatbot), only while the administrator has the
 *   API on, alerts are sent (kill switch, MerchAlert guard) and the creator
 *   is active and has access to webhooks (step 25.12.4). One delivery per webhook and order, queued in Action
 *   Scheduler so a slow receiver never holds up the checkout; without
 *   Action Scheduler the delivery fails at once.
 * - **How:** one attempt (the project owner's choice), with
 *   `wp_safe_remote_post()`, no redirects, a 5 second timeout and a small
 *   answer limit. The host is resolved first and refused unless every
 *   address is public (FILTER_FLAG_GLOBAL_RANGE: no private, loopback,
 *   link-local such as cloud metadata, shared or reserved IPv4/IPv6); cURL
 *   then connects to exactly that address (no DNS rebinding; 25.13 review).
 *   The creator sees a reason code, never the transport's message. A 2xx answer is a success and clears
 *   the failures; anything else counts one more, and PAUSE_AFTER in a row
 *   pause the webhook until the creator resumes it. Polling and resending
 *   from the history fetch what a webhook missed.
 * - **Tests:** sent at once so the creator sees the answer; they never count
 *   towards the pause.
 */
class WebhookDispatcher {

	/**
	 * The Action Scheduler hook.
	 */
	public const HOOK = 'merchboard_alert_webhook_send';

	/**
	 * Failed deliveries in a row that pause a webhook.
	 */
	public const PAUSE_AFTER = 10;

	/**
	 * Seconds to wait for an answer.
	 */
	public const TIMEOUT = 5;

	/**
	 * Tests a creator may send per minute (each one waits for the receiver; 25.13 review).
	 */
	public const TESTS_PER_MINUTE = 10;

	/**
	 * Reason of a delivery whose host is not only public addresses (or has none).
	 */
	public const BLOCKED = 'blocked_address';

	/**
	 * Reason of a delivery that could not connect.
	 */
	public const UNREACHABLE = 'unreachable';

	/**
	 * Reason of a delivery whose receiver did not answer in time.
	 */
	public const TIMED_OUT = 'timeout';

	/**
	 * Limit of the creator's tests.
	 *
	 * @var OverlayRateLimiter
	 */
	private OverlayRateLimiter $tests;

	/**
	 * The addresses of a host name.
	 *
	 * @var Closure(string): list<string>
	 */
	private Closure $resolve;

	/**
	 * The current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param AlertWebhookRepository               $webhooks     Webhooks.
	 * @param WebhookDeliveryRepository            $deliveries   Deliveries.
	 * @param AlertWebhooks                        $service      Addresses and secrets.
	 * @param WebhookPayloads                      $payloads     Bodies.
	 * @param AlertEventRepository                 $events       Alerts.
	 * @param CreatorRepository                    $creators     Creators.
	 * @param AlertApiSwitch                       $api          The administrator's switch.
	 * @param AlertsAvailability                   $availability Kill switch and MerchAlert guard.
	 * @param JobQueue                             $queue        Jobs (Action Scheduler).
	 * @param AlertOverlays                        $overlays     Test alerts.
	 * @param Logger                               $logger       Technical log.
	 * @param (Closure(): DateTimeImmutable)|null  $clock        Current time; defaults to now.
	 * @param OverlayRateLimiter|null              $tests        Limit of the creator's tests; defaults to TESTS_PER_MINUTE.
	 * @param (Closure(string): list<string>)|null $resolve      The addresses of a host; defaults to DNS (A and AAAA).
	 */
	public function __construct(
		private AlertWebhookRepository $webhooks,
		private WebhookDeliveryRepository $deliveries,
		private AlertWebhooks $service,
		private WebhookPayloads $payloads,
		private AlertEventRepository $events,
		private CreatorRepository $creators,
		private AlertApiSwitch $api,
		private AlertsAvailability $availability,
		private JobQueue $queue,
		private AlertOverlays $overlays,
		private Logger $logger,
		?Closure $clock = null,
		?OverlayRateLimiter $tests = null,
		?Closure $resolve = null
	) {
		$this->clock   = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$this->tests   = $tests ?? new OverlayRateLimiter( null, 'merchboard_alerts_wh_test_', self::TESTS_PER_MINUTE );
		$this->resolve = $resolve ?? self::dns( ... );
	}

	/**
	 * Queues the deliveries of a paid order (`woocommerce_order_status_*`,
	 * after the alerts are recorded). Cheap while the API is off.
	 *
	 * @param mixed $order_id Order id from WooCommerce.
	 */
	public function order_paid( mixed $order_id ): void {
		if ( ! is_numeric( $order_id ) || (int) $order_id <= 0 || ! $this->sending() ) {
			return;
		}

		try {
			foreach ( $this->events->creators_of_order( (int) $order_id ) as $creator_id ) {
				$webhooks = $this->active( $creator_id );
				$events   = array() === $webhooks ? array() : $this->events->for_creator_orders( $creator_id, array( (int) $order_id ) );

				if ( array() === $events ) {
					continue;
				}

				// One delivery per purchase: an order that becomes processing and then completed is not sent twice (25.13 review).
				$event_id = WebhookPayloads::event_id( AlertApiEvents::PURCHASE, $events[0] );

				foreach ( $webhooks as $webhook ) {
					if ( ! $this->deliveries->has_event( $webhook->id, $event_id ) ) {
						$this->enqueue( $webhook, AlertApiEvents::PURCHASE, $events );
					}
				}
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Webhook deliveries could not be queued.', array( 'reason' => $e->getMessage() ) );
		}
	}

	/**
	 * Queues resent alerts (the history's resend), one delivery per order
	 * and webhook; nothing while the API is off.
	 *
	 * @phpstan-param list<AlertEvent> $events
	 *
	 * @param int          $creator_id Creator.
	 * @param AlertEvent[] $events     The creator's alerts.
	 * @return int Deliveries queued.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	public function resend( int $creator_id, array $events ): int {
		if ( array() === $events || ! $this->sending() ) {
			return 0;
		}

		$by_order = array();

		foreach ( $events as $event ) {
			$by_order[ $event->order_id ][] = $event;
		}

		$queued = 0;

		foreach ( $this->active( $creator_id ) as $webhook ) {
			foreach ( $by_order as $order_events ) {
				$this->enqueue( $webhook, AlertApiEvents::RESEND, $order_events );
				++$queued;
			}
		}

		return $queued;
	}

	/**
	 * Sends a test purchase to one of the creator's webhooks at once.
	 *
	 * @param int $creator_id Creator.
	 * @param int $webhook_id Webhook.
	 * @return WebhookDelivery The delivery with its outcome.
	 * @throws WebhookException When the API is off, the creator has no access, sent too many tests or it is not the creator's webhook.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	public function send_test( int $creator_id, int $webhook_id ): WebhookDelivery {
		$this->check_access( $creator_id );
		$this->check_rate( $creator_id );

		return $this->test_one( $creator_id, $webhook_id );
	}

	/**
	 * Sends a test purchase to a webhook (the checks are done).
	 *
	 * @param int $creator_id Creator.
	 * @param int $webhook_id Webhook.
	 * @throws WebhookException When it is not the creator's webhook.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	private function test_one( int $creator_id, int $webhook_id ): WebhookDelivery {
		$webhook = $this->webhooks->find_for_creator( $creator_id, $webhook_id ) ?? self::refuse( WebhookException::UNKNOWN );
		$id      = $this->store( $webhook, AlertApiEvents::TEST, array( $this->overlays->test_event( $creator_id ) ) );
		$this->deliver( $id, $webhook, false );

		return $this->deliveries->find( $id ) ?? self::refuse( WebhookException::UNKNOWN );
	}

	/**
	 * Sends a test purchase to each of the creator's switched-on webhooks at
	 * once (also a paused one, so it can be checked before resuming).
	 *
	 * @param int $creator_id Creator.
	 * @return list<WebhookDelivery> The deliveries with their outcome, oldest webhook first.
	 * @throws WebhookException When the API is off, the creator has no access or sent too many tests.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	public function send_test_all( int $creator_id ): array {
		$this->check_access( $creator_id );
		$this->check_rate( $creator_id );

		$deliveries = array();

		foreach ( $this->webhooks->for_creator( $creator_id ) as $webhook ) {
			if ( $webhook->enabled ) {
				$deliveries[] = $this->test_one( $creator_id, $webhook->id );
			}
		}

		return $deliveries;
	}

	/**
	 * Counts a test of the creator and refuses beyond TESTS_PER_MINUTE.
	 *
	 * @param int $creator_id Creator.
	 * @throws WebhookException When there were too many.
	 */
	private function check_rate( int $creator_id ): void {
		if ( ! $this->tests->allow( 'creator:' . $creator_id ) ) {
			self::refuse( WebhookException::TOO_MANY );
		}
	}

	/**
	 * Requires the API on and the creator's access to webhooks.
	 *
	 * @param int $creator_id Creator.
	 * @throws WebhookException When one of them is missing.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function check_access( int $creator_id ): void {
		if ( ! $this->api->enabled() ) {
			self::refuse( WebhookException::DISABLED );
		}

		if ( ! $this->service->allowed( $creator_id ) ) {
			self::refuse( WebhookException::NO_ACCESS );
		}
	}

	/**
	 * Refuses a test.
	 *
	 * @param string $reason WebhookException constant.
	 * @throws WebhookException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new WebhookException( $reason );
	}

	/**
	 * Delivers a queued delivery (the Action Scheduler job): skipped when the
	 * webhook is gone, off or paused, or the switches or the creator say no.
	 *
	 * @param mixed $delivery_id Delivery.
	 */
	public function run( mixed $delivery_id ): void {
		try {
			$delivery = is_numeric( $delivery_id ) ? $this->deliveries->find( (int) $delivery_id ) : null;

			if ( null === $delivery || WebhookDelivery::PENDING !== $delivery->status ) {
				return;
			}

			$webhook = $this->webhooks->find( $delivery->webhook_id );

			if ( null === $webhook || ! $webhook->is_active() || ! $this->sending() || true !== $this->creators->find( $webhook->creator_id )?->is_active() || ! $this->service->allowed( $webhook->creator_id ) ) {
				$this->deliveries->finish( $delivery->id, WebhookDelivery::SKIPPED, null, 'skipped' );

				return;
			}

			$this->deliver( $delivery->id, $webhook, true );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A webhook delivery failed.', array( 'reason' => $e->getMessage() ) );
		}
	}

	/**
	 * Stores a delivery and queues its job.
	 *
	 * @phpstan-param non-empty-list<AlertEvent> $events
	 *
	 * @param AlertWebhook $webhook Webhook.
	 * @param string       $kind    AlertApiEvents::PURCHASE or RESEND.
	 * @param AlertEvent[] $events  The creator's alerts of one order.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function enqueue( AlertWebhook $webhook, string $kind, array $events ): void {
		$id = $this->store( $webhook, $kind, $events );

		if ( ! $this->queue->enqueue( self::HOOK, array( $id ) ) ) {
			$this->deliveries->finish( $id, WebhookDelivery::FAILED, null, 'queue_unavailable' );
		}
	}

	/**
	 * Stores a pending delivery with its body.
	 *
	 * @phpstan-param non-empty-list<AlertEvent> $events
	 *
	 * @param AlertWebhook $webhook Webhook.
	 * @param string       $kind    AlertApiEvents constant.
	 * @param AlertEvent[] $events  The creator's alerts of one order.
	 * @return int Delivery.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function store( AlertWebhook $webhook, string $kind, array $events ): int {
		$event_id = WebhookPayloads::event_id( $kind, $events[0] );
		$json     = $this->payloads->json( $event_id, $kind, $events );
		$body     = AlertWebhook::DISCORD === $webhook->format ? $this->payloads->discord( $webhook->template, $kind, $events ) : $json['body'];

		return $this->deliveries->insert( $webhook, $event_id, $kind, $json['order_ref'], $body );
	}

	/**
	 * Sends a delivery once and notes the outcome.
	 *
	 * @param int          $id      Delivery.
	 * @param AlertWebhook $webhook Webhook.
	 * @param bool         $count   Whether a failure counts towards the pause (not for tests).
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	private function deliver( int $id, AlertWebhook $webhook, bool $count ): void {
		$delivery = $this->deliveries->find( $id );
		$url      = $this->service->url_of( $webhook );
		$secret   = $this->service->secret_of( $webhook );

		if ( null === $delivery ) {
			return;
		}

		if ( null === $url || ( AlertWebhook::JSON === $webhook->format && null === $secret ) ) {
			// The salts changed: the creator adds the webhook again.
			$this->outcome( $id, $webhook, $count, null, 'unreadable' );

			return;
		}

		$now     = ( $this->clock )();
		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( AlertWebhook::JSON === $webhook->format ) {
			$headers['X-MerchBoard-Event-Id']  = $delivery->event_id;
			$headers['X-MerchBoard-Timestamp'] = (string) $now->getTimestamp();
			$headers['X-MerchBoard-Signature'] = WebhookPayloads::signature( $secret, $now->getTimestamp(), $delivery->payload );
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ip   = $this->public_address( $host );

		if ( null === $ip ) {
			$this->outcome( $id, $webhook, $count, null, self::BLOCKED );

			return;
		}

		// cURL connects to the address that was checked, not to a new DNS answer.
		$pin = static function ( mixed $handle, mixed $args, mixed $target ) use ( $host, $ip ): void {
			if ( $handle instanceof \CurlHandle && is_string( $target ) && wp_parse_url( $target, PHP_URL_HOST ) === $host ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':443:' . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pins the checked address on WordPress' own cURL handle.
			}
		};
		add_action( 'http_api_curl', $pin, 10, 3 );

		try {
			$response = wp_safe_remote_post(
				$url,
				array(
					'body'                => $delivery->payload,
					'headers'             => $headers,
					'timeout'             => self::TIMEOUT,
					'redirection'         => 0,
					'limit_response_size' => 1024,
					'user-agent'          => 'MerchDashboard-Webhooks/1',
				)
			);
		} finally {
			remove_action( 'http_api_curl', $pin, 10 );
		}

		if ( is_wp_error( $response ) ) {
			// The creator gets a reason code; the transport's message (never the address: a Discord address is a secret) goes to the technical log.
			$message = (string) preg_replace( '#https?://\S+#i', '[url]', $response->get_error_code() . ': ' . $response->get_error_message() );
			$this->logger->info(
				'A webhook delivery failed.',
				array(
					'webhook_id' => $webhook->id,
					'reason'     => $message,
				)
			);
			$this->outcome( $id, $webhook, $count, null, str_contains( $message, 'cURL error 28' ) || str_contains( strtolower( $message ), 'timed out' ) ? self::TIMED_OUT : self::UNREACHABLE );

			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$this->outcome( $id, $webhook, $count, $status, $status >= 200 && $status < 300 ? null : 'http_' . $status );
	}

	/**
	 * The address to connect to: the host's first address when every one of
	 * them is public; null when it has none or any is private, loopback,
	 * link-local, shared or reserved.
	 *
	 * @param string $host Host name.
	 */
	private function public_address( string $host ): ?string {
		$addresses = '' === $host ? array() : ( $this->resolve )( $host );

		foreach ( $addresses as $address ) {
			if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE ) ) {
				return null;
			}
		}

		return $addresses[0] ?? null;
	}

	/**
	 * A host's IPv4 and IPv6 addresses from DNS.
	 *
	 * @param string $host Host name.
	 * @return list<string>
	 */
	private static function dns( string $host ): array {
		$records   = dns_get_record( $host, DNS_A | DNS_AAAA );
		$addresses = array();

		foreach ( is_array( $records ) ? $records : array() as $record ) {
			$address = $record['ip'] ?? $record['ipv6'] ?? null;

			if ( is_string( $address ) ) {
				$addresses[] = $address;
			}
		}

		$fallback = array() === $addresses ? gethostbynamel( $host ) : false;

		return false === $fallback ? $addresses : $fallback;
	}

	/**
	 * Notes a delivery's outcome and the webhook's failures.
	 *
	 * @param int          $id          Delivery.
	 * @param AlertWebhook $webhook     Webhook.
	 * @param bool         $count       Whether it counts towards the pause.
	 * @param int|null     $http_status The answer's status code.
	 * @param string|null  $error       Why it failed; null for a success.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function outcome( int $id, AlertWebhook $webhook, bool $count, ?int $http_status, ?string $error ): void {
		$this->deliveries->finish( $id, null === $error ? WebhookDelivery::SENT : WebhookDelivery::FAILED, $http_status, $error );

		if ( ! $count ) {
			return;
		}

		if ( $this->webhooks->record( $webhook->id, null === $error, self::PAUSE_AFTER, ( $this->clock )() ) ) {
			$this->logger->warning(
				'A webhook was paused after failed deliveries in a row.',
				array(
					'webhook_id' => $webhook->id,
					'creator_id' => $webhook->creator_id,
				)
			);
		}
	}

	/**
	 * Whether purchases are sent now.
	 */
	private function sending(): bool {
		return $this->api->enabled() && $this->availability->sending();
	}

	/**
	 * A creator's webhooks that get purchases; none for an inactive creator.
	 *
	 * @param int $creator_id Creator.
	 * @return list<AlertWebhook>
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function active( int $creator_id ): array {
		if ( true !== $this->creators->find( $creator_id )?->is_active() || ! $this->service->allowed( $creator_id ) ) {
			return array();
		}

		return array_values( array_filter( $this->webhooks->for_creator( $creator_id ), static fn( AlertWebhook $webhook ): bool => $webhook->is_active() ) );
	}
}
