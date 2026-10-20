<?php
/**
 * Human-readable audit records.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditEntry;
use MerchBoard\Modules\Creators\CreatorStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Turns audit records into readable lines for the admin screens (phase 11,
 * product and category events from phase 17).
 *
 * Context is never dumped as raw JSON: known keys (the shapes AuditTrail
 * callers write, docs/DATABASE.md) get a readable line; unknown keys and codes
 * are shown only as "key: value" for short scalar values, and nested data as
 * a placeholder. The context itself is never changed.
 *
 * Ids are resolved from AuditContextLabels, which the caller looks up once per
 * page (context_ids()): products and categories as "Name (#id)", or "#id" when
 * they no longer exist; a product links to its edit screen only when the
 * labels carry a URL (the viewer may edit it).
 *
 * details() returns escaped HTML: every dynamic value goes through esc_html(),
 * esc_attr() or esc_url() here.
 */
final class AuditLogFormatter {

	/**
	 * Longest scalar value shown for an unknown context key.
	 */
	private const MAX_VALUE_LENGTH = 100;

	/**
	 * Characters of a run id shown (the full id is in the title).
	 */
	private const RUN_ID_LENGTH = 8;

	/**
	 * Outcome counts of `category_sync_completed`, in the order of the
	 * reconciliation result (CategoryReconciliationResult::to_array()).
	 */
	private const COUNT_KEYS = array( 'linked', 'moved', 'unlinked', 'unchanged', 'skipped_manual', 'ambiguous', 'skipped_inactive', 'errors' );

	/**
	 * Order outcome counts of `sales_backfill_completed`, in the order of
	 * SalesBackfillStatus.
	 */
	private const BACKFILL_KEYS = array( 'registered', 'skipped_history', 'skipped_not_counted', 'skipped_no_creator', 'skipped_missing', 'skipped_busy', 'failed' );

	/**
	 * Ledger row types counted by `sales_backfill_completed` (SalesEntryType).
	 */
	private const BACKFILL_ROW_KEYS = array( 'sale', 'refund', 'reversal', 'correction' );

