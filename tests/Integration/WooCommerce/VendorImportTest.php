<?php
/**
 * The migration from WooCommerce Product Vendors (phase 26, ADR-074).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\VendorImport\ProductVendorsPlugin;
use MerchBoard\Modules\WooCommerce\VendorImport\ProductVendorsSource;
use MerchBoard\Modules\WooCommerce\VendorImport\VendorImportException;
use MerchBoard\Modules\WooCommerce\VendorImport\VendorImportPage;
use MerchBoard\Modules\WooCommerce\VendorImport\VendorImportPlan;
use MerchBoard\Modules\WooCommerce\VendorImport\VendorImportReport;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use WC_Product_Vendors_Utils;

/**
 * The dry run (26.1): statuses, the chosen user, products and settlement
 * starts, read through Product Vendors' own API, writing nothing.
 */
final class VendorImportTest extends IntegrationTestCase {

	/**
	 * Vendor term ids by name.
	 *
	 * @var array<string, int>
	 */
	private array $vendors = array();

	/**
	 * Users by login.
	 *
	 * @var array<string, int>
	 */
	private array $users = array();

	/**
	 * Products created by the test.
	 *
	 * @var list<int>
	 */
	private array $posts = array();

	private int $orion;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( Settings::OPTION );
		require_once dirname( __DIR__, 2 ) . '/Fixtures/product-vendors.php';

		if ( ! defined( 'WC_PRODUCT_VENDORS_VERSION' ) ) {
			define( 'WC_PRODUCT_VENDORS_VERSION', '2.6.2-test' );
		}

		register_taxonomy( ProductVendorsPlugin::TAXONOMY, array( 'product' ) );

		foreach ( ProductVendorsSource::ROLES as $role ) {
			add_role( $role, $role, array( 'read' => true ) );
		}

		foreach ( array( 'nova_admin' => ProductVendorsSource::ADMIN_ROLE, 'nova_manager' => ProductVendorsSource::MANAGER_ROLE, 'orion_admin' => ProductVendorsSource::ADMIN_ROLE, 'pia' => ProductVendorsSource::PENDING_ROLE, 'late' => ProductVendorsSource::ADMIN_ROLE, 'modern' => ProductVendorsSource::ADMIN_ROLE ) as $login => $role ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short fixture.
			$this->users[ $login ] = $this->create_user( $login );
			( new \WP_User( $this->users[ $login ] ) )->set_role( $role );
		}

		$creators    = $this->service( CreatorService::class );
		$this->orion = $creators->create( 'Orion', CreatorStatus::Active, $this->users['orion_admin'] )->id;
		$creators->create( 'Ghost', CreatorStatus::Active, null );

		// Admins as Product Vendors stores them: a comma-separated text, one id, none.
		$this->vendor( 'Nova Merch', $this->users['nova_manager'] . ',' . $this->users['nova_admin'] );
		$this->vendor( 'Orion Shop', array( $this->users['orion_admin'] ) );
		$this->vendor( 'Pending Pia', $this->users['pia'] );
		$this->vendor( 'Ghost', array() );
		$this->vendor( 'Shared', array( $this->users['nova_admin'] ) );
		// Product Vendors 2.1.80+: the administrator is stored outside vendor_data.
		$this->vendor( 'Late', array() );
		WC_Product_Vendors_Utils::$extra_admins = array( $this->vendors['Late'] => array( $this->users['late'] ) );
		// Product Vendors 2.4.9's admin meta storage: only get_vendor_admins() knows the administrator.
		$this->vendor( 'Modern', array() );
		WC_Product_Vendors_Utils::$meta_admins = array( $this->vendors['Modern'] => array( $this->users['modern'] ) );

