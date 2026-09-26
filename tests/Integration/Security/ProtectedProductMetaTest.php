<?php
/**
 * The protected product meta guard on WordPress' real metadata API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Security;

use LogicException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Security\ProtectedMetaWrite;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Integration\IntegrationTestCase;

/**
 * Runs against the guard the booted plugin registered in
 * Plugin::register_hooks() (ADR-035). WooCommerce is not installed here, so
 * this proves the core registration without WooCommerce. Everything is real:
 * plugin boot, metadata API, object cache, post deletion and MariaDB.
 */
final class ProtectedProductMetaTest extends IntegrationTestCase {

	private const LINK   = ProtectedProductMeta::LINK_KEY;
	private const SOURCE = ProtectedProductMeta::SOURCE_KEY;

	private ProtectedProductMeta $guard;

	private int $product;

	private int $variation;

	private int $other;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		// ADR-036's uninstall window stays closed here: UninstallTest may have
		// defined WP_UNINSTALL_PLUGIN in this process, so the opt-in is off.
		delete_option( Settings::OPTION );

		$this->product   = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Guarded tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->variation = (int) wp_insert_post( array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $this->product, 'post_title' => 'Guarded tee - S' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->other     = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Other tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// An existing link, as ProductCreatorLink would have stored it.
		$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $this->product, self::LINK, '3' );
		wp_cache_delete( $this->product, 'post_meta' );

