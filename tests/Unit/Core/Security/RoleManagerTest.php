<?php
/**
 * Tests for role and capability synchronisation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Security;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\InMemoryRoles;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RoleManager::class )]
final class RoleManagerTest extends TestCase {

	private const ADMIN_CAPS = array(
		'read'               => true,
		'manage_options'     => true,
		'activate_plugins'   => true,
		'edit_users'         => true,
		'delete_users'       => true,
		'manage_woocommerce' => true,
	);

	private InMemoryOptions $options;

	private RecordingLogger $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->options = InMemoryOptions::install();
		$this->logger  = new RecordingLogger();
	}

	public function test_fresh_install_creates_creator_role_with_exact_capabilities(): void {
		$roles = $this->wordpress_roles();

		self::assertTrue( $this->manager()->sync() );

		self::assertSame(
			array(
				'read'                        => true,
				'merchboard_access_dashboard' => true,
			),
			$roles->capabilities( 'merchboard_creator' )
		);
		self::assertSame( 'Merch Dashboard Creator', $roles->names['merchboard_creator'] );
		self::assertSame( RoleManager::VERSION, $this->options->options[ RoleManager::VERSION_OPTION ] );
	}

	public function test_administrator_gains_plugin_capabilities_and_keeps_all_existing_ones(): void {
		$roles = $this->wordpress_roles();

		$this->manager()->sync();

		self::assertSame(
			self::ADMIN_CAPS + array(
				'merchboard_manage'                 => true,
				'merchboard_manage_creators'        => true,
				'merchboard_manage_settings'        => true,
				'merchboard_view_logs'              => true,
				'merchboard_manage_creator_pricing' => true,
				'merchboard_delete_order_history'   => true,
				'merchboard_manage_payouts'         => true,
				'merchboard_view_payout_details'    => true,
			),
			$roles->capabilities( 'administrator' )
		);
	}

	public function test_repeated_sync_is_idempotent_and_does_not_duplicate_the_role(): void {
		$roles   = $this->wordpress_roles();
		$manager = $this->manager();

		$manager->sync();
		$after_first = array(
			'creator' => $roles->capabilities( 'merchboard_creator' ),
			'admin'   => $roles->capabilities( 'administrator' ),
		);
		$manager->sync();
		$manager->sync();

		self::assertSame( 1, $roles->add_role_calls, 'Role must be created once and then only synchronised.' );
		self::assertSame( $after_first['creator'], $roles->capabilities( 'merchboard_creator' ) );
		self::assertSame( $after_first['admin'], $roles->capabilities( 'administrator' ) );
		self::assertSame( array(), $this->logger->entries );
	}

	public function test_other_roles_are_never_modified(): void {
		$roles  = $this->wordpress_roles();
		$before = array(
			'editor'       => $roles->capabilities( 'editor' ),
			'subscriber'   => $roles->capabilities( 'subscriber' ),
			'customer'     => $roles->capabilities( 'customer' ),
			'shop_manager' => $roles->capabilities( 'shop_manager' ),
		);

		$this->manager()->sync();

		foreach ( $before as $role => $capabilities ) {
			self::assertSame( $capabilities, $roles->capabilities( $role ), $role );
		}
	}

	public function test_escalated_creator_role_is_reduced_to_the_defined_capabilities(): void {
		$roles = $this->wordpress_roles(
			array(
				'merchboard_creator' => array(
					'read'                        => true,
					'merchboard_access_dashboard' => true,
					'manage_options'              => true,
					'edit_users'                  => true,
					'merchboard_manage_creators'  => true,
				),
			)
		);

		$this->manager()->sync();

		self::assertSame(
			array(
				'read'                        => true,
				'merchboard_access_dashboard' => true,
			),
			$roles->capabilities( 'merchboard_creator' )
		);
		self::assertSame( 'warning', $this->logger->entries[0]['level'] );
		self::assertSame(
			array( 'manage_options', 'edit_users', 'merchboard_manage_creators' ),
			$this->logger->entries[0]['context']['capabilities']
		);
	}

	public function test_product_capabilities_on_the_creator_role_are_removed(): void {
		$roles = $this->wordpress_roles(
			array(
				'merchboard_creator' => array(
					'read'                        => true,
					'merchboard_access_dashboard' => true,
					'edit_product'                => true,
					'edit_products'               => true,
					'publish_products'            => true,
					'delete_products'             => true,
				),
			)
		);

		$this->manager()->sync();

		self::assertSame(
			array(
				'read'                        => true,
				'merchboard_access_dashboard' => true,
			),
			$roles->capabilities( 'merchboard_creator' )
		);
	}

	public function test_denied_creator_capability_is_granted_again(): void {
		$roles = $this->wordpress_roles(
			array(
				'merchboard_creator' => array(
					'read'                        => true,
					'merchboard_access_dashboard' => false,
				),
			)
		);

		$this->manager()->sync();

		self::assertTrue( $roles->capabilities( 'merchboard_creator' )['merchboard_access_dashboard'] );
	}

	public function test_obsolete_plugin_capabilities_are_removed_from_administrator_but_foreign_ones_kept(): void {
		$admin = self::ADMIN_CAPS + array(
			'merchboard_retired_capability' => true,
			'merchboard_access_dashboard'   => true,
			'some_other_plugin_capability'  => true,
		);
		$roles = $this->wordpress_roles( array( 'administrator' => $admin ) );

		$this->manager()->sync();

		$capabilities = $roles->capabilities( 'administrator' );
		self::assertArrayNotHasKey( 'merchboard_retired_capability', $capabilities );
		self::assertArrayNotHasKey( 'merchboard_access_dashboard', $capabilities, 'Administrators are not creators by default.' );
		self::assertTrue( $capabilities['some_other_plugin_capability'] );
		self::assertTrue( $capabilities['manage_options'] );
	}

	public function test_missing_administrator_role_is_logged_and_does_not_block_the_creator_role(): void {
		$roles = InMemoryRoles::install( array( 'subscriber' => array( 'read' => true ) ) );

		self::assertTrue( $this->manager()->sync() );

		self::assertArrayHasKey( 'merchboard_creator', $roles->roles );
		self::assertSame( 'warning', $this->logger->entries[0]['level'] );
		self::assertSame( RoleManager::VERSION, $this->options->options[ RoleManager::VERSION_OPTION ] );
	}

	public function test_failure_to_create_the_role_is_logged_and_version_not_recorded(): void {
		InMemoryRoles::install( array( 'administrator' => self::ADMIN_CAPS ), true );

		self::assertFalse( $this->manager()->sync() );

		self::assertSame( 'error', $this->logger->entries[0]['level'] );
		self::assertArrayNotHasKey( RoleManager::VERSION_OPTION, $this->options->options );
	}

	public function test_needs_sync_follows_the_stored_version(): void {
		$manager = $this->manager();

		self::assertTrue( $manager->needs_sync() );

		$this->options->options[ RoleManager::VERSION_OPTION ] = 'garbage';
		self::assertTrue( $manager->needs_sync() );

		$this->options->options[ RoleManager::VERSION_OPTION ] = (string) RoleManager::VERSION;
		self::assertFalse( $manager->needs_sync() );
	}

	public function test_resulting_permissions_positive_and_negative(): void {
		$roles = $this->wordpress_roles();
		$this->manager()->sync();

		$admin      = array( 'administrator' );
		$creator    = array( 'merchboard_creator' );
		$customer   = array( 'customer', 'merchboard_creator' );
		$subscriber = array( 'subscriber' );

		// Creator: dashboard access only.
		self::assertTrue( $roles->user_with_roles_can( $creator, Capabilities::ACCESS_DASHBOARD ) );
		foreach ( array_merge( Capabilities::admin_capabilities(), array( 'manage_options', 'activate_plugins', 'edit_users' ) ) as $capability ) {
			self::assertFalse( $roles->user_with_roles_can( $creator, $capability ), $capability );
		}

		// A customer who is also a creator keeps customer rights and gains only dashboard access.
		self::assertTrue( $roles->user_with_roles_can( $customer, Capabilities::ACCESS_DASHBOARD ) );
		self::assertFalse( $roles->user_with_roles_can( $customer, Capabilities::MANAGE ) );

		// Administrator: plugin administration, not creator access.
		foreach ( Capabilities::admin_capabilities() as $capability ) {
			self::assertTrue( $roles->user_with_roles_can( $admin, $capability ), $capability );
		}
		self::assertFalse( $roles->user_with_roles_can( $admin, Capabilities::ACCESS_DASHBOARD ) );

		// Ordinary users get nothing from the plugin.
		foreach ( Capabilities::all() as $capability ) {
			self::assertFalse( $roles->user_with_roles_can( $subscriber, $capability ), $capability );
		}

		// Capabilities the plugin does not (yet) define are granted to nobody.
		foreach ( array( $admin, $creator, $subscriber ) as $user_roles ) {
			self::assertFalse( $roles->user_with_roles_can( $user_roles, 'merchboard_manage_settlements' ) );
			self::assertFalse( $roles->user_with_roles_can( $user_roles, 'merchboard_view_reports' ) );
		}

		// Audit logs: administrators only; dashboard access never implies it.
		self::assertTrue( $roles->user_with_roles_can( $admin, Capabilities::VIEW_LOGS ) );
		self::assertFalse( $roles->user_with_roles_can( $creator, Capabilities::VIEW_LOGS ) );
		self::assertFalse( $roles->user_with_roles_can( $customer, Capabilities::VIEW_LOGS ) );
	}

	/**
	 * Role manager under test.
	 */
	private function manager(): RoleManager {
		return new RoleManager( $this->logger );
	}

	/**
	 * A typical WordPress + WooCommerce role setup.
	 *
	 * @param array<string, array<string, bool>> $overrides Roles to add or replace.
	 */
	private function wordpress_roles( array $overrides = array() ): InMemoryRoles {
		return InMemoryRoles::install(
			array_merge(
				array(
					'administrator' => self::ADMIN_CAPS,
					'editor'        => array(
						'read'       => true,
						'edit_posts' => true,
					),
					'subscriber'    => array( 'read' => true ),
					'customer'      => array( 'read' => true ),
					'shop_manager'  => array(
						'read'               => true,
						'manage_woocommerce' => true,
					),
				),
				$overrides
			)
		);
	}
}
