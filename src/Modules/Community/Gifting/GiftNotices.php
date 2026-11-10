<?php
/**
 * Who hears about a gift.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use Closure;
use MerchBoard\Core\Logging\Logger;
use Throwable;
use WC_Emails;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 10, on `merchboard_gift_event`:
 *
 * - the creator's bell: a gift was bought (`gift_bought`) and claimed
 *   (`gift_claimed`);
 * - the buyer's e-mail in WooCommerce's e-mail design: the winner was drawn,
 *   the gift was claimed, and the code when it goes back to the buyer;
 * - the administrator: a gift went back to the buyer
 *   (`admin_gift_returned`);
 * - a note on the gift order for each step (never the code).
 *
 * The buyer's address is read from the order when the e-mail is sent; it
 * is never logged.
 */
final class GiftNotices {

	/**
	 * A creator's name.
	 *
	 * @var Closure(int): string
	 */
	private Closure $creator_name;

	/**
	 * A product's name.
	 *
	 * @var Closure(int): string
	 */
	private Closure $product_name;

	/**
	 * A product's page.
	 *
	 * @var Closure(int): string
	 */
	private Closure $product_url;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): string $creator_name
	 * @phpstan-param Closure(int): string $product_name
	 * @phpstan-param Closure(int): string $product_url
	 *
	 * @param Closure $creator_name A creator's name ('' when unknown).
	 * @param Closure $product_name A product's name ('' when it is gone).
	 * @param Closure $product_url  A product's page.
	 * @param Logger  $logger       Technical log.
	 */
	public function __construct( Closure $creator_name, Closure $product_name, Closure $product_url, private Logger $logger ) {
		$this->creator_name = $creator_name;
		$this->product_name = $product_name;
		$this->product_url  = $product_url;
	}

	/**
	 * Listens to the gifts' events.
	 */
	public function register(): void {
		add_action( 'merchboard_gift_event', array( $this, 'event' ), 10, 3 );
	}

	/**
	 * `merchboard_gift_event`.
	 *
	 * @param mixed $event `bought`, `won`, `claimed` or `returned`.
	 * @param mixed $gift  The gift.
	 * @param mixed $data  Event data.
	 */
	public function event( mixed $event, mixed $gift, mixed $data = array() ): void {
		if ( ! is_string( $event ) || ! $gift instanceof Gift ) {
			return;
		}

		$data    = is_array( $data ) ? $data : array();
		$product = ( $this->product_name )( $gift->product_id );
		$winner  = is_string( $data['winner'] ?? null ) ? $data['winner'] : (string) $gift->winner_login;
		$code    = is_string( $data['code'] ?? null ) ? $data['code'] : '';
		$order   = wc_get_order( $gift->order_id );
		$order   = $order instanceof WC_Order ? $order : null;

		switch ( $event ) {
			case 'bought':
				do_action(
					'merchboard_notify',
					'gift_bought',
					$gift->creator_id,
					array(
						'product' => $product,
						'buyer'   => (string) $gift->buyer_name,
					),
					'gift:' . $gift->id . ':bought'
				);
				$this->note( $order, __( 'Twitch gift: paid and waiting for a draw in the stream.', 'merchboard' ) );
				break;

			case 'won':
				/* translators: %s: the winner's Twitch name. */
				$this->note( $order, sprintf( __( 'Twitch gift: won by %s. The winner has 24 hours to claim it.', 'merchboard' ), $winner ) );
				$this->mail(
					$order,
					/* translators: %s: product name. */
					sprintf( __( 'Your gift %s has a winner', 'merchboard' ), $product ),
					__( 'Your gift has a winner', 'merchboard' ),
					array(
						/* translators: 1: the winner's Twitch name, 2: product name, 3: creator name. */
						sprintf( __( '%1$s won the %2$s you gave in %3$s\'s stream. The winner has 24 hours to claim it; we let you know when it is claimed.', 'merchboard' ), $winner, $product, ( $this->creator_name )( $gift->creator_id ) ),
					)
				);
				break;

			case 'claimed':
				do_action(
					'merchboard_notify',
					'gift_claimed',
					$gift->creator_id,
					array(
						'product' => $product,
						'winner'  => $winner,
					),
					'gift:' . $gift->id . ':claimed'
				);
				/* translators: %s: the winner's Twitch name. */
				$this->note( $order, sprintf( __( 'Twitch gift: claimed by %s.', 'merchboard' ), $winner ) );
				$this->mail(
					$order,
					/* translators: %s: product name. */
					sprintf( __( 'Your gift %s was claimed', 'merchboard' ), $product ),
					__( 'Your gift was claimed', 'merchboard' ),
					array(
						/* translators: 1: the winner's Twitch name, 2: product name. */
						sprintf( __( '%1$s claimed the %2$s you gave. Thank you for your gift to the community!', 'merchboard' ), $winner, $product ),
					)
				);
				break;

			case 'returned':
				do_action(
					'merchboard_notify',
					'admin_gift_returned',
					$gift->creator_id,
					array(
						'product'      => $product,
						'order_number' => null === $order ? (string) $gift->order_id : (string) $order->get_order_number(),
					),
					'gift:' . $gift->id . ':returned'
				);
				$this->note( $order, __( 'Twitch gift: nobody claimed it after three draws. The buyer got the gift code by e-mail.', 'merchboard' ) );
				$this->mail(
					$order,
					/* translators: %s: product name. */
					sprintf( __( 'Your gift %s comes back to you', 'merchboard' ), $product ),
					__( 'Your gift comes back to you', 'merchboard' ),
					array(
						/* translators: %s: product name. */
						sprintf( __( 'Nobody claimed the %s you gave after three draws, so the gift comes back to you.', 'merchboard' ), $product ),
						/* translators: %s: the gift code. */
						sprintf( __( 'Use the code %s in the shop: it covers the product, in the size and colour you choose, and up to 50 kr. of the shipping. You pay only if you choose a dearer variant or shipping. The code can be used once.', 'merchboard' ), $code ),
					),
					( $this->product_url )( $gift->product_id )
				);
				break;
		}
	}

	/**
	 * A note on the gift order.
	 *
	 * @param WC_Order|null $order Order.
	 * @param string        $note  Note.
	 */
	private function note( ?WC_Order $order, string $note ): void {
		if ( null === $order ) {
			return;
		}

		try {
			$order->add_order_note( $note );
		} catch ( Throwable ) {
			$this->logger->warning( 'A gift note could not be added to its order.', array( 'order_id' => (int) $order->get_id() ) );
		}
	}

	/**
	 * An e-mail to the buyer in WooCommerce's e-mail design.
	 *
	 * @phpstan-param list<string> $paragraphs
	 *
	 * @param WC_Order|null $order      The gift order.
	 * @param string        $subject    Subject.
	 * @param string        $heading    Heading.
	 * @param string[]      $paragraphs Text (plain).
	 * @param string        $link       A link to show under the text ('' for none).
	 */
	private function mail( ?WC_Order $order, string $subject, string $heading, array $paragraphs, string $link = '' ): void {
		$to     = null === $order ? '' : (string) $order->get_billing_email();
		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;

		if ( null === $order || ! is_email( $to ) || ! $mailer instanceof WC_Emails ) {
			return;
		}

		$body = '';

		foreach ( $paragraphs as $paragraph ) {
			$body .= '<p>' . esc_html( $paragraph ) . '</p>';
		}

		if ( '' !== $link ) {
			$body .= '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'Go to the product', 'merchboard' ) . '</a></p>';
		}

		try {
			$sent = $mailer->send( $to, $subject, $mailer->wrap_message( $heading, $body ) );
		} catch ( Throwable ) {
			$sent = false;
		}

		if ( ! $sent ) {
			$this->logger->warning( 'A gift e-mail to the buyer could not be sent.', array( 'order_id' => (int) $order->get_id() ) );
		}
	}
}
