<?php
/**
 * Registry of the plugin's database tables.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use InvalidArgumentException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The only place where table names are built.
 *
 * Table names can never come from input: `name()` accepts only the constants
 * below, so a caller cannot make a repository read an arbitrary table.
 */
final class Tables {

	/**
	 * Creators (see docs/DATABASE.md).
	 */
	public const CREATORS = 'merchboard_creators';

	/**
	 * Audit trail of business changes (see docs/DATABASE.md, ADR-023).
	 */
	public const AUDIT_LOG = 'merchboard_audit_log';

	/**
	 * Creator meta (profile fields), schema version 3. WordPress meta layout.
	 */
	public const CREATOR_META = 'merchboard_creatormeta';

	/**
	 * Creator ↔ product category mapping, schema version 4 (ADR-033).
	 */
	public const CREATOR_CATEGORIES = 'merchboard_creator_categories';

	/**
	 * Sales ledger, schema version 5 (ADR-037, ADR-038). Append-only.
	 */
	public const SALES = 'merchboard_sales';

	/**
	 * Sales cycle events per order, schema version 5 (ADR-038). Append-only.
	 */
	public const SALES_CYCLES = 'merchboard_sales_cycles';

	/**
	 * Product-specific creator share per parent product (ADR-049; recreated by
	 * schema version 8, fixed profits of ADR-046 before). Current state.
	 */
	public const PRICING_OVERRIDES = 'merchboard_pricing_overrides';

	/**
	 * Base price and last applied pricing per sellable unit (ADR-045), created
	 * by schema version 6 and dropped by version 8 (ADR-049). Retired: only
	 * migrations and opt-in uninstall still name it.
	 */
	public const ITEM_PRICING = 'merchboard_item_pricing';

	/**
	 * Creator share snapshot per order line, schema version 6 (ADR-047, ADR-049). Append-only.
	 */
	public const SALES_LINE_PRICING = 'merchboard_sales_line_pricing';

	/**
	 * Actual WooCommerce tax per sales ledger row, schema version 7 (ADR-048). Append-only.
	 */
	public const SALES_TAX = 'merchboard_sales_tax';

	/**
	 * Payout requests and their outcome, schema version 9 (ADR-055). Amount,
	 * method and creator never change; the status only moves forward.
	 */
	public const PAYOUTS = 'merchboard_payouts';

	/**
	 * Encrypted bank and PayPal details of pending payouts, schema version 9
	 * (ADR-056). Separate from PAYOUTS, so reading requests never reads them.
	 */
	public const PAYOUT_SECRETS = 'merchboard_payout_secrets';

	/**
	 * Settlement documents (self-billing), schema version 11 (ADR-060).
	 * Insert-only: never updated or deleted (except opt-in uninstall).
	 */
	public const SETTLEMENT_DOCUMENTS = 'merchboard_settlement_documents';

	/**
	 * The ledger rows settled by a document, as a snapshot, schema version 11
	 * (ADR-060). `UNIQUE(event_key)`: a ledger row is settled at most once.
	 */
	public const SETTLEMENT_ROWS = 'merchboard_settlement_document_rows';

	/**
	 * The next number of each document series, schema version 11 (ADR-060, D9).
	 */
	public const SETTLEMENT_COUNTERS = 'merchboard_settlement_counters';

	/**
	 * The G1 assessment of an order paid with a payout gift card, schema
	 * version 12 (E3 T2). Current state, one row per order.
	 */
	public const ORDER_GIFT_CARD_ASSESSMENTS = 'merchboard_order_gift_card_assessments';

	/**
	 * The payouts whose gift card paid an order, schema version 12 (E3 T2).
	 * References only (payout, provider, external card id), never a code.
	 */
	public const ORDER_GIFT_CARD_PAYOUTS = 'merchboard_order_gift_card_payouts';

	/**
	 * Diagnostic data of failed G1 assessments, schema version 12 (E3 T2).
	 */
	public const ORDER_GIFT_CARD_ATTEMPTS = 'merchboard_order_gift_card_attempts';

	/**
	 * The single G1 activation row, schema version 12 (E3 T2).
	 */
	public const G1_STATE = 'merchboard_g1_state';

	/**
	 * The operation registry, schema version 13 (E3 T4, F-5). Insert-only.
	 */
	public const OPERATIONS = 'merchboard_operations';

	/**
	 * Recalculations of settled payouts, schema version 14 (ADR-063): one row
	 * per preview with its decision, execution and closing.
	 */
	public const RECALCULATIONS = 'merchboard_payout_recalculations';

	/**
	 * The items of a recalculation: one per creator, currency and group.
	 */
	public const RECALCULATION_ITEMS = 'merchboard_payout_recalculation_items';

	/**
	 * Executed payout adjustments (ADR-063): counted in the balance and
	 * settled by the next settlement document. Insert-only, except the
	 * document that settles them.
	 */
	public const PAYOUT_ADJUSTMENTS = 'merchboard_payout_adjustments';

	/**
	 * The settled rows an adjustment regulated; the primary key regulates a
	 * row at most once per rule.
	 */
	public const PAYOUT_ADJUSTMENT_ROWS = 'merchboard_payout_adjustment_rows';

	/**
	 * Exports of recalculations, schema version 15 (ADR-062 pkt. 9, ADR-063):
	 * files with manifest and checksums, kept unchanged.
	 */
	public const RECALCULATION_EXPORTS = 'merchboard_payout_recalculation_exports';

