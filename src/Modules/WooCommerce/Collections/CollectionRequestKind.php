<?php
/**
 * What a creator can ask for.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 1: a new collection, or designs added to one of the creator's
 * own. Removing and dissolving happen in the collection plugin.
 */
enum CollectionRequestKind: string {

	case NewCollection = 'new';
	case Add           = 'add';
}
