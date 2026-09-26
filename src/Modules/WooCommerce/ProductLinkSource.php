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
 * The two supported write paths of phase 17 (ADR-032, which replaced the
 * WooCommerce product screen of ADR-030 D6/ADR-031). Recorded as `source` in
 * the audit context. The WooCommerce product editor is not a write path.
 */
enum ProductLinkSource: string {

	/**
	 * Merch Dashboard (wp-admin): the product links on the creator's edit page.
	 */
	case Admin = 'admin';

	/**
	 * The explicit one-time administrative backfill from the category mapping.
	 */
	case Backfill = 'backfill';
}
