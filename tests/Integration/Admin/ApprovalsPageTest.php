<?php
/**
 * Everything waiting for the administrator's approval on one page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\Approvals\ApprovalsPage;
use MerchBoard\Modules\Admin\Approvals\ApprovalTab;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerMenu;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\WooCommerce\CreatorPricingAdminTest;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * A tab per kind from the filter, a red count on the menu and each tab while
 * something waits, the first busy tab opens, a user sees only the tabs they
 * may open, and the replaced pages' addresses lead to their tabs.
 */
#[CoversClass( ApprovalsPage::class )]
#[CoversClass( ApprovalTab::class )]
final class ApprovalsPageTest extends IntegrationTestCase {

	/**
	 * Previous $_GET.
	 *
	 * @var array<mixed>
	 */
	private array $saved_get = array();

	/**
	 * Waiting by tab key.
	 *
	 * @var array<string, int>
	 */
	private array $waiting = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture.
		$_GET            = array();
		$this->waiting   = array(
			'first'  => 0,
			'second' => 2,
			'money'  => 1,
		);

		// Late, so only the test's tabs are seen; the modules' own stay registered for other tests.
		add_filter( ApprovalsPage::FILTER, array( $this, 'tabs' ), 999 );
		add_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		remove_filter( ApprovalsPage::FILTER, array( $this, 'tabs' ), 999 );
		remove_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		$_GET = $this->saved_get;
		parent::tearDown();
	}

	/**
	 * The tabs of the test instead of the modules': two for creators and one
	 * for payouts, which throws while counting.
	 *
	 * @param mixed $tabs The modules' tabs.
	 * @return list<ApprovalTab>
	 */
	public function tabs( mixed $tabs ): array {
		unset( $tabs );

		$made = array();

		foreach ( array( 'first', 'second', 'money' ) as $key ) {
			$made[] = new ApprovalTab(
				$key,
				ucfirst( $key ) . ' kind',
				'money' === $key ? Capabilities::MANAGE_PAYOUTS : Capabilities::MANAGE_CREATORS,
				function () use ( $key ): int {
					if ( 'money' === $key && $this->waiting['money'] < 0 ) {
						throw new RuntimeException( 'Cannot count.' );
					}

					return $this->waiting[ $key ];
				},
				static function () use ( $key ): void {
					echo '<p>content of ' . esc_html( $key ) . '</p>';
				},
				'second' === $key ? array( 'merchboard-old-second' ) : array()
			);
		}

		return $made;
	}

	public function test_the_menu_counts_everything_waiting_and_hides_nothing_it_may_open(): void {
		$item = $this->menu_item();

		self::assertNotNull( $item );
		self::assertSame( Capabilities::MANAGE_CREATORS, $item[1] );
		self::assertStringContainsString( 'awaiting-mod count-3', $item[0], 'Two of one kind and one of another.' );

		$this->waiting = array(
			'first'  => 0,
			'second' => 0,
			'money'  => -1,
		);

		self::assertStringNotContainsString( 'awaiting-mod', (string) ( $this->menu_item()[0] ?? '' ), 'Nothing waiting, or a kind that cannot be counted, shows no count.' );
	}

	public function test_the_first_busy_tab_opens_and_each_busy_tab_shows_its_count(): void {
		$html = $this->render();

		self::assertStringContainsString( 'content of second', $html );
		self::assertStringNotContainsString( 'content of first', $html );
		self::assertSame( 2, substr_count( $html, 'merchboard-approval-count' ), 'Only the busy tabs have a red count.' );
		self::assertMatchesRegularExpression( '#nav-tab nav-tab-active" href="[^"]*tab=second#', $html );

		$_GET['tab'] = 'first';

		self::assertStringContainsString( 'content of first', $this->render(), 'A tab opens by its key.' );

		$_GET['tab'] = 'unknown';

		self::assertStringContainsString( 'content of second', $this->render() );
	}

	public function test_a_user_sees_only_the_tabs_they_may_open(): void {
		$user = $this->create_user( 'payouts-only' );

		( new \WP_User( $user ) )->add_cap( Capabilities::MANAGE_PAYOUTS );
		wp_set_current_user( $user );

		$html = $this->render();

		self::assertStringContainsString( 'content of money', $html );
		self::assertStringNotContainsString( 'Second kind', $html );
		self::assertSame( Capabilities::MANAGE_PAYOUTS, $this->menu_item()[1] ?? null );

		wp_set_current_user( $this->create_user( 'nobody' ) );

		try {
			$this->render();
			self::fail( 'Refused expected.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'not allowed', $e->getMessage() );
		}
	}

	public function test_a_replaced_page_leads_to_its_tab_with_its_arguments(): void {
		$url = (string) ApprovalsPage::former_url(
			array(
				'page'                   => 'merchboard-old-second',
				'merchboard_base_result' => 'approved',
			),
			ApprovalsPage::all()
		);

		self::assertStringContainsString( 'page=' . ApprovalsPage::SLUG, $url );
		self::assertStringContainsString( 'tab=second', $url );
		self::assertStringContainsString( 'merchboard_base_result=approved', $url );
		self::assertNull( ApprovalsPage::former_url( array( 'page' => 'merchboard-settings' ), ApprovalsPage::all() ) );

		// The product designer's former designs page: its queue is a tab here, its notification settings stay.
		self::assertStringContainsString( 'page=' . ApprovalsPage::SLUG . '&tab=designs', (string) DesignerMenu::former_url( array( 'page' => DesignerMenu::LINK_DESIGNS ) ) );
		self::assertStringContainsString( 'page=' . DesignerMenu::SLUG . '&tab=notifications', (string) DesignerMenu::former_url( array( 'page' => DesignerMenu::LINK_DESIGNS, 'view' => 'notifications' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * The page's menu item: [title, capability, slug, …], or null.
	 *
	 * @return array<int, string>|null
	 */
	private function menu_item(): ?array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$GLOBALS['submenu'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu for this test.

		( new ApprovalsPage() )->add_menu();

		foreach ( (array) ( $GLOBALS['submenu']['merchboard'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && ApprovalsPage::SLUG === ( $entry[2] ?? null ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * The rendered page.
	 */
	private function render(): string {
		ob_start();

		try {
			( new ApprovalsPage() )->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}
}
