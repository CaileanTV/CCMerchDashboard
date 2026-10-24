<?php
/**
 * Admin navigation, Overview and user selection with real WordPress.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\OverviewPage;
use MerchBoard\Modules\Creators\Admin\CreatorAdminActions;
use MerchBoard\Modules\Creators\Admin\UserProfileSection;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\UserAccounts;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use WP_User;

final class AdminPhase10Test extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install();
	}

	public function test_user_search_matches_login_and_display_name_but_never_email(): void {
		$users = $this->service( UserAccounts::class );
		$id    = wp_insert_user(
			array(
				'user_login'   => 'searchable_login',
				'user_pass'    => wp_generate_password(),
				'user_email'   => 'hidden.mailbox@example.org',
				'display_name' => 'Zelda Quartz',
				'role'         => 'subscriber',
			)
		);
		self::assertIsInt( $id );

		self::assertSame( array( 'searchable_login' ), array_column( $users->search( 'able_log', 20 ), 'login' ) );
		self::assertSame( array( 'searchable_login' ), array_column( $users->search( 'quartz', 20 ), 'login' ) );
		self::assertSame( array(), $users->search( 'hidden.mailbox', 20 ), 'E-mail is not searched.' );
		self::assertSame( array(), $users->search( 'hidden.mailbox@example.org', 20 ), 'Not even with "@" in the term.' );
		self::assertSame( array(), $users->search( 'q', 20 ), 'At least two characters.' );

		$result = $users->search( 'quartz', 20 )[0];
		self::assertSame( array( 'id', 'login', 'label' ), array_keys( $result ) );
		self::assertSame( 'Zelda Quartz (searchable_login)', $result['label'] );
		self::assertStringNotContainsString( '@', (string) wp_json_encode( $result ) );
	}

	public function test_user_search_escapes_wildcards_and_respects_the_limit(): void {
		$users = $this->service( UserAccounts::class );

		for ( $i = 0; $i < 4; $i++ ) {
			$this->create_user( 'bulk_user_' . $i );
		}

		self::assertCount( 2, $users->search( 'bulk_user', 2 ) );
		self::assertSame( array(), $users->search( '%%', 20 ), 'SQL wildcards are literal.' );
		self::assertSame( array(), $users->search( "' OR 1=1 --", 20 ) );
	}

	public function test_login_lookups(): void {
		$users = $this->service( UserAccounts::class );
		$id    = $this->create_user( 'lookup_me' );

		self::assertSame( $id, $users->find_by_login( 'lookup_me' ) );
		self::assertNull( $users->find_by_login( 'lookup' ), 'Exact match only.' );
		self::assertNull( $users->find_by_login( '' ) );
		self::assertSame( 'lookup_me', $users->login( $id ) );
		self::assertNull( $users->login( 999999 ) );
	}

	public function test_form_links_a_user_by_login_and_rejects_unknown_logins(): void {
		$id      = $this->create_user( 'form_login' );
		$actions = $this->service( CreatorAdminActions::class );

		$url = $actions->save(
			array(
				'_wpnonce'                      => wp_create_nonce( CreatorAdminActions::save_nonce_action( 0 ) ),
				'creator_id'                    => '0',
				'display_name'                  => 'By Login',
				'status'                        => 'inactive',
				CreatorAdminActions::USER_FIELD => 'form_login',
			)
		);

		self::assertStringContainsString( 'merchboard_notice=created', $url );
		self::assertSame( (string) $id, $this->rows( Tables::CREATORS )[0]['user_id'] );

		$url = $actions->save(
			array(
				'_wpnonce'                      => wp_create_nonce( CreatorAdminActions::save_nonce_action( 0 ) ),
				'creator_id'                    => '0',
				'display_name'                  => 'Unknown',
				'status'                        => 'inactive',
				CreatorAdminActions::USER_FIELD => 'nobody_by_that_login',
			)
		);

		self::assertStringContainsString( 'merchboard_notice=user_not_found', $url );
		self::assertCount( 1, $this->rows( Tables::CREATORS ) );
	}

	public function test_menu_uses_merchboard_manage_for_the_top_level_and_specific_caps_below(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$GLOBALS['menu']    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu for this test.
		$GLOBALS['submenu'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu for this test.

		do_action( 'admin_menu', '' );

		$menu    = $GLOBALS['menu'];
		$submenu = $GLOBALS['submenu'];
		self::assertIsArray( $menu );
		self::assertIsArray( $submenu );
		self::assertIsArray( $submenu['merchboard'] ?? null );

		$top = array_values( array_filter( $menu, static fn( array $item ): bool => 'merchboard' === $item[2] ) );
		self::assertCount( 1, $top, 'Exactly one Merch Dashboard top-level menu.' );
		self::assertSame( Capabilities::MANAGE, $top[0][1] );
		self::assertSame(
			array(
				'merchboard'           => Capabilities::MANAGE,
				'merchboard-creators'  => Capabilities::MANAGE_CREATORS,
				'merchboard-settings'  => Capabilities::MANAGE_SETTINGS,
				'merchboard-news'      => Capabilities::MANAGE_CREATORS,
				'merchboard-audit-log' => Capabilities::VIEW_LOGS,
			),
			array_column( $submenu['merchboard'], 1, 2 )
		);
	}

	public function test_overview_renders_real_counts_and_status_for_administrators_only(): void {
		$creators = $this->service( CreatorService::class );
		$creators->create( 'Active One', CreatorStatus::Active, $this->create_user( 'overview_active' ) );
		$creators->create( 'Inactive One', CreatorStatus::Inactive, null );

		ob_start();
		$this->service( OverviewPage::class )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<th scope="row">Active</th><td>1</td>', $html );
		self::assertStringContainsString( '<th scope="row">Total</th><td>2</td>', $html );
		self::assertStringContainsString( 'Up to date (version 29)', $html );
		self::assertStringContainsString( '<th scope="row">Transaction support</th><td>Available</td>', $html );
		self::assertStringNotContainsString( 'Active One', $html );
		self::assertStringNotContainsString( '@example.org', $html );

		foreach ( array( 'subscriber' ) as $role ) {
			wp_set_current_user( $this->create_user( 'overview_' . $role ) );
			self::assertFalse( current_user_can( Capabilities::MANAGE ) );
		}

		self::assertTrue( user_can( 1, Capabilities::MANAGE ) );
	}

	public function test_profile_section_for_linked_and_unlinked_users(): void {
		$linked   = $this->create_user( 'profile_linked' );
		$unlinked = $this->create_user( 'profile_unlinked' );
		$this->service( CreatorService::class )->create( 'Profile Creator', CreatorStatus::Active, $linked );
		$section = $this->service( UserProfileSection::class );

		self::assertStringContainsString( 'Profile Creator</a> (Active)', $this->profile( $section, $linked ) );
		self::assertStringContainsString( 'action=new&#038;user_id=' . $unlinked, $this->profile( $section, $unlinked ) );

		wp_set_current_user( $unlinked );
		self::assertSame( '', $this->profile( $section, $linked ), 'Nothing for users without merchboard_manage_creators.' );
	}

	/**
	 * Renders the profile section.
	 *
	 * @param UserProfileSection $section Section.
	 * @param int                $user_id User id.
	 */
	private function profile( UserProfileSection $section, int $user_id ): string {
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		ob_start();
		$section->render( $user );

		return (string) ob_get_clean();
	}
}
