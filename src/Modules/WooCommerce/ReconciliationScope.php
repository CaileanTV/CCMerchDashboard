<?php
/**
 * Which products a batch run looks at.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * The bounded set of products a reconciliation run pages through (ADR-033).
 * Every product is still judged by all its categories; the scope only limits
 * which products are looked at:
 * - all(): every product whose ownership the mappings can affect (full sync);
 * - category(): products directly in one `product_cat` (mapping created,
 *   creator reactivated);
 * - with a creator: also every product holding a category link to that
 *   creator, wherever its categories are now (mapping removed, category
 *   deleted), so products that already left the category are found.
 */
final readonly class ReconciliationScope {

	/**
	 * Constructor.
	 *
	 * @param int|null $term_id    Category, or null.
	 * @param int|null $creator_id Creator whose category links are included, or null.
	 * @throws InvalidArgumentException When an id is not positive.
	 */
	private function __construct( public ?int $term_id, public ?int $creator_id ) {
		if ( ( null !== $term_id && $term_id < 1 ) || ( null !== $creator_id && $creator_id < 1 ) ) {
			throw new InvalidArgumentException( 'Scope ids must be positive.' );
		}
	}

	/**
	 * Every relevant product.
	 */
	public static function all(): self {
		return new self( null, null );
	}

	/**
	 * Products directly in a category.
	 *
	 * @param int $term_id Term id.
	 */
	public static function category( int $term_id ): self {
		return new self( $term_id, null );
	}

	/**
	 * Products directly in a category (if any) or holding a category link to
	 * the creator.
	 *
	 * @param int|null $term_id    Term id, or null (e.g. a deleted term).
	 * @param int      $creator_id Creator id.
	 */
	public static function mapping( ?int $term_id, int $creator_id ): self {
		return new self( $term_id, $creator_id );
	}

	/**
	 * Whether this is the full scope.
	 */
	public function is_all(): bool {
		return null === $this->term_id && null === $this->creator_id;
	}

	/**
	 * Name for audit context and jobs.
	 */
	public function kind(): string {
		return match ( true ) {
			$this->is_all()             => 'all',
			null === $this->creator_id  => 'category',
			default                     => 'mapping',
		};
	}

	/**
	 * Audit context / job payload (ids only when set).
	 *
	 * @return array<string, int|string>
	 */
	public function to_array(): array {
		$data = array( 'scope' => $this->kind() );

		if ( null !== $this->term_id ) {
			$data['term_id'] = $this->term_id;
		}

		if ( null !== $this->creator_id ) {
			$data['creator_id'] = $this->creator_id;
		}

		return $data;
	}

	/**
	 * Rebuilds a scope from to_array() (job payloads).
	 *
	 * @param array<mixed> $data Data.
	 * @throws InvalidArgumentException When the data is not a scope.
	 */
	public static function from_array( array $data ): self {
		$term_id    = $data['term_id'] ?? null;
		$creator_id = $data['creator_id'] ?? null;

		if ( ( null !== $term_id && ! is_int( $term_id ) ) || ( null !== $creator_id && ! is_int( $creator_id ) ) ) {
			throw new InvalidArgumentException( 'Invalid scope.' );
		}

		$scope = new self( $term_id, $creator_id );

		if ( ( $data['scope'] ?? null ) !== $scope->kind() ) {
			throw new InvalidArgumentException( 'Invalid scope.' );
		}

		return $scope;
	}
}
