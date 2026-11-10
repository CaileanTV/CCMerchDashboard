<?php
/**
 * Gifts in WordPress' privacy tools.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use MerchBoard\Core\Database\DatabaseException;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107, security: the buyer's chosen name and the winner's Twitch login
 * of the gifts a customer bought are exported and erased with WordPress'
 * "Export/Erase Personal Data" by the customer's e-mail address (the gift
 * orders' billing e-mail). Erasing keeps the gift itself (status, product,
 * amounts), only the names go.
 */
final class GiftPrivacy {

	/**
	 * Orders per page.
	 */
	private const PER_PAGE = 50;

	/**
	 * Constructor.
	 *
	 * @param GiftRepository $gifts Gifts.
	 */
	public function __construct( private GiftRepository $gifts ) {
	}

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
		$exporters                           = is_array( $exporters ) ? $exporters : array();
		$exporters['merchboard-twitch-gift'] = array(
			'exporter_friendly_name' => __( 'Twitch gifts', 'merchboard' ),
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
		$erasers                           = is_array( $erasers ) ? $erasers : array();
		$erasers['merchboard-twitch-gift'] = array(
			'eraser_friendly_name' => __( 'Twitch gifts', 'merchboard' ),
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
		$data   = array();
		$orders = $this->orders( $email, $page );

		foreach ( $orders as $order ) {
			try {
				$gift = $this->gifts->find_by_order( (int) $order->get_id() );
			} catch ( DatabaseException ) {
				$gift = null;
			}

			if ( null === $gift ) {
				continue;
			}

			$data[] = array(
				'group_id'    => 'merchboard-twitch-gift',
				'group_label' => __( 'Twitch gifts', 'merchboard' ),
				'item_id'     => 'twitch-gift-' . $gift->id,
				'data'        => array(
					array(
						'name'  => __( 'Order', 'merchboard' ),
						'value' => (string) $order->get_order_number(),
					),
					array(
						'name'  => __( 'Name in the announcement', 'merchboard' ),
						'value' => $gift->buyer_name ?? __( 'Anonymous', 'merchboard' ),
					),
					array(
						'name'  => __( 'Winner', 'merchboard' ),
						'value' => (string) $gift->winner_login,
					),
					array(
						'name'  => __( 'Status', 'merchboard' ),
						'value' => $gift->status,
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
	 * @param int    $page  Page (1-based).
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$orders = $this->orders( $email, $page );

		try {
			$changed = $this->gifts->anonymize( array_map( static fn( WC_Order $order ): int => (int) $order->get_id(), $orders ) );
			$failed  = false;
		} catch ( DatabaseException ) {
			$changed = 0;
			$failed  = true;
		}

		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => $failed,
			'messages'       => $failed ? array( __( 'The names of the Twitch gifts could not be erased. Please try again.', 'merchboard' ) ) : array(),
			'done'           => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * The customer's orders (by billing e-mail).
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page.
	 * @return list<WC_Order>
	 */
	private function orders( string $email, int $page ): array {
		if ( ! function_exists( 'wc_get_orders' ) || '' === $email ) {
			return array();
		}

		$found = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => self::PER_PAGE,
				'paged'         => max( 1, $page ),
				'type'          => 'shop_order',
			)
		);

		return array_values( array_filter( is_array( $found ) ? $found : array(), static fn( mixed $order ): bool => $order instanceof WC_Order ) );
	}
}
