<?php
/**
 * A mailer that keeps the e-mails instead of sending them.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\Notifications\Email\EmailStyle;
use MerchBoard\Modules\Notifications\Email\Mailer;

/**
 * Records notification e-mails; `fail` makes every sending fail with "boom".
 */
final class RecordingMailer extends Mailer {

	/**
	 * The e-mails "sent".
	 *
	 * @var list<array{to: string, subject: string, html: string, text: string}>
	 */
	public array $sent = array();

	/**
	 * Whether sending fails.
	 *
	 * @var bool
	 */
	public bool $fail = false;

	/**
	 * Records the e-mail.
	 *
	 * @param string     $to      Recipient.
	 * @param string     $subject Subject.
	 * @param string     $html    HTML.
	 * @param string     $text    Text.
	 * @param EmailStyle $style   Style.
	 */
	public function send( string $to, string $subject, string $html, string $text, EmailStyle $style ): ?string {
		if ( $this->fail ) {
			return 'boom';
		}

		$this->sent[] = array(
			'to'      => $to,
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
		);

		return null;
	}
}
