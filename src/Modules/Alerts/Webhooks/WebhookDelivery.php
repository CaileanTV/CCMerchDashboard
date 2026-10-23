<?php
/**
 * One delivery of a webhook.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Webhooks;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A delivery (ADR-073): the event id, the kind (purchase, test, resend),
 * the body that is sent and the outcome. Times are UTC.
 */
final readonly class WebhookDelivery {

	public const PENDING = 'pending';
	public const SENT    = 'sent';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';

	/**
	 * Constructor.
	 *
	 * @param int                    $id          Id.
	 * @param int                    $webhook_id  Webhook.
	 * @param int                    $creator_id  Creator.
	 * @param string                 $event_id    The event's id (`evt_…`).
	 * @param string                 $kind        purchase, test or resend.
	 * @param string                 $order_ref   The order's reference.
	 * @param string                 $payload     The body.
	 * @param string                 $status      One of the constants.
	 * @param int|null               $http_status The answer's status code; null without an answer.
	 * @param string|null            $error       Why it failed.
	 * @param DateTimeImmutable      $created_at  Queued.
	 * @param DateTimeImmutable|null $sent_at     Tried; null while pending.
	 */
	public function __construct(
		public int $id,
		public int $webhook_id,
		public int $creator_id,
		public string $event_id,
		public string $kind,
		public string $order_ref,
		public string $payload,
		public string $status,
		public ?int $http_status,
		public ?string $error,
		public DateTimeImmutable $created_at,
		public ?DateTimeImmutable $sent_at
	) {
	}

	/**
	 * The delivery for the creator's log: never the body.
	 *
	 * @return array{id: int, webhook_id: int, event_id: string, kind: string, status: string, http_status: int|null, error: string|null, created_at: string}
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'webhook_id'  => $this->webhook_id,
			'event_id'    => $this->event_id,
			'kind'        => $this->kind,
			'status'      => $this->status,
			'http_status' => $this->http_status,
			'error'       => $this->error,
			'created_at'  => $this->created_at->format( DATE_ATOM ),
		);
	}
}
