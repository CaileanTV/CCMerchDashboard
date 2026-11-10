<?php
/**
 * A customer's Twitch data in WordPress' privacy tools.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use MerchBoard\Modules\WooCommerce\SubDiscount\SubDiscountMeta;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-106, security: the remembered Twitch account (user meta) and the
 * Twitch account and tiers stored on the customer's orders are exported and
 * erased with WordPress' "Export/Erase Personal Data" by the customer's
 * e-mail address. Erasing keeps each line's discount amount (accounting),
 * only the account and tiers go.
 */
final class TwitchSubPrivacy {

	/**
	 * Orders per page.
	 */
	private const PER_PAGE = 50;

	/**
	 * Hooks the exporter and the eraser.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param mixed $exporters Exporters.
	 * @return array<mixed>
	 */
	public function exporters( mixed $exporters ): array {
		$exporters                               = is_array( $exporters ) ? $exporters : array();
		$exporters['merchboard-twitch-customer'] = array(
			'exporter_friendly_name' => __( 'Twitch login in the shop', 'merchboard' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param mixed $erasers Erasers.
	 * @return array<mixed>
	 */
	public function erasers( mixed $erasers ): array {
		$erasers                               = is_array( $erasers ) ? $erasers : array();
		$erasers['merchboard-twitch-customer'] = array(
			'eraser_friendly_name' => __( 'Twitch login in the shop', 'merchboard' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports one page.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page (1-based).
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$data = array();
		$user = get_user_by( 'email', $email );

		if ( 1 === $page && false !== $user ) {
			$remembered = get_user_meta( $user->ID, TwitchCustomerSession::USER_META, true );

			if ( is_array( $remembered ) && is_string( $remembered['login'] ?? null ) ) {
				$data[] = array(
					'group_id'    => 'merchboard-twitch',
					'group_label' => __( 'Twitch login in the shop', 'merchboard' ),
					'item_id'     => 'twitch-account-' . $user->ID,
					'data'        => array(
						array(
							'name'  => __( 'Remembered Twitch account', 'merchboard' ),
							'value' => $remembered['login'],
						),
					),
				);
			}
		}

		$orders = $this->orders( $email, $page );

		foreach ( $orders as $order ) {
			$stored = json_decode( (string) $order->get_meta( SubDiscountMeta::ORDER, true, 'edit' ), true );

			if ( ! is_array( $stored ) ) {
				continue;
			}

			$data[] = array(
				'group_id'    => 'merchboard-twitch',
				'group_label' => __( 'Twitch login in the shop', 'merchboard' ),
				'item_id'     => 'twitch-order-' . $order->get_id(),
				'data'        => array(
					array(
						'name'  => __( 'Order', 'merchboard' ),
						'value' => (string) $order->get_order_number(),
					),
					array(
						'name'  => __( 'Twitch account', 'merchboard' ),
						'value' => is_string( $stored['login'] ?? null ) ? $stored['login'] : '',
					),
					array(
						'name'  => __( 'Subscription checked', 'merchboard' ),
						'value' => is_string( $stored['checked_at'] ?? null ) ? $stored['checked_at'] : '',
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * Erases one page.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page (1-based; erased orders leave the query, so page 1 is read again).
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$removed = false;
		$user    = get_user_by( 'email', $email );

		if ( false !== $user && '' !== get_user_meta( $user->ID, TwitchCustomerSession::USER_META, true ) ) {
			delete_user_meta( $user->ID, TwitchCustomerSession::USER_META );
			$removed = true;
		}

		$orders = $this->orders( $email, 1, true );

		foreach ( $orders as $order ) {
			$order->delete_meta_data( SubDiscountMeta::ORDER );
			$order->save();
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * The customer's orders (by billing e-mail) with Twitch data.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page.
	 * @param bool   $with  Only orders that still have the data.
	 * @return list<WC_Order>
	 */
	private function orders( string $email, int $page, bool $with = false ): array {
		if ( ! function_exists( 'wc_get_orders' ) || '' === $email ) {
			return array();
		}

		$args = array(
			'billing_email' => $email,
			'limit'         => self::PER_PAGE,
			'paged'         => max( 1, $page ),
			'type'          => 'shop_order',
		);

		if ( $with ) {
			// A privacy request: rare and paged.
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Rare, paged privacy request.
				array(
					'key'     => SubDiscountMeta::ORDER,
					'compare' => 'EXISTS',
				),
			);
		}

		$found = wc_get_orders( $args );

		return array_values( array_filter( is_array( $found ) ? $found : array(), static fn( mixed $order ): bool => $order instanceof WC_Order ) );
	}
}
