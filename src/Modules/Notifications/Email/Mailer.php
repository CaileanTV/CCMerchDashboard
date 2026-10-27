<?php
/**
 * Sends one notification e-mail with wp_mail().
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications\Email;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress' own mail (ADR-079): HTML with a text part, from the shop's
 * sender. The filters it adds apply to this one e-mail only.
 */
class Mailer {

	/**
	 * Sends an e-mail.
	 *
	 * @param string     $to      Recipient.
	 * @param string     $subject Subject.
	 * @param string     $html    HTML body.
	 * @param string     $text    Text part.
	 * @param EmailStyle $style   Sender.
	 * @return string|null Null when sent, otherwise a short reason.
	 */
	public function send( string $to, string $subject, string $html, string $text, EmailStyle $style ): ?string {
		if ( ! is_email( $to ) ) {
			return 'invalid_recipient';
		}

		$error   = null;
		$alt     = static function ( mixed $mailer ) use ( $text ): void {
			if ( is_object( $mailer ) && property_exists( $mailer, 'AltBody' ) ) {
				$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
			}
		};
		$failed  = static function ( mixed $wp_error ) use ( &$error ): void {
			$error = $wp_error instanceof WP_Error ? $wp_error->get_error_message() : 'wp_mail_failed';
		};
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( is_email( $style->from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', str_replace( array( "\r", "\n", '<', '>', '"' ), '', $style->from_name ), $style->from_email );
		}

		add_action( 'phpmailer_init', $alt );
		add_action( 'wp_mail_failed', $failed );

		try {
			$sent = wp_mail( $to, str_replace( array( "\r", "\n" ), ' ', $subject ), $html, $headers );
		} finally {
			remove_action( 'phpmailer_init', $alt );
			remove_action( 'wp_mail_failed', $failed );
		}

		if ( $sent ) {
			return null;
		}

		return '' === trim( (string) $error ) ? 'wp_mail_failed' : substr( wp_strip_all_tags( (string) $error ), 0, 180 );
	}
}
