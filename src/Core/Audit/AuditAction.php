<?php
/**
 * Audit action types.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * The only valid values of `merchboard_audit_log.action` (ADR-023).
 *
 * One record per semantic change: an edit that renames a creator and
 * deactivates it writes `creator_updated` and `creator_deactivated`.
 */
enum AuditAction: string {

	/**
	 * Creator created (context: initial status, linked user id or null).
	 */
	case CreatorCreated = 'creator_created';

	/**
	 * Creator attributes changed (context: names of the changed fields, no values).
	 */
	case CreatorUpdated = 'creator_updated';

	/**
	 * Creator status changed to active (context: previous status).
	 */
	case CreatorActivated = 'creator_activated';

	/**
	 * Creator status changed to inactive (context: previous status).
	 */
	case CreatorDeactivated = 'creator_deactivated';

	/**
	 * WordPress user linked to a creator (context: user id).
	 */
	case CreatorUserLinked = 'creator_user_linked';

	/**
	 * WordPress user unlinked from a creator (context: previous user id).
	 */
	case CreatorUserUnlinked = 'creator_user_unlinked';

	/**
	 * A plugin setting changed (creator id NULL; context: setting name and the
	 * new value, which is never personal data).
	 */
	case SettingsUpdated = 'settings_updated';

	/**
	 * A WooCommerce product was linked to the creator (context: product id, the
	 * previous creator id or null, and the write path; ADR-030/031).
	 */
	case ProductCreatorLinked = 'product_creator_linked';

	/**
	 * A WooCommerce product's link to the creator was removed or moved to another
	 * creator (context: product id and the write path). The creator id is NULL
	 * when the removed link was not a valid creator reference.
	 */
	case ProductCreatorUnlinked = 'product_creator_unlinked';

	/**
	 * A WooCommerce product category was mapped to the creator (context: term
	 * id; ADR-033). The mapping is not ownership of any product.
	 */
	case CreatorCategoryLinked = 'creator_category_linked';

	/**
	 * A product category mapping of the creator was removed (context: term id
	 * and reason; ADR-033). Product links are not changed by this.
	 */
	case CreatorCategoryUnlinked = 'creator_category_unlinked';

	/**
	 * A full category reconciliation run finished all its batches (context:
	 * run id, dry-run status, trigger and the count per outcome; ADR-033). Not
	 * tied to a creator; system work with no actor. Dry runs write none.
	 */
	case CategorySyncCompleted = 'category_sync_completed';

	/**
	 * A sales ledger backfill run finished all its pages (context: run id,
	 * the user who started it, if known, and counts only: orders scanned, per
	 * outcome, rows per type and failures per code; ADR-038, status note
	 * "ROADMAP trin 7"). No order ids, no amounts. System work with no actor.
	 * Dry runs write none.
	 */
	case SalesBackfillCompleted = 'sales_backfill_completed';

	/**
	 * Creator pricing was applied to a sellable unit: Merch Dashboard wrote
	 * WooCommerce' regular price (ADR-045). Creator id: the parent product's
	 * owner. Context: product and variation id, previous and new regular
	 * price, rule, markup, base price, profit, currency, the base price's
	 * source and the unit's state before. Prices are business data, never
	 * customer data.
	 *
	 * Withdrawn (ADR-049): Merch Dashboard no longer writes WooCommerce
	 * prices, so this action is never recorded again. It stays for the
	 * append-only history that already contains it.
	 */
	case CreatorPricingApplied = 'creator_pricing_applied';

	/**
	 * Like CreatorPricingApplied, but on an explicit decision to replace a
	 * regular price that had drifted from the price Merch Dashboard last
	 * wrote (PricingService::apply_despite_drift(), ADR-045 point 6). Never
	 * automatic. Withdrawn like CreatorPricingApplied (ADR-049); kept for the
	 * existing history.
	 */
	case CreatorPricingDriftOverwritten = 'creator_pricing_drift_overwritten';

	/**
	 * The creator's default markup (share of the sale price) changed (context:
	 * previous and new markup in basis points; ADR-049). No WooCommerce price
	 * and no snapshot is changed by this.
	 */
	case CreatorMarkupChanged = 'creator_markup_changed';

