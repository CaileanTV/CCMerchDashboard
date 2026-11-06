<?php
/**
 * A published version of the creators' agreement.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Agreement;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A version is never changed once published (ADR-092). `material` tells
 * whether creators who must accept the agreement have to accept it again.
 */
final readonly class AgreementVersion {

	/**
	 * Constructor.
	 *
	 * @param int               $version      Version number (1, 2, …).
	 * @param string            $body         The text (HTML, wp_kses_post), with its placeholders.
	 * @param bool              $material     Whether it must be accepted again.
	 * @param DateTimeImmutable $published_at When it was published (UTC).
	 */
	public function __construct(
		public int $version,
		public string $body,
		public bool $material,
		public DateTimeImmutable $published_at
	) {
	}
}