	/**
	 * Label of an action.
	 *
	 * @param AuditAction $action Action.
	 */
	public static function action_label( AuditAction $action ): string {
		return match ( $action ) {
			AuditAction::CreatorCreated      => __( 'Creator created', 'merchboard' ),
			AuditAction::CreatorUpdated      => __( 'Creator updated', 'merchboard' ),
			AuditAction::CreatorActivated    => __( 'Creator activated', 'merchboard' ),
			AuditAction::CreatorDeactivated  => __( 'Creator deactivated', 'merchboard' ),
			AuditAction::CreatorUserLinked   => __( 'WordPress user linked', 'merchboard' ),
			AuditAction::CreatorUserUnlinked => __( 'WordPress user unlinked', 'merchboard' ),
			AuditAction::SettingsUpdated     => __( 'Settings changed', 'merchboard' ),
			AuditAction::ProductCreatorLinked   => __( 'Product linked', 'merchboard' ),
			AuditAction::ProductCreatorUnlinked => __( 'Product unlinked', 'merchboard' ),
			AuditAction::CreatorCategoryLinked   => __( 'Product category mapped', 'merchboard' ),
			AuditAction::CreatorCategoryUnlinked => __( 'Product category unmapped', 'merchboard' ),
			AuditAction::CategorySyncCompleted   => __( 'Category reconciliation completed', 'merchboard' ),
			AuditAction::SalesBackfillCompleted  => __( 'Sales ledger backfill completed', 'merchboard' ),
			AuditAction::CreatorPricingApplied   => __( 'Creator price applied', 'merchboard' ),
			AuditAction::CreatorPricingDriftOverwritten => __( 'Creator price applied over a changed WooCommerce price', 'merchboard' ),
			AuditAction::CreatorMarkupChanged    => __( 'Default markup changed', 'merchboard' ),
			AuditAction::ProductFixedProfitSet   => __( 'Fixed profit set', 'merchboard' ),
			AuditAction::ProductFixedProfitRemoved => __( 'Fixed profit removed', 'merchboard' ),
			AuditAction::CreatorVatStatusChanged   => __( 'VAT status changed', 'merchboard' ),
			AuditAction::CreatorVatHistoryRecorded => __( 'VAT status registered in the history', 'merchboard' ),
			AuditAction::CreatorBillingDetailsChanged => __( 'Master data for settlement documents changed', 'merchboard' ),
			AuditAction::ProductMarkupSet          => __( 'Product markup set', 'merchboard' ),
			AuditAction::ProductMarkupRemoved      => __( 'Product markup removed', 'merchboard' ),
			AuditAction::OrderHistoryDeleted       => __( 'Order history deleted', 'merchboard' ),
			AuditAction::PayoutRequested           => __( 'Payout requested', 'merchboard' ),
			AuditAction::PayoutCancelled           => __( 'Payout cancelled', 'merchboard' ),
			AuditAction::PayoutRejected            => __( 'Payout rejected', 'merchboard' ),
			AuditAction::PayoutCompleted           => __( 'Payout completed', 'merchboard' ),
			AuditAction::PayoutGiftCardRegistered  => __( 'Payout gift card registered', 'merchboard' ),
			AuditAction::PayoutDetailsViewed       => __( 'Payout details viewed', 'merchboard' ),
			AuditAction::PayoutStartChanged        => __( 'Settlement start changed', 'merchboard' ),
			AuditAction::PayoutSettingsChanged     => __( 'Payout settings changed', 'merchboard' ),
			AuditAction::SettlementDocumentIssued  => __( 'Settlement document issued', 'merchboard' ),
			AuditAction::SettlementDocumentCorrected => __( 'Settlement document corrected', 'merchboard' ),
			AuditAction::G1Activated               => __( 'Gift card commission rule activated', 'merchboard' ),
			AuditAction::PayoutRecalculationPreviewed => __( 'Payout recalculation previewed', 'merchboard' ),
			AuditAction::PayoutRecalculationApproved => __( 'Payout recalculation approved', 'merchboard' ),
			AuditAction::PayoutRecalculationRejected => __( 'Payout recalculation rejected', 'merchboard' ),
			AuditAction::PayoutRecalculationReopened => __( 'Payout recalculation reopened', 'merchboard' ),
			AuditAction::PayoutAdjustmentExecuted  => __( 'Payout adjustment executed', 'merchboard' ),
			AuditAction::PayoutRecalculationItemFailed => __( 'Payout recalculation item failed', 'merchboard' ),
			AuditAction::PayoutRecalculationExecuted => __( 'Payout recalculation executed', 'merchboard' ),
			AuditAction::PayoutRecalculationClosed => __( 'Payout recalculation closed', 'merchboard' ),
			AuditAction::PayoutRecalculationExported => __( 'Payout recalculation exported', 'merchboard' ),
			AuditAction::PayoutRecalculationExportDownloaded => __( 'Payout recalculation export downloaded', 'merchboard' ),
			AuditAction::CreatorCodeRegistered     => __( 'Creator discount code registered', 'merchboard' ),
			AuditAction::CreatorCodeEnded          => __( 'Creator discount code ended', 'merchboard' ),
			AuditAction::OrderExcludedByCreatorCode => __( 'Order excluded (creator discount code)', 'merchboard' ),
			AuditAction::AlertOverlayCreated       => __( 'Alert overlay set up', 'merchboard' ),
			AuditAction::AlertOverlayIdRegenerated => __( 'Alert overlay URL replaced', 'merchboard' ),
			AuditAction::AlertFontsKeyChanged      => __( 'Google Fonts API key changed', 'merchboard' ),
			AuditAction::AlertFontsSynced          => __( 'Google Fonts catalogue fetched', 'merchboard' ),
			AuditAction::AlertFontApproved         => __( 'Alert font approved', 'merchboard' ),
			AuditAction::AlertFontRemoved          => __( 'Alert font removed', 'merchboard' ),
			AuditAction::AlertSoundLibraryAdded    => __( 'Sound added to the alert sound library', 'merchboard' ),
			AuditAction::AlertSoundLibraryRemoved  => __( 'Sound removed from the alert sound library', 'merchboard' ),
			AuditAction::AlertSoundLibraryRenamed  => __( 'Sound of the alert sound library renamed', 'merchboard' ),
			AuditAction::AlertImageLibraryAdded    => __( 'Image added to the alert image library', 'merchboard' ),
			AuditAction::AlertImageLibraryRemoved  => __( 'Image removed from the alert image library', 'merchboard' ),
			AuditAction::AlertImageLibraryRenamed  => __( 'Image of the alert image library renamed', 'merchboard' ),
			AuditAction::AlertTwitchAppChanged => __( 'Twitch app of the alert chatbot saved', 'merchboard' ),
			AuditAction::AlertTwitchAppRemoved => __( 'Twitch app of the alert chatbot removed', 'merchboard' ),
			AuditAction::AlertChatbotBotConnected => __( 'Chatbot account connected', 'merchboard' ),
			AuditAction::AlertChatbotBotDisconnected => __( 'Chatbot account disconnected', 'merchboard' ),
			AuditAction::AlertChatbotFormatChanged => __( 'Chat message format changed', 'merchboard' ),
			AuditAction::AlertChatbotChannelConnected => __( 'Chatbot added to a creator\'s channel', 'merchboard' ),
			AuditAction::AlertChatbotChannelDisconnected => __( 'Chatbot removed from a creator\'s channel', 'merchboard' ),
			AuditAction::AlertChatbotMessageSent => __( 'Chat message sent as the bot', 'merchboard' ),
		};
	}

