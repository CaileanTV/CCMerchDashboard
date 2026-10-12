<?php
/**
 * Section registry and Danish translations with real WordPress (phase 15).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Dashboard;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Dashboard\Branding\BrandLogo;
use MerchBoard\Modules\Dashboard\DashboardAssets;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Dashboard\DashboardSections;
use MerchBoard\Modules\Dashboard\ShellController;
use MerchBoard\Modules\Dashboard\ThemePreference;
use MerchBoard\Modules\WooCommerce\Dashboard\DashboardSectionRegistration;
use MerchBoard\Tests\Integration\IntegrationTestCase;

final class SectionsAndTranslationsTest extends IntegrationTestCase {

	private const MO = MERCHBOARD_TEST_ROOT . '/languages/merchboard-da_DK.mo';

	private int $creator;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->creator = $this->create_user( 'section_creator' );
		$this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->creator );
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh script registry, as in a new request.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh style registry, as in a new request.
	}

	protected function tearDown(): void {
		remove_all_filters( DashboardSections::FILTER );
		unload_textdomain( 'merchboard' );
		$GLOBALS['locale']     = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset to the site default.
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset.
		parent::tearDown();
	}

	public function test_registry_uses_real_capabilities_and_the_filter(): void {
		add_filter(
			DashboardSections::FILTER,
			static function ( array $sections ): array {
				$sections[] = array(
					'id'         => 'reports',
					'label'      => 'Reports',
					'path'       => 'reports',
					'capability' => Capabilities::MANAGE,
				);
				$sections[] = array(
					'id'         => 'broken',
					'label'      => '',
					'path'       => '../x',
					'capability' => Capabilities::ACCESS_DASHBOARD,
				);

				return $sections;
			}
		);
		$registry = $this->service( DashboardSections::class );

		wp_set_current_user( $this->creator );
		self::assertSame( array( 'overview', 'profile' ), array_column( $registry->for_current_user(), 'id' ), 'Creator: no manage capability, broken entry dropped.' );

		wp_get_current_user()->add_cap( Capabilities::MANAGE );
		self::assertSame( array( 'overview', 'profile', 'reports' ), array_column( $registry->for_current_user(), 'id' ) );

		wp_set_current_user( $this->create_user( 'plain_subscriber' ) );
		self::assertSame( array(), $registry->for_current_user() );
	}

	public function test_the_sales_products_and_orders_sections_are_sent_only_with_the_woocommerce_registration(): void {
		$registry = $this->service( DashboardSections::class );
		wp_set_current_user( $this->creator );

		self::assertSame( array( 'overview', 'profile' ), array_column( $registry->for_current_user(), 'id' ), 'No WooCommerce module: no Sales, Products, Orders or Payouts section.' );

		( new DashboardSectionRegistration() )->register();

		self::assertSame(
			array(
				array(
					'id'    => 'overview',
					'label' => 'Overview',
					'path'  => '',
				),
				array(
					'id'    => 'sales',
					'label' => 'Sales',
					'path'  => 'sales',
				),
				array(
					'id'    => 'products',
					'label' => 'Products',
					'path'  => 'products',
				),
				array(
					'id'    => 'orders',
					'label' => 'Orders',
					'path'  => 'orders',
				),
				array(
					'id'    => 'payouts',
					'label' => 'Payouts',
					'path'  => 'payouts',
				),
				array(
					'id'    => 'profile',
					'label' => 'Profile',
					'path'  => 'profile',
				),
			),
			$registry->for_current_user()
		);

		wp_set_current_user( $this->create_user( 'plain_subscriber' ) );
		self::assertSame( array(), $registry->for_current_user() );
	}

	public function test_php_strings_are_translated_to_danish(): void {
		self::assertTrue( load_textdomain( 'merchboard', self::MO ) );
		wp_set_current_user( $this->creator );

		self::assertSame( array( 'Overblik', 'Profil' ), array_column( $this->service( DashboardSections::class )->for_current_user(), 'label' ) );
		self::assertSame( 'Din adgang er ikke aktiv', __( 'Your access is not active', 'merchboard' ) );
		self::assertSame( 'Din adgang til creator-dashboardet er ikke aktiv. Kontakt Shop.', sprintf( __( 'Your creator dashboard access is not active. Please contact %s.', 'merchboard' ), 'Shop' ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Test.
		self::assertSame( 'Welcome, %s', __( 'Welcome, %s', 'merchboard' ), 'JavaScript-only strings are served from the JSON file, not the MO.' ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Test.
		self::assertSame( '2 creators er tilknyttet en WordPress-bruger, der ikke længere findes. De giver ingen adgang; frakobl dem på creatorernes redigeringssider.', sprintf( _n( '%d creator is linked to a WordPress user that no longer exists. It grants no access; unlink it on the creator\'s edit screen.', '%d creators are linked to a WordPress user that no longer exists. They grant no access; unlink them on the creators\' edit screens.', 2, 'merchboard' ), 2 ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Test.
	}

	public function test_shell_prints_the_danish_script_translations_with_the_nonce(): void {
		$dir = sys_get_temp_dir() . '/merchboard-i18n-build/';

		foreach ( array( 'build', 'languages' ) as $folder ) {
			if ( ! is_dir( $dir . $folder ) ) {
				mkdir( $dir . $folder, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			}
		}

		$json = 'merchboard-da_DK-' . md5( 'build/dashboard.js' ) . '.json';
		copy( MERCHBOARD_TEST_ROOT . '/languages/' . $json, $dir . 'languages/' . $json );
		file_put_contents( $dir . 'build/dashboard.asset.php', "<?php return array( 'dependencies' => array( 'wp-i18n' ), 'version' => 'i18n-test' );" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $dir . 'build/dashboard.js', '/* bundle */' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		// The dashboard is a frontend page: it follows the site language (creators
		// cannot reach their WordPress profile to pick a personal language).
		$GLOBALS['locale'] = 'da_DK'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates a Danish site.
		wp_set_current_user( $this->creator );
		self::assertSame( 'da_DK', determine_locale() );

		$controller = new ShellController(
			new DashboardRoute(),
			$this->service( CreatorContext::class ),
			new DashboardAssets( $dir, content_url( '/plugins/merchboard/' ), $this->service( Logger::class ) ),
			$this->service( DashboardSections::class ),
			new ThemePreference(),
			$this->service( BrandLogo::class )
		);
		$response   = $controller->respond();
		$html       = $controller->render( $response );

		self::assertSame( 200, $response->status );
		self::assertStringContainsString( 'Nettosalg (ekskl. moms)', $html, 'Script translations are printed for the bundle.' );
		self::assertStringContainsString( '"locale":"da_DK"', $html );
		self::assertSame( substr_count( $html, '<script' ), substr_count( $html, 'nonce="' . $response->script_nonce . '"' ), 'The translation script carries the nonce too.' );
	}
}
