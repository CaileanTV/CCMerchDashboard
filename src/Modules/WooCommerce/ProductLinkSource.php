<?php
/**
 * Supported write paths for a product's creator link.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The two supported write paths of phase 17 (ADR-030 D6 as clarified by
 * ADR-031). Recorded as `source` in the audit context.
 */
enum ProductLinkSource: string {

	/**
	 * The classic WooCommerce product edit screen (single product).
	 */
	case ProductScreen = 'product_screen';

	/**
	 * The explicit one-time administrative backfill from the category mapping.
	 */
	case Backfill = 'backfill';
}
