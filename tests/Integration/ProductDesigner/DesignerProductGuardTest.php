<?php
/**
 * The legacy guard on a real database (ADR-080 pkt. 7).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * Legacy products must never become designer products: only a draft created
 * for a row (its secret token as pending meta) can be claimed, the marker is
 * protected against every generic write path, and a product whose marker
 * does not match its row is treated as legacy. Synthetic ids only.
 */
final class DesignerProductGuardTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private DesignerProductGuard $guard;

	private RecordingLogger $logger;

	private int $creator;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Guard A', CreatorStatus::Active )->id;
		$this->posts   = new ProductPosts( $this->wpdb );
		$this->logger  = new RecordingLogger();
		$this->guard   = new DesignerProductGuard( $this->wpdb, $this->service( ProtectedProductMeta::class ), $this->logger );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_a_draft_created_for_its_row_is_claimed_marked_and_proven(): void {
		$row   = $this->row( str_repeat( 'a1', 16 ) );
		$draft = $this->draft( str_repeat( 'a1', 16 ) );

		self::assertNull( $this->guard->for_row( $row ) );
		$proof = $this->guard->claim( $row, $draft );

		self::assertSame( array( $row, $draft, $this->creator ), array( $proof->design_product_id, $proof->wc_product_id, $proof->creator_id ) );
		self::assertSame( array( str_repeat( 'a1', 16 ) ), get_post_meta( $draft, ProtectedProductMeta::DESIGNER_KEY, false ) );
		self::assertSame( array(), get_post_meta( $draft, DesignerProductGuard::PENDING_KEY, false ), 'The pending meta is gone once claimed.' );
		self::assertSame( $draft, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT wc_product_id FROM %i WHERE id = %d', $this->table( Tables::DESIGNER_PRODUCTS ), $row ) ) );
		self::assertEquals( $proof, $this->guard->guard( $draft ) );
		self::assertEquals( $proof, $this->guard->for_row( $row ) );
		self::assertEquals( $proof, $this->guard->claim( $row, $draft ), 'Claiming again changes nothing.' );
	}

	public function test_legacy_products_are_never_designer_products(): void {
		$legacy = $this->posts->add( 'Legacy tee', 'publish', array( (string) $this->creator ) );
		$row    = $this->row( str_repeat( 'b2', 16 ) );

		self::assertNull( $this->guard->guard( $legacy ), 'No row names it.' );

		foreach ( array( $legacy, 999999 ) as $product ) {
			try {
				$this->guard->claim( $row, $product );
				self::fail( 'A product not created for the row cannot be claimed.' );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::NOT_DESIGNER_PRODUCT, $e->reason() );
			}
		}

		// Every generic write path is refused: the marker cannot be forged.
		self::assertFalse( add_post_meta( $legacy, ProtectedProductMeta::DESIGNER_KEY, str_repeat( 'b2', 16 ) ) );
		self::assertFalse( update_post_meta( $legacy, '_MerchBoard_Designer_Token', str_repeat( 'b2', 16 ) ) );
		self::assertSame( array(), get_post_meta( $legacy, ProtectedProductMeta::DESIGNER_KEY, false ) );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT wc_product_id FROM %i WHERE id = %d', $this->table( Tables::DESIGNER_PRODUCTS ), $row ) ), 'The row is untouched.' );
	}

	public function test_another_row_s_draft_or_product_cannot_be_claimed(): void {
		$first  = $this->row( str_repeat( 'c3', 16 ) );
		$second = $this->row( str_repeat( 'd4', 16 ) );
		$draft  = $this->draft( str_repeat( 'c3', 16 ) );

		try {
			$this->guard->claim( $second, $draft );
			self::fail( 'The draft was created for the first row.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::NOT_DESIGNER_PRODUCT, $e->reason() );
		}

		$this->guard->claim( $first, $draft );
		$other = $this->draft( str_repeat( 'c3', 16 ) );

		try {
			$this->guard->claim( $first, $other );
			self::fail( 'A row keeps its product.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::NOT_DESIGNER_PRODUCT, $e->reason() );
		}
	}

	public function test_a_mismatching_marker_is_legacy_and_an_interrupted_claim_is_finished(): void {
		$row   = $this->row( str_repeat( 'e5', 16 ) );
		$draft = $this->draft( str_repeat( 'e5', 16 ) );

		// Interrupted after the row was linked, before the marker.
		$this->wpdb->update( $this->table( Tables::DESIGNER_PRODUCTS ), array( 'wc_product_id' => $draft ), array( 'id' => $row ) );
		self::assertNull( $this->guard->guard( $draft ), 'Without its marker the product is legacy.' );
		self::assertCount( 1, $this->logger->at( 'warning' ), 'Logged, never repaired by the guard.' );
		self::assertSame( $draft, $this->guard->claim( $row, $draft )->wc_product_id );

		// A marker that does not match the row (e.g. a restored product): legacy again.
		$this->posts->meta( $draft, ProtectedProductMeta::DESIGNER_KEY, str_repeat( 'f6', 16 ) );
		self::assertNull( $this->guard->guard( $draft ), 'Two markers.' );
		$this->wpdb->delete(
			$this->wpdb->postmeta,
			array(
				'post_id'  => $draft,
				'meta_key' => ProtectedProductMeta::DESIGNER_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test data.
			)
		);
		$this->posts->meta( $draft, ProtectedProductMeta::DESIGNER_KEY, str_repeat( 'f6', 16 ) );
		self::assertNull( $this->guard->guard( $draft ), 'Another token.' );
	}

	/**
	 * A designer product row with its base product.
	 *
	 * @param string $token Creation token (32 hex characters).
	 */
	private function row( string $token ): int {
		$now = '2026-10-29 12:00:00';
		$this->wpdb->insert(
			$this->table( Tables::DESIGNER_BASES ),
			array(
				'name'        => 'Tee',
				'description' => '',
				'base_price'  => '150',
				'currency'    => 'DKK',
				'colors'      => '[]',
				'sizes'       => '[]',
				'views'       => '[]',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$this->wpdb->insert(
			$this->table( Tables::DESIGNER_PRODUCTS ),
			array(
				'creator_id'     => $this->creator,
				'base_id'        => (int) $this->wpdb->insert_id,
				'creation_token' => $token,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * A draft product carrying a pending token, as the writer creates it.
	 *
	 * @param string $token Creation token.
	 */
	private function draft( string $token ): int {
		$id = $this->posts->add( 'Designer draft', 'draft', array() );
		$this->posts->meta( $id, DesignerProductGuard::PENDING_KEY, $token );

		return $id;
	}
}
