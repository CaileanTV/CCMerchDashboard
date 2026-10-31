<?php
/**
 * Integration tests for the administrator's order log.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;
use MerchBoard\Modules\Alerts\Admin\OrderLogTab;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessageRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Step 25.9.3 (ADR-065 pkt. 7): the order log shows the orders with alerts
 * of a period (today from midnight in the site's time zone, the last 24
 * hours, all), newest first across all creators, with totals, each
 * creator's alerts and the chatbot's outcome; it is the Alerts page's first
 * and default tab.
 */
#[CoversClass( OrderLogTab::class )]
#[CoversClass( AlertEventRepository::class )]
#[CoversClass( ChatMessageRepository::class )]
final class OrderLogTest extends IntegrationTestCase {

	/**
	 * Now: 2026-10-22 12:00 in Copenhagen (10:00 UTC).
	 */
	private const NOW = '2026-10-22 12:00:00';

	private AlertEventRepository $events;
	private ChatMessageRepository $messages;
	private CreatorRepository $creators;
	private int $nova;
	private int $orion;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		update_option( 'timezone_string', 'Europe/Copenhagen' );

		$this->events   = new AlertEventRepository( $this->wpdb );
		$this->messages = new ChatMessageRepository( $this->wpdb );
		$this->creators = new CreatorRepository( $this->wpdb );
		$this->nova     = $this->creators->create( 'Nova', CreatorStatus::Active )->id;
		$this->orion    = $this->creators->create( 'Orion', CreatorStatus::Active )->id;
	}

	protected function tearDown(): void {
		delete_option( 'timezone_string' );
		unset( $_GET['tab'], $_GET[ OrderLogTab::PERIOD_ARG ], $_GET[ OrderLogTab::PAGE_ARG ] );
		parent::tearDown();
	}

	public function test_the_periods_count_and_list_orders_newest_first_across_creators(): void {
		// Yesterday 23:30 in Copenhagen: within 24 hours, not today.
		$this->alert( $this->nova, 100, 1001, '2026-10-21 21:30:00', 'Hoodie', 'old_fan' );
		// Two days ago: only in "all".
		$this->alert( $this->nova, 101, 1011, '2026-10-20 08:00:00', 'Mug', '' );
		// Today: an order with two creators, and a later order.
		$this->alert( $this->nova, 102, 1021, '2026-10-22 06:00:00', 'Cap', 'nova_fan' );
		$this->alert( $this->orion, 102, 1022, '2026-10-22 06:00:00', 'Poster', 'nova_fan' );
		$this->alert( $this->orion, 103, 1031, '2026-10-22 09:00:00', 'Sticker', '' );

		$tab = $this->tab();
		self::assertSame( '2026-10-21 22:00:00', $tab->since( 'today' )?->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'Midnight in the site\'s time zone.' );
		self::assertSame(
			array(
				array(
					'orders'   => 2,
					'alerts'   => 3,
					'creators' => 2,
				),
				array(
					'orders'   => 3,
					'alerts'   => 4,
					'creators' => 2,
				),
				array(
					'orders'   => 4,
					'alerts'   => 5,
					'creators' => 2,
				),
			),
			array( $this->events->log_totals( $tab->since( 'today' ) ), $this->events->log_totals( $tab->since( '24h' ) ), $this->events->log_totals( null ) )
		);
		self::assertSame( 'today', OrderLogTab::period( '<script>' ) );

		$this->messages->note( $this->nova, ChatPlatform::Twitch, 102, 0, ChatMessageRepository::SENT );
		$this->messages->note( $this->orion, ChatPlatform::YouTube, 102, 0, ChatMessageRepository::WAITING, 'not_live' );

		$today = $this->render( $tab, 'today' );
		self::assertLessThan( strpos( $today, '#102' ), strpos( $today, '#103' ), 'The newest order first.' );
		self::assertStringNotContainsString( '#100', $today );
		self::assertStringContainsString( 'nova_fan', $today );
		self::assertStringContainsString( 'Anonymous', $today, 'Without a username.' );
		self::assertStringContainsString( 'Cap × 1', $today );
		self::assertStringContainsString( '<strong>Orion</strong>', $today );
		self::assertStringContainsString( 'Twitch: sent', $today );
		self::assertStringContainsString( 'YouTube: waiting – not live', $today );
		self::assertStringContainsString( 'No chat message', $today );
		self::assertStringContainsString( '<strong aria-current="page">Today</strong>', $today );

		$all = $this->render( $tab, 'all' );
		self::assertStringContainsString( '#100', $all );
		self::assertStringContainsString( '#101', $all );
		self::assertStringContainsString( '<strong aria-current="page">All</strong>', $all );
		self::assertStringContainsString( '#100', $this->render( $tab, '24h' ) );
	}

	public function test_long_periods_are_paged_and_an_empty_period_says_so(): void {
		$tab = $this->tab();
		self::assertStringContainsString( 'No orders with alerts in this period.', $this->render( $tab, 'today' ) );

		for ( $order = 1; $order <= OrderLogTab::PER_PAGE + 1; $order++ ) {
			$this->alert( $this->nova, 200 + $order, 2000 + $order, '2026-10-22 08:00:00', 'Hoodie', '' );
		}

		$first = $this->render( $tab, 'today' );
		self::assertStringContainsString( '#221', $first );
		self::assertStringNotContainsString( '#201<', $first, 'The oldest is on page 2.' );
		self::assertStringContainsString( 'paged=2', $first );
		self::assertStringContainsString( '#201<', $this->render( $tab, 'today', 2 ) );
		self::assertStringContainsString( '#201<', $this->render( $tab, 'today', 99 ), 'A page after the last shows the last.' );
	}

	public function test_the_order_log_is_the_alerts_pages_first_and_default_tab(): void {
		$this->alert( $this->nova, 102, 1021, '2026-10-22 06:00:00', 'Cap', 'nova_fan' );
		$page = new AlertsAdminPage( null, null, null, null, $this->tab() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'nav-tab-active" href="' . esc_url( AlertsAdminPage::url( OrderLogTab::TAB ) ) . '" aria-current="page">Order log', $html );
		self::assertStringNotContainsString( '>Fonts<', $html, 'Fonts are under Settings.' );
		self::assertStringContainsString( 'Orders with alerts', $html );
		self::assertStringContainsString( 'Off. No alerts are recorded.', $html, 'The alerts card.' );

		$_GET['tab'] = 'fonts';
		ob_start();
		$page->render();
		self::assertStringNotContainsString( 'Orders with alerts', (string) ob_get_clean(), 'Fonts is still a tab.' );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ), 'Reading writes nothing.' );
	}

	/**
	 * The tab at NOW.
	 */
	private function tab(): OrderLogTab {
		return new OrderLogTab( $this->events, $this->messages, $this->creators, new AlertsAvailability( static fn(): bool => false ), static fn(): DateTimeImmutable => new DateTimeImmutable( self::NOW, new DateTimeZone( 'Europe/Copenhagen' ) ) );
	}

	/**
	 * The rendered tab.
	 *
	 * @param OrderLogTab $tab    Tab.
	 * @param string      $period Period.
	 * @param int         $page   Page.
	 */
	private function render( OrderLogTab $tab, string $period, int $page = 1 ): string {
		ob_start();
		$tab->render( $period, $page );

		return (string) ob_get_clean();
	}

	/**
	 * Stores an alert.
	 *
	 * @param int    $creator_id Creator.
	 * @param int    $order_id   Order (also its number).
	 * @param int    $item_id    Order line.
	 * @param string $created    When it was recorded (UTC).
	 * @param string $product    Product name.
	 * @param string $username   Username.
	 */
	private function alert( int $creator_id, int $order_id, int $item_id, string $created, string $product, string $username ): void {
		$this->events->insert( new AlertEvent( $creator_id, $order_id, (string) $order_id, $item_id, 5, 0, $product, '', 1, 0, array(), $username, new DateTimeImmutable( $created, new DateTimeZone( 'UTC' ) ) ) );
	}
}
