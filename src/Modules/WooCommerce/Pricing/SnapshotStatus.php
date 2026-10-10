<?php
/**
 * Status of a sales-line pricing snapshot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * `share`: the snapshot holds the creator share of ADR-049 (written since
 * schema version 8). `priced` and `unpriced` are the withdrawn model of
 * ADR-047 (base price + profit): such rows stay unchanged in the table, but
 * they hold no share, so their profit and payout are unknown.
 */
enum SnapshotStatus: string {

	case Share = 'share';

	case Priced = 'priced';

	case Unpriced = 'unpriced';
}
