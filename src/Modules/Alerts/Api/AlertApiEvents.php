<?php
/**
 * Alerts as the alert API sends them.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Api;

use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertMoney;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * One alert (one order line of the creator) for a bot (ADR-073): the same
 * facts as the alert template's variables and nothing else. The customer's
 * voluntary username ('' when they gave none), the product, quantity,
 * categories, variation and the amounts as plain numbers with the shop's
 * decimals (null when unknown). `order_ref` groups the lines of one order
 * without giving away the order number: an HMAC of the creator and the order,
 * stable for the creator and different for every creator. A test or a
 * resend has id 0, so it never moves the bot's cursor.
 *
 * @phpstan-type ApiEvent array{id: int, kind: string, created_at: string, order_ref: string, username: string, product: string, quantity: int, category: string, variation: string, price: string|null, line_total: string|null, total: string|null, currency: string}
 */
class AlertApiEvents {

	public const PURCHASE = 'purchase';
	public const TEST     = 'test';
	public const RESEND   = 'resend';

	/**
	 * Constructor.
	 *
	 * @param AlertTemplate $template Category names.
	 */
	public function __construct( private AlertTemplate $template ) {
	}

	/**
	 * An alert for a bot.
	 *
	 * @param AlertEvent $event Alert.
	 * @param string     $kind  PURCHASE, TEST or RESEND.
	 * @return array{id: int, kind: string, created_at: string, order_ref: string, username: string, product: string, quantity: int, category: string, variation: string, price: string|null, line_total: string|null, total: string|null, currency: string}
	 */
	public function event( AlertEvent $event, string $kind ): array {
		$currency = '' !== $event->currency ? $event->currency : ( function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '' );

		return array(
			'id'         => self::PURCHASE === $kind ? $event->id : 0,
			'kind'       => $kind,
			'created_at' => $event->created_at->format( DATE_ATOM ),
			'order_ref'  => $event->order_id > 0 ? self::order_ref( $event->creator_id, $event->order_id ) : bin2hex( random_bytes( 8 ) ),
			'username'   => $event->customer_username,
			'product'    => $event->product_name,
			'quantity'   => $event->quantity,
			'category'   => $this->template->categories( $event->category_ids ),
			'variation'  => $event->variation,
			'price'      => AlertMoney::plain( AlertMoney::per_item( $event->line_total, $event->quantity ) ),
			'line_total' => AlertMoney::plain( $event->line_total ),
			'total'      => AlertMoney::plain( $event->creator_total ),
			'currency'   => $currency,
		);
	}

	/**
	 * The reference of a creator's order: 16 hex characters.
	 *
	 * @param int $creator_id Creator.
	 * @param int $order_id   Order.
	 */
	public static function order_ref( int $creator_id, int $order_id ): string {
		return substr( hash_hmac( 'sha256', 'alert-order:' . $creator_id . ':' . $order_id, wp_salt( 'auth' ) ), 0, 16 );
	}
}
