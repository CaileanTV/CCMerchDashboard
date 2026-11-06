<?php
/**
 * The e-mails of a sign-up.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Signup;

use MerchBoard\Modules\Notifications\Email\EmailRenderer;
use MerchBoard\Modules\Notifications\Email\EmailStyle;
use MerchBoard\Modules\Notifications\Email\Mailer;
use MerchBoard\Modules\Notifications\EmailMessage;

defined( 'ABSPATH' ) || exit;

/**
 * Sent directly in the shop's e-mail design (ADR-079), never stored in the
 * notifications: the verification link carries WordPress' key to set a
 * password and must not be kept anywhere.
 */
class SignupMails {

	/**
	 * Constructor.
	 *
	 * @param EmailRenderer $renderer The design.
	 * @param Mailer        $mailer   wp_mail().
	 */
	public function __construct( private EmailRenderer $renderer, private Mailer $mailer ) {
	}

	/**
	 * "Confirm your e-mail and choose a password".
	 *
	 * @param string $to   Recipient.
	 * @param string $name The creator's name.
	 * @param string $url  WordPress' link to set a password.
	 */
	public function verify( string $to, string $name, string $url ): void {
		$this->send(
			$to,
			$name,
			new EmailMessage(
				__( 'Confirm your e-mail and choose a password', 'merchboard' ),
				__( 'Welcome! One more step', 'merchboard' ),
				array(
					__( 'Thank you for signing up as a creator. Confirm your e-mail by choosing a password; then you can log in and prepare your shop.', 'merchboard' ),
					__( 'The link works for 24 hours. After that, use "Lost your password?" on the login page with this e-mail; the sign-up is kept for 7 days. If you did not sign up, you can ignore this e-mail; the sign-up is then removed.', 'merchboard' ),
				),
				array(),
				null,
				null,
				__( 'Choose a password', 'merchboard' ),
				$url
			)
		);
	}

	/**
	 * Renders and sends a message.
	 *
	 * @param string       $to      Recipient.
	 * @param string       $name    Greeting name ('' for none).
	 * @param EmailMessage $message Message.
	 */
	private function send( string $to, string $name, EmailMessage $message ): void {
		$style = EmailStyle::current();

		$this->mailer->send( $to, $message->subject, $this->renderer->html( $message, $style, $message->url, $name ), $this->renderer->text( $message, $style, $message->url, $name ), $style );
	}
}
