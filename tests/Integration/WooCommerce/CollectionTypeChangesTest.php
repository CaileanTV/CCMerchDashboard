<?php
/**
 * A collection changed into an ordinary product, against a real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestException;
use MerchBoard\Modules\WooCommerce\Collections\CollectionTypeChanges;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * ADR-120 pkt. 7 with the status note of 2026-11-22. The product type
 * taxonomy is registered here, as WooCommerce is not loaded.
 */
#[CoversClass( CollectionTypeChanges::class )]
final class CollectionTypeChangesTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private DesignCollections $collections;

	private CollectionTypeChanges $types;

	private bool $active = true;

	private int $collection;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		register_taxonomy( 'product_type', 'product' );

		$this->active = true;
		$this->posts  = new ProductPosts( $this->wpdb );
		$this->posts->remove_all();

		$this->collections = new DesignCollections( fn(): bool => $this->active );
		$this->types       = new CollectionTypeChanges( $this->collections, $this->service( AuditTrail::class ), $this->service( Logger::class ) );
		$this->types->register();

		$this->collection = $this->posts->add( 'Collection', 'publish', array() );
		update_post_meta(
			$this->collection,
			DesignCollections::MEMBERS_KEY,
			array(
				array(
					'id'    => 21,
					'label' => 'Red',
				),
			)
		);
		wp_set_object_terms( $this->collection, DesignCollections::TYPE, 'product_type' );
	}

	protected function tearDown(): void {
		remove_action( 'set_object_terms', array( $this->types, 'terms_set' ) );
		wp_delete_object_term_relationships( $this->collection, 'product_type' );

		foreach ( array( DesignCollections::TYPE, 'simple' ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_type' );

			if ( $term instanceof \WP_Term ) {
				wp_delete_term( $term->term_id, 'product_type' );
			}
		}

		$this->posts->remove_all();
		unregister_taxonomy( 'product_type' );
		parent::tearDown();
	}

	public function test_a_change_while_the_plugin_is_active_is_on_purpose(): void {
		wp_set_object_terms( $this->collection, 'simple', 'product_type' );

		self::assertFalse( $this->collections->is_collection( $this->collection ), 'It can be sold.' );
		self::assertSame( array(), $this->collections->untyped() );
	}

	public function test_the_type_given_back_makes_it_a_collection_again(): void {
		wp_set_object_terms( $this->collection, 'simple', 'product_type' );
		wp_set_object_terms( $this->collection, DesignCollections::TYPE, 'product_type' );

		self::assertTrue( $this->collections->is_collection( $this->collection ) );
		self::assertSame( '', get_post_meta( $this->collection, DesignCollections::RELEASED_KEY, true ) );
	}

	public function test_a_type_lost_while_the_plugin_is_off_stays_a_collection(): void {
		$this->active = false;
		wp_set_object_terms( $this->collection, 'simple', 'product_type' );

		self::assertTrue( $this->collections->is_collection( $this->collection ) );
		self::assertSame( array( $this->collection ), $this->collections->untyped() );
	}

	public function test_the_administrator_releases_one_changed_before(): void {
		$this->active = false;
		wp_set_object_terms( $this->collection, 'simple', 'product_type' );

		wp_set_current_user( $this->create_user( 'customer' ) );

		try {
			$this->types->release( $this->collection );
			self::fail( 'Released without the capability.' );
		} catch ( CollectionRequestException $e ) {
			self::assertSame( CollectionRequestException::NOT_AUTHORIZED, $e->reason() );
		}

		wp_set_current_user( 1 );
		$this->types->release( $this->collection );

		self::assertFalse( $this->collections->is_collection( $this->collection ) );
		self::assertSame( 1, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action = %s', $this->table( Tables::AUDIT_LOG ), AuditAction::CollectionReleased->value ) ) );
	}

	public function test_a_real_collection_is_never_released(): void {
		wp_set_current_user( 1 );

		try {
			$this->types->release( $this->collection );
			self::fail( 'A collection was released.' );
		} catch ( CollectionRequestException $e ) {
			self::assertSame( CollectionRequestException::NOT_FOUND, $e->reason() );
		}

		self::assertTrue( $this->collections->is_collection( $this->collection ) );
	}
}