	/**
	 * Readable details of a record's context.
	 *
	 * @param AuditEntry         $entry  Record.
	 * @param AuditContextLabels $labels Labels looked up for the page.
	 * @return list<string> Escaped HTML lines.
	 */
	public static function details( AuditEntry $entry, AuditContextLabels $labels ): array {
		$lines    = array();
		$counts   = AuditAction::CategorySyncCompleted === $entry->action ? self::counts( $entry->context ) : null;
		$backfill = AuditAction::SalesBackfillCompleted === $entry->action ? self::backfill_outcomes( $entry->context ) : null;

		foreach ( $entry->context as $key => $value ) {
			$key = (string) $key;

			if ( null !== $backfill ) {
				$line = self::backfill_line( $key, $value, $backfill );

				if ( null !== $line ) {
					// The outcome counts are one line, where the first one stood.
					if ( '' !== $line ) {
						$lines[] = $line;
					}

					if ( in_array( $key, self::BACKFILL_KEYS, true ) ) {
						$backfill = '';
					}

					continue;
				}
			}

			if ( null !== $counts && in_array( $key, self::COUNT_KEYS, true ) ) {
				// All eight counts as one line, where the first one stood.
				if ( '' !== $counts ) {
					$lines[] = $counts;
					$counts  = '';
				}

				continue;
			}

			$lines[] = self::line( $key, $value, $labels );
		}

		return $lines;
	}

	/**
	 * User ids mentioned in a record's context (to look up labels in bulk).
	 *
	 * @param AuditEntry $entry Record.
	 * @return list<int>
	 */
	public static function context_user_ids( AuditEntry $entry ): array {
		return self::ids( $entry->context, array( 'user_id', 'trigger_user_id' ) );
	}

	/**
	 * Every id a record's context refers to, by kind (to look up in bulk).
	 *
	 * @param AuditEntry $entry Record.
	 * @return array{users: list<int>, creators: list<int>, products: list<int>, categories: list<int>}
	 */
	public static function context_ids( AuditEntry $entry ): array {
		$categories = $entry->context['category_ids'] ?? array();
		$creators   = $entry->context['creator_ids'] ?? array();

		return array(
			'users'      => self::context_user_ids( $entry ),
			'creators'   => array_merge( self::ids( $entry->context, array( 'previous_creator_id', 'creator_id' ) ), is_array( $creators ) ? self::ids( $creators, array_keys( $creators ) ) : array() ),
			'products'   => self::ids( $entry->context, array( 'product_id' ) ),
			'categories' => array_merge( self::ids( $entry->context, array( 'term_id' ) ), is_array( $categories ) ? self::ids( $categories, array_keys( $categories ) ) : array() ),
		);
	}

