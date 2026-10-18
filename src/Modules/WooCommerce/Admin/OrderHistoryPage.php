<?php
/**
 * Admin page: the order history stored in Merch Dashboard's sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusion;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionRepository;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionStatus;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\PayoutGiftCardOrders;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupSource;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitGap;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitSummary;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricing;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistory;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistoryDetails;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistoryFilter;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistoryRow;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleEventType;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Merch Dashboard → Order history (ADR-051): what the sales ledger stores,
 * for administrators with `merchboard_manage_creators` (the capability that
 * already shows creators' sales in wp-admin). Read only.
 *
 * - **List:** one row per unique order (OrderHistory: grouped by order id,
 *   paginated and filtered on the server), with dates, creators, currency,
 *   number of ledger events and order lines, the stored net amount, how many
 *   rows have stored tax, and the profit status (complete, incomplete or
 *   unknown, with known/unknown row counts).
 * - **Order:** `?order=<id>` shows the order's ledger events, its order lines
 *   with their pricing snapshot and its cycles (OrderHistoryDetails), each
 *   event with its profit or the reason it is unknown (ProfitGap), plus the
 *   current WooCommerce status via CRUD.
 * - **Settlement (ADR-060):** the list shows how many of an order's ledger
 *   rows are settled and in which settlement documents; the order shows the
 *   document of each event. Read from the stored documents, never
 *   recalculated. Links to the payout only with `merchboard_manage_payouts`.
 * - **Payout gift cards (G1):** an order the gift card plugin recorded as
 *   paid with a card of a completed payout is marked in the list and
 *   explained on the order; its commission basis is not decided, so the
 *   ledger is unchanged. When the plugin cannot be read, that is said.
 *
 * No customer data and no item names (ADR-037 pkt. 10). Every request
 * parameter is validated; invalid values are ignored with a notice. The page
 * itself writes nothing: the ledger, snapshots and tax rows are append-only
 * (ADR-037/038/047/048). The one exception is the deletion of an order's
 * history (ADR-052), offered on the order's page to users with
 * `merchboard_delete_order_history` when the WooCommerce order is in the
 * trash or deleted, and handled by OrderHistoryActions (admin-post).
 */
final class OrderHistoryPage {

	public const SLUG = 'merchboard-order-history';

	public const PER_PAGE = 20;

	/**
	 * `status` value that shows every stored order, also those without profit data.
	 */
	public const ALL = 'all';

	/**
	 * `admin_menu` priority: after Settings (20), before the audit log (30).
	 */
	public const PRIORITY = 25;

	/**
	 * Creator names by id (per request).
	 *
	 * @var array<int, string>
	 */
	private array $names = array();

	/**
	 * Orders of the current list paid with a payout gift card: payout ids by order (G1).
	 *
	 * @var array<int, list<int>>
	 */
	private array $paid_with_cards = array();

	/**
	 * Decisions of the current list's orders excluded by a creator code (ADR-068).
	 *
	 * @var array<int, OrderExclusion>
	 */
	private array $excluded = array();

	/**
	 * Constructor.
	 *
	 * @param OrderHistory                  $history     Unique orders of the ledger.
	 * @param OrderHistoryDetails           $details     One order's events, lines and cycles.
	 * @param OrderStatusLookup             $orders      Current WooCommerce state (CRUD).
	 * @param CreatorRepository             $creators    Creator names.
	 * @param Logger                        $logger      Technical log.
	 * @param SettlementRepository|null     $settlements Settled ledger rows (ADR-060, read only); null = not shown.
	 * @param PayoutGiftCardOrders|null     $gift_cards  Orders paid with payout gift cards (G1, read only); null = not shown.
	 * @param OrderExclusionRepository|null $exclusions  Orders excluded by a creator code (ADR-068, read only); null = not shown.
	 */
	public function __construct(
		private OrderHistory $history,
		private OrderHistoryDetails $details,
		private OrderStatusLookup $orders,
		private CreatorRepository $creators,
		private Logger $logger,
		private ?SettlementRepository $settlements = null,
		private ?PayoutGiftCardOrders $gift_cards = null,
		private ?OrderExclusionRepository $exclusions = null
	) {
	}

