<?php
/**
 * "Order log" tab of the Alerts admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use Closure;
use DateInterval;
use DateTimeImmutable;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessageRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Creators\CreatorRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator's overview of the orders with alerts (ADR-065 pkt. 7,
 * step 25.9.3), the first tab of Merch Dashboard → Alerts: status cards
 * (alerts, WooCommerce, orders, alerts and creators in the period), the
 * periods "today" (from midnight in the site's time zone), "last 24 hours"
 * and "all", and the orders, newest alert first, with each creator's alerts
 * and the chatbot's outcome per platform. Read only (nothing is changed, so
 * nothing is audited); the creators resend from their own history. The
 * customer's voluntary username is shown, never a name. Refreshed by the
 * "Refresh" link, not automatically. Everything is escaped at output.
 */
final class OrderLogTab {

	/**
	 * The tab's key in the page URL.
	 */
	public const TAB = 'order-log';

	/**
	 * Query argument of the period.
	 */
	public const PERIOD_ARG = 'period';

	/**
	 * Query argument of the page number.
	 */
	public const PAGE_ARG = 'paged';

	/**
	 * The periods; the first is the default.
	 */
	public const PERIODS = array( 'today', '24h', 'all' );

	/**
	 * Orders per page.
	 */
	public const PER_PAGE = 20;