	/**
	 * One context line.
	 *
	 * @param string             $key    Context key.
	 * @param mixed              $value  Context value.
	 * @param AuditContextLabels $labels Labels.
	 */
	private static function line( string $key, mixed $value, AuditContextLabels $labels ): string {
		switch ( $key ) {
			case 'user_id':
				return null === $value
					? esc_html__( 'WordPress user: none', 'merchboard' )
					/* translators: %s: user label or "#id". */
					: self::text( __( 'WordPress user: %s', 'merchboard' ), self::user( $value, $labels ) );
			case 'trigger_user_id':
				/* translators: %s: user label or "#id". */
				return self::text( __( 'Triggered by: %s', 'merchboard' ), self::user( $value, $labels ) );
			case 'status':
				/* translators: %s: creator status. */
				return self::text( __( 'Status: %s', 'merchboard' ), self::status( $value ) );
			case 'previous_status':
				/* translators: %s: creator status. */
				return self::text( __( 'Previous status: %s', 'merchboard' ), self::status( $value ) );
			case 'changed_fields':
				/* translators: %s: comma-separated field names. */
				return self::text( __( 'Changed: %s', 'merchboard' ), self::fields( $value ) );
			case 'reason':
				return 'user_deleted' === $value
					? esc_html__( 'Reason: the WordPress user was deleted', 'merchboard' )
					/* translators: %s: reason. */
					: self::text( __( 'Reason: %s', 'merchboard' ), self::reason( $value ) );
			case 'trigger':
				/* translators: %s: what started the reconciliation. */
				return self::text( __( 'Trigger: %s', 'merchboard' ), self::reason( $value ) );
			case 'setting':
				return match ( $value ) {
					'delete_data_on_uninstall' => esc_html__( 'Setting: delete all data when the plugin is deleted', 'merchboard' ),
					'dashboard_slug'           => esc_html__( 'Setting: creator dashboard URL slug', 'merchboard' ),
					'counted_order_statuses'   => esc_html__( 'Setting: counted order statuses', 'merchboard' ),
					'logo_attachment_id'       => esc_html__( 'Setting: logo (Media Library attachment ID, 0 = default logo)', 'merchboard' ),
					/* translators: %s: setting name. */
					default                    => self::text( __( 'Setting: %s', 'merchboard' ), self::scalar( $value ) ),
				};
			case 'previous_value':
				/* translators: %s: previous setting value. */
				return self::text( __( 'Previous value: %s', 'merchboard' ), self::setting_value( $value ) );
			case 'value':
				/* translators: %s: new setting value. */
				return self::text( __( 'New value: %s', 'merchboard' ), self::setting_value( $value ) );
			case 'enabled':
				return true === $value ? esc_html__( 'New value: enabled', 'merchboard' ) : esc_html__( 'New value: disabled', 'merchboard' );
			case 'product_id':
				/* translators: %s: product name and id, or "#id". */
				return self::html( __( 'Product: %s', 'merchboard' ), self::product( $value, $labels ) );
			case 'term_id':
				/* translators: %s: product category name and id, or "#id". */
				return self::text( __( 'Product category: %s', 'merchboard' ), self::category( $value, $labels ) );
			case 'category_ids':
				/* translators: %s: comma-separated product categories. */
				return self::text( __( 'Product categories: %s', 'merchboard' ), self::categories( $value, $labels ) );
			case 'previous_creator_id':
				return null === $value
					? esc_html__( 'Previous creator: none', 'merchboard' )
					/* translators: %s: creator name or "#id". */
					: self::text( __( 'Previous creator: %s', 'merchboard' ), self::creator( $value, $labels ) );
			case 'creator_id':
				/* translators: %s: creator name or "#id". */
				return self::text( __( 'Creator: %s', 'merchboard' ), self::creator( $value, $labels ) );
			case 'source':
				/* translators: %s: origin of the product link. */
				return self::text( __( 'Origin: %s', 'merchboard' ), self::source( $value ) );
			case 'previous_source':
				/* translators: %s: previous origin of the product link. */
				return self::text( __( 'Previous origin: %s', 'merchboard' ), self::source( $value ) );
			case 'previous_link':
				return 'invalid' === $value
					? esc_html__( 'Previous link: invalid', 'merchboard' )
					/* translators: %s: previous link state. */
					: self::text( __( 'Previous link: %s', 'merchboard' ), self::scalar( $value ) );
			case 'choice':
				/* translators: %s: what happens to the category-managed links. */
				return self::text( __( 'Choice: %s', 'merchboard' ), self::choice( $value ) );
			case 'run_id':
				/* translators: %s: shortened run id. */
				return self::html( __( 'Run: %s', 'merchboard' ), self::run_id( $value ) );
			case 'scope':
				/* translators: %s: which products the reconciliation covered. */
				return self::text( __( 'Scope: %s', 'merchboard' ), self::scope( $value ) );
			case 'dry_run':
				return true === $value ? esc_html__( 'Dry run: yes', 'merchboard' ) : esc_html__( 'Dry run: no', 'merchboard' );
			case 'total':
				/* translators: %s: number of products. */
				return self::text( __( 'Products evaluated: %s', 'merchboard' ), self::scalar( $value ) );
			case 'order_id':
				/* translators: %s: WooCommerce order id. */
				return self::text( __( 'Order: #%s', 'merchboard' ), self::scalar( $value ) );
			case 'woocommerce_order':
				return 'trash' === $value
					? esc_html__( 'WooCommerce order: in the trash', 'merchboard' )
					: esc_html__( 'WooCommerce order: deleted', 'merchboard' );
			case 'ledger_rows':
				/* translators: %s: number of rows. */
				return self::text( __( 'Ledger rows deleted: %s', 'merchboard' ), self::scalar( $value ) );
			case 'cycle_events':
				/* translators: %s: number of rows. */
				return self::text( __( 'Cycle events deleted: %s', 'merchboard' ), self::scalar( $value ) );
			case 'tax_rows':
				/* translators: %s: number of rows. */
				return self::text( __( 'Stored tax rows deleted: %s', 'merchboard' ), self::scalar( $value ) );
			case 'snapshots':
				/* translators: %s: number of rows. */
				return self::text( __( 'Profit snapshots deleted: %s', 'merchboard' ), self::scalar( $value ) );
			case 'creator_ids':
				/* translators: %s: comma-separated creators. */
				return self::text( __( 'Creators: %s', 'merchboard' ), is_array( $value ) && array() !== $value ? implode( ', ', array_map( static fn( mixed $id ): string => self::creator( $id, $labels ), $value ) ) : '—' );
		}

		return esc_html( sanitize_key( $key ) . ': ' . self::scalar( $value ) );
	}