	/**
	 * Adds the submenu page. Hooked on `admin_menu` by the module with
	 * PRIORITY (after Settings, before the audit log).
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Order History', 'merchboard' ),
			__( 'Order history', 'merchboard' ),
			Capabilities::MANAGE_CREATORS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * URL of the page.
	 *
	 * @param array<string, int|string> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Validated request parameters.
	 *
	 * @param array<mixed> $input Unslashed GET parameters.
	 * @return array{order: int|null, filter: OrderHistoryFilter, page: int, invalid: list<string>, values: array<string, string>, erased: int|null, erase_error: string|null}
	 */
	public static function parameters( array $input ): array {
		$invalid = array();
		$values  = array();
		$number  = static function ( string $key ) use ( $input, &$invalid, &$values ): ?int {
			$raw = is_scalar( $input[ $key ] ?? null ) ? trim( (string) $input[ $key ] ) : '';

			if ( '' === $raw ) {
				return null;
			}

			if ( 1 !== preg_match( '/^[1-9]\d{0,18}$/', $raw ) ) {
				$invalid[] = $key;
				return null;
			}

			$values[ $key ] = $raw;

			return (int) $raw;
		};

		$order      = $number( 'order' );
		$order_id   = $number( 'order_id' );
		$creator_id = $number( 'creator' );
		$page       = $number( 'paged' ) ?? 1;
		$status     = is_scalar( $input['status'] ?? null ) ? sanitize_key( (string) $input['status'] ) : '';

		if ( '' !== $status && self::ALL !== $status && ! in_array( $status, OrderHistoryFilter::STATUSES, true ) ) {
			$invalid[] = 'status';
			$status    = '';
		} elseif ( '' !== $status ) {
			$values['status'] = $status;
		}

		// Without a choice, orders without any profit data (from before it was
		// stored) are hidden; "all" shows them. Nothing is deleted.
		$status = '' === $status ? OrderHistoryFilter::WITH_PROFIT_DATA : $status;

		$erased = $number( 'erased' );
		$error  = is_scalar( $input['erase_error'] ?? null ) ? sanitize_key( (string) $input['erase_error'] ) : '';
		unset( $values['erased'] );

		return array(
			'order'       => $order,
			'filter'      => new OrderHistoryFilter( $order_id, $creator_id, self::ALL === $status ? null : $status ),
			'page'        => min( $page, 1000000 ),
			'invalid'     => $invalid,
			'values'      => $values,
			'erased'      => $erased,
			'erase_error' => '' === $error ? null : $error,
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view the order history.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$params = self::parameters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view; every parameter is validated in parameters().

		echo '<div class="wrap merchboard-order-history"><h1>' . esc_html__( 'Order history', 'merchboard' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'What Merch Dashboard has stored in its sales ledger, per WooCommerce order. One row per order; open an order to see its ledger events, order lines and why its profit is complete or not. The stored history is append-only; only the history of an order that is in the WooCommerce trash or deleted (e.g. a test order) can be deleted, on the order\'s page.', 'merchboard' ) . '</p>';

		if ( null !== $params['erased'] ) {
			/* translators: %d: order id. */
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( 'The stored history of order #%d was deleted. The WooCommerce order was not changed.', 'merchboard' ), $params['erased'] ) ) . '</p></div>';
		}

