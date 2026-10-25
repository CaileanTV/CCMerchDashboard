<?php
/**
 * Tests for the top-level menu, Overview and the submenus under it.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Admin;

use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Admin\AdminModule;
use MerchBoard\Modules\Admin\OverviewPage;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\UserAccounts;
use MerchBoard\Modules\Settings\Admin\SettingsPage;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use wpdb;

#[CoversClass( AdminMenu::class )]
#[CoversClass( AdminModule::class )]
#[CoversClass( OverviewPage::class )]
#[CoversClass( CreatorRepository::class )]
final class AdminShellTest extends TestCase {

	use CreatorAdminEnvironment;

	private InMemoryOptions $options;

	/**
	 * Menu registrations: [function, parent|null, capability, slug].
	 *
	 * @var list<array{0: string, 1: string|null, 2: string, 3: string}>
	 */
	private array $menus = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();
		$this->options = InMemoryOptions::install(
			array(
				Migrator::VERSION_OPTION    => 31,
				RoleManager::VERSION_OPTION => RoleManager::VERSION,
			)
		);

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( '_n' )->alias( static fn( string $single, string $plural, int $n ): string => 1 === $n ? $single : $plural );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title = '', array $args = array() ): void {
				throw new WpDie( $message, (int) ( $args['response'] ?? 500 ) );
			}
		);
		Functions\when( 'add_menu_page' )->alias(
			function ( string $page_title, string $menu_title, string $capability, string $slug ): string {
				$this->menus[] = array( 'add_menu_page', null, $capability, $slug );
				return 'toplevel_page_' . $slug;
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			function ( string $parent_slug, string $page_title, string $menu_title, string $capability, string $slug ): string {
				$this->menus[] = array( 'add_submenu_page', $parent_slug, $capability, $slug );
				return 'merch-dashboard_page_' . $slug;
			}
		);
	}

	public function test_top_level_menu_and_overview_require_merchboard_manage(): void {
		( new AdminMenu( $this->overview() ) )->register();
		( new CreatorsAdminPage( new CreatorRepository( $this->db ), $this->users, new TransactionSupport( $this->db ) ) )->add_menu();
		( new SettingsPage() )->add_menu();

		self::assertSame(
			array(
				array( 'add_menu_page', null, Capabilities::MANAGE, 'merchboard' ),
				array( 'add_submenu_page', 'merchboard', Capabilities::MANAGE, 'merchboard' ),
				array( 'add_submenu_page', 'merchboard', Capabilities::MANAGE_CREATORS, 'merchboard-creators' ),
				array( 'add_submenu_page', 'merchboard', Capabilities::MANAGE_SETTINGS, 'merchboard-settings' ),
			),
			$this->menus,
			'One top-level menu; each page keeps its own, more specific capability.'
		);
	}

	public function test_admin_menu_runs_before_feature_submenus(): void {
		( new AdminModule() )->register( $this->container() );

		self::assertSame( 9, AdminMenu::PRIORITY );
		self::assertNotFalse( has_action( 'admin_menu' ) );
	}

	public function test_overview_refuses_users_without_merchboard_manage(): void {
		foreach ( array( array( Capabilities::MANAGE_CREATORS ), array( Capabilities::MANAGE_SETTINGS ), array( Capabilities::ACCESS_DASHBOARD ), array( 'read' ), array( 'manage_options' ) ) as $caps ) {
			$this->caps = $caps;

			try {
				$this->render();
				self::fail( 'Expected WpDie for ' . implode( ',', $caps ) );
			} catch ( WpDie $e ) {
				self::assertSame( 403, $e->response );
			}
		}
	}

	public function test_overview_shows_counts_and_system_status_from_real_data(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );
		$this->service->create( 'Other', CreatorStatus::Inactive, 43 );
		$this->service->create( 'Unlinked', CreatorStatus::Inactive, null );
		$this->caps = array( Capabilities::MANAGE );

		$html = $this->render();

		self::assertStringContainsString( '<th scope="row">Active</th><td>1</td>', $html );
		self::assertStringContainsString( '<th scope="row">Inactive</th><td>2</td>', $html );
		self::assertStringContainsString( '<th scope="row">Total</th><td>3</td>', $html );
		self::assertStringContainsString( '<th scope="row">Linked to a WordPress user</th><td>2</td>', $html );
		self::assertStringContainsString( 'Up to date (version 31)', $html );
		self::assertStringContainsString( '<th scope="row">Transaction support</th><td>Available</td>', $html );
		self::assertStringContainsString( '<th scope="row">Roles and capabilities</th><td>Up to date</td>', $html );
		self::assertStringContainsString( 'Not active (optional for now)', $html );
		self::assertStringContainsString( 'Kept when the plugin is deleted', $html );
		self::assertStringNotContainsString( 'no longer exists', $html );
		self::assertStringNotContainsString( 'Nova', $html, 'No names or other personal data.' );
		self::assertStringNotContainsString( '@', $html );
		self::assertStringNotContainsString( 'Manage creators', $html, 'Links only for users who may use them.' );
		self::assertStringNotContainsString( 'page=merchboard-settings', $html );
	}

	public function test_overview_reports_problems(): void {
		$this->service->create( 'Dangling', CreatorStatus::Active, 42 );
		unset( $this->users->users[42] );
		$this->options->options[ Migrator::VERSION_OPTION ]    = 1;
		$this->options->options[ RoleManager::VERSION_OPTION ] = 0;
		$this->options->options[ Settings::OPTION ]            = array( Settings::DELETE_DATA_ON_UNINSTALL => true );
		$this->db->engines['merchboard_audit_log']             = 'MyISAM';
		$this->caps = array( Capabilities::MANAGE, Capabilities::MANAGE_CREATORS, Capabilities::MANAGE_SETTINGS );

		$html = $this->render();

		self::assertStringContainsString( '1 creator is linked to a WordPress user that no longer exists.', $html );
		self::assertStringContainsString( 'Update pending (version 1 of 31)', $html );
		self::assertStringContainsString( 'Not available: creator changes are disabled', $html );
		self::assertStringContainsString( '<th scope="row">Roles and capabilities</th><td>Update pending</td>', $html );
		self::assertStringContainsString( 'DELETED when the plugin is deleted', $html );
		self::assertStringContainsString( 'page=merchboard-creators', $html );
		self::assertStringContainsString( 'page=merchboard-settings', $html );

		$this->options->transients['merchboard_db_migration_failed'] = 1;
		self::assertStringContainsString( 'Update failed; retried automatically.', $this->render() );
	}

	public function test_recent_activity_only_for_users_who_may_view_logs(): void {
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => gmdate( 'Y-m-d H:i', $ts ) );
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );

		$this->caps = array( Capabilities::MANAGE );
		self::assertStringNotContainsString( 'Recent activity', $this->render() );

		$this->caps = array( Capabilities::MANAGE, Capabilities::VIEW_LOGS );
		$html       = $this->render();
		self::assertStringContainsString( 'Recent activity', $html );
		self::assertStringContainsString( '<td>Creator created</td>', $html );
		self::assertStringContainsString( 'page=merchboard-audit-log', $html );
		self::assertStringNotContainsString( 'Nova', $html, 'Event and time only.' );
	}

	private function overview(): OverviewPage {
		return new OverviewPage(
			new CreatorRepository( $this->db ),
			$this->users,
			new Migrator( $this->db, Schema::migrations() ),
			new TransactionSupport( $this->db ),
			new RoleManager( new RecordingLogger() ),
			new AuditRepository( $this->db )
		);
	}

	private function render(): string {
		ob_start();

		try {
			$this->overview()->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	private function container(): Container {
		$container = new Container();
		$container->set( wpdb::class, fn(): wpdb => $this->db );
		$container->set( CreatorRepository::class, fn(): CreatorRepository => new CreatorRepository( $this->db ) );
		$container->set( UserAccounts::class, fn(): UserAccounts => $this->users );

		return $container;
	}
}