	/**
	 * A fixed profit was set or changed for a parent product of the creator
	 * (context: product id, fixed profit, currency and the previous fixed
	 * profit or null; ADR-046). Withdrawn (ADR-049): never recorded again;
	 * kept for the existing history.
	 */
	case ProductFixedProfitSet = 'product_fixed_profit_set';

	/**
	 * A parent product's fixed profit was removed, so the creator's default
	 * markup applies again (context: product id, previous fixed profit and
	 * currency; ADR-046). Withdrawn (ADR-049): never recorded again; kept for
	 * the existing history.
	 */
	case ProductFixedProfitRemoved = 'product_fixed_profit_removed';

	/**
	 * The creator's VAT status changed (context: previous and new status;
	 * ADR-048). It changes no WooCommerce price, no snapshot and no past
	 * payout: snapshots keep the status of the moment their line was created.
	 */
	case CreatorVatStatusChanged = 'creator_vat_status_changed';

	/**
	 * A registration of the creator's VAT status was recorded in its history
	 * (ADR-062 K06; context: entry id, status, the date it applies
	 * from, the check date, whether a documentation reference and a reason
	 * were given (never their text), and the current status afterwards and
	 * whether it changed). Nothing earlier is recalculated.
	 */
	case CreatorVatHistoryRecorded = 'creator_vat_history_recorded';

	/**
	 * The creator's master data for settlement documents changed (ADR-062
	 * K09; context: names of the changed fields, no values). Issued
	 * documents keep the master data of their snapshot.
	 */
	case CreatorBillingDetailsChanged = 'creator_billing_details_changed';

	/**
	 * A product-specific share was set or changed for a parent product of the
	 * creator (context: product id, markup and the previous markup or null,
	 * in basis points; ADR-049). No WooCommerce price and no snapshot is
	 * changed by this.
	 */
	case ProductMarkupSet = 'product_markup_set';

	/**
	 * A parent product's own share was removed, so the creator's default
	 * markup applies again (context: product id and previous markup in basis
	 * points; ADR-049). No WooCommerce price and no snapshot is changed by
	 * this.
	 */
	case ProductMarkupRemoved = 'product_markup_removed';

	/**
	 * An admin permanently deleted Merch Dashboard's stored history of one
	 * order whose WooCommerce order was in the trash or deleted, e.g. a test
	 * order (ADR-052). Context: order id, the WooCommerce state, the number of
	 * deleted rows per table and the affected creator ids; no amounts and no
	 * customer data. The WooCommerce order itself is never changed.
	 */
	case OrderHistoryDeleted = 'order_history_deleted';

	/**
	 * A creator requested a payout (ADR-055). Context: payout id, amount,
	 * currency and method; never the bank or PayPal details.
	 */
	case PayoutRequested = 'payout_requested';

	/**
	 * A creator cancelled their pending payout; the reservation is released
	 * (ADR-055). Context: payout id, amount, currency and method.
	 */
	case PayoutCancelled = 'payout_cancelled';

	/**
	 * An admin rejected a pending payout; the reservation is released
	 * (ADR-055). Context: payout id, amount, currency, method and `has_reason`;
	 * never the reason text.
	 */
	case PayoutRejected = 'payout_rejected';

	/**
	 * An admin registered a pending bank or PayPal payout as transferred
	 * (ADR-055). Context: payout id, amount, currency, method and
	 * `has_reference`; never the reference or the details.
	 */
	case PayoutCompleted = 'payout_completed';

	/**
	 * An admin registered a gift card on a pending gift card payout, which is
	 * then completed (ADR-057). Context: payout id, amount, currency, method,
	 * `provider` (provider id) and `external_id` (the card's id in it); never
	 * the code.
	 */
	case PayoutGiftCardRegistered = 'payout_gift_card_registered';

	/**
	 * An admin viewed the full details of a pending payout (ADR-056, ADR-058).
	 * Context: payout id and method; never the details.
	 */
	case PayoutDetailsViewed = 'payout_details_viewed';

