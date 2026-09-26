<?php
/**
 * What the product link needs to know about a WooCommerce product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * A read-only snapshot of a product: its kind, status and every stored
 * creator link entry. Entries match the meta key case-insensitively (MySQL
 * compares keys that way), so a stray `_Merchboard_Creator_ID` counts as a
 * link too and is never silently ignored.
 */
final readonly class ProductRecord {

	/**
	 * Constructor.
	 *
	 * @param int                                     $id           Product id.
	 * @param int                                     $parent_id    Parent product id (0 for a parent product).
	 * @param bool                                    $is_variation Whether this is a variation.
	 * @param string                                  $status       Post status.
	 * @param list<array{key: string, value: string}> $link_entries Stored creator link entries.
	 */
	public function __construct(
		public int $id,
		public int $parent_id,
		public bool $is_variation,
		public string $status,
		public array $link_entries
	) {
	}

	/**
	 * Whether any creator link entry is stored (valid or not).
	 */
	public function has_link(): bool {
		return array() !== $this->link_entries;
	}

	/**
	 * The linked creator id when the product holds exactly one entry with the
	 * exact key and a positive integer value; otherwise null.
	 */
	public function creator_id(): ?int {
		if ( 1 !== count( $this->link_entries ) ) {
			return null;
		}

		$entry = $this->link_entries[0];

		if ( ProductCreatorLink::META_KEY !== $entry['key'] || 1 !== preg_match( '/^[1-9][0-9]*$/', $entry['value'] ) ) {
			return null;
		}

		return (int) $entry['value'];
	}

	/**
	 * Whether stored entries exist that are not one valid link (several
	 * entries, another key spelling or an invalid value).
	 */
	public function has_invalid_link(): bool {
		return $this->has_link() && null === $this->creator_id();
	}
}
