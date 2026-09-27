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
 * The two write paths of the product link (ADR-032, ADR-033). Recorded as
 * `source` in the audit context and stored with the link as
 * `_merchboard_creator_link_source`. The WooCommerce product editor is not a
 * write path.
 */
enum ProductLinkSource: string {

	/**
	 * Merch Dashboard (wp-admin): the product links on the creator's edit page.
	 */
	case Admin = 'admin';

	/**
	 * The system category reconciliation (ADR-033): the link currently follows
	 * from a creator ↔ product category mapping.
	 */
	case Category = 'category';
}
