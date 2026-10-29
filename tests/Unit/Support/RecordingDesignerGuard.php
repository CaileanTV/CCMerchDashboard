<?php
/**
 * A designer product guard without a database, for unit tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\GuardedProduct;

/**
 * A guard without a database that records its calls.
 */
final class RecordingDesignerGuard extends DesignerProductGuard {

	/**
	 * Calls.
	 *
	 * @var list<string>
	 */
	public array $calls = array();

	/**
	 * Constructor (no database).
	 *
	 * @param GuardedProduct|null $existing What for_row() gives.
	 */
	public function __construct( private ?GuardedProduct $existing ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod -- No database in this fake.
	}

	/**
	 * The row's product.
	 *
	 * @param int $design_product_id Row.
	 */
	public function for_row( int $design_product_id ): ?GuardedProduct {
		$this->calls[] = 'for_row ' . $design_product_id;

		return $this->existing;
	}

	/**
	 * The row's token.
	 *
	 * @param int $design_product_id Row.
	 */
	public function token( int $design_product_id ): string {
		$this->calls[] = 'token ' . $design_product_id;

		return str_repeat( 'a', 32 );
	}

	/**
	 * Claims a draft.
	 *
	 * @param int $design_product_id Row.
	 * @param int $wc_product_id     Draft.
	 */
	public function claim( int $design_product_id, int $wc_product_id ): GuardedProduct {
		$this->calls[] = 'claim ' . $design_product_id . ' ' . $wc_product_id;

		return new GuardedProduct( $design_product_id, $wc_product_id, 7 );
	}
}
