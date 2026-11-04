<?php
/**
 * Filling in missing pricing snapshots of a creator's order lines.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-090 (the owner's choice 2026-11-04, an exception to ADR-051): a
 * creator's order lines whose payout is unknown get their pricing snapshot
 * filled in after an administrator has seen them on the creator's page.
 *
 * - A snapshot with a share but no VAT status gets the status only.
 * - A line without a snapshot gets one with the share that applies to its
 *   product now (MarkupResolver); a snapshot of ADR-047's withdrawn model
 *   (no share) is replaced the same way.
 * - The VAT status is the creator's status by the VAT history on the sale
 *   date, otherwise "not VAT registered", unless the administrator chooses
 *   one status for every line.
 *
 * Never touched: a line with a settled row (issued documents never change),
 * a designer product's line (its price belongs to its version), a snapshot
 * of another creator, order, product or currency, and a line whose ledger
 * rows lack their stored tax (the amount paid incl. VAT is unknown, so the
 * payout stays unknown). Other creators' lines are never read.
 */
class SalesLinePricingBackfill {

	/**
	 * Most order lines one plan holds.
	 */
	public const MAX_LINES = 500;

	/**
	 * Constructor.
	 *
	 * @param wpdb                        $wpdb      Database.
	 * @param SalesLinePricingRepository  $snapshots Snapshots.
	 * @param MarkupResolver              $markups   The share that applies now.
	 * @param PricingProductStore         $products  Store price decimals.
	 * @param CreatorVatHistoryRepository $history   The creators' VAT history (and the creator lock).
	 * @param AuditTrail                  $audit     Audit.
	 * @param Transaction                 $transaction Transaction.
	 * @param DesignerPriceSource|null    $designer  Designer products' prices; null: none.
	 * @param Closure|null                $start     The creator's settlement start (PayoutRepository::settlement_start()); null: the whole history.
	 * @phpstan-param (Closure(int): ?DateTimeImmutable)|null $start
	 */
	public function __construct(
		private wpdb $wpdb,
		private SalesLinePricingRepository $snapshots,
		private MarkupResolver $markups,
		private PricingProductStore $products,
		private CreatorVatHistoryRepository $history,
		private AuditTrail $audit,
		private Transaction $transaction,
		private ?DesignerPriceSource $designer = null,
		private ?Closure $start = null
	) {
	}