	/**
	 * History of the creators' VAT status, schema version 16 (ADR-062 K06):
	 * insert-only, never changed or deleted.
	 */
	public const CREATOR_VAT_HISTORY = 'merchboard_creator_vat_history';

	/**
	 * Registrations of the creators' own discount codes, schema version 17
	 * (ADR-068, K1): kept permanently; a change ends a registration and
	 * creates a new one.
	 */
	public const CREATOR_CODES = 'merchboard_creator_codes';

	/**
	 * Orders excluded because a creator's own discount code was used, schema
	 * version 17 (ADR-068, M2): one decision per order, stored at the first
	 * assessment and never deleted automatically.
	 */
	public const ORDER_EXCLUSIONS = 'merchboard_order_exclusions';

	/**
	 * Purchase alerts, schema version 18 (ADR-065): one row per order line
	 * and creator, the alert history of the creator's overlay.
	 */
	public const ALERT_EVENTS = 'merchboard_alert_events';

	/**
	 * All tables owned by the plugin.
	 */
	private const ALL = array(
		self::CREATORS,
		self::AUDIT_LOG,
		self::CREATOR_META,
		self::CREATOR_CATEGORIES,
		self::SALES,
		self::SALES_CYCLES,
		self::PRICING_OVERRIDES,
		self::SALES_LINE_PRICING,
		self::SALES_TAX,
		self::PAYOUTS,
		self::PAYOUT_SECRETS,
		self::SETTLEMENT_DOCUMENTS,
		self::SETTLEMENT_ROWS,
		self::SETTLEMENT_COUNTERS,
		self::ORDER_GIFT_CARD_ASSESSMENTS,
		self::ORDER_GIFT_CARD_PAYOUTS,
		self::ORDER_GIFT_CARD_ATTEMPTS,
		self::G1_STATE,
		self::OPERATIONS,
		self::RECALCULATIONS,
		self::RECALCULATION_ITEMS,
		self::PAYOUT_ADJUSTMENTS,
		self::PAYOUT_ADJUSTMENT_ROWS,
		self::RECALCULATION_EXPORTS,
		self::CREATOR_VAT_HISTORY,
		self::CREATOR_CODES,
		self::ORDER_EXCLUSIONS,
		self::ALERT_EVENTS,
	);

	/**
	 * Tables the opt-in uninstall keeps (ADR-059, B2): the creators (archived
	 * by the uninstall), the audit log, the payout history and the settlement
	 * documents with their rows and number counters, as historical
	 * documentation; the G1 tables with the activation row (E3, F5), so a
	 * reinstall finds the assessments and the activation again; and the
	 * operation registry (E3 T4, B-2); and the recalculations with their
	 * adjustments (ADR-063), which explain amounts on settlement documents; and
	 * the history of the creators' VAT status (ADR-062 K06), which explains the
	 * status of historical sales and documents; and the creators' discount
	 * code registrations with the order exclusions (ADR-068, K2), which explain
	 * why an order is missing from sales and settlement documents. Everything
	 * else, including the encrypted payout details, is dropped.
	 */
	private const RETAINED_ON_UNINSTALL = array(
		self::CREATORS,
		self::AUDIT_LOG,
		self::PAYOUTS,
		self::SETTLEMENT_DOCUMENTS,
		self::SETTLEMENT_ROWS,
		self::SETTLEMENT_COUNTERS,
		self::ORDER_GIFT_CARD_ASSESSMENTS,
		self::ORDER_GIFT_CARD_PAYOUTS,
		self::ORDER_GIFT_CARD_ATTEMPTS,
		self::G1_STATE,
		self::OPERATIONS,
		self::RECALCULATIONS,
		self::RECALCULATION_ITEMS,
		self::PAYOUT_ADJUSTMENTS,
		self::PAYOUT_ADJUSTMENT_ROWS,
		self::RECALCULATION_EXPORTS,
		self::CREATOR_VAT_HISTORY,
		self::CREATOR_CODES,
		self::ORDER_EXCLUSIONS,
	);

	/**
	 * Tables of earlier schema versions that a later migration dropped. They
	 * are not part of all(), but may still exist on a site whose migrations
	 * have not run yet, so uninstall drops them too.
	 */
	private const RETIRED = array(
		self::ITEM_PRICING,
	);

	/**
	 * Returns the full, site-prefixed table name.
	 *
	 * @param wpdb   $wpdb  WordPress database object (provides the site prefix).
	 * @param string $table One of the class constants.
	 * @throws InvalidArgumentException When the table is not owned by the plugin.
	 */
	public static function name( wpdb $wpdb, string $table ): string {
		if ( ! in_array( $table, self::ALL, true ) && ! in_array( $table, self::RETIRED, true ) ) {
			throw new InvalidArgumentException( 'Unknown table.' );
		}

		return $wpdb->prefix . $table;
	}

	/**
	 * Unprefixed names of all plugin tables.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return self::ALL;
	}

	/**
	 * Unprefixed names of retired plugin tables (dropped by a migration).
	 *
	 * @return list<string>
	 */
	public static function retired(): array {
		return self::RETIRED;
	}

	/**
	 * Unprefixed names of the tables the opt-in uninstall keeps (ADR-059).
	 *
	 * @return list<string>
	 */
	public static function retained_on_uninstall(): array {
		return self::RETAINED_ON_UNINSTALL;
	}
}
