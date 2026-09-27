<?php
/**
 * In-memory reconciliation candidates for unit tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\ProductRecord;
use MerchBoard\Modules\WooCommerce\ReconciliationCandidates;
use MerchBoard\Modules\WooCommerce\ReconciliationScope;

/**
 * The same selection as ReconciliationCandidateRepository over a FakeProductStore:
 * parent products (not trash or auto-draft) in the scope (all: in a mapped
 * category or with a stored link origin; a category; a creator's category
 * links), by id after the cursor. Records the calls and can be told to fail a
 * call or to stop advancing.
 */
final class FakeReconciliationCandidates implements ReconciliationCandidates {

	/**
	 * Calls as [after_id, limit].
	 *
	 * @var list<array{int, int}>
	 */
	public array $calls = array();

	/**
	 * Scope of each call.
	 *
	 * @var list<ReconciliationScope>
	 */
	public array $scopes = array();

	/**
	 * Call number (1-based) that throws a DatabaseException.
	 *
	 * @var int|null
	 */
	public ?int $fail_on_call = null;

	/**
	 * Return the first page again instead of advancing.
	 *
	 * @var bool
	 */
	public bool $stuck = false;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): list<int> $mapped_terms
	 *
	 * @param FakeProductStore $products     Products.
	 * @param Closure          $mapped_terms Currently mapped term ids.
	 */
	public function __construct( private FakeProductStore $products, private Closure $mapped_terms ) {
	}

	/**
	 * The next page of candidate ids.
	 *
	 * @param ReconciliationScope $scope    Which products.
	 * @param int                 $after_id Cursor.
	 * @param int                 $limit    Page size.
	 * @return list<int>
	 * @throws DatabaseException When told to fail.
	 */
	public function after( ReconciliationScope $scope, int $after_id, int $limit ): array {
		$this->calls[]  = array( $after_id, $limit );
		$this->scopes[] = $scope;

		if ( count( $this->calls ) === $this->fail_on_call ) {
			throw new DatabaseException( 'Simulated candidate query failure.' );
		}

		$mapped = ( $this->mapped_terms )();
		$ids    = array();

		foreach ( $this->products->ids() as $id ) {
			$product = $this->products->find( $id );

			if ( null === $product || $product->is_variation || in_array( $product->status, array( 'trash', 'auto-draft' ), true ) ) {
				continue;
			}

			if ( ( $this->stuck || $id > $after_id ) && self::in_scope( $scope, $product, $mapped ) ) {
				$ids[] = $id;
			}
		}

		return array_slice( $ids, 0, $limit );
	}

	/**
	 * Whether the product is in the scope.
	 *
	 * @phpstan-param list<int> $mapped
	 *
	 * @param ReconciliationScope $scope   Scope.
	 * @param ProductRecord       $product Product.
	 * @param int[]               $mapped  Mapped term ids.
	 */
	private static function in_scope( ReconciliationScope $scope, ProductRecord $product, array $mapped ): bool {
		if ( $scope->is_all() ) {
			return array() !== array_intersect( $product->category_ids, $mapped ) || array() !== $product->source_entries;
		}

		if ( null !== $scope->term_id && in_array( $scope->term_id, $product->category_ids, true ) ) {
			return true;
		}

		return null !== $scope->creator_id
			&& in_array( (string) $scope->creator_id, array_column( $product->link_entries, 'value' ), true )
			&& in_array( 'category', array_column( $product->source_entries, 'value' ), true );
	}
}