	/**
	 * Now.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $now;

	/**
	 * Constructor.
	 *
	 * @param AlertEventRepository                $events       Alerts.
	 * @param ChatMessageRepository               $messages     The chat message log.
	 * @param CreatorRepository                   $creators     Creators.
	 * @param AlertsAvailability                  $availability Kill switch and MerchAlert guard.
	 * @param (Closure(): DateTimeImmutable)|null $now          Now; defaults to the clock.
	 */
	public function __construct( private AlertEventRepository $events, private ChatMessageRepository $messages, private CreatorRepository $creators, private AlertsAvailability $availability, ?Closure $now = null ) {
		$this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * A known period ('today' for anything else).
	 *
	 * @param string $period Requested period.
	 */
	public static function period( string $period ): string {
		return in_array( $period, self::PERIODS, true ) ? $period : self::PERIODS[0];
	}

	/**
	 * The start of a period: midnight today in the site's time zone, 24 hours
	 * ago, or null for all.
	 *
	 * @param string $period A known period.
	 */
	public function since( string $period ): ?DateTimeImmutable {
		$now = ( $this->now )()->setTimezone( wp_timezone() );

		return match ( $period ) {
			'today' => $now->setTime( 0, 0 ),
			'24h'   => $now->sub( new DateInterval( 'PT24H' ) ),
			default => null,
		};
	}

	/**
	 * URL of the tab with a period and a page.
	 *
	 * @param string $period A known period.
	 * @param int    $page   Page number.
	 */
	public static function url( string $period, int $page = 1 ): string {
		$args = array( self::PERIOD_ARG => $period );

		if ( $page > 1 ) {
			$args[ self::PAGE_ARG ] = $page;
		}

		return add_query_arg( $args, AlertsAdminPage::url( self::TAB ) );
	}

	/**
	 * Renders the tab.
	 *
	 * @param string $period Requested period.
	 * @param int    $page   Requested page number.
	 * @throws \MerchBoard\Core\Database\DatabaseException When the alerts cannot be read.
	 */
	public function render( string $period, int $page ): void {
		$period = self::period( $period );
		$since  = $this->since( $period );
		$totals = $this->events->log_totals( $since );
		$pages  = max( 1, (int) ceil( $totals['orders'] / self::PER_PAGE ) );
		$page   = min( max( 1, $page ), $pages );
		$ids    = $this->events->log_page( $since, new Pagination( $page, self::PER_PAGE ) );

		$this->render_filters( $period, $page );
		$this->render_cards( $totals );

		if ( array() === $ids ) {
			echo '<p>' . esc_html__( 'No orders with alerts in this period. Alerts are recorded when an order with a creator\'s products is paid (processing or completed) while alerts are switched on.', 'merchboard' ) . '</p>';

			return;
		}

		$this->render_orders( $ids );

		if ( $pages > 1 ) {
			$links = paginate_links(
				array(
					'base'      => add_query_arg( self::PAGE_ARG, '%#%', self::url( $period ) ),
					'format'    => '',
					'current'   => $page,
					'total'     => $pages,
					'prev_text' => __( '&laquo; Previous', 'merchboard' ),
					'next_text' => __( 'Next &raquo;', 'merchboard' ),
				)
			);

			if ( '' !== $links ) {
				echo '<div class="tablenav bottom"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
			}
		}
	}

	/**
	 * The periods and the refresh link.
	 *
	 * @param string $period The shown period.
	 * @param int    $page   The shown page.
	 */
	private function render_filters( string $period, int $page ): void {
		$labels = array(
			'today' => __( 'Today', 'merchboard' ),
			'24h'   => __( 'Last 24 hours', 'merchboard' ),
			'all'   => __( 'All', 'merchboard' ),
		);
		$links  = array();

		foreach ( $labels as $key => $label ) {
			$links[] = $key === $period
				? '<strong aria-current="page">' . esc_html( $label ) . '</strong>'
				: '<a href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '<p class="subsubsub" style="float:none">' . implode( ' | ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped above.
		echo '<p><a class="button" href="' . esc_url( self::url( $period, $page ) ) . '">' . esc_html__( 'Refresh', 'merchboard' ) . '</a></p>';
	}

	/**
	 * The status cards.
	 *
	 * @param array{orders: int, alerts: int, creators: int} $totals Totals of the period.
	 */
	private function render_cards( array $totals ): void {
		$cards = array(
			array( __( 'Alerts', 'merchboard' ), AlertsSettingsSection::status( $this->availability->enabled(), $this->availability->merchalert_active() ) ),
			array(
				__( 'WooCommerce', 'merchboard' ),
				defined( 'WC_VERSION' )
					/* translators: %s: WooCommerce version. */
					? sprintf( __( 'Active (version %s)', 'merchboard' ), (string) constant( 'WC_VERSION' ) )
					: __( 'Active', 'merchboard' ),
			),
			array( __( 'Orders with alerts', 'merchboard' ), number_format_i18n( $totals['orders'] ) ),
			array( __( 'Alerts', 'merchboard' ) . ' / ' . __( 'Creators', 'merchboard' ), number_format_i18n( $totals['alerts'] ) . ' / ' . number_format_i18n( $totals['creators'] ) ),
		);

		echo '<div class="merchboard-alert-cards" style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0">';

		foreach ( $cards as [ $title, $value ] ) {
			echo '<div class="card" style="margin:0;min-width:180px;max-width:280px"><h2 class="title">' . esc_html( $title ) . '</h2><p>' . esc_html( $value ) . '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * The orders of a page.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @param int[] $ids Order ids, newest first.
	 * @throws \MerchBoard\Core\Database\DatabaseException When the alerts cannot be read.
	 */
	private function render_orders( array $ids ): void {
		$alerts   = array();
		$outcomes = array();
		$names    = array();

		foreach ( $this->events->for_orders( $ids ) as $event ) {
			$alerts[ $event->order_id ][ $event->creator_id ][] = $event;
		}

		foreach ( $this->messages->outcomes( $ids ) as $outcome ) {
			$outcomes[ $outcome['order_id'] ][ $outcome['creator_id'] ][] = $outcome;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Order', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Time', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Username', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Alerts', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Chat', 'merchboard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $ids as $order_id ) {
			$creators = $alerts[ $order_id ] ?? array();
			$first    = self::first( $creators );

			if ( null === $first ) {
				continue;
			}

			echo '<tr>';
			echo '<td>' . $this->order_cell( $first ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in order_cell().
			echo '<td>' . esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), self::latest( $creators )->getTimestamp() ) ) . '</td>';
			echo '<td>' . esc_html( '' !== $first->customer_username ? $first->customer_username : __( 'Anonymous', 'merchboard' ) ) . '</td>';
			echo '<td>';

			foreach ( $creators as $creator_id => $events ) {
				$names[ $creator_id ] ??= $this->creators->find( $creator_id )->display_name ?? sprintf( '#%d', $creator_id );
				echo '<p style="margin:0 0 4px"><strong>' . esc_html( $names[ $creator_id ] ) . '</strong></p><ul style="margin:0 0 8px">';

				foreach ( $events as $event ) {
					echo '<li>' . self::line( $event ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in line().
				}

				echo '</ul>';
			}

			echo '</td><td>';

			foreach ( array_keys( $creators ) as $creator_id ) {
				echo '<p style="margin:0 0 8px">' . self::chat( $outcomes[ $order_id ][ $creator_id ] ?? array(), count( $creators ) > 1 ? $names[ $creator_id ] ?? '' : '' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in chat().
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The order number, linked to the order when it still exists.
	 *
	 * @param AlertEvent $event An alert of the order.
	 */
	private function order_cell( AlertEvent $event ): string {
		$number = esc_html( '#' . $event->order_number );
		$order  = function_exists( 'wc_get_order' ) ? wc_get_order( $event->order_id ) : false;

		return $order instanceof WC_Order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . $number . '</a>' : $number;
	}

	/**
	 * An alert's product: image, name × quantity and variation.
	 *
	 * @param AlertEvent $event Alert.
	 */
	private static function line( AlertEvent $event ): string {
		$image = $event->image_id > 0 ? wp_get_attachment_image( $event->image_id, array( 32, 32 ), false, array( 'style' => 'vertical-align:middle;margin-right:6px' ) ) : '';
		$text  = sprintf(
			/* translators: 1: product name, 2: quantity. */
			__( '%1$s × %2$d', 'merchboard' ),
			$event->product_name,
			$event->quantity
		);

		return wp_kses_post( $image ) . esc_html( $text ) . ( '' !== $event->variation ? ' <span class="description">' . esc_html( $event->variation ) . '</span>' : '' );
	}

	/**
	 * The chatbot's outcome of a creator's messages, per platform.
	 *
	 * @param list<array{order_id: int, creator_id: int, platform: string, status: string, reason: string, count: int}> $outcomes Outcomes.
	 * @param string                                                                                                    $creator  The creator's name when the order has several creators, else ''.
	 */
	private static function chat( array $outcomes, string $creator ): string {
		$prefix = '' !== $creator ? '<strong>' . esc_html( $creator ) . ':</strong> ' : '';

		if ( array() === $outcomes ) {
			return $prefix . esc_html__( 'No chat message', 'merchboard' );
		}

		$parts = array();

		foreach ( $outcomes as $outcome ) {
			$platform = ChatPlatform::parse( $outcome['platform'] );
			$text     = ( null === $platform ? $outcome['platform'] : $platform->label() ) . ': ' . self::status( $outcome['status'] );

			if ( $outcome['count'] > 1 ) {
				$text .= ' (' . number_format_i18n( $outcome['count'] ) . ')';
			}

			if ( ChatMessageRepository::SENT !== $outcome['status'] && '' !== $outcome['reason'] ) {
				$text .= ' – ' . self::reason( $outcome['reason'] );
			}

			$parts[] = esc_html( $text );
		}

		return $prefix . implode( '<br>', $parts );
	}

	/**
	 * A message status in words.
	 *
	 * @param string $status Status code.
	 */
	private static function status( string $status ): string {
		return match ( $status ) {
			ChatMessageRepository::SENT    => __( 'sent', 'merchboard' ),
			ChatMessageRepository::WAITING => __( 'waiting', 'merchboard' ),
			ChatMessageRepository::FAILED  => __( 'failed', 'merchboard' ),
			ChatMessageRepository::SKIPPED => __( 'skipped', 'merchboard' ),
			default                        => $status,
		};
	}

	/**
	 * A reason in words (its code when unknown).
	 *
	 * @param string $reason Reason code.
	 */
	private static function reason( string $reason ): string {
		return match ( $reason ) {
			'not_live' => __( 'not live', 'merchboard' ),
			default    => $reason,
		};
	}

	/**
	 * The first alert of an order.
	 *
	 * @param array<int, list<AlertEvent>> $creators Alerts by creator.
	 */
	private static function first( array $creators ): ?AlertEvent {
		foreach ( $creators as $events ) {
			foreach ( $events as $event ) {
				return $event;
			}
		}

		return null;
	}

	/**
	 * When the order's latest alert was recorded.
	 *
	 * @param array<int, list<AlertEvent>> $creators Alerts by creator (not empty).
	 */
	private static function latest( array $creators ): DateTimeImmutable {
		$latest = null;

		foreach ( $creators as $events ) {
			foreach ( $events as $event ) {
				$latest = null === $latest || $event->created_at > $latest ? $event->created_at : $latest;
			}
		}

		return $latest ?? new DateTimeImmutable( '@0' );
	}
}
