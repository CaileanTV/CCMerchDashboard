<?php
/**
 * The bodies a webhook sends.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertMoney;
use MerchBoard\Modules\Alerts\Api\AlertApiEvents;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannel;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotFormat;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessages;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * One event per paid order of a creator (ADR-073, SparkyBot's format):
 *
 * - **JSON:** `{ id, type, created_at, order_ref, username, display_name,
 *   total, currency, item_count, items: [ { product, quantity, category,
 *   variation, image, price, line_total } ] }`. `image` is the product
 *   image's URL in the shop's product size, '' when none (step 25.12.5).
 *   `id` is stable for a purchase (an HMAC of the creator and the order),
 *   so a receiver can drop repeats; a
 *   test or a resend gets a new one. `username` is the customer's voluntary
 *   username ('' when none); `display_name` is that or "Anonymous". Never
 *   the customer's identity or checkout details.
 * - **Discord:** `{ content, allowed_mentions: { parse: [] }, embeds }`: the
 *   chat message with the webhook's template, one message per order (lines
 *   below each other in line mode), at most 2000 characters, with the
 *   products' images below it (each once, at most ten; no `embeds` without
 *   images; step 25.12.5). Tests and resends start with "[Test]" /
 *   "[Resent]".
 *
 * The signature is HMAC-SHA256 of "{timestamp}.{body}" with the secret.
 */
class WebhookPayloads {

	/**
	 * Discord's longest message.
	 */
	public const DISCORD_MAX = 2000;

	/**
	 * Discord's most embeds in one message.
	 */
	public const DISCORD_EMBEDS = 10;

	/**
	 * Constructor.
	 *
	 * @param AlertApiEvents $shape    The alert API's fields.
	 * @param ChatMessages   $messages Chat messages.
	 * @param ChatbotFormat  $format   Line or order messages.
	 */
	public function __construct( private AlertApiEvents $shape, private ChatMessages $messages, private ChatbotFormat $format ) {
	}

	/**
	 * The event id of a delivery.
	 *
	 * @param string     $kind  AlertApiEvents::PURCHASE, TEST or RESEND.
	 * @param AlertEvent $first The order's first alert.
	 */
	public static function event_id( string $kind, AlertEvent $first ): string {
		if ( AlertApiEvents::PURCHASE === $kind && $first->order_id > 0 ) {
			return 'evt_' . substr( hash_hmac( 'sha256', 'alert-webhook:' . $first->creator_id . ':' . $first->order_id, wp_salt( 'auth' ) ), 0, 32 );
		}

		return 'evt_' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * The JSON body.
	 *
	 * @phpstan-param non-empty-list<AlertEvent> $events
	 *
	 * @param string       $event_id Event id.
	 * @param string       $kind     AlertApiEvents::PURCHASE, TEST or RESEND.
	 * @param AlertEvent[] $events   The creator's alerts of one order.
	 * @return array{order_ref: string, body: string}
	 */
	public function json( string $event_id, string $kind, array $events ): array {
		$items = array();

		foreach ( $events as $event ) {
			$line    = $this->shape->event( $event, $kind );
			$items[] = array(
				'product'    => $line['product'],
				'quantity'   => $line['quantity'],
				'category'   => $line['category'],
				'variation'  => $line['variation'],
				'image'      => $line['image'],
				'price'      => $line['price'],
				'line_total' => $line['line_total'],
			);
		}

		$first = $this->shape->event( $events[0], $kind );
		$body  = array(
			'id'           => $event_id,
			'type'         => $kind,
			'created_at'   => AlertApiEvents::PURCHASE === $kind ? $first['created_at'] : ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( DATE_ATOM ),
			'order_ref'    => $first['order_ref'],
			'username'     => $first['username'],
			'display_name' => '' !== $first['username'] ? $first['username'] : AlertTemplate::ANONYMOUS,
			'total'        => AlertMoney::plain( $events[0]->creator_total ),
			'currency'     => $first['currency'],
			'item_count'   => array_sum( array_map( static fn( AlertEvent $event ): int => $event->quantity, $events ) ),
			'items'        => $items,
		);

		return array(
			'order_ref' => $first['order_ref'],
			'body'      => (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
	}

	/**
	 * The Discord body.
	 *
	 * @phpstan-param non-empty-list<AlertEvent> $events
	 *
	 * @param string       $template The webhook's template ('' for the default).
	 * @param string       $kind     AlertApiEvents::PURCHASE, TEST or RESEND.
	 * @param AlertEvent[] $events   The creator's alerts of one order.
	 */
	public function discord( string $template, string $kind, array $events ): string {
		$format = $this->format->get();
		$mode   = 'order' === $format['mode'] ? 'order' : 'line';

		if ( '' === $template ) {
			$template = 'order' === $mode ? ChatbotChannel::DEFAULT_ORDER_TEMPLATE : ChatbotChannel::DEFAULT_TEMPLATE;
		}

		// The customer types the username: Discord must show it as text, never as a link or formatting (25.13 review).
		$events = array_map( self::escaped( ... ), $events );
		$text   = 'order' === $mode
			? $this->messages->order( $template, $events, $format )
			: implode( "\n", array_map( fn( AlertEvent $event ): string => $this->messages->line( $template, $event ), $events ) );

		$prefix = match ( $kind ) {
			AlertApiEvents::TEST   => '[Test] ',
			AlertApiEvents::RESEND => '[Resent] ',
			default                => '',
		};

		$body   = array(
			'content'          => mb_substr( $prefix . $text, 0, self::DISCORD_MAX ),
			'allowed_mentions' => array( 'parse' => array() ),
		);
		$images = array_values( array_unique( array_filter( array_map( $this->shape->image( ... ), $events ) ) ) );

		if ( array() !== $images ) {
			$body['embeds'] = array_map( static fn( string $url ): array => array( 'image' => array( 'url' => $url ) ), array_slice( $images, 0, self::DISCORD_EMBEDS ) );
		}

		return (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * An alert with Discord's markdown in the customer's username escaped
	 * (e.g. `[Free](https://…)` is shown as typed, not as a link).
	 *
	 * @param AlertEvent $event Alert.
	 */
	private static function escaped( AlertEvent $event ): AlertEvent {
		$username = (string) preg_replace( '/([\\\\*_~`|>\[\]()#:<-])/u', '\\\\$1', $event->customer_username );

		return new AlertEvent(
			$event->creator_id,
			$event->order_id,
			$event->order_number,
			$event->order_item_id,
			$event->product_id,
			$event->variation_id,
			$event->product_name,
			$event->variation,
			$event->quantity,
			$event->image_id,
			$event->category_ids,
			$username,
			$event->created_at,
			$event->id,
			$event->line_total,
			$event->creator_total,
			$event->currency
		);
	}

	/**
	 * The signature header's value.
	 *
	 * @param string $secret    Signing secret.
	 * @param int    $timestamp Unix time sent in X-MerchBoard-Timestamp.
	 * @param string $body      Body.
	 */
	public static function signature( string $secret, int $timestamp, string $body ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}
}