	/**
	 * A translated format with a plain-text value, escaped.
	 *
	 * @param string $format Format with one %s.
	 * @param string $value  Plain text.
	 */
	private static function text( string $format, string $value ): string {
		return esc_html( sprintf( $format, $value ) );
	}

	/**
	 * A translated format with an already escaped HTML value.
	 *
	 * @param string $format Format with one %s.
	 * @param string $html   Escaped HTML.
	 */
	private static function html( string $format, string $html ): string {
		return sprintf( esc_html( $format ), $html );
	}

	/**
	 * A `sales_backfill_completed` line for a known key; null for other keys
	 * (shown as usual); '' for an outcome count already shown.
	 *
	 * @param string $key      Context key.
	 * @param mixed  $value    Context value.
	 * @param string $outcomes The outcome line, or '' once it is shown.
	 */
	private static function backfill_line( string $key, mixed $value, string $outcomes ): ?string {
		if ( in_array( $key, self::BACKFILL_KEYS, true ) ) {
			return $outcomes;
		}

		return match ( $key ) {
			/* translators: %s: number of orders. */
			'scanned'  => self::text( __( 'Orders scanned: %s', 'merchboard' ), self::scalar( $value ) ),
			/* translators: %s: ledger rows per type, e.g. "Sale: 2 · Refund: 1". */
			'rows'     => self::text( __( 'Rows written: %s', 'merchboard' ), self::backfill_rows( $value ) ),
			/* translators: %s: failed orders per error code, or "none". */
			'failures' => self::text( __( 'Failures by code: %s', 'merchboard' ), self::backfill_failures( $value ) ),
			default    => null,
		};
	}

