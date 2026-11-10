<?php
/**
 * Where a shop customer's Twitch login lives during the visit.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * The customer's session (WooCommerce's session in the shop, ADR-106 pkt.
 * 2); an array in tests.
 */
interface CustomerSessionStore {

	/**
	 * A stored value, or null.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): mixed;

	/**
	 * Stores a value; null removes it.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function set( string $key, mixed $value ): void;
}