		if ( null !== $params['erase_error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( self::erase_error_text( $params['erase_error'] ) ) . '</p></div>';
		}

		if ( array() !== $params['invalid'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Some filter values were invalid and have been ignored.', 'merchboard' ) . '</p></div>';
		}

		try {
			if ( null !== $params['order'] ) {
				$this->render_order( $params['order'] );
			} else {
				$this->render_list( $params['filter'], $params['page'], $params['values'] );
				$this->render_exclusions();
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The order history could not be read.', array( 'error' => $e::class ) );
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The order history could not be read. Please try again later.', 'merchboard' ) . '</p></div>';
		}

		$this->render_legend();
		echo '</div>';
	}

	/**
	 * The paginated list of unique orders.
	 *
	 * @param OrderHistoryFilter    $filter Filter.
	 * @param int                   $page   Requested page.
	 * @param array<string, string> $values Valid filter values (for links).
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	private function render_list( OrderHistoryFilter $filter, int $page, array $values ): void {
		$this->render_filters( $filter );

		if ( OrderHistoryFilter::WITH_PROFIT_DATA === $filter->status ) {
			$hidden = $this->history->count( new OrderHistoryFilter( $filter->order_id, $filter->creator_id, ProfitSummary::UNKNOWN ) );

			if ( $hidden > 0 ) {
				/* translators: %s: number of orders. */
				$text = _n( '%s order without profit data is hidden: it was stored before Merch Dashboard saved profit snapshots and tax, or its lines had no creator when they were created. Nothing is deleted.', '%s orders without profit data are hidden: they were stored before Merch Dashboard saved profit snapshots and tax, or their lines had no creator when they were created. Nothing is deleted.', $hidden, 'merchboard' );
				echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( $text, number_format_i18n( $hidden ) ) ) . ' <a href="' . esc_url( self::url( array( 'status' => self::ALL ) + array_diff_key( $values, array( 'status' => true ) ) ) ) . '">' . esc_html__( 'Show all orders', 'merchboard' ) . '</a></p></div>';
			}
		}

		$total = $this->history->count( $filter );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$rows  = $total > 0 && $page <= $pages ? $this->history->page( $filter, new Pagination( $page, self::PER_PAGE ) ) : array();

		/* translators: %s: number of orders. */
		echo '<p>' . esc_html( sprintf( _n( '%s order', '%s orders', $total, 'merchboard' ), number_format_i18n( $total ) ) ) . '</p>';

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No stored orders match the filter.', 'merchboard' ) . '</p>';
			return;
		}

		$settled = null === $this->settlements ? null : $this->settlements->settlements_for_orders( array_map( static fn( OrderHistoryRow $row ): int => $row->order_id, $rows ), $filter->creator_id );
		$cards   = $this->gift_card_lookup( array_map( static fn( OrderHistoryRow $row ): int => $row->order_id, $rows ) );

		$this->paid_with_cards = $cards['paid'] ?? array();
		$this->excluded        = null === $this->exclusions ? array() : $this->exclusions->for_orders( array_map( static fn( OrderHistoryRow $row ): int => $row->order_id, $rows ) );

		if ( true === ( $cards['unsupported'] ?? false ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Gift card plugin does not support order registration lookup', 'merchboard' ) . '</p></div>';
		}

		if ( true === ( $cards['failed'] ?? false ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Gift card order registration could not be checked', 'merchboard' ) . '</p></div>';
		}

		echo '<table class="widefat striped merchboard-order-history-table"><thead><tr>';

		foreach ( array_merge( array( __( 'Order', 'merchboard' ), __( 'Ledger dates', 'merchboard' ), __( 'Creator', 'merchboard' ), __( 'Currency', 'merchboard' ), __( 'Events / lines', 'merchboard' ), __( 'Stored net amount', 'merchboard' ), __( 'Tax data', 'merchboard' ), __( 'Profit data', 'merchboard' ) ), null === $settled ? array() : array( __( 'Settlement', 'merchboard' ) ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$this->render_row(
				$row,
				null === $settled ? null : ( $settled[ $row->order_id ] ?? array(
					'rows'      => 0,
					'documents' => array(),
				) )
			);
		}

		echo '</tbody></table>';

		$links = paginate_links(
			array(
				'base'      => self::url( $values + array( 'paged' => '%#%' ) ),
				'format'    => '',
				'current'   => min( $page, $pages ),
				'total'     => $pages,
				'prev_text' => __( '&laquo; Previous', 'merchboard' ),
				'next_text' => __( 'Next &raquo;', 'merchboard' ),
			)
		);

		if ( is_string( $links ) && '' !== $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	/**
	 * One order of the list.
	 *
	 * @param OrderHistoryRow                                                                    $row     Order.
	 * @param array{rows: int, documents: list<array{number: string, payout_id: int|null}>}|null $settled Its settled rows; null = not shown.
	 */
	private function render_row( OrderHistoryRow $row, ?array $settled = null ): void {
		$order = $this->orders->find( $row->order_id );

		echo '<tr><td><a href="' . esc_url( self::url( array( 'order' => $row->order_id ) ) ) . '">' . esc_html( sprintf( '#%d', $row->order_id ) ) . '</a>';
		echo '<br><span class="description">' . esc_html( $order['status'] ) . '</span>';

		if ( isset( $this->paid_with_cards[ $row->order_id ] ) ) {
			echo '<br><strong>' . esc_html__( 'Registered by gift card plugin — payout status not confirmed', 'merchboard' ) . '</strong>';
		}

		if ( isset( $this->excluded[ $row->order_id ] ) ) {
			echo '<br><strong>' . esc_html( self::exclusion_label( $this->excluded[ $row->order_id ] ) ) . '</strong>';
		}

		echo '</td>';
		echo '<td>' . esc_html( self::date( $row->first_at ) ) . ( $row->first_at->getTimestamp() === $row->last_at->getTimestamp() ? '' : '<br>– ' . esc_html( self::date( $row->last_at ) ) ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', array_map( array( $this, 'creator_name' ), $row->creator_ids ) ) ) . '</td>';
		echo '<td>' . esc_html( $row->currency ) . '</td>';
		/* translators: 1: number of ledger events, 2: number of order lines. */
		echo '<td>' . esc_html( sprintf( __( '%1$d events / %2$d lines', 'merchboard' ), $row->events, $row->lines ) ) . '</td>';
		echo '<td>' . esc_html( CreatorSalesSection::money( $row->net_amount, $row->currency ) ) . '</td>';
		/* translators: 1: rows with stored tax, 2: all rows. */
		echo '<td>' . esc_html( sprintf( __( '%1$d of %2$d rows', 'merchboard' ), $row->taxed_rows, $row->events ) ) . '</td>';
		echo '<td>' . self::profit_cell( $row->profit ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in profit_cell().

		if ( null !== $settled ) {
			if ( 0 === $settled['rows'] ) {
				echo '<td>' . esc_html__( 'Not settled', 'merchboard' ) . '</td>';
			} else {
				/* translators: 1: settled ledger rows, 2: all ledger rows. */
				echo '<td>' . esc_html( sprintf( __( '%1$d of %2$d rows', 'merchboard' ), $settled['rows'], $row->events ) ) . '<br>' . implode( ', ', array_map( array( self::class, 'document_link' ), $settled['documents'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in document_link().
			}
		}

		echo '</tr>';
	}

	/**
	 * The filter form (GET).
	 *
	 * @param OrderHistoryFilter $filter Current filter.
	 */
	private function render_filters( OrderHistoryFilter $filter ): void {
		echo '<form method="get" class="merchboard-order-history-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label for="merchboard-oh-order">' . esc_html__( 'Order ID', 'merchboard' ) . '</label> ';
		echo '<input type="text" inputmode="numeric" pattern="[0-9]*" id="merchboard-oh-order" name="order_id" class="small-text" value="' . esc_attr( null === $filter->order_id ? '' : (string) $filter->order_id ) . '"> ';
		echo '<label for="merchboard-oh-creator">' . esc_html__( 'Creator', 'merchboard' ) . '</label> ';
		echo '<select id="merchboard-oh-creator" name="creator"><option value="">' . esc_html__( 'All creators', 'merchboard' ) . '</option>';

		foreach ( $this->creators->all() as $creator ) {
			echo '<option value="' . esc_attr( (string) $creator->id ) . '"' . selected( $filter->creator_id, $creator->id, false ) . '>' . esc_html( $creator->display_name ) . '</option>';
		}

		echo '</select> ';
		echo '<label for="merchboard-oh-status">' . esc_html__( 'Profit data', 'merchboard' ) . '</label> ';
		echo '<select id="merchboard-oh-status" name="status"><option value="' . esc_attr( self::ALL ) . '"' . selected( $filter->status, null, false ) . '>' . esc_html__( 'All orders', 'merchboard' ) . '</option>';

		foreach ( OrderHistoryFilter::STATUSES as $status ) {
			echo '<option value="' . esc_attr( $status ) . '"' . selected( $filter->status, $status, false ) . '>' . esc_html( self::status_label( $status ) ) . '</option>';
		}

		echo '</select> <button type="submit" class="button">' . esc_html__( 'Filter', 'merchboard' ) . '</button> ';
		echo '<a class="button-link" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Reset', 'merchboard' ) . '</a>';
		echo '</form>';
	}

	/**
	 * One order's stored history.
	 *
	 * @param int $order_id Order id.
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	private function render_order( int $order_id ): void {
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← All orders', 'merchboard' ) . '</a></p>';

		// Also for an excluded order without ledger rows (ADR-068).
		$this->render_exclusion_notice( $order_id );

		$details = $this->details->for_order( $order_id );

		if ( null === $details ) {
			/* translators: %d: order id. */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'Merch Dashboard has no stored history for order #%d.', 'merchboard' ), $order_id ) ) . '</p></div>';
			return;
		}

		$order = $this->orders->find( $order_id );

		/* translators: %d: order id. */
		echo '<h2>' . esc_html( sprintf( __( 'Order #%d', 'merchboard' ), $order_id ) ) . '</h2>';
		echo '<p>' . esc_html__( 'WooCommerce:', 'merchboard' ) . ' ' . esc_html( $order['status'] );

		if ( null !== $order['edit_url'] ) {
			echo ' · <a href="' . esc_url( $order['edit_url'] ) . '">' . esc_html__( 'Open in WooCommerce', 'merchboard' ) . '</a>';
		}

		echo '</p>';
		$this->render_payout_card( $order_id );

		$cycles = array_map(
			static fn( $event ): string => sprintf(
				/* translators: 1: cycle number, 2: open or closed, 3: date. */
				__( 'Cycle %1$d %2$s %3$s', 'merchboard' ),
				$event->cycle,
				SalesCycleEventType::Open === $event->event ? __( 'opened', 'merchboard' ) : __( 'closed', 'merchboard' ),
				self::date( $event->occurred_at )
			),
			$details['cycles']->events
		);
		echo '<p class="description">' . esc_html( array() === $cycles ? __( 'No cycle events.', 'merchboard' ) : implode( ' · ', $cycles ) ) . '</p>';

		echo '<h3>' . esc_html__( 'Order lines', 'merchboard' ) . '</h3>';
		echo '<table class="widefat striped merchboard-order-history-lines"><thead><tr>';

		foreach ( array( __( 'Order line', 'merchboard' ), __( 'Events', 'merchboard' ), __( 'Stored net amount', 'merchboard' ), __( 'Profit snapshot', 'merchboard' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';
		$currency = $details['events'][0]['entry']->currency;

		foreach ( $details['lines'] as $item => $line ) {
			echo '<tr><td>' . esc_html( sprintf( '#%d', $item ) ) . '</td><td>' . esc_html( (string) $line['events'] ) . '</td>';
			echo '<td>' . esc_html( CreatorSalesSection::money( SalesAmount::format( $line['net'] ), $currency ) ) . '</td>';
			echo '<td>' . esc_html( self::snapshot_text( $line['snapshot'], $line['invalid_snapshot'] ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Ledger events', 'merchboard' ) . '</h3>';
		echo '<table class="widefat striped merchboard-order-history-events"><thead><tr>';

		$settled = null === $this->settlements ? null : $this->settlements->settlements_for_events( array_map( static fn( array $event ): string => $event['entry']->event_key, $details['events'] ) );

		foreach ( array_merge( array( __( 'Date', 'merchboard' ), __( 'Event', 'merchboard' ), __( 'Creator', 'merchboard' ), __( 'Order line', 'merchboard' ), __( 'Product', 'merchboard' ), __( 'Quantity', 'merchboard' ), __( 'Stored net amount', 'merchboard' ), __( 'Stored tax', 'merchboard' ), __( 'Profit', 'merchboard' ) ), null === $settled ? array() : array( __( 'Settlement', 'merchboard' ) ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $details['events'] as $event ) {
			$entry     = $event['entry'];
			$diagnosis = $event['diagnosis'];
			$product   = 0 === $entry->variation_id ? sprintf( '#%d', $entry->product_id ) : sprintf( '#%1$d / #%2$d', $entry->product_id, $entry->variation_id );

			echo '<tr><td>' . esc_html( self::date( $entry->occurred_at ) ) . '</td>';
			echo '<td>' . esc_html( $entry->type->value . ( null === $entry->correction_reason ? '' : ' (' . $entry->correction_reason->value . ')' ) ) . '<br><span class="description"><code>' . esc_html( $entry->event_key ) . '</code></span></td>';
			echo '<td>' . esc_html( $this->creator_name( $entry->creator_id ) ) . '</td>';
			echo '<td>' . esc_html( sprintf( '#%d', $entry->order_item_id ) ) . '</td>';
			echo '<td>' . esc_html( $product ) . '</td>';
			echo '<td>' . esc_html( (string) $entry->quantity ) . '</td>';
			echo '<td>' . esc_html( CreatorSalesSection::money( $entry->net_amount, $entry->currency ) ) . '</td>';
			echo '<td>' . esc_html( null === $event['tax'] ? __( 'Missing', 'merchboard' ) : CreatorSalesSection::money( SalesAmount::format( $event['tax']['tax'] ), $event['tax']['currency'] ) ) . '</td>';
			echo '<td>' . ( null === $diagnosis->profit
				? '<strong>' . esc_html__( 'Unknown', 'merchboard' ) . '</strong><br><span class="description">' . esc_html( self::gap_text( $diagnosis->gap ?? ProfitGap::Other ) ) . '</span>'
				: esc_html( CreatorSalesSection::money( SalesAmount::format( $diagnosis->profit ), $entry->currency ) ) ) . '</td>';

			if ( null !== $settled ) {
				$document = $settled[ $entry->event_key ] ?? null;
				echo '<td>' . ( null === $document ? esc_html__( 'Not settled', 'merchboard' ) : self::document_link( $document ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in document_link().
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
		$this->render_erase( $order_id );
	}

	/**
	 * Deletion of the order's stored history (ADR-052), only for
	 * `merchboard_delete_order_history`, and only offered when the WooCommerce
	 * order is in the trash or deleted. The handler checks everything again.
	 *
	 * @param int $order_id Order id.
	 */
	private function render_erase( int $order_id ): void {
		if ( ! current_user_can( Capabilities::DELETE_ORDER_HISTORY ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Delete stored history', 'merchboard' ) . '</h3>';

		if ( null === $this->orders->erasable_state( $order_id ) ) {
			echo '<p class="description">' . esc_html__( 'The stored history can only be deleted when the WooCommerce order is in the trash or deleted, e.g. a test order. Move the order to the trash in WooCommerce first.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Permanently deletes what Merch Dashboard has stored for this order: its ledger rows, cycle events, stored tax and profit snapshots. The order disappears from the creator\'s sales, statistics and profit. The WooCommerce order is not changed. If it is restored from the trash later, it is registered again without profit data. This cannot be undone.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( OrderHistoryActions::ACTION ) . '">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '">';
		wp_nonce_field( OrderHistoryActions::nonce_action( $order_id ) );
		/* translators: %d: order id. */
		echo '<p><label for="merchboard-oh-confirm">' . esc_html( sprintf( __( 'Type the order ID (%d) to confirm', 'merchboard' ), $order_id ) ) . '</label> ';
		echo '<input type="text" inputmode="numeric" autocomplete="off" required id="merchboard-oh-confirm" name="confirm_order_id" class="small-text"> ';
		echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Delete stored history', 'merchboard' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Message of a rejected deletion.
	 *
	 * @param string $code Outcome code.
	 */
	private static function erase_error_text( string $code ): string {
		return match ( $code ) {
			OrderHistoryActions::NOT_CONFIRMED           => __( 'Nothing was deleted: the typed order ID did not match.', 'merchboard' ),
			OrderHistoryEraseException::ORDER_ACTIVE   => __( 'Nothing was deleted: the WooCommerce order is not in the trash or deleted.', 'merchboard' ),
			OrderHistoryEraseException::NO_HISTORY     => __( 'Nothing was deleted: Merch Dashboard has no stored history for the order.', 'merchboard' ),
			OrderHistoryEraseException::BUSY           => __( 'Nothing was deleted: the order is being synchronized right now. Please try again.', 'merchboard' ),
			OrderHistoryEraseException::SETTLED        => __( 'Nothing was deleted: the order is part of a settlement document, so its history is kept.', 'merchboard' ),
			OrderHistoryEraseException::NOT_AUTHORIZED => __( 'Sorry, you are not allowed to delete order history.', 'merchboard' ),
			default                                    => __( 'Nothing was deleted: the order history could not be deleted. Please try again later.', 'merchboard' ),
		};
	}

	/**
	 * The status label of an exclusion (ADR-068, design decision 3).
	 *
	 * @param OrderExclusion $exclusion Decision.
	 */
	private static function exclusion_label( OrderExclusion $exclusion ): string {
		return OrderExclusionStatus::Open === $exclusion->status
			? __( 'Open case – creator discount code', 'merchboard' )
			: __( 'Excluded – creator discount code', 'merchboard' );
	}

	/**
	 * The orders excluded by a creator's own discount code (ADR-068, K3),
	 * newest first, filtered on open cases with `?exclusions=open`. Also lists
	 * excluded orders that have no ledger rows. Read only.
	 *
	 * @throws DatabaseException When the decisions cannot be read.
	 */
	private function render_exclusions(): void {
		if ( null === $this->exclusions ) {
			return;
		}

		// Read-only display parameters, whitelisted; nothing is changed by this request.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$open = isset( $_GET['exclusions'] ) && 'open' === sanitize_key( wp_unslash( $_GET['exclusions'] ) );
		$page = isset( $_GET['exclusions_page'] ) ? max( 1, absint( wp_unslash( $_GET['exclusions_page'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$status     = $open ? OrderExclusionStatus::Open : null;
		$total      = $this->exclusions->count( $status );
		$open_count = $open ? $total : $this->exclusions->count( OrderExclusionStatus::Open );

		echo '<h2 id="merchboard-exclusions">' . esc_html__( 'Orders excluded by creator discount codes', 'merchboard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'An order that used a creator\'s own discount code counts nowhere: not in sales, profit, payouts or new settlement documents. The decision is made once and is permanent. An open case is an order that was already settled; its settled rows and documents are not changed, and the case is not resolved automatically.', 'merchboard' ) . '</p>';
		echo '<p>';
		echo $open ? '<a href="' . esc_url( self::url() . '#merchboard-exclusions' ) . '">' . esc_html__( 'All excluded orders', 'merchboard' ) . '</a>' : '<strong>' . esc_html__( 'All excluded orders', 'merchboard' ) . '</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the expression.
		echo ' | ';
		/* translators: %s: number of open cases. */
		$open_text = sprintf( __( 'Open cases (%s)', 'merchboard' ), number_format_i18n( $open_count ) );
		echo $open ? '<strong>' . esc_html( $open_text ) . '</strong>' : '<a href="' . esc_url( self::url( array( 'exclusions' => 'open' ) ) . '#merchboard-exclusions' ) . '">' . esc_html( $open_text ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the expression.
		echo '</p>';

		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$rows  = $total > 0 && $page <= $pages ? $this->exclusions->page( $status, new Pagination( $page, self::PER_PAGE ) ) : array();

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No orders are excluded.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped merchboard-exclusions-table"><thead><tr>';

		foreach ( array( __( 'Order', 'merchboard' ), __( 'Status', 'merchboard' ), __( 'Creator', 'merchboard' ), __( 'Discount code', 'merchboard' ), __( 'Order created', 'merchboard' ), __( 'Decided', 'merchboard' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $exclusion ) {
			echo '<tr><td><a href="' . esc_url( self::url( array( 'order' => $exclusion->order_id ) ) ) . '">' . esc_html( sprintf( '#%d', $exclusion->order_id ) ) . '</a></td>';
			echo '<td><strong>' . esc_html( self::exclusion_label( $exclusion ) ) . '</strong>';

			if ( $exclusion->coupon_deviation ) {
				echo '<br><span class="description">' . esc_html__( 'The coupon deviated from the registration when the order was decided.', 'merchboard' ) . '</span>';
			}

			echo '</td>';
			echo '<td>' . esc_html( null === $exclusion->creator_id ? '—' : $this->creator_name( $exclusion->creator_id ) ) . '</td>';
			echo '<td><code>' . esc_html( $exclusion->code ) . '</code></td>';
			echo '<td>' . esc_html( self::date( $exclusion->order_created_at ) ) . '</td>';
			echo '<td>' . esc_html( self::date( $exclusion->assessed_at ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		$links = paginate_links(
			array(
				'base'      => self::url( ( $open ? array( 'exclusions' => 'open' ) : array() ) + array( 'exclusions_page' => '%#%' ) ) . '#merchboard-exclusions',
				'format'    => '',
				'current'   => min( $page, $pages ),
				'total'     => $pages,
				'prev_text' => __( '&laquo; Previous', 'merchboard' ),
				'next_text' => __( 'Next &raquo;', 'merchboard' ),
			)
		);

		if ( is_string( $links ) && '' !== $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	/**
	 * The exclusion of one order, if any (ADR-068).
	 *
	 * @param int $order_id Order.
	 * @throws DatabaseException When the decision cannot be read.
	 */
	private function render_exclusion_notice( int $order_id ): void {
		$exclusion = null === $this->exclusions ? null : $this->exclusions->find( $order_id );

		if ( null === $exclusion ) {
			return;
		}

		$creator = null === $exclusion->creator_id ? '—' : $this->creator_name( $exclusion->creator_id );
		$text    = OrderExclusionStatus::Open === $exclusion->status
			? __( 'The order already had rows in a settlement document. Those rows and documents are not changed; the order\'s other rows do not count. The case is not resolved automatically.', 'merchboard' )
			: __( 'None of the order\'s rows count in sales, profit, payouts or new settlement documents, and no new ledger events are stored for it.', 'merchboard' );

		/* translators: 1: creator name, 2: discount code, 3: date. */
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( self::exclusion_label( $exclusion ) ) . '</strong>: ' . esc_html( sprintf( __( 'the order used the discount code of %1$s (%2$s); decided %3$s.', 'merchboard' ), $creator, $exclusion->code, self::date( $exclusion->assessed_at ) ) ) . '</p><p>' . esc_html( $text ) . '</p>';

		if ( $exclusion->coupon_deviation ) {
			echo '<p>' . esc_html__( 'The coupon deviated from the registration when the order was decided (it was missing or had another ID). The order is excluded all the same.', 'merchboard' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Explanation of the statuses and why profit can be unknown.
	 */
	private function render_legend(): void {
		echo '<h2>' . esc_html__( 'About the profit data', 'merchboard' ) . '</h2><ul class="ul-disc">';

		foreach ( array(
			__( 'Complete: every ledger row of the order has its profit snapshot and its stored tax, so the creator profit can be calculated (ADR-049).', 'merchboard' ),
			__( 'Incomplete: some rows have the data, others do not. Unknown: no row has it. Missing history is never filled in, recalculated from current settings or shown as 0.', 'merchboard' ),
			self::gap_text( ProfitGap::NoSnapshot ),
			self::gap_text( ProfitGap::NoTax ),
			__( 'The sales ledger, profit snapshots and stored tax are append-only. The one exception: an administrator can delete the stored history of one order once the WooCommerce order is in the trash or deleted, e.g. a test order. That removes the order from the creator\'s sales and profit and is recorded in the audit log.', 'merchboard' ),
		) as $text ) {
			echo '<li>' . esc_html( $text ) . '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Profit status with counts. Escaped HTML.
	 *
	 * @param ProfitSummary $profit Summary.
	 */
	private static function profit_cell( ProfitSummary $profit ): string {
		$status = $profit->status();
		$amount = $profit->amount_units();
		$html   = '<strong>' . esc_html( self::status_label( $status ) ) . '</strong>';

		if ( null !== $amount ) {
			$html .= '<br>' . esc_html( CreatorSalesSection::money( SalesAmount::format( $amount ), $profit->currency ) );
		}

		if ( ProfitSummary::COMPLETE !== $status ) {
			/* translators: 1: rows with known profit, 2: rows without the data. */
			$html .= '<br><span class="description">' . esc_html( sprintf( __( '%1$d known, %2$d without data', 'merchboard' ), $profit->known_rows, $profit->unknown_rows ) ) . '</span>';
		}

		return $html;
	}

	/**
	 * The payout gift cards recorded on the orders (G1), or null when not
	 * shown. A lookup that throws (the payouts cannot be read, an adapter
	 * fails unexpectedly) is "could not be checked", never "not recorded" or
	 * "not supported", and does not stop the readable ledger from being shown.
	 * Only the exception's class is logged.
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int[] $order_ids Orders.
	 * @return array{paid: array<int, list<int>>, unsupported: bool, failed: bool}|null
	 */
	private function gift_card_lookup( array $order_ids ): ?array {
		if ( null === $this->gift_cards ) {
			return null;
		}

		try {
			return $this->gift_cards->for_orders( $order_ids );
		} catch ( Throwable $e ) {
			$this->logger->error( 'The payout gift cards could not be read for the order history.', array( 'error' => $e::class ) );

			return array(
				'paid'        => array(),
				'unsupported' => false,
				'failed'      => true,
			);
		}
	}

	/**
	 * What the gift card plugin itself recorded about the order and payout
	 * gift cards (G1). Shown as the plugin's record, never as a confirmed
	 * payout status (owner decision B2, 2026-10-16), whatever G1's activation
	 * and cut-off are (B3). The payout is linked only for a user with
	 * `merchboard_manage_payouts`.
	 *
	 * @param int $order_id Order.
	 */
	private function render_payout_card( int $order_id ): void {
		$cards = $this->gift_card_lookup( array( $order_id ) );

		if ( null === $cards ) {
			return;
		}

		$payouts = $cards['paid'][ $order_id ] ?? array();

		if ( array() !== $payouts ) {
			$links     = array_map(
				static function ( int $id ): string {
					/* translators: %d: payout id. */
					$label = esc_html( sprintf( __( 'Payout #%d', 'merchboard' ), $id ) );

					return current_user_can( Capabilities::MANAGE_PAYOUTS ) ? '<a href="' . esc_url( PayoutsPage::url( array( 'payout' => $id ) ) ) . '">' . $label . '</a>' : $label;
				},
				$payouts
			);
			$undecided = esc_html__( 'How such orders count towards the creator\'s commission is not decided yet (G1); the ledger, the balances and the settlement documents treat this order like any other order.', 'merchboard' );
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Registered by gift card plugin — payout status not confirmed', 'merchboard' ) . '</strong>: ' . implode( ', ', $links ) . '</p><p>' . $undecided . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The links and $undecided are escaped above.
			return;
		}

		if ( $cards['unsupported'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Gift card plugin does not support order registration lookup', 'merchboard' ) . '</p></div>';
		}

		if ( $cards['failed'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Gift card order registration could not be checked', 'merchboard' ) . '</p></div>';
		}
	}

	/**
	 * A settlement document number, linked to its payout only for a user
	 * with `merchboard_manage_payouts` (ADR-058). Escaped HTML.
	 *
	 * @param array{number: string, payout_id: int|null} $document Document.
	 */
	private static function document_link( array $document ): string {
		if ( null === $document['payout_id'] || ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			return esc_html( $document['number'] );
		}

		return '<a href="' . esc_url( PayoutsPage::url( array( 'payout' => $document['payout_id'] ) ) ) . '">' . esc_html( $document['number'] ) . '</a>';
	}

	/**
	 * Label of a profit status.
	 *
	 * @param string $status Status.
	 */
	private static function status_label( string $status ): string {
		return match ( $status ) {
			OrderHistoryFilter::WITH_PROFIT_DATA => __( 'With profit data (complete or incomplete)', 'merchboard' ),
			ProfitSummary::COMPLETE   => __( 'Complete', 'merchboard' ),
			ProfitSummary::INCOMPLETE => __( 'Incomplete', 'merchboard' ),
			default                   => __( 'Unknown', 'merchboard' ),
		};
	}

	/**
	 * Explanation of a missing piece.
	 *
	 * @param ProfitGap $gap Gap.
	 */
	public static function gap_text( ProfitGap $gap ): string {
		return match ( $gap ) {
			ProfitGap::NoSnapshot       => __( 'No profit snapshot: snapshots are taken when an order line is created and only if its product then has a creator. Order lines created before Merch Dashboard recorded snapshots, or before their product was linked to the creator, never have one.', 'merchboard' ),
			ProfitGap::InvalidSnapshot  => __( 'The stored profit snapshot of this order line cannot be read.', 'merchboard' ),
			ProfitGap::LegacySnapshot   => __( 'The snapshot is from the withdrawn pricing model and has no share of the sale price.', 'merchboard' ),
			ProfitGap::SnapshotMismatch => __( 'The snapshot belongs to another creator, product, variation, order or currency than this ledger row.', 'merchboard' ),
			ProfitGap::NoTax            => __( 'No stored tax: ledger rows registered before Merch Dashboard stored the actual tax have none, so the amount paid incl. VAT is unknown.', 'merchboard' ),
			ProfitGap::TaxCurrency      => __( 'The stored tax is in another currency than the ledger row.', 'merchboard' ),
			ProfitGap::Other            => __( 'The profit cannot be calculated from the stored data.', 'merchboard' ),
		};
	}

	/**
	 * Summary of a line's snapshot.
	 *
	 * @param SalesLinePricing|null $snapshot Snapshot.
	 * @param bool                  $invalid  Unreadable row.
	 */
	private static function snapshot_text( ?SalesLinePricing $snapshot, bool $invalid ): string {
		if ( $invalid ) {
			return __( 'Unreadable', 'merchboard' );
		}

		if ( null === $snapshot ) {
			return __( 'Missing', 'merchboard' );
		}

		if ( ! $snapshot->has_share() ) {
			return __( 'Withdrawn model (no share)', 'merchboard' );
		}

		$vat = null === $snapshot->vat_registered
			? __( 'VAT status unknown', 'merchboard' )
			: ( $snapshot->vat_registered ? __( 'VAT registered', 'merchboard' ) : __( 'Not VAT registered', 'merchboard' ) );

		return sprintf(
			/* translators: 1: share in percent, 2: source of the share, 3: VAT status. */
			__( '%1$s (%2$s) · %3$s', 'merchboard' ),
			PricingLabels::markup( $snapshot->share_bps ),
			MarkupSource::Product === $snapshot->share_source ? __( 'Product markup', 'merchboard' ) : __( 'Creator markup', 'merchboard' ),
			$vat
		);
	}

	/**
	 * A creator's name (or "#id" when the creator no longer exists).
	 *
	 * @param int $creator_id Creator id.
	 */
	private function creator_name( int $creator_id ): string {
		if ( ! isset( $this->names[ $creator_id ] ) ) {
			$this->names[ $creator_id ] = $this->creators->find( $creator_id )->display_name ?? sprintf( '#%d', $creator_id );
		}

		return $this->names[ $creator_id ];
	}

	/**
	 * A UTC time in the site's time zone and format.
	 *
	 * @param DateTimeImmutable $time Time (UTC).
	 */
	private static function date( DateTimeImmutable $time ): string {
		$text = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time->getTimestamp() );

		return false === $text || '' === $text ? $time->format( 'Y-m-d H:i' ) : $text;
	}
}
