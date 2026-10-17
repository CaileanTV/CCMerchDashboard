<?php
/**
 * Admin page: payout requests (queue, history and handling).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardStatus;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\PayoutGiftCardOrders;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalance;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutMethod;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CommissionVat;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementPdf;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Merch Dashboard → Payouts (ADR-055, ADR-056, ADR-058), for users with
 * `merchboard_manage_payouts`:
 *
 * - **Queue:** pending requests (bank, PayPal, gift card), oldest first; **History:**
 *   every other status, newest decision first. Paginated on the server;
 *   only the masked destination is listed. **Balances** (`?view=balances`):
 *   per creator and currency the released earnings, the reserved and paid
 *   out amounts and the resulting balance (PayoutBalances, ADR-054), 20
 *   creators per page. An unknown earning is shown as unknown, never as 0.
 *   No payment details.
 * - **Payout** (`?payout=<id>`): its data and, while pending, the forms to
 *   reject it with a reason or to register the transfer made outside Merch
 *   Dashboard (PayoutActions). The full bank or PayPal details are shown only
 *   after an explicit, nonce-protected request (`reveal`) and only through
 *   PayoutDecisions::details(), which checks both payout capabilities and
 *   writes `payout_details_viewed` before returning them. They are never
 *   part of a list, a URL or a log.
 *
 * Merch Dashboard never sends money: completing only records that the admin
 * made the transfer. A completion that the balance no longer covers is
 * refused for manual handling. Without a valid `MERCHBOARD_PAYOUT_KEY`, a
 * notice says that pending requests can only be rejected.
 *
 * A gift card payout (ADR-057) has no details: its page shows the amount
 * and a payout reference for the card the admin creates by hand in the
 * chosen gift card plugin, and a POST form for the card's code
 * (PayoutGiftCards validates and registers it). A completed one shows the
 * card's provider, id and its current status and balance in the plugin,
 * never its code.
 */
final class PayoutsPage {

	public const SLUG = 'merchboard-payouts';

	public const PER_PAGE = 20;

	/**
	 * `admin_menu` priority: after Settings (20), before the order history (25).
	 */
	public const PRIORITY = 24;

	/**
	 * Nonce action of revealing one payout's full details.
	 */
	public const REVEAL_ACTION = 'merchboard_reveal_payout_details';

	/**
	 * Creator names by id (per request).
	 *
	 * @var array<int, string>
	 */
	private array $names = array();

	/**
	 * Constructor.
	 *
	 * @param PayoutRepository          $payouts   Payouts (read only).
	 * @param PayoutDecisions           $decisions The audited view of full details.
	 * @param PayoutCipher              $cipher    Whether a valid key is configured.
	 * @param CreatorRepository         $creators  Creator names.
	 * @param Logger                    $logger    Technical log.
	 * @param GiftCardProviders|null    $providers Gift card adapters (links and reconciliation); null = none.
	 * @param SettlementDocuments|null  $documents Settlement documents (ADR-060); null = none.
	 * @param PayoutBalances|null       $balances  Balances per creator and currency (ADR-054); null = no balances view.
	 * @param PayoutGiftCardOrders|null $gift_card_orders Orders paid with payout gift cards (G1); null = not shown.
	 */
	public function __construct(
		private PayoutRepository $payouts,
		private PayoutDecisions $decisions,
		private PayoutCipher $cipher,
		private CreatorRepository $creators,
		private Logger $logger,
		private ?GiftCardProviders $providers = null,
		private ?SettlementDocuments $documents = null,
		private ?PayoutBalances $balances = null,
		private ?PayoutGiftCardOrders $gift_card_orders = null
	) {
	}

	/**
	 * The reference an admin can note in the gift card plugin for a payout.
	 *
	 * @param int $payout_id Payout id.
	 */
	public static function reference( int $payout_id ): string {
		return sprintf( 'MB-PAYOUT-%d', $payout_id );
	}