	/**
	 * A creator's settlement start was set, changed or removed (D3, ADR-054).
	 * Context: previous and new start (UTC, null = the whole history).
	 */
	case PayoutStartChanged = 'payout_start_changed';

	/**
	 * The payout holding period was changed (planning decision 2). Context:
	 * setting, previous and new value.
	 */
	case PayoutSettingsChanged = 'payout_settings_changed';

	/**
	 * A settlement document was issued with a completed payout (ADR-060).
	 * Context: document id and number, payout id, number of rows, currency.
	 */
	case SettlementDocumentIssued = 'settlement_document_issued';

	/**
	 * A correction document was issued for a settlement document (ADR-060,
	 * D11). Context: document id and number, the corrected number, payout id
	 * and `has_reason`; never the reason text.
	 */
	case SettlementDocumentCorrected = 'settlement_document_corrected';

	/**
	 * G1 was activated: the cut-off from which orders paid with a payout
	 * gift card are assessed was stored, once (E3 T3). Context: `cutoff_utc`.
	 */
	case G1Activated = 'g1_activated';

	/**
	 * A recalculation of settled payouts was previewed (ADR-063). Context:
	 * reference, reopened reference, numbers of items per group, rows.
	 */
	case PayoutRecalculationPreviewed = 'payout_recalculation_previewed';

	/**
	 * A previewed recalculation was approved (ADR-063). Context: reference,
	 * active items and their total difference per currency, `has_note`.
	 */
	case PayoutRecalculationApproved = 'payout_recalculation_approved';

	/**
	 * A recalculation was rejected (ADR-063). Context: reference, previous
	 * status, `has_note`; never the note.
	 */
	case PayoutRecalculationRejected = 'payout_recalculation_rejected';

	/**
	 * A rejected recalculation was reopened as a new preview (ADR-063).
	 * Context: the rejected and the new reference.
	 */
	case PayoutRecalculationReopened = 'payout_recalculation_reopened';

	/**
	 * An approved recalculation item was executed as an adjustment of the
	 * creator's balance (ADR-063). Context: reference, item and adjustment
	 * id, currency, amount, rows.
	 */
	case PayoutAdjustmentExecuted = 'payout_adjustment_executed';

	/**
	 * The execution of a recalculation item failed and stopped the
	 * execution; nothing of the item was written (ADR-063). Context:
	 * reference, item id, failure code.
	 */
	case PayoutRecalculationItemFailed = 'payout_recalculation_item_failed';

	/**
	 * Every active item of a recalculation is executed (ADR-063). Context:
	 * reference, executed items.
	 */
	case PayoutRecalculationExecuted = 'payout_recalculation_executed';

	/**
	 * A recalculation was closed explicitly (ADR-063). Context: reference,
	 * previous status, `has_note`.
	 */
	case PayoutRecalculationClosed = 'payout_recalculation_closed';

	/**
	 * A recalculation was exported (ADR-062 pkt. 9). Context: reference,
	 * export reference, filters, `complete`, items and rows exported.
	 */
	case PayoutRecalculationExported = 'payout_recalculation_exported';

	/**
	 * A file of a recalculation export was downloaded after its integrity
	 * check. Context: export reference and file name.
	 */
	case PayoutRecalculationExportDownloaded = 'payout_recalculation_export_downloaded';

	/**
	 * A creator's own discount code was registered (ADR-068, K1). Context:
	 * registration id, coupon id, the ended registration it replaces, and the
	 * validation result; never an e-mail address.
	 */
	case CreatorCodeRegistered = 'creator_code_registered';

	/**
	 * A creator's discount code registration was ended (ADR-068, K1).
	 * Context: registration id and coupon id.
	 */
	case CreatorCodeEnded = 'creator_code_ended';

	/**
	 * An order was excluded because a creator's own discount code was used
	 * (ADR-068, M2), recorded by the sales ledger synchronization. Context:
	 * order id, registration id, decision (excluded or open), whether the
	 * order had settled rows and ledger rows, and the coupon deviation flag.
	 */
	case OrderExcludedByCreatorCode = 'order_excluded_by_creator_code';

