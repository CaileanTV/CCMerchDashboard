<?php
/**
 * Outcome of a "Keep as manual" removal.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * What CategoryMappingRemoval::keep_as_manual() did.
 */
final readonly class KeepAsManualResult {

	/**
	 * Constructor.
	 *
	 * @param int                $converted Links converted in this call.
	 * @param array<int, string> $failed    Product id => reason code of products not converted.
	 * @param bool               $remaining Whether category links of the mapping are left to convert (run again).
	 * @param bool               $removed   Whether the mapping was removed.
	 * @param bool               $found     Whether the mapping existed.
	 */
	public function __construct(
		public int $converted,
		public array $failed,
		public bool $remaining,
		public bool $removed,
		public bool $found
	) {
	}
}
