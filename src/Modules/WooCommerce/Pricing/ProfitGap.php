<?php
/**
 * Why a ledger row's profit is unknown.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * The first missing piece of history that keeps RealizedProfit from giving a
 * profit (ADR-049, ADR-051), in the order RealizedProfit needs them:
 *
 * - `no_snapshot`: the order line has no pricing snapshot. Snapshots are only
 *   taken when the line is created and its product then has a creator, so
 *   lines created before snapshots existed, or before the product was linked,
 *   never have one;
 * - `invalid_snapshot`: a snapshot row exists but cannot be read;
 * - `legacy_snapshot`: a snapshot of the withdrawn model (before schema
 *   version 8) without a share;
 * - `snapshot_mismatch`: the snapshot belongs to another creator, order,
 *   product, variation or currency than the ledger row;
 * - `no_tax`: the row has no stored tax (rows registered before schema
 *   version 7);
 * - `tax_currency`: the stored tax is in another currency;
 * - `other`: none of the above (should not happen).
 */
enum ProfitGap: string {

	case NoSnapshot = 'no_snapshot';

	case InvalidSnapshot = 'invalid_snapshot';

	case LegacySnapshot = 'legacy_snapshot';

	case SnapshotMismatch = 'snapshot_mismatch';

	case NoTax = 'no_tax';

	case TaxCurrency = 'tax_currency';

	case Other = 'other';
}