		$this->guard = $this->service( ProtectedProductMeta::class );
	}

	protected function tearDown(): void {
		foreach ( array( $this->product, $this->variation, $this->other ) as $id ) {
			$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, $id );
			$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->posts, $id );
		}

		wp_cache_flush();
		parent::tearDown();
	}

	public function test_the_booted_plugin_registers_the_guard_in_core_without_woocommerce(): void {
		self::assertFalse( class_exists( 'WooCommerce', false ), 'No WooCommerce in this environment.' );
		self::assertFalse( self::$container?->has( ProductCreatorLink::class ) ?? true, 'The WooCommerce module did not boot.' );

		foreach ( array( 'add_post_metadata' => 'filter_add', 'update_post_metadata' => 'filter_update', 'delete_post_metadata' => 'filter_delete', 'update_post_metadata_by_mid' => 'filter_update_by_mid', 'delete_post_metadata_by_mid' => 'filter_delete_by_mid' ) as $hook => $method ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			self::assertSame( 1000, has_filter( $hook, array( $this->guard, $method ) ), $hook );
		}

		self::assertFalse( update_post_meta( $this->other, self::LINK, '4' ), 'Denied by the booted core guard alone.' );
		self::assertSame( '', get_post_meta( $this->other, self::LINK, true ) );
	}

	public function test_generic_writes_of_both_keys_are_denied_on_products_and_variations(): void {
		$before = $this->stored();
		$audit  = count( $this->rows( Tables::AUDIT_LOG ) );

		self::assertFalse( update_post_meta( $this->product, self::LINK, '4' ), 'Change.' );
		self::assertFalse( add_post_meta( $this->product, self::LINK, '4' ), 'Second entry.' );
		self::assertFalse( add_post_meta( $this->other, self::LINK, '4' ), 'New link.' );
		self::assertFalse( add_post_meta( $this->product, self::SOURCE, 'admin' ), 'Source.' );
		self::assertFalse( update_post_meta( $this->other, '_Merchboard_Creator_ID', '4' ), 'Another spelling.' );
		self::assertFalse( add_post_meta( $this->variation, self::LINK, '4' ), 'Variation link.' );
		self::assertFalse( add_post_meta( $this->variation, self::SOURCE, 'category' ), 'Variation source.' );
		self::assertFalse( delete_post_meta( $this->product, self::LINK ), 'Delete.' );
		self::assertFalse( delete_post_meta( $this->product, self::SOURCE ), 'Delete source.' );
		self::assertFalse( delete_post_meta_by_key( self::LINK ), 'Delete from every post.' );
		self::assertFalse( update_metadata( 'post', $this->product, self::LINK, '' ), 'Empty value.' );

		self::assertSame( $before, $this->stored(), 'Nothing reached the database.' );
		self::assertSame( '3', get_post_meta( $this->product, self::LINK, true ), 'Reads are unaffected.' );
		self::assertCount( $audit, $this->rows( Tables::AUDIT_LOG ), 'Denied writes create no audit records.' );
	}

	public function test_writes_by_meta_id_cannot_change_rename_or_delete_a_link(): void {
		$link_id  = $this->meta_id( $this->product, self::LINK );
		$price_id = (int) add_post_meta( $this->product, '_price', '10' );
		$before   = $this->stored();

		self::assertFalse( update_metadata_by_mid( 'post', $link_id, '4' ) );
		self::assertFalse( update_metadata_by_mid( 'post', $link_id, '3', '_renamed' ) );
		self::assertFalse( update_metadata_by_mid( 'post', $price_id, '3', self::SOURCE ), 'Renaming other meta into the source key.' );
		self::assertFalse( delete_metadata_by_mid( 'post', $link_id ) );

		self::assertSame( $before, $this->stored() );
		self::assertTrue( update_metadata_by_mid( 'post', $price_id, '12' ), 'Other meta by id still works.' );
	}

	public function test_other_product_meta_is_written_normally(): void {
		self::assertIsInt( add_post_meta( $this->product, '_price', '10' ) );
		self::assertTrue( update_post_meta( $this->product, '_price', '12' ) );
		self::assertIsInt( add_post_meta( $this->variation, '_regular_price', '7' ) );
		self::assertTrue( delete_post_meta( $this->product, '_price' ) );
		self::assertSame( '7', get_post_meta( $this->variation, '_regular_price', true ) );
	}

	public function test_an_authorized_write_changes_exactly_its_product_while_it_runs(): void {
		$this->guard->run(
			ProtectedMetaWrite::for_manual_link( $this->product ),
			function (): void {
				self::assertTrue( delete_post_meta( $this->product, self::LINK ) );
				self::assertIsInt( add_post_meta( $this->product, self::LINK, '5', true ) );
				self::assertIsInt( add_post_meta( $this->product, self::SOURCE, 'admin', true ) );
				self::assertFalse( add_post_meta( $this->other, self::LINK, '5' ), 'Another product stays protected.' );
				self::assertFalse( add_post_meta( $this->variation, self::LINK, '5' ), 'Its variation too.' );
			}
		);

		self::assertSame( array( '5' ), get_post_meta( $this->product, self::LINK, false ) );
		self::assertSame( array( 'admin' ), get_post_meta( $this->product, self::SOURCE, false ) );
		self::assertSame( array(), get_post_meta( $this->variation, self::LINK, false ) );
		self::assertFalse( update_post_meta( $this->product, self::LINK, '6' ), 'Denied again afterwards.' );
		self::assertSame( '5', get_post_meta( $this->product, self::LINK, true ) );
	}

	public function test_the_system_write_works_without_a_logged_in_user_and_is_single_use(): void {
		wp_set_current_user( 0 );
		$write = ProtectedMetaWrite::for_category_reconciliation( $this->other );

		$this->guard->run( $write, fn() => add_post_meta( $this->other, self::LINK, '7', true ) );

		self::assertSame( '7', get_post_meta( $this->other, self::LINK, true ) );

		$this->expectException( LogicException::class );
		$this->guard->run( $write, fn() => update_post_meta( $this->other, self::LINK, '8' ) );
	}

	public function test_a_failed_operation_leaves_the_guard_closed(): void {
		try {
			$this->guard->run(
				ProtectedMetaWrite::for_manual_link( $this->product ),
				static function (): void {
					throw new LogicException( 'boom' );
				}
			);
		} catch ( LogicException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Expected.
		}

		self::assertFalse( update_post_meta( $this->product, self::LINK, '9' ) );
		self::assertSame( '3', get_post_meta( $this->product, self::LINK, true ) );
	}

	public function test_permanently_deleting_a_product_removes_its_protected_meta(): void {
		$product = $this->product;

		self::assertNotFalse( wp_delete_post( $product, true ) );

		self::assertSame( array(), $this->stored_for( $product ), 'No orphaned link rows.' );
		self::assertFalse( add_post_meta( $this->other, self::LINK, '4' ), 'The deletion opened nothing else.' );
	}

	/**
	 * Protected rows of the three test posts, straight from the database.
	 *
	 * @return list<array<string, string>>
	 */
	private function stored(): array {
		return array_merge( $this->stored_for( $this->product ), $this->stored_for( $this->variation ), $this->stored_for( $this->other ) );
	}

	/**
	 * Protected rows of one post.
	 *
	 * @param int $post_id Post id.
	 * @return list<array<string, string>>
	 */
	private function stored_for( int $post_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, post_id, meta_key, meta_value FROM %i WHERE post_id = %d AND LOWER(TRIM(meta_key)) IN (%s, %s) ORDER BY meta_id', $wpdb->postmeta, $post_id, self::LINK, self::SOURCE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion on stored rows.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Meta id of a post's row.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 */
	private function meta_id( int $post_id, string $key ): int {
		$wpdb = $this->wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $post_id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture lookup.
	}
}
