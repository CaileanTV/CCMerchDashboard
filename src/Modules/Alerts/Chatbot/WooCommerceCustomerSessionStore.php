<?php
/**
 * The shop customer's WooCommerce session.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use WC_Session;
use WC_Session_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's customer session (cookie `wp_woocommerce_session_*`). The
 * session is started when the request has none (a REST request, e.g. the
 * Twitch callback), and its cookie is set when something is stored, so a
 * guest keeps the login for the rest of the visit.
 */
final class WooCommerceCustomerSessionStore implements CustomerSessionStore {

	/**
	 * A stored value, or null (also without WooCommerce).
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): mixed {
		return self::session()?->get( $key );
	}

	/**
	 * Stores a value; null removes it.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function set( string $key, mixed $value ): void {
		$session = self::session();

		if ( null === $session ) {
			return;
		}

		if ( null !== $value && $session instanceof WC_Session_Handler && ! $session->has_session() ) {
			$session->set_customer_session_cookie( true );
		}

		$session->set( $key, $value );
	}

	/**
	 * WooCommerce's session, started when needed.
	 */
	private static function session(): ?WC_Session {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$woocommerce = WC();

		if ( null === $woocommerce->session ) {
			$woocommerce->initialize_session();
		}

		return $woocommerce->session instanceof WC_Session ? $woocommerce->session : null;
	}
}
