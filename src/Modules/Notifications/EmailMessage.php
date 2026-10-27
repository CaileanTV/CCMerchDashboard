<?php
/**
 * The content of one notification e-mail.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * The building blocks of the e-mail design (ADR-079), as plain text; the
 * renderer escapes everything. Each part except the subject and heading may
 * be empty.
 *
 * - `details`: label/value lines in the tinted info box.
 * - `table`: a head and rows (e.g. product, quantity, amount); the last
 *   column is right-aligned.
 * - `note`: a titled text in a tinted box (e.g. the reason of a rejection).
 */
final class EmailMessage {

	/**
	 * Constructor.
	 *
	 * @param string                    $subject    Subject line.
	 * @param string                    $heading    Heading in the card.
	 * @param string[]                  $paragraphs Text after the greeting.
	 * @param array<array<string>>      $details    Info box lines.
	 * @param array<string, mixed>|null $table      Table.
	 * @param string[]|null             $note       Note title and text.
	 * @param string                    $button     Button label ('' for none).
	 * @param string                    $url        Button target.
	 * @param string                    $closing    Last line in the card.
	 * @param bool                      $greet      Whether to greet the recipient by name.
	 *
	 * @phpstan-param list<string> $paragraphs
	 * @phpstan-param list<array{0: string, 1: string}> $details
	 * @phpstan-param array{head: list<string>, rows: list<list<string>>}|null $table
	 * @phpstan-param array{0: string, 1: string}|null $note
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $heading,
		public readonly array $paragraphs = array(),
		public readonly array $details = array(),
		public readonly ?array $table = null,
		public readonly ?array $note = null,
		public readonly string $button = '',
		public readonly string $url = '',
		public readonly string $closing = '',
		public readonly bool $greet = true
	) {
	}
}
