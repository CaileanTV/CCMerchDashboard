<?php
/**
 * A news message to the creators.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * One message of "What's new" (ADR-072): its title, its body as cleaned HTML
 * (NewsHtml), its status and, once published, when (UTC).
 */
final readonly class NewsMessage {

	public const DRAFT     = 'draft';
	public const PUBLISHED = 'published';
	public const WITHDRAWN = 'withdrawn';

	/**
	 * Constructor.
	 *
	 * @param int                    $id           Id.
	 * @param string                 $title        One-line title.
	 * @param string                 $body         Cleaned HTML.
	 * @param string                 $status       One of the constants.
	 * @param DateTimeImmutable|null $published_at When it was published (UTC); null for a draft.
	 * @param DateTimeImmutable      $updated_at   Last change (UTC).
	 */
	public function __construct(
		public int $id,
		public string $title,
		public string $body,
		public string $status,
		public ?DateTimeImmutable $published_at,
		public DateTimeImmutable $updated_at
	) {
	}

	/**
	 * Whether creators see it.
	 */
	public function is_published(): bool {
		return self::PUBLISHED === $this->status && null !== $this->published_at;
	}
}
