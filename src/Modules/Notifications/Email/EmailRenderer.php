<?php
/**
 * Turns a notification e-mail into HTML and text.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications\Email;

use MerchBoard\Modules\Notifications\EmailMessage;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an EmailMessage with `templates/emails/layout.php` (inline styles
 * for e-mail programs) and as plain text for the text part.
 */
final class EmailRenderer {

	/**
	 * The HTML e-mail.
	 *
	 * @param EmailMessage $message Content.
	 * @param EmailStyle   $style   Look.
	 * @param string       $url     Button target ('' for no button).
	 * @param string       $name    Recipient name for the greeting ('' for none).
	 */
	public function html( EmailMessage $message, EmailStyle $style, string $url, string $name ): string {
		$merchboard_view = array(
			'message'  => $message,
			'style'    => $style,
			'url'      => $url,
			'greeting' => self::greeting( $message, $name ),
			'muted'    => EmailStyle::mix( $style->text, $style->body, 0.62 ),
			'line'     => EmailStyle::mix( $style->text, $style->body, 0.12 ),
			'tint'     => EmailStyle::mix( $style->base, $style->body, 0.07 ),
			'lang'     => str_replace( '_', '-', determine_locale() ),
		);

		ob_start();
		require dirname( __DIR__, 4 ) . '/templates/emails/layout.php';

		return (string) ob_get_clean();
	}

	/**
	 * The plain-text e-mail.
	 *
	 * @param EmailMessage $message Content.
	 * @param EmailStyle   $style   Look (footer).
	 * @param string       $url     Button target ('' for none).
	 * @param string       $name    Recipient name ('' for none).
	 */
	public function text( EmailMessage $message, EmailStyle $style, string $url, string $name ): string {
		$lines   = array( $message->heading, '' );
		$welcome = self::greeting( $message, $name );

		if ( '' !== $welcome ) {
			$lines[] = $welcome;
			$lines[] = '';
		}

		foreach ( $message->paragraphs as $paragraph ) {
			$lines[] = $paragraph;
			$lines[] = '';
		}

		foreach ( $message->details as $detail ) {
			$lines[] = $detail[0] . ': ' . $detail[1];
		}

		if ( null !== $message->table ) {
			$lines[] = '';

			foreach ( $message->table['rows'] as $row ) {
				$lines[] = implode( '  ', $row );
			}
		}

		if ( null !== $message->note ) {
			$lines[] = '';
			$lines[] = $message->note[0] . ':';
			$lines[] = $message->note[1];
		}

		if ( '' !== $message->button && '' !== $url ) {
			$lines[] = '';
			$lines[] = $message->button . ': ' . $url;
		}

		if ( '' !== $message->closing ) {
			$lines[] = '';
			$lines[] = $message->closing;
		}

		$lines[] = '';
		$lines[] = '--';
		$lines[] = $style->footer;

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * "Hi Nova," or ''.
	 *
	 * @param EmailMessage $message Content.
	 * @param string       $name    Recipient name.
	 */
	private static function greeting( EmailMessage $message, string $name ): string {
		/* translators: %s: recipient's name. */
		return $message->greet && '' !== trim( $name ) ? sprintf( __( 'Hi %s,', 'merchboard' ), trim( $name ) ) : '';
	}
}