	/**
	 * The seven order outcome counts of `sales_backfill_completed` as one
	 * line, or null when the context does not hold all of them as integers.
	 *
	 * @param array<mixed> $context Context.
	 */
	private static function backfill_outcomes( array $context ): ?string {
		$labels = array(
			'registered'          => __( 'Registered', 'merchboard' ),
			'skipped_history'     => __( 'Already in the ledger', 'merchboard' ),
			'skipped_not_counted' => __( 'Not counted', 'merchboard' ),
			'skipped_no_creator'  => __( 'No creator', 'merchboard' ),
			'skipped_missing'     => __( 'Not found', 'merchboard' ),
			'skipped_busy'        => __( 'Busy', 'merchboard' ),
			'failed'              => __( 'Failed', 'merchboard' ),
		);
		$parts  = array();

		foreach ( self::BACKFILL_KEYS as $key ) {
			if ( ! is_int( $context[ $key ] ?? null ) ) {
				return null;
			}

			/* translators: 1: outcome, 2: number of orders. */
			$parts[] = sprintf( __( '%1$s: %2$d', 'merchboard' ), $labels[ $key ], $context[ $key ] );
		}

		return esc_html( implode( ' · ', $parts ) );
	}

	/**
	 * Rows per ledger type ("Sale: 2 · Refund: 1 · …"); plain text.
	 *
	 * @param mixed $value Rows per type.
	 */
	private static function backfill_rows( mixed $value ): string {
		$labels = array(
			'sale'       => __( 'Sale', 'merchboard' ),
			'refund'     => __( 'Refund', 'merchboard' ),
			'reversal'   => __( 'Reversal', 'merchboard' ),
			'correction' => __( 'Correction', 'merchboard' ),
		);
		$parts  = array();

		foreach ( self::BACKFILL_ROW_KEYS as $key ) {
			if ( ! is_array( $value ) || ! is_int( $value[ $key ] ?? null ) ) {
				return '…';
			}

			/* translators: 1: ledger row type, 2: number of rows. */
			$parts[] = sprintf( __( '%1$s: %2$d', 'merchboard' ), $labels[ $key ], $value[ $key ] );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Failed orders per error code ("lock_timeout: 1, db_error: 2") or
	 * "none"; plain text. Codes are shown as stored (they are fixed values).
	 *
	 * @param mixed $value Failures per code.
	 */
	private static function backfill_failures( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return '…';
		}

		if ( array() === $value ) {
			return __( 'none', 'merchboard' );
		}

		$parts = array();

		foreach ( $value as $code => $count ) {
			if ( ! is_int( $count ) ) {
				return '…';
			}

			$parts[] = sanitize_key( (string) $code ) . ': ' . $count;
		}

		return implode( ', ', $parts );
	}

	/**
	 * The eight outcome counts of `category_sync_completed` as one line, or
	 * null when the context does not hold all of them as integers.
	 *
	 * @param array<mixed> $context Context.
	 */
	private static function counts( array $context ): ?string {
		$labels = array(
			'linked'           => __( 'Linked', 'merchboard' ),
			'moved'            => __( 'Moved', 'merchboard' ),
			'unlinked'         => __( 'Removed', 'merchboard' ),
			'unchanged'        => __( 'Unchanged', 'merchboard' ),
			'skipped_manual'   => __( 'Manual', 'merchboard' ),
			'ambiguous'        => __( 'Ambiguous', 'merchboard' ),
			'skipped_inactive' => __( 'Inactive', 'merchboard' ),
			'errors'           => __( 'Errors', 'merchboard' ),
		);
		$parts  = array();

		foreach ( self::COUNT_KEYS as $key ) {
			if ( ! is_int( $context[ $key ] ?? null ) ) {
				return null;
			}

			/* translators: 1: outcome, 2: number of products. */
			$parts[] = sprintf( __( '%1$s: %2$d', 'merchboard' ), $labels[ $key ], $context[ $key ] );
		}

		return esc_html( implode( ' · ', $parts ) );
	}

	/**
	 * A user reference: its label while the user exists, else "#id (no
	 * longer exists)". Plain text.
	 *
	 * @param mixed              $value  User id.
	 * @param AuditContextLabels $labels Labels.
	 */
	private static function user( mixed $value, AuditContextLabels $labels ): string {
		if ( ! is_int( $value ) ) {
			return self::scalar( $value );
		}

		/* translators: %d: user id. */
		return $labels->users[ $value ] ?? sprintf( __( '#%d (no longer exists)', 'merchboard' ), $value );
	}

	/**
	 * A creator reference: its name, else "Creator #id (not found)". Plain text.
	 *
	 * @param mixed              $value  Creator id.
	 * @param AuditContextLabels $labels Labels.
	 */
	private static function creator( mixed $value, AuditContextLabels $labels ): string {
		if ( ! is_int( $value ) ) {
			return self::scalar( $value );
		}

		/* translators: %d: creator id. */
		return $labels->creators[ $value ] ?? sprintf( __( 'Creator #%d (not found)', 'merchboard' ), $value );
	}

	/**
	 * A product: "Name (#id)", linked when the labels carry an edit URL, or
	 * "#id" when it no longer exists. Escaped HTML.
	 *
	 * @param mixed              $value  Product id.
	 * @param AuditContextLabels $labels Labels.
	 */
	private static function product( mixed $value, AuditContextLabels $labels ): string {
		if ( ! is_int( $value ) ) {
			return esc_html( self::scalar( $value ) );
		}

		$product = $labels->products[ $value ] ?? null;

		if ( null === $product ) {
			return esc_html( '#' . $value );
		}

		$label = esc_html( self::named( $product['name'], $value ) );

		return null === $product['url'] ? $label : '<a href="' . esc_url( $product['url'] ) . '">' . $label . '</a>';
	}

	/**
	 * A product category: "Name (#id)", or "#id" when it no longer exists.
	 * Plain text.
	 *
	 * @param mixed              $value  Term id.
	 * @param AuditContextLabels $labels Labels.
	 */
	private static function category( mixed $value, AuditContextLabels $labels ): string {
		if ( ! is_int( $value ) ) {
			return self::scalar( $value );
		}

		$name = $labels->categories[ $value ] ?? null;

		return null === $name ? '#' . $value : self::named( $name, $value );
	}

	/**
	 * Several product categories. Plain text.
	 *
	 * @param mixed              $value  Term ids.
	 * @param AuditContextLabels $labels Labels.
	 */
	private static function categories( mixed $value, AuditContextLabels $labels ): string {
		if ( ! is_array( $value ) ) {
			return self::scalar( $value );
		}

		if ( array() === $value ) {
			return '—';
		}

		return implode( ', ', array_map( static fn( mixed $id ): string => self::category( $id, $labels ), array_values( $value ) ) );
	}

	/**
	 * "Name (#id)"; an empty name is shown as "(no name)".
	 *
	 * @param string $name Name.
	 * @param int    $id   Id.
	 */
	private static function named( string $name, int $id ): string {
		/* translators: 1: name, 2: id. */
		return sprintf( __( '%1$s (#%2$d)', 'merchboard' ), '' === $name ? __( '(no name)', 'merchboard' ) : $name, $id );
	}

	/**
	 * A reason or trigger code. Unknown codes are shown as they are.
	 *
	 * @param mixed $value Code.
	 */
	private static function reason( mixed $value ): string {
		return match ( $value ) {
			'category_added'    => __( 'the product was added to a mapped category', 'merchboard' ),
			'category_removed'  => __( 'the product left its mapped category', 'merchboard' ),
			'category_moved'    => __( 'the product moved to another creator\'s category', 'merchboard' ),
			'category_changed'  => __( 'product categories changed', 'merchboard' ),
			'mapping_created'   => __( 'a category mapping was created', 'merchboard' ),
			'mapping_removed'   => __( 'a category mapping was removed', 'merchboard' ),
			'creator_activated' => __( 'the creator was activated', 'merchboard' ),
			'term_deleted'      => __( 'the product category was deleted', 'merchboard' ),
			'ambiguous'         => __( 'categories of several creators (ambiguous)', 'merchboard' ),
			'full_sync'         => __( 'full reconciliation', 'merchboard' ),
			'admin'             => __( 'an administrator', 'merchboard' ),
			default             => self::scalar( $value ),
		};
	}

	/**
	 * A link origin. Unknown codes are shown as they are.
	 *
	 * @param mixed $value Origin.
	 */
	private static function source( mixed $value ): string {
		return match ( $value ) {
			'admin'    => __( 'Manual', 'merchboard' ),
			'category' => __( 'Product category', 'merchboard' ),
			default    => self::scalar( $value ),
		};
	}

	/**
	 * A mapping removal choice. Unknown codes are shown as they are.
	 *
	 * @param mixed $value Choice.
	 */
	private static function choice( mixed $value ): string {
		return match ( $value ) {
			'remove_links'   => __( 'Remove category-managed links', 'merchboard' ),
			'keep_as_manual' => __( 'Keep as manual links', 'merchboard' ),
			default          => self::scalar( $value ),
		};
	}

	/**
	 * A reconciliation scope. Unknown values are shown as they are.
	 *
	 * @param mixed $value Scope.
	 */
	private static function scope( mixed $value ): string {
		return match ( $value ) {
			'all'      => __( 'all relevant products', 'merchboard' ),
			'category' => __( 'the products of one category', 'merchboard' ),
			'mapping'  => __( 'the products of one mapping', 'merchboard' ),
			default    => self::scalar( $value ),
		};
	}

	/**
	 * A run id, shortened, with the full id as title. Escaped HTML.
	 *
	 * @param mixed $value Run id.
	 */
	private static function run_id( mixed $value ): string {
		if ( ! is_string( $value ) || mb_strlen( $value ) <= self::RUN_ID_LENGTH ) {
			return esc_html( self::scalar( $value ) );
		}

		$full = self::scalar( $value );

		return '<abbr title="' . esc_attr( $full ) . '">' . esc_html( mb_substr( $full, 0, self::RUN_ID_LENGTH ) ) . '</abbr>';
	}

	/**
	 * A status value.
	 *
	 * @param mixed $value Status.
	 */
	private static function status( mixed $value ): string {
		$status = is_string( $value ) ? CreatorStatus::tryFrom( $value ) : null;

		if ( null === $status ) {
			return self::scalar( $value );
		}

		return CreatorStatus::Active === $status ? __( 'Active', 'merchboard' ) : __( 'Inactive', 'merchboard' );
	}

	/**
	 * A list of field names.
	 *
	 * @param mixed $value Field names.
	 */
	private static function fields( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return self::scalar( $value );
		}

		$names = array_map( static fn( mixed $field ): string => is_string( $field ) ? sanitize_key( $field ) : '…', $value );

		return implode(
			', ',
			array_map(
				static fn( string $field ): string => match ( $field ) {
					'display_name' => __( 'display name', 'merchboard' ),
					'bio'          => __( 'bio', 'merchboard' ),
					'social_links' => __( 'social links', 'merchboard' ),
					default        => $field,
				},
				$names
			)
		);
	}