		$this->product( 'Nova Merch' );
		$this->product( 'Nova Merch', $this->orion );
		$this->product( 'Orion Shop', $this->orion );
		$this->product( 'Orion Shop' );
	}

	protected function tearDown(): void {
		foreach ( $this->posts as $id ) {
			$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, $id );
			$this->execute( 'DELETE FROM %i WHERE object_id = %d', $this->wpdb->term_relationships, $id );
			$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->posts, $id );
		}

		foreach ( $this->vendors as $term_id ) {
			wp_delete_term( $term_id, ProductVendorsPlugin::TAXONOMY );
		}

		foreach ( ProductVendorsSource::ROLES as $role ) {
			remove_role( $role );
		}

		WC_Product_Vendors_Utils::$extra_admins = array();
		WC_Product_Vendors_Utils::$meta_admins  = array();
		unregister_taxonomy( ProductVendorsPlugin::TAXONOMY );
		delete_option( VendorImportPlan::OPTION );
		parent::tearDown();
	}

	public function test_the_dry_run_shows_each_vendor_and_writes_nothing(): void {
		$before = $this->snapshot();
		$report = $this->plan()->build();

		self::assertSame( '2.6.2-test', $report->version );
		self::assertSame(
			array(
				'Ghost'       => array( VendorImportReport::NO_ADMIN, 0, 0 ),
				'Late'        => array( VendorImportReport::NEW, $this->users['late'], 0 ),
				'Modern'      => array( VendorImportReport::NEW, $this->users['modern'], 0 ),
				'Nova Merch'  => array( VendorImportReport::NEW, $this->users['nova_admin'], 0 ),
				'Orion Shop'  => array( VendorImportReport::MATCHED, $this->users['orion_admin'], $this->orion ),
				'Pending Pia' => array( VendorImportReport::PENDING, 0, 0 ),
				'Shared'      => array( VendorImportReport::USER_TAKEN, $this->users['nova_admin'], 0 ),
			),
			$this->summary( $report ),
			'Admins from get_vendor_admins() (2.4.9), vendor_data and Product Vendors\' own lookup; a Vendor Admin before a Vendor Manager; a user for one vendor only.'
		);

		$rows = $this->by_name( $report );
		self::assertSame( array( 'link' => 1, 'same' => 0, 'conflict' => 1 ), $rows['Nova Merch']['products'], 'A product of another creator is left.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'link' => 1, 'same' => 1, 'conflict' => 0 ), $rows['Orion Shop']['products'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'new', '', null ), array( $rows['Nova Merch']['settlement'], $rows['Orion Shop']['settlement'], $rows['Pending Pia']['settlement'] ), 'A new creator gets the migration\'s time; an existing one shows its own start.' );
		self::assertTrue( $rows['Ghost']['same_name'], 'A creator with the same name is only a warning.' );
		self::assertSame( array( 'nova_admin', 'nova_manager' ), array_map( static fn( array $user ): string => explode( ' (', $user['name'] )[0], $rows['Nova Merch']['users'] ) );
		self::assertSame( array( 'vendors' => 5, 'skipped' => 2, 'products' => 2, 'conflict' => 1 ), $report->audit_context() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( $before, $this->snapshot(), 'The dry run writes nothing.' );
	}

	public function test_the_administrator_chooses_the_user_and_the_fingerprint_follows(): void {
		$proposed = $this->plan()->build();
		$chosen   = $this->plan()->build( array( $this->vendors['Nova Merch'] => $this->users['nova_manager'] ) );

		self::assertSame( $this->users['nova_manager'], $this->by_name( $chosen )['Nova Merch']['user_id'] );
		self::assertSame( VendorImportReport::NEW, $this->by_name( $chosen )['Shared']['status'], 'The other user is free for the second vendor now.' );
		self::assertNotSame( $proposed->fingerprint(), $chosen->fingerprint() );
		self::assertSame( $proposed->fingerprint(), $this->plan()->build()->fingerprint(), 'The same dry run, the same fingerprint.' );

		$ignored = $this->plan()->build( array( $this->vendors['Nova Merch'] => $this->users['pia'] ) );
		self::assertSame( $this->users['nova_admin'], $this->by_name( $ignored )['Nova Merch']['user_id'], 'A user who is not the vendor\'s is never chosen.' );
	}

	public function test_an_earlier_migration_is_recognized_and_its_creator_is_kept(): void {
		update_option( VendorImportPlan::OPTION, array( 'vendors' => array( $this->vendors['Orion Shop'] => $this->orion ) ), false );
		$rows = $this->by_name( $this->plan()->build() );

		self::assertSame( array( VendorImportReport::IMPORTED, $this->orion ), array( $rows['Orion Shop']['status'], $rows['Orion Shop']['creator_id'] ) );
	}

	public function test_it_is_refused_without_the_rights_or_while_product_vendors_is_inactive(): void {
		wp_set_current_user( $this->create_user( 'editor_only' ) );
		self::assertSame( VendorImportException::NOT_AUTHORIZED, $this->refusal( $this->plan() ) );

		wp_set_current_user( 1 );
		$inactive = new class() implements ProductVendorsSource {
			public function active(): bool {
				return false;
			}

			public function version(): string {
				return '';
			}

			public function vendors(): array {
				return array();
			}

			public function admins( int $term_id ): array {
				return array();
			}

			public function roles( int $user_id ): array {
				return array();
			}

			public function product_ids( int $term_id ): array {
				return array();
			}
		};
		self::assertSame( VendorImportException::INACTIVE, $this->refusal( $this->plan( $inactive ) ) );
	}

	public function test_the_page_shows_the_dry_run_with_a_choice_of_user(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$page = new VendorImportPage( $this->plan(), new ProductVendorsPlugin() );
		$_GET = array(
			VendorImportPage::PREVIEW_ARG => '1',
			VendorImportPage::USER_ARG    => array( $this->vendors['Nova Merch'] => (string) $this->users['nova_manager'] ),
		);

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$_GET = array();

		self::assertStringContainsString( 'Nova Merch', $html );
		self::assertStringContainsString( 'name="user[' . $this->vendors['Nova Merch'] . ']"', $html );
		self::assertMatchesRegularExpression( '/<option value="' . $this->users['nova_manager'] . '" selected=/', $html );
		self::assertStringNotContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html, 'No migration form before 26.2.' );
		self::assertSame(
			array( 7 => 3 ),
			VendorImportPage::choices(
				array(
					'7'  => '3',
					'x'  => '2',
					'8'  => 'abc',
					'-1' => '4',
				)
			)
		);
	}

	/**
	 * The dry run.
	 *
	 * @param ProductVendorsSource|null $source Product Vendors; the real adapter by default.
	 */
	private function plan( ?ProductVendorsSource $source = null ): VendorImportPlan {
		$products   = new PostMetaProductStore();
		$repository = $this->service( CreatorRepository::class );
		$audit      = $this->service( AuditTrail::class );

		return new VendorImportPlan(
			$source ?? new ProductVendorsPlugin(),
			$repository,
			new ProductCreatorLink( $products, $repository, $audit, $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) ),
			new PayoutSettings( new PayoutRepository( $this->wpdb ), $audit, $this->service( Transaction::class ), static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
		);
	}

	/**
	 * Why the dry run was refused.
	 *
	 * @param VendorImportPlan $plan The dry run.
	 */
	private function refusal( VendorImportPlan $plan ): string {
		try {
			$plan->build();
		} catch ( VendorImportException $e ) {
			return $e->reason();
		}

		self::fail( 'The dry run was not refused.' );
	}

	/**
	 * A vendor with its administrators as Product Vendors stores them.
	 *
	 * @param string $name   Name.
	 * @param mixed  $admins The `admins` value.
	 */
	private function vendor( string $name, mixed $admins ): void {
		$term = wp_insert_term( $name, ProductVendorsPlugin::TAXONOMY );
		self::assertIsArray( $term );
		$this->vendors[ $name ] = (int) $term['term_id'];
		update_term_meta( (int) $term['term_id'], 'vendor_data', array( 'admins' => $admins, 'commission' => '20', 'paypal' => 'vendor@example.com' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short fixture.
	}

	/**
	 * A vendor's product.
	 *
	 * @param string   $vendor     Vendor name.
	 * @param int|null $creator_id Linked creator.
	 */
	private function product( string $vendor, ?int $creator_id = null ): int {
		$id            = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => $vendor . ' tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->posts[] = $id;
		wp_set_object_terms( $id, array( $this->vendors[ $vendor ] ), ProductVendorsPlugin::TAXONOMY );

		if ( null !== $creator_id ) {
			$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $id, ProductCreatorLink::META_KEY, (string) $creator_id );
			wp_cache_delete( $id, 'post_meta' );
		}

		return $id;
	}

	/**
	 * Status, user and creator per vendor name.
	 *
	 * @param VendorImportReport $report The dry run.
	 * @return array<string, array{0: string, 1: int, 2: int}>
	 */
	private function summary( VendorImportReport $report ): array {
		$summary = array();

		foreach ( $report->vendors as $vendor ) {
			$summary[ $vendor['name'] ] = array( $vendor['status'], $vendor['user_id'], $vendor['creator_id'] );
		}

		ksort( $summary );

		return $summary;
	}

	/**
	 * The rows per vendor name.
	 *
	 * @param VendorImportReport $report The dry run.
	 * @return array<string, array<string, mixed>>
	 */
	private function by_name( VendorImportReport $report ): array {
		return array_column( $report->vendors, null, 'name' );
	}

	/**
	 * What the migration could change: creators, audit, product meta,
	 * users' roles, Product Vendors' data and the option.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		$roles = array();

		foreach ( $this->users as $login => $id ) {
			$roles[ $login ] = get_userdata( $id )->roles ?? array();
		}

		$meta = array();

		foreach ( $this->vendors as $name => $term_id ) {
			$meta[ $name ] = get_term_meta( $term_id, 'vendor_data', true );
		}

		return array(
			'creators' => $this->rows( Tables::CREATORS ),
			'audit'    => count( $this->rows( Tables::AUDIT_LOG ) ),
			'links'    => $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT post_id, meta_key, meta_value FROM %i WHERE meta_key LIKE %s ORDER BY meta_id', $this->wpdb->postmeta, '_merchboard%' ), ARRAY_A ),
			'roles'    => $roles,
			'vendors'  => $meta,
			'option'   => get_option( VendorImportPlan::OPTION, null ),
		);
	}
}