	/**
	 * The creator's order lines with a ledger row whose payout is unknown in
	 * the balance, oldest first, each with what filling in would do. The lines
	 * are chosen exactly as LedgerCreatorPayouts::earned() counts unknown
	 * rows: the settlement start's window, creator-code exclusions and the
	 * known rule with the start (30.15 review), so history before the start is
	 * never listed.
	 *
	 * @param int $creator_id Creator.
	 * @return list<PricingBackfillLine>
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	public function plan( int $creator_id ): array {
		if ( $creator_id < 1 ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$start = null === $this->start ? null : ( $this->start )( $creator_id );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Constant SQL fragments of the ledger classes (their placeholders are tables and the window's start); every value is a placeholder.
		$sql = $wpdb->prepare(
			"SELECT r.order_item_id, MIN( r.order_id ) AS order_id, MIN( r.product_id ) AS product_id, MIN( r.variation_id ) AS variation_id,
				MIN( r.item_name ) AS item_name, MIN( r.currency ) AS currency, COUNT( DISTINCT r.currency ) AS currencies,
				MIN( CASE WHEN r.type = 'sale' THEN r.occurred_at END ) AS sold_at, MIN( r.occurred_at ) AS first_at,
				SUM( CASE WHEN r.type = 'sale' THEN r.net_amount ELSE 0 END ) AS net,
				SUM( CASE WHEN r.type = 'sale' THEN COALESCE( t.tax_amount, 0 ) ELSE 0 END ) AS tax,
				SUM( CASE WHEN t.event_key IS NULL OR t.currency <> r.currency THEN 1 ELSE 0 END ) AS no_tax,
				SUM( CASE WHEN s.id IS NULL THEN 1 ELSE 0 END ) AS no_sale,
				SUM( CASE WHEN d.event_key IS NOT NULL THEN 1 ELSE 0 END ) AS settled
			" . LedgerCreatorPayouts::FROM . ' LEFT JOIN %i d ON d.event_key = r.event_key' . OrderExclusionRepository::JOIN . '
			WHERE r.creator_id = %d AND ' . LedgerCreatorPayouts::COUNTED . LedgerCreatorPayouts::window( $start ) . '
			GROUP BY r.order_item_id
			HAVING SUM( CASE WHEN ' . LedgerCreatorPayouts::known( $start ) . ' THEN 0 ELSE 1 END ) > 0
			ORDER BY first_at, r.order_item_id
			LIMIT %d',
			...array_merge(
				array(
					Tables::name( $wpdb, Tables::SALES ),
					Tables::name( $wpdb, Tables::SALES_LINE_PRICING ),
					Tables::name( $wpdb, Tables::SALES_TAX ),
					Tables::name( $wpdb, Tables::SALES ),
					Tables::name( $wpdb, Tables::SETTLEMENT_ROWS ),
					OrderExclusionRepository::table_name( $wpdb ),
					$creator_id,
				),
				LedgerCreatorPayouts::window_args( $start ),
				array( self::MAX_LINES )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; read for an administrator's page only.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the order lines without a complete pricing snapshot.' );
		}

		$lines = array();

		foreach ( $rows as $row ) {
			$row = (array) $row;

			// Only with a start does a row without its sale row stay unknown (it cannot be placed).
			if ( null === $start ) {
				$row['no_sale'] = 0;
			}

			$lines[] = $this->line( $creator_id, $row );
		}

		return $lines;
	}

	/**
	 * A fingerprint of a plan: a page shown for another plan is refused.
	 *
	 * @phpstan-param list<PricingBackfillLine> $lines
	 *
	 * @param PricingBackfillLine[] $lines Plan.
	 */
	public static function fingerprint( array $lines ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				array_map(
					static fn( PricingBackfillLine $line ): array => array( $line->order_item_id, $line->outcome, $line->reason, $line->share_bps, $line->share_source?->value, $line->price_decimals, $line->history_vat, $line->net_units, $line->tax_units ),
					$lines
				)
			)
		);
	}

	/**
	 * Fills in the snapshots of the plan the administrator saw, in one
	 * transaction under the creator's lock, and audits it.
	 *
	 * @param int       $creator_id  Creator.
	 * @param string    $fingerprint The fingerprint of the plan shown.
	 * @param bool|null $chosen      One VAT status for every line; null: by the history.
	 * @return array{vat: int, snapshot: int, withdrawn: int} What was filled in.
	 * @throws PricingException When not authorized or the plan changed. A DatabaseException rolls everything back.
	 */
	public function apply( int $creator_id, string $fingerprint, ?bool $chosen ): array {
		if ( ! current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) ) {
			throw new PricingException( PricingException::NOT_AUTHORIZED );
		}

		$done = $this->transaction->run(
			function () use ( $creator_id, $fingerprint, $chosen ): array {
				if ( ! $this->history->lock_creator( $creator_id ) ) {
					throw new PricingException( PricingException::CREATOR_NOT_FOUND );
				}

				$lines = $this->plan( $creator_id );

				if ( ! hash_equals( self::fingerprint( $lines ), $fingerprint ) ) {
					throw new PricingException( PricingException::BACKFILL_CHANGED );
				}

				$done  = array(
					PricingBackfillLine::VAT       => 0,
					PricingBackfillLine::SNAPSHOT  => 0,
					PricingBackfillLine::WITHDRAWN => 0,
				);
				$items = array();
				$now   = PricingRows::now();

				foreach ( $lines as $line ) {
					if ( ! $line->acts() ) {
						continue;
					}

					$vat    = $line->vat( $chosen );
					$stored = PricingBackfillLine::VAT === $line->outcome
						? $this->snapshots->fill_vat( $line->order_item_id, $vat )
						: $this->store( $creator_id, $line, $vat, $now );

					if ( ! $stored ) {
						throw new DatabaseException( 'A pricing snapshot changed while it was filled in.' );
					}

					++$done[ $line->outcome ];
					$items[] = $line->order_item_id;
				}

				if ( array() !== $items ) {
					$this->audit->record(
						AuditAction::SalesLinePricingBackfilled,
						$creator_id,
						array(
							'vat_filled'         => $done[ PricingBackfillLine::VAT ],
							'snapshots_created'  => $done[ PricingBackfillLine::SNAPSHOT ],
							'withdrawn_replaced' => $done[ PricingBackfillLine::WITHDRAWN ],
							'vat_source'         => null === $chosen ? 'history' : ( $chosen ? 'registered' : 'not_registered' ),
							'order_item_ids'     => $items,
						)
					);
				}

				return $done;
			}
		);

		return array(
			'vat'       => (int) ( $done[ PricingBackfillLine::VAT ] ?? 0 ),
			'snapshot'  => (int) ( $done[ PricingBackfillLine::SNAPSHOT ] ?? 0 ),
			'withdrawn' => (int) ( $done[ PricingBackfillLine::WITHDRAWN ] ?? 0 ),
		);
	}

	/**
	 * Stores a new snapshot, or replaces a withdrawn one, with the plan's share.
	 *
	 * @param int                 $creator_id Creator.
	 * @param PricingBackfillLine $line       Line that acts.
	 * @param bool                $vat        VAT status.
	 * @param DateTimeImmutable   $now        Capture time (it shows the snapshot was filled in later).
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function store( int $creator_id, PricingBackfillLine $line, bool $vat, DateTimeImmutable $now ): bool {
		if ( ! $line->acts() ) {
			return false;
		}

		$snapshot = new SalesLinePricing(
			$line->order_item_id,
			$line->order_id,
			$creator_id,
			$line->product_id,
			$line->variation_id,
			SnapshotStatus::Share,
			$line->currency,
			$now,
			$line->share_bps,
			$line->share_source,
			$line->price_decimals,
			$vat
		);

		return PricingBackfillLine::WITHDRAWN === $line->outcome ? $this->snapshots->replace_withdrawn( $snapshot ) : $this->snapshots->insert( $snapshot );
	}

	/**
	 * A plan line from a ledger row group.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $row        Grouped ledger rows of one order line.
	 * @throws DatabaseException When a value does not match the schema.
	 */
	private function line( int $creator_id, array $row ): PricingBackfillLine {
		try {
			$item      = (int) $row['order_item_id'];
			$product   = (int) $row['product_id'];
			$variation = (int) $row['variation_id'];
			$currency  = (string) $row['currency'];
			$sold      = is_string( $row['sold_at'] ?? null ) ? $row['sold_at'] : ( is_string( $row['first_at'] ?? null ) ? $row['first_at'] : null );
			$sold_at   = null === $sold ? null : new DateTimeImmutable( $sold, new DateTimeZone( 'UTC' ) );
			$base      = array( $item, (int) $row['order_id'], $product, $variation, (string) $row['item_name'], $currency, is_string( $row['sold_at'] ?? null ) ? $sold_at : null, SalesAmount::parse( (string) $row['net'] ), SalesAmount::parse( (string) $row['tax'] ) );
		} catch ( Throwable ) {
			throw new DatabaseException( 'An order line does not match the schema.' );
		}

		$skip = static fn( string $reason ): PricingBackfillLine => new PricingBackfillLine( ...array_merge( $base, array( PricingBackfillLine::SKIP, $reason ) ) );

		if ( (int) $row['settled'] > 0 ) {
			return $skip( PricingBackfillLine::SETTLED );
		}

		if ( (int) $row['no_tax'] > 0 || 1 !== (int) $row['currencies'] ) {
			return $skip( PricingBackfillLine::NO_TAX );
		}

		if ( (int) $row['no_sale'] > 0 ) {
			return $skip( PricingBackfillLine::NO_SALE );
		}

		try {
			$snapshot = $this->snapshots->find( $item );
		} catch ( DatabaseException ) {
			return $skip( PricingBackfillLine::UNREADABLE );
		}

		$vat = null === $sold_at ? null : $this->history->at( $creator_id, $sold_at->setTimezone( wp_timezone() )->format( 'Y-m-d' ) )?->vat_registered;

		if ( null !== $snapshot ) {
			if ( $snapshot->creator_id !== $creator_id || $snapshot->order_id !== (int) $row['order_id'] || $snapshot->product_id !== $product || $snapshot->variation_id !== $variation || $snapshot->currency !== $currency ) {
				return $skip( PricingBackfillLine::MISMATCH );
			}

			if ( SnapshotStatus::Designer === $snapshot->status ) {
				return $skip( PricingBackfillLine::DESIGNER );
			}

			if ( $snapshot->has_share() ) {
				return null === $snapshot->vat_registered
					? new PricingBackfillLine( ...array_merge( $base, array( PricingBackfillLine::VAT, null, $snapshot->share_bps, $snapshot->share_source, $snapshot->price_decimals, $vat ) ) )
					: $skip( PricingBackfillLine::OTHER );
			}
		}

		if ( null !== $this->designer?->price( $product, $variation ) ) {
			return $skip( PricingBackfillLine::DESIGNER );
		}

		$markup = $this->markups->resolve( $product );

		// A share of 0 % would make the payout a known 0 without a warning: set the markup first (30.15 review).
		if ( null === $markup || 0 === $markup->markup->bps ) {
			return $skip( PricingBackfillLine::NO_MARKUP );
		}

		if ( $markup->creator_id !== $creator_id ) {
			return $skip( PricingBackfillLine::MISMATCH );
		}

		return new PricingBackfillLine( ...array_merge( $base, array( null === $snapshot ? PricingBackfillLine::SNAPSHOT : PricingBackfillLine::WITHDRAWN, null, $markup->markup->bps, $markup->source, $this->products->decimals(), $vat ) ) );
	}
}