	/**
	 * A creator set up an alert overlay (ADR-065). Context: none; the secret
	 * overlay id is never recorded.
	 */
	case AlertOverlayCreated = 'alert_overlay_created';

	/**
	 * A creator replaced the secret id of the alert overlay, so the old OBS
	 * URL stopped working (ADR-065). Context: none; ids are never recorded.
	 */
	case AlertOverlayIdRegenerated = 'alert_overlay_id_regenerated';

	/**
	 * The Google Fonts API key of the alert font library was saved or removed
	 * (ADR-067). Context: whether it was removed; never the key.
	 */
	case AlertFontsKeyChanged = 'alert_fonts_key_changed';

	/**
	 * The Google Fonts catalogue was fetched (ADR-067). Context: number of families.
	 */
	case AlertFontsSynced = 'alert_fonts_synced';

	/**
	 * A font was approved for alert overlays (ADR-067). Context: family.
	 */
	case AlertFontApproved = 'alert_font_approved';

	/**
	 * An approved alert font was removed (ADR-067). Context: family.
	 */
	case AlertFontRemoved = 'alert_font_removed';

	/**
	 * A sound was added to the alert sound library (phase 25, step 6.3). Context: file name.
	 */
	case AlertSoundLibraryAdded = 'alert_sound_library_added';

	/**
	 * A sound was removed from the alert sound library. Context: file name.
	 */
	case AlertSoundLibraryRemoved = 'alert_sound_library_removed';

	/**
	 * A sound of the alert sound library got a new name (step 6.7). Context: name, previous name.
	 */
	case AlertSoundLibraryRenamed = 'alert_sound_library_renamed';

	/**
	 * An image was added to the alert image library (phase 25, step 6.10). Context: file name.
	 */
	case AlertImageLibraryAdded = 'alert_image_library_added';

	/**
	 * An image was removed from the alert image library. Context: file name.
	 */
	case AlertImageLibraryRemoved = 'alert_image_library_removed';

	/**
	 * An image of the alert image library got a new name. Context: name, previous name.
	 */
	case AlertImageLibraryRenamed = 'alert_image_library_renamed';

	/**
	 * The Twitch app of the alert chatbot was saved (phase 25, step 8). Context: whether it is a new app; never the secret.
	 */
	case AlertTwitchAppChanged = 'alert_twitch_app_changed';

	/**
	 * The Twitch app of the alert chatbot was removed, with the bot and every secret.
	 */
	case AlertTwitchAppRemoved = 'alert_twitch_app_removed';

	/**
	 * The Twitch bot account was connected. Context: its login.
	 */
	case AlertChatbotBotConnected = 'alert_chatbot_bot_connected';

	/**
	 * The Twitch bot account was disconnected. Context: its login.
	 */
	case AlertChatbotBotDisconnected = 'alert_chatbot_bot_disconnected';

	/**
	 * How chat messages are composed was changed. Context: the mode.
	 */
	case AlertChatbotFormatChanged = 'alert_chatbot_format_changed';

	/**
	 * A creator added the bot to the own channel. Context: the channel login and, from step 8.2, the platform.
	 */
	case AlertChatbotChannelConnected = 'alert_chatbot_channel_connected';

	/**
	 * The bot was removed from a creator's channel. Context: the channel login and, from step 8.2, the platform.
	 */
	case AlertChatbotChannelDisconnected = 'alert_chatbot_channel_disconnected';

	/**
	 * An administrator sent a chat message as the bot. Context: the channel login and, from step 8.2, the platform; never the message.
	 */
	case AlertChatbotMessageSent = 'alert_chatbot_message_sent';

	/**
	 * The Kick app of the alert chatbot was saved (phase 25, step 8.2, ADR-069). Context: whether it is a new app; never the secret.
	 */
	case AlertKickAppChanged = 'alert_kick_app_changed';

	/**
	 * The Kick app of the alert chatbot was removed with every secret.
	 */
	case AlertKickAppRemoved = 'alert_kick_app_removed';
}