	/**
	 * Positive integer ids among the given keys of an array.
	 *
	 * @param array<mixed>      $data Data.
	 * @param array<int|string> $keys Keys.
	 * @return list<int>
	 */
	private static function ids( array $data, array $keys ): array {
		$ids = array();

		foreach ( $keys as $key ) {
			$id = $data[ $key ] ?? null;

			if ( is_int( $id ) && $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * A setting value (`previous_value`, `value`): a flat list of scalars is
	 * shown as "a, b", an empty list as "—"; nested or associative arrays stay
	 * hidden ("…"), like everywhere else. The whole text is capped. Plain text.
	 *
	 * @param mixed $value Value.
	 */
	private static function setting_value( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return self::scalar( $value );
		}

		if ( array() === $value ) {
			return '—';
		}

		if ( ! array_is_list( $value ) ) {
			return '…';
		}

		$items = array();

		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				return '…';
			}

			$items[] = is_bool( $item ) ? ( $item ? 'true' : 'false' ) : (string) $item;
		}

		$text = implode( ', ', $items );

		return mb_strlen( $text ) > self::MAX_VALUE_LENGTH ? mb_substr( $text, 0, self::MAX_VALUE_LENGTH ) . '…' : $text;
	}

	/**
	 * A short scalar value; nested data is not shown. Plain text.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return '—';
		}

		if ( ! is_scalar( $value ) ) {
			return '…';
		}

		$text = (string) $value;

		return mb_strlen( $text ) > self::MAX_VALUE_LENGTH ? mb_substr( $text, 0, self::MAX_VALUE_LENGTH ) . '…' : $text;
	}
}
