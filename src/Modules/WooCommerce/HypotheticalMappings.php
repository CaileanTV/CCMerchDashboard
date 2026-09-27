<?php
/**
 * The stored mappings with one planned change, for dry runs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view: the stored mappings as if one category were mapped to a
 * creator (with()) or as if its mapping were removed (without()). Nothing is
 * stored; used only by CategoryMappingPreview.
 */
final class HypotheticalMappings implements MappingLookup {

	/**
	 * Constructor.
	 *
	 * @param MappingLookup        $stored  The stored mappings.
	 * @param int                  $term_id The category that changes.
	 * @param CreatorCategory|null $mapping Its mapping in the plan (null: removed).
	 */
	private function __construct(
		private MappingLookup $stored,
		private int $term_id,
		private ?CreatorCategory $mapping
	) {
	}

	/**
	 * As if the category were mapped to the creator.
	 *
	 * @param MappingLookup $stored     Stored mappings.
	 * @param int           $term_id    Category.
	 * @param int           $creator_id Creator.
	 */
	public static function with( MappingLookup $stored, int $term_id, int $creator_id ): self {
		return new self( $stored, $term_id, new CreatorCategory( $term_id, $creator_id, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), null ) );
	}

	/**
	 * As if the category's mapping were removed.
	 *
	 * @param MappingLookup $stored  Stored mappings.
	 * @param int           $term_id Category.
	 */
	public static function without( MappingLookup $stored, int $term_id ): self {
		return new self( $stored, $term_id, null );
	}

	/**
	 * The mapping of a category in the plan.
	 *
	 * @param int $term_id Term id.
	 */
	public function find( int $term_id ): ?CreatorCategory {
		return $term_id === $this->term_id ? $this->mapping : $this->stored->find( $term_id );
	}
}