	/**
	 * Adds the submenu page (hooked on `admin_menu` with PRIORITY).
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Payouts', 'merchboard' ),
			__( 'Payouts', 'merchboard' ),
			Capabilities::MANAGE_PAYOUTS,
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
	 * Message and type of an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'error'|'warning']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'rejected'                            => array( __( 'The request was rejected. The reservation is released and the creator sees your reason.', 'merchboard' ), 'success' ),
			'completed'                           => array( __( 'The payout was registered as transferred.', 'merchboard' ), 'success' ),
			PayoutActions::NOT_CONFIRMED          => array( __( 'Not registered: confirm that you have made the transfer outside Merch Dashboard.', 'merchboard' ), 'error' ),
			PayoutException::INVALID_REASON       => array( __( 'Not rejected: enter a reason of at most 500 characters. The creator sees it.', 'merchboard' ), 'error' ),
			PayoutException::INVALID_REFERENCE    => array( __( 'Not registered: the reference may have at most 191 characters.', 'merchboard' ), 'error' ),
			PayoutException::PAYMENT_DETAILS_IN_TEXT => array( __( 'Not saved: the reference or reason must not contain bank or payment details, such as an account number, an IBAN or an e-mail address. Nothing was changed. Use the transaction ID from the bank or PayPal, or describe the reason without the details.', 'merchboard' ), 'error' ),
			PayoutException::NOT_PENDING          => array( __( 'This request has already been handled; nothing was changed.', 'merchboard' ), 'warning' ),
			PayoutException::NOT_FOUND            => array( __( 'The payout does not exist.', 'merchboard' ), 'error' ),
			PayoutException::INSUFFICIENT_BALANCE => array( __( 'Not registered: the creator\'s balance no longer covers this payout, for example after a refund. Handle it manually: do not transfer the amount, and reject the request if it cannot be paid.', 'merchboard' ), 'error' ),
			PayoutException::BALANCE_UNKNOWN      => array( __( 'Not registered: the creator\'s balance is unknown, because some sales lack the data needed. Handle it manually; nothing is completed automatically.', 'merchboard' ), 'error' ),
			PayoutException::DETAILS_UNREADABLE   => array( __( 'The payment details cannot be decrypted (MERCHBOARD_PAYOUT_KEY is missing or was changed). This request can only be rejected.', 'merchboard' ), 'error' ),
			PayoutException::NOT_AUTHORIZED       => array( __( 'You are not allowed to do this.', 'merchboard' ), 'error' ),
			PayoutException::BUSY                 => array( __( 'Another payout of this creator is being processed. Please try again in a moment.', 'merchboard' ), 'error' ),
			'gift_card_registered'                => array( __( 'The gift card was validated and registered. The payout is completed, and the creator can now see the card.', 'merchboard' ), 'success' ),
			PayoutException::WRONG_METHOD         => array( __( 'This action does not fit the payout method; nothing was changed.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARDS_UNAVAILABLE => array( __( 'Not registered: no available gift card plugin is chosen in Settings → Payouts.', 'merchboard' ), 'error' ),
			PayoutException::INVALID_GIFT_CARD_CODE => array( __( 'Not registered: enter the gift card code.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARD_NOT_FOUND  => array( __( 'Not registered: no gift card has this code.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARD_AMBIGUOUS  => array( __( 'Not registered: several gift cards have this code. Give the card a unique code in the gift card plugin.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARD_INVALID_DATA => array( __( 'Not registered: the gift card\'s amount, balance, currency or expiry cannot be read exactly. Enter the amount with a decimal point and at most the shop\'s decimals.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARD_READ_FAILED => array( __( 'Not registered: the gift card could not be validated. Nothing was changed; try again later.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARD_NOT_ACTIVE => array( __( 'Not registered: the gift card is not active.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARD_EXPIRED    => array( __( 'Not registered: the gift card has expired.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARD_CURRENCY   => array( __( 'Not registered: the gift card has another currency than the payout.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARD_AMOUNT     => array( __( 'Not registered: the gift card\'s amount is not the payout amount.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARD_USED       => array( __( 'Not registered: the gift card\'s balance is not its full amount, so it has been used.', 'merchboard' ), 'error' ),
			PayoutException::GIFT_CARD_LINKED     => array( __( 'Not registered: this gift card is already registered on another payout.', 'merchboard' ), 'error' ),
			'error'                               => array( __( 'A technical error occurred. Nothing was changed; try again later.', 'merchboard' ), 'error' ),
			default                               => null,
		};
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage payouts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only parameters, validated here; the reveal is checked with its nonce below.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$input  = wp_unslash( $_GET );
		$payout = self::id( $input['payout'] ?? null );
		$page   = self::id( $input['paged'] ?? null ) ?? 1;
		$view   = in_array( $input['view'] ?? '', array( 'history', 'balances' ), true ) ? (string) $input['view'] : 'queue';
		$result = is_string( $input['result'] ?? null ) ? sanitize_key( $input['result'] ) : '';
		$reveal = is_string( $input['_wpnonce'] ?? null ) ? $input['_wpnonce'] : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap merchboard-payouts"><h1>' . esc_html__( 'Payouts', 'merchboard' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Payout requests of creators. Merch Dashboard never sends money: you transfer the amount yourself, outside Merch Dashboard, and then register it here.', 'merchboard' ) . '</p>';

		if ( ! $this->cipher->available() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No valid MERCHBOARD_PAYOUT_KEY is configured in wp-config.php. Creators cannot request bank or PayPal payouts, and pending requests can only be rejected.', 'merchboard' ) . '</p></div>';
		}

		$notice = self::result_message( $result );

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		try {
			if ( null !== $payout ) {
				$this->render_payout( $payout, $reveal );
			} else {
				$this->render_list( null === $this->balances && 'balances' === $view ? 'queue' : $view, min( $page, 1000000 ) );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The payouts could not be read.', array( 'error' => $e::class ) );
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The payouts could not be read. Please try again later.', 'merchboard' ) . '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * The queue, the history or the balances, paginated.
	 *
	 * @param string $view 'queue', 'history' or 'balances'.
	 * @param int    $page Requested page.
	 * @throws DatabaseException When the payouts cannot be read.
	 */
	private function render_list( string $view, int $page ): void {
		$history = 'history' === $view;
		$queued  = $this->payouts->count_for_admin( true );
		$done    = $this->payouts->count_for_admin( false );
		$tabs    = array(
			/* translators: %s: number of requests. */
			'queue'   => array( self::url(), sprintf( __( 'Queue (%s)', 'merchboard' ), number_format_i18n( $queued ) ) ),
			/* translators: %s: number of requests. */
			'history' => array( self::url( array( 'view' => 'history' ) ), sprintf( __( 'History (%s)', 'merchboard' ), number_format_i18n( $done ) ) ),
		);

		if ( null !== $this->balances ) {
			$tabs['balances'] = array( self::url( array( 'view' => 'balances' ) ), __( 'Balances', 'merchboard' ) );
		}

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Payout views', 'merchboard' ) . '">';

		foreach ( $tabs as $key => $tab ) {
			echo '<a class="nav-tab' . ( $key === $view ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $tab[0] ) . '"' . ( $key === $view ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab[1] ) . '</a>';
		}

		echo '</nav>';

		if ( 'balances' === $view && null !== $this->balances ) {
			$this->render_balances( $this->balances, $page );
			return;
		}

		$total = $history ? $done : $queued;
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$rows  = $total > 0 && $page <= $pages ? $this->payouts->page_for_admin( ! $history, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) : array();

		if ( array() === $rows ) {
			echo '<p>' . esc_html( $history ? __( 'No handled payouts yet.', 'merchboard' ) : __( 'No pending payout requests.', 'merchboard' ) ) . '</p>';
			return;
		}

		echo '<table class="widefat striped merchboard-payouts-table"><thead><tr>';

		foreach ( array( __( 'Payout', 'merchboard' ), __( 'Creator', 'merchboard' ), __( 'Amount', 'merchboard' ), __( 'Method', 'merchboard' ), __( 'Destination', 'merchboard' ), __( 'Status', 'merchboard' ), __( 'Requested', 'merchboard' ), __( 'Handled', 'merchboard' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><td><a href="' . esc_url( self::url( array( 'payout' => $row->id ) ) ) . '">' . esc_html( sprintf( '#%d', $row->id ) ) . '</a></td>';
			echo '<td>' . esc_html( $this->creator_name( $row->creator_id ) ) . '</td>';
			echo '<td>' . esc_html( self::money( $row ) ) . '</td>';
			echo '<td>' . esc_html( self::method_label( $row->method ) ) . '</td>';
			echo '<td>' . esc_html( $row->destination_hint ) . '</td>';
			echo '<td>' . esc_html( self::status_label( $row->status ) ) . '</td>';
			echo '<td>' . esc_html( self::date( $row->requested_at ) ) . '</td>';
			echo '<td>' . esc_html( null === $row->decided_at ? '–' : self::date( $row->decided_at ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		$links = paginate_links(
			array(
				'base'      => self::url( ( $history ? array( 'view' => 'history' ) : array() ) + array( 'paged' => '%#%' ) ),
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
	 * Balances per creator and currency (ADR-054), creators by name. An
	 * unknown earning makes the balance unknown; it is never shown as 0.
	 *
	 * @param PayoutBalances $balances Balances.
	 * @param int            $page     Requested page.
	 * @throws DatabaseException When the creators or a balance cannot be read.
	 */
	private function render_balances( PayoutBalances $balances, int $page ): void {
		$found = $this->creators->paginate( $page, self::PER_PAGE, null, 'display_name', 'asc' );
		$pages = max( 1, (int) ceil( $found['total'] / self::PER_PAGE ) );

		// A page beyond the last shows the last one, as the pagination below does.
		if ( $page > $pages ) {
			$page  = $pages;
			$found = $this->creators->paginate( $page, self::PER_PAGE, null, 'display_name', 'asc' );
		}

		echo '<p class="description">' . esc_html__( 'Released earnings are the creator payouts of sales whose holding period has ended, counted from the settlement start; refunds count at once. Balance = released earnings − paid out − reserved. When a ledger row cannot be calculated, the earnings and the balance are unknown and nothing can be requested.', 'merchboard' ) . '</p>';

		if ( array() === $found['items'] ) {
			echo '<p>' . esc_html__( 'No creators yet.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped merchboard-payout-balances-table"><thead><tr>';

		foreach ( array( __( 'Creator', 'merchboard' ), __( 'Currency', 'merchboard' ), __( 'Released earnings', 'merchboard' ), __( 'Reserved', 'merchboard' ), __( 'Paid out', 'merchboard' ), __( 'Balance', 'merchboard' ), __( 'Available', 'merchboard' ), __( 'Outstanding', 'merchboard' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $found['items'] as $creator ) {
			try {
				$rows = $balances->for_creator( $creator->id );
			} catch ( PayoutException ) {
				$rows = null;
			}

			if ( null === $rows || array() === $rows ) {
				echo '<tr><td>' . esc_html( $creator->display_name ) . '</td><td colspan="7">' . ( null === $rows ? '<strong>' . esc_html__( 'Unknown', 'merchboard' ) . '</strong>' : esc_html__( 'No earnings or payouts yet.', 'merchboard' ) ) . '</td></tr>';
				continue;
			}

			foreach ( $rows as $balance ) {
				echo '<tr><td>' . esc_html( $creator->display_name ) . '</td>';
				echo '<td>' . esc_html( $balance->currency ) . '</td>';
				echo '<td>' . self::balance_cell( $balance->earned_units, $balance->currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in balance_cell().

				if ( PayoutBalance::UNKNOWN === $balance->status() ) {
					/* translators: %d: number of ledger rows. */
					echo '<br><span class="description">' . esc_html( sprintf( _n( '%d ledger row cannot be calculated', '%d ledger rows cannot be calculated', $balance->unknown_rows, 'merchboard' ), $balance->unknown_rows ) ) . '</span>';
				}

				echo '</td>';

				foreach ( array( $balance->reserved_units, $balance->completed_units, $balance->balance_units(), $balance->available_units(), $balance->outstanding_units() ) as $units ) {
					echo '<td>' . self::balance_cell( $units, $balance->currency ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in balance_cell().
				}

				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		$links = paginate_links(
			array(
				'base'      => self::url(
					array(
						'view'  => 'balances',
						'paged' => '%#%',
					)
				),
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
	 * An amount in units, or "Unknown" (never 0) when null. Escaped HTML.
	 *
	 * @param int|null $units    Amount, null when unknown.
	 * @param string   $currency Currency.
	 */
	private static function balance_cell( ?int $units, string $currency ): string {
		return null === $units
			? '<strong>' . esc_html__( 'Unknown', 'merchboard' ) . '</strong>'
			: esc_html( CreatorSalesSection::money( SalesAmount::format( $units ), $currency ) );
	}

	/**
	 * One payout with its handling forms.
	 *
	 * @param int    $payout_id Payout id.
	 * @param string $reveal    Nonce of a request for the full details ('' = none).
	 * @throws DatabaseException When the payout cannot be read.
	 */
	private function render_payout( int $payout_id, string $reveal ): void {
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← All payouts', 'merchboard' ) . '</a></p>';
		$payout = $this->payouts->find( $payout_id );

		if ( null === $payout ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The payout does not exist.', 'merchboard' ) . '</p></div>';
			return;
		}

		/* translators: %d: payout id. */
		echo '<h2>' . esc_html( sprintf( __( 'Payout #%d', 'merchboard' ), $payout->id ) ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$fields = array(
			__( 'Creator', 'merchboard' )     => $this->creator_name( $payout->creator_id ),
			__( 'Amount', 'merchboard' )      => self::money( $payout ),
			__( 'Method', 'merchboard' )      => self::method_label( $payout->method ),
			__( 'Destination', 'merchboard' ) => $payout->destination_hint,
			__( 'Status', 'merchboard' )      => self::status_label( $payout->status ),
			__( 'Requested', 'merchboard' )   => self::date( $payout->requested_at ),
			__( 'Handled', 'merchboard' )     => null === $payout->decided_at ? '–' : self::date( $payout->decided_at ),
		);

		if ( null !== $payout->decision_reason ) {
			$fields[ __( 'Reason', 'merchboard' ) ] = $payout->decision_reason;
		}

		if ( null !== $payout->reference ) {
			$fields[ __( 'Reference', 'merchboard' ) ] = $payout->reference;
		}

		if ( PayoutMethod::GiftCard === $payout->method ) {
			$fields[ __( 'Payout reference', 'merchboard' ) ] = self::reference( $payout->id );
		}

		if ( null !== $payout->gift_card_provider && null !== $payout->gift_card_external_id ) {
			$provider = $this->providers?->by_id( $payout->gift_card_provider );
			/* translators: 1: gift card plugin, 2: the card's id in it. */
			$fields[ __( 'Gift card', 'merchboard' ) ] = sprintf( __( '%1$s, card #%2$s', 'merchboard' ), null === $provider ? $payout->gift_card_provider : $provider->label(), $payout->gift_card_external_id );
		}

		foreach ( $fields as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( PayoutMethod::GiftCard === $payout->method && PayoutStatus::Completed === $payout->status ) {
			$this->render_gift_card_state( $payout );
		}

		if ( PayoutStatus::Completed === $payout->status ) {
			$this->render_settlement_document( $payout );
		}

		if ( PayoutStatus::Pending !== $payout->status ) {
			return;
		}

		if ( PayoutMethod::GiftCard === $payout->method ) {
			$this->render_gift_card_register( $payout );
		} else {
			$this->render_details( $payout, $reveal );
			$this->render_complete( $payout );
		}

		$this->render_reject( $payout );
	}

	/**
	 * Registering the gift card an admin created by hand in the plugin. The
	 * code is only posted; it is never put in a URL, shown again or stored.
	 *
	 * @param Payout $payout Pending gift card payout.
	 */
	private function render_gift_card_register( Payout $payout ): void {
		echo '<h3>' . esc_html__( 'Register gift card', 'merchboard' ) . '</h3>';
		$provider = $this->providers?->usable();

		if ( null === $provider ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No available gift card plugin is chosen in Settings → Payouts, so no gift card can be registered. The request can be rejected.', 'merchboard' ) . '</p></div>';
			return;
		}

		echo '<ol>';
		/* translators: 1: gift card plugin, 2: amount with currency, 3: payout reference. */
		echo '<li>' . esc_html( sprintf( __( 'Create a gift card in %1$s with exactly this amount and balance: %2$s (no VAT added or deducted). Note the reference %3$s in its internal notes.', 'merchboard' ), $provider->label(), self::money( $payout ), self::reference( $payout->id ) ) ) . '</li>';
		echo '<li>' . esc_html__( 'Do not send or use the card. Enter its code below: Merch Dashboard checks that it is one unused, active card of this amount and currency, then completes the payout and shows the card to the creator.', 'merchboard' ) . '</li>';
		echo '</ol>';
		$create = $provider->admin_url( null );

		if ( null !== $create ) {
			echo '<p><a class="button" href="' . esc_url( $create ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Create gift card in the plugin', 'merchboard' ) . '</a></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( PayoutActions::GIFT_CARD_ACTION ) . '">';
		echo '<input type="hidden" name="payout_id" value="' . esc_attr( (string) $payout->id ) . '">';
		wp_nonce_field( PayoutActions::nonce_action( PayoutActions::GIFT_CARD_ACTION, $payout->id ) );
		echo '<p><label for="merchboard-payout-gift-card-code">' . esc_html__( 'Gift card code', 'merchboard' ) . '</label><br>';
		echo '<input type="text" id="merchboard-payout-gift-card-code" name="gift_card_code" class="regular-text" maxlength="191" autocomplete="off" spellcheck="false" required></p>';
		submit_button( __( 'Validate and register gift card', 'merchboard' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The registered card as the plugin has it now (reconciliation): status
	 * and balance, never its code.
	 *
	 * @param Payout $payout Completed gift card payout.
	 */
	private function render_gift_card_state( Payout $payout ): void {
		$provider = null === $payout->gift_card_provider ? null : $this->providers?->by_id( $payout->gift_card_provider );

		if ( null === $provider || null === $payout->gift_card_external_id ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Gift card in the plugin now', 'merchboard' ) . '</h3>';

		try {
			$card = $provider->availability()->available ? $provider->find_by_id( $payout->gift_card_external_id ) : null;
			$text = null === $card
				? __( 'The gift card cannot be read now: the plugin is not available, or the card was deleted.', 'merchboard' )
				: sprintf(
					/* translators: 1: status, 2: balance with currency. */
					__( 'Status: %1$s. Balance: %2$s.', 'merchboard' ),
					self::card_status_label( $card->status ),
					CreatorSalesSection::money( SalesAmount::format( $card->balance_units ), $card->currency )
				);
		} catch ( Throwable ) {
			$text = __( 'The gift card cannot be read now.', 'merchboard' );
		}

		echo '<p>' . esc_html( $text ) . '</p>';

		if ( null !== $this->gift_card_orders ) {
			// G1: the orders the plugin recorded as paid with this card; the
			// plugin's record, never a confirmed payout status (B2).
			try {
				$lookup = $this->gift_card_orders->for_payout( $payout->id );
			} catch ( Throwable ) {
				$lookup = array(
					'orders' => null,
					'reason' => PayoutGiftCardOrders::FAILED,
				);
			}

			if ( null === $lookup['orders'] ) {
				echo '<p>' . esc_html( PayoutGiftCardOrders::UNSUPPORTED === $lookup['reason'] ? __( 'Gift card plugin does not support order registration lookup', 'merchboard' ) : __( 'Gift card order registration could not be checked', 'merchboard' ) ) . '</p>';
			} else {
				$orders = array() === $lookup['orders'] ? esc_html__( 'None', 'merchboard' ) : implode( ', ', array_map( static fn( int $id ): string => '<a href="' . esc_url( OrderHistoryPage::url( array( 'order' => $id ) ) ) . '">' . esc_html( sprintf( '#%d', $id ) ) . '</a>', $lookup['orders'] ) );
				echo '<p><strong>' . esc_html__( 'Registered by gift card plugin — payout status not confirmed', 'merchboard' ) . '</strong><br>' . esc_html__( 'Orders', 'merchboard' ) . ': ' . $orders . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			}
		}

		$link = $provider->admin_url( $payout->gift_card_external_id );

		if ( null !== $link ) {
			echo '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'Open the card in the plugin', 'merchboard' ) . '</a></p>';
		}
	}

	/**
	 * The settlement document of a completed payout as issued (ADR-060),
	 * never recalculated, with a link to its PDF. Template version 1 keeps its
	 * preliminary mark (060-9); later versions show the note on tax and
	 * reporting. Payouts completed before schema version 11 have none (060-8).
	 *
	 * @param Payout $payout Completed payout.
	 */
	private function render_settlement_document( Payout $payout ): void {
		if ( null === $this->documents ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Settlement document', 'merchboard' ) . '</h3>';

		try {
			$shown = $this->documents->for_payout( $payout->id );
		} catch ( Throwable $e ) {
			$this->logger->error( 'The settlement document could not be read.', array( 'payout_id' => $payout->id, 'error' => $e::class ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The settlement document cannot be shown. Please try again later.', 'merchboard' ) . '</p></div>';
			return;
		}

		if ( null === $shown ) {
			echo '<p class="description">' . esc_html__( 'This payout has no settlement document: it was completed before settlement documents were introduced.', 'merchboard' ) . '</p>';
			return;
		}

		$document = $shown['document'];
		$money    = static fn( int $units ): string => CreatorSalesSection::money( SalesAmount::format( $units ), $document->currency );
		$creator  = is_array( $document->snapshot['creator'] ?? null ) ? $document->snapshot['creator'] : array();

		if ( SettlementPdf::preliminary( $document ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Preliminary, not approved by an auditor or SKAT.', 'merchboard' ) . '</strong></p></div>';
		}

		echo '<p><a class="button" href="' . esc_url( SettlementPdfDownload::url( $payout->id ) ) . '">' . esc_html__( 'Download PDF', 'merchboard' ) . '</a></p>';
		echo '<table class="form-table merchboard-settlement-document" role="presentation"><tbody>';
		$fields = array(
			__( 'Document number', 'merchboard' )          => $document->number,
			__( 'Issued', 'merchboard' )                   => self::date( $document->issued_at ),
			__( 'Period', 'merchboard' )                   => ( null === $document->period_from ? __( 'Whole history', 'merchboard' ) : self::date( $document->period_from ) ) . ' – ' . self::date( $document->period_to ),
			__( 'Creator', 'merchboard' )                  => is_scalar( $creator['name'] ?? null ) ? (string) $creator['name'] : '',
			__( 'VAT registered at issue', 'merchboard' )  => null === $document->vat_registered ? __( 'Unknown', 'merchboard' ) : ( $document->vat_registered ? __( 'Yes', 'merchboard' ) : __( 'No', 'merchboard' ) ),
			__( 'Commission in the period', 'merchboard' ) => $money( $document->commission_units ),
			__( 'Carried from earlier', 'merchboard' )     => $money( $document->opening_units ),
			__( 'Paid out', 'merchboard' )                 => $money( $document->payout_units ),
			__( 'Carried forward', 'merchboard' )          => $money( $document->closing_units ),
			__( 'Settled sales rows', 'merchboard' )       => number_format_i18n( count( $shown['rows'] ) ),
		);

		// After the commission: its VAT (template version 3, ADR-062 K05 and K12), then the adjustments.
		$after = array();
		$split = CommissionVat::shown( $document );

		if ( null !== $split ) {
			$after[ __( 'Commission excl. VAT', 'merchboard' ) ] = $money( $split['excl_units'] );
			/* translators: %s: VAT rate in percent, e.g. 25. */
			$after[ sprintf( __( 'VAT (%s %%)', 'merchboard' ), CommissionVat::percent_for_locale( $split['rate_bps'] ) ) ] = $money( $split['vat_units'] );
			$after[ __( 'Commission incl. VAT', 'merchboard' ) ] = $money( $split['incl_units'] );

			if ( 0 !== $split['untaxed_units'] ) {
				$after[ __( 'Commission without VAT (not VAT registered when sold)', 'merchboard' ) ] = $money( $split['untaxed_units'] );
			}
		}

		if ( 0 !== $document->adjustment_units ) {
			// ADR-063: adjustments this document settled, with their recalculations.
			/* translators: %s: references of the recalculations, e.g. R2-000001. */
			$after[ sprintf( __( 'Adjustments (%s)', 'merchboard' ), implode( ', ', SettlementPdf::adjustment_references( $document ) ) ) ] = $money( $document->adjustment_units );
		}

		$fields = array_slice( $fields, 0, 6, true ) + $after + array_slice( $fields, 6, null, true );

		foreach ( $fields as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';

		foreach ( $shown['corrections'] as $correction ) {
			/* translators: 1: number of the correction document, 2: date. */
			echo '<p>' . esc_html( sprintf( __( 'Corrected by %1$s (%2$s).', 'merchboard' ), $correction->number, self::date( $correction->issued_at ) ) ) . '</p>';
		}

		if ( ! SettlementPdf::preliminary( $document ) ) {
			list( $heading, $note ) = SettlementPdf::tax_note();
			echo '<h4>' . esc_html( $heading ) . '</h4><p class="description merchboard-settlement-tax-note">' . esc_html( $note ) . '</p>';
		}
	}

	/**
	 * Label of a gift card status.
	 *
	 * @param GiftCardStatus $status Status.
	 */
	private static function card_status_label( GiftCardStatus $status ): string {
		return match ( $status ) {
			GiftCardStatus::Active   => __( 'active', 'merchboard' ),
			GiftCardStatus::Disabled => __( 'disabled', 'merchboard' ),
			GiftCardStatus::Expired  => __( 'expired', 'merchboard' ),
			GiftCardStatus::Other    => __( 'other', 'merchboard' ),
		};
	}

	/**
	 * The full bank or PayPal details: only for users with both payout
	 * capabilities, only on an explicit request with a valid nonce, and
	 * only after the view has been audited (PayoutDecisions::details()).
	 *
	 * @param Payout $payout Pending payout.
	 * @param string $reveal Nonce of the request ('' = none).
	 */
	private function render_details( Payout $payout, string $reveal ): void {
		echo '<h3>' . esc_html__( 'Full payment details', 'merchboard' ) . '</h3>';

		if ( ! current_user_can( Capabilities::VIEW_PAYOUT_DETAILS ) ) {
			echo '<p class="description">' . esc_html__( 'Only users allowed to see full payment details can view them. Ask an administrator who can.', 'merchboard' ) . '</p>';
			return;
		}

		if ( '' === $reveal || 1 !== wp_verify_nonce( $reveal, self::REVEAL_ACTION . '_' . $payout->id ) ) {
			$url = wp_nonce_url( self::url( array( 'payout' => $payout->id ) ), self::REVEAL_ACTION . '_' . $payout->id );
			echo '<p>' . esc_html__( 'The details are shown only on request, and every view is recorded in the audit log.', 'merchboard' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Show full payment details', 'merchboard' ) . '</a></p>';
			return;
		}

		try {
			$details = $this->decisions->details( $payout->id );
		} catch ( PayoutException $e ) {
			$message = self::result_message( $e->reason() ) ?? array( __( 'The details cannot be shown.', 'merchboard' ), 'error' );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $message[0] ) . '</p></div>';
			return;
		} catch ( Throwable $e ) {
			$this->logger->error( 'The payout details could not be shown.', array( 'payout_id' => $payout->id, 'error' => $e::class ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The details cannot be shown. Please try again later.', 'merchboard' ) . '</p></div>';
			return;
		}

		echo '<table class="form-table merchboard-payout-details" role="presentation"><tbody>';

		if ( PayoutMethod::Bank === $details->method ) {
			echo '<tr><th scope="row">' . esc_html__( 'Registration number', 'merchboard' ) . '</th><td><code>' . esc_html( $details->registration ) . '</code></td></tr>';
			echo '<tr><th scope="row">' . esc_html__( 'Account number', 'merchboard' ) . '</th><td><code>' . esc_html( $details->account ) . '</code></td></tr>';
		} else {
			echo '<tr><th scope="row">' . esc_html__( 'PayPal email address', 'merchboard' ) . '</th><td><code>' . esc_html( $details->account ) . '</code></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'This view was recorded in the audit log. The details are deleted when the request has been handled.', 'merchboard' ) . '</p>';
	}

	/**
	 * Registering the transfer made outside Merch Dashboard.
	 *
	 * @param Payout $payout Pending payout.
	 */
	private function render_complete( Payout $payout ): void {
		echo '<h3>' . esc_html__( 'Register as paid out', 'merchboard' ) . '</h3>';
		echo '<p>' . esc_html__( 'Transfer the amount yourself (bank or PayPal) first. Registering it here sends no money; it only records that the transfer has been made. The creator\'s balance is checked again, and a payout it no longer covers is refused for manual handling.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( PayoutActions::COMPLETE_ACTION ) . '">';
		echo '<input type="hidden" name="payout_id" value="' . esc_attr( (string) $payout->id ) . '">';
		wp_nonce_field( PayoutActions::nonce_action( PayoutActions::COMPLETE_ACTION, $payout->id ) );
		echo '<p><label for="merchboard-payout-reference">' . esc_html__( 'Reference (optional)', 'merchboard' ) . '</label><br>';
		echo '<input type="text" id="merchboard-payout-reference" name="reference" class="regular-text" maxlength="191" autocomplete="off"></p>';
		echo '<p class="description">' . esc_html__( 'For example the transaction ID from the bank or PayPal. Never enter an account number, IBAN or e-mail address: the reference is kept with the payout history.', 'merchboard' ) . '</p>';
		echo '<p><label><input type="checkbox" name="confirm_transfer" value="1" required> ' . esc_html__( 'The transfer has been made outside Merch Dashboard', 'merchboard' ) . '</label></p>';
		submit_button( __( 'Register as paid out', 'merchboard' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Rejecting with a reason.
	 *
	 * @param Payout $payout Pending payout.
	 */
	private function render_reject( Payout $payout ): void {
		echo '<h3>' . esc_html__( 'Reject', 'merchboard' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( PayoutActions::REJECT_ACTION ) . '">';
		echo '<input type="hidden" name="payout_id" value="' . esc_attr( (string) $payout->id ) . '">';
		wp_nonce_field( PayoutActions::nonce_action( PayoutActions::REJECT_ACTION, $payout->id ) );
		echo '<p><label for="merchboard-payout-reason">' . esc_html__( 'Reason (shown to the creator)', 'merchboard' ) . '</label><br>';
		echo '<textarea id="merchboard-payout-reason" name="reason" rows="3" class="large-text" maxlength="500" required></textarea></p>';
		echo '<p class="description">' . esc_html__( 'Never enter an account number, IBAN or e-mail address: the reason is shown to the creator and kept with the payout history.', 'merchboard' ) . '</p>';
		submit_button( __( 'Reject request', 'merchboard' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The creator's display name, or "#id" when it no longer exists.
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
	 * The amount with its currency.
	 *
	 * @param Payout $payout Payout.
	 */
	private static function money( Payout $payout ): string {
		return CreatorSalesSection::money( SalesAmount::format( $payout->amount_units ), $payout->currency );
	}

	/**
	 * Label of a method.
	 *
	 * @param PayoutMethod $method Method.
	 */
	private static function method_label( PayoutMethod $method ): string {
		return match ( $method ) {
			PayoutMethod::Bank     => __( 'Bank transfer', 'merchboard' ),
			PayoutMethod::PayPal   => __( 'PayPal', 'merchboard' ),
			PayoutMethod::GiftCard => __( 'Gift card', 'merchboard' ),
		};
	}

	/**
	 * Label of a status.
	 *
	 * @param PayoutStatus $status Status.
	 */
	private static function status_label( PayoutStatus $status ): string {
		return match ( $status ) {
			PayoutStatus::Pending   => __( 'Pending', 'merchboard' ),
			PayoutStatus::Completed => __( 'Paid out', 'merchboard' ),
			PayoutStatus::Rejected  => __( 'Rejected', 'merchboard' ),
			PayoutStatus::Cancelled => __( 'Cancelled', 'merchboard' ),
		};
	}

	/**
	 * A UTC time in the site's date and time format and time zone.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function date( DateTimeImmutable $time ): string {
		$text = wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $time->getTimestamp() );

		return is_string( $text ) ? $text : $time->format( 'Y-m-d H:i' );
	}

	/**
	 * A positive id, or null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function id( mixed $value ): ?int {
		$raw = is_scalar( $value ) ? trim( (string) $value ) : '';

		return 1 === preg_match( '/^[1-9]\d{0,18}$/', $raw ) ? (int) $raw : null;
	}
}
