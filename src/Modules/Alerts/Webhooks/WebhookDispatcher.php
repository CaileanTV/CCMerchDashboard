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
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers a creator's purchases to their webhooks (step 25.12.2, ADR-073).
 *
 * - **When:** after the order is paid and its alerts are recorded (the same
 *   hooks as alerts and the chatbot), only while the administrator has the
 *   API on, alerts are sent (kill switch, MerchAlert guard) and the creator
 *   is active. One delivery per webhook and order, queued in Action
 *   Scheduler so a slow receiver never holds up the checkout; without
 *   Action Scheduler the delivery fails at once.
 * - **How:** one attempt (the project owner's choice), with
 *   `wp_safe_remote_post()` (no private addresses), no redirects, a 5 second
 *   timeout and a small answer limit. A 2xx answer is a success and clears
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
	 * The current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param AlertWebhookRepository              $webhooks     Webhooks.
	 * @param WebhookDeliveryRepository           $deliveries   Deliveries.
	 * @param AlertWebhooks                       $service      Addresses and secrets.
	 * @param WebhookPayloads                     $payloads     Bodies.
	 * @param AlertEventRepository                $events       Alerts.
	 * @param CreatorRepository                   $creators     Creators.
	 * @param AlertApiSwitch                      $api          The administrator's switch.
	 * @param AlertsAvailability                  $availability Kill switch and MerchAlert guard.
	 * @param JobQueue                            $queue        Jobs (Action Scheduler).
	 * @param AlertOverlays                       $overlays     Test alerts.
	 * @param Logger                              $logger       Technical log.
	 * @param (Closure(): DateTimeImmutable)|null $clock        Current time; defaults to now.
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
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
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

				foreach ( $webhooks as $webhook ) {
					$this->enqueue( $webhook, AlertApiEvents::PURCHASE, $events );
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
	 * @throws WebhookException When the API is off or it is not the creator's webhook.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	public function send_test( int $creator_id, int $webhook_id ): WebhookDelivery {
		if ( ! $this->api->enabled() ) {
			self::refuse( WebhookException::DISABLED );
		}

		$webhook = $this->webhooks->find_for_creator( $creator_id, $webhook_id ) ?? self::refuse( WebhookException::UNKNOWN );
		$id      = $this->store( $webhook, AlertApiEvents::TEST, array( $this->overlays->test_event( $creator_id ) ) );
		$this->deliver( $id, $webhook, false );

		return $this->deliveries->find( $id ) ?? self::refuse( WebhookException::UNKNOWN );
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

			if ( null === $webhook || ! $webhook->is_active() || ! $this->sending() || true !== $this->creators->find( $webhook->creator_id )?->is_active() ) {
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

		if ( is_wp_error( $response ) ) {
			// Never the address (a Discord address is a secret).
			$this->outcome( $id, $webhook, $count, null, (string) preg_replace( '#https?://\S+#i', '[url]', $response->get_error_code() . ': ' . $response->get_error_message() ) );

			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$this->outcome( $id, $webhook, $count, $status, $status >= 200 && $status < 300 ? null : 'http_' . $status );
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
		if ( true !== $this->creators->find( $creator_id )?->is_active() ) {
			return array();
		}

		return array_values( array_filter( $this->webhooks->for_creator( $creator_id ), static fn( AlertWebhook $webhook ): bool => $webhook->is_active() ) );
	}
}
