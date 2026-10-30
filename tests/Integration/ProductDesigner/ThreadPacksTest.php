<?php
/**
 * The embroidery packs on a real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\ThreadPacks;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;

/**
 * Packs are stored in their own option, audited with their change. A side
 * chooses a pack, which makes a new version of the base product, and a pack
 * in use cannot be deleted.
 */
final class ThreadPacksTest extends IntegrationTestCase {

	private ThreadPacks $packs;

	private BaseProductService $bases;

	private BaseProductRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		DesignerFiles::remove_all();
		wp_set_current_user( 1 );

		$files            = new DesignerFiles( static fn( string $from, string $to ): bool => rename( $from, $to ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test upload move.
		$this->repository = new BaseProductRepository( $this->wpdb );
		$this->bases      = new BaseProductService( $this->repository, new DesignerAssetRepository( $this->wpdb ), $files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );
		$this->packs      = new ThreadPacks( $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->repository );
	}

	protected function tearDown(): void {
		delete_option( ThreadPacks::OPTION );
		DesignerFiles::remove_all();
		parent::tearDown();
	}

	public function test_a_new_pack_is_stored_beside_gelato_s_and_audited(): void {
		self::assertTrue( $this->packs->save( '', 'Summer threads', '2', self::threads() ) );

		self::assertSame( array( 'gelato', 'summer-threads' ), array_column( ThreadPacks::all(), 'id' ) );
		self::assertSame(
			array(
				array(
					'code' => 'A1',
					'name' => 'Lime',
					'hex'  => '#aabb00',
				),
				array(
					'code' => 'B2',
					'name' => 'Sea',
					'hex'  => '#0088aa',
				),
			),
			ThreadPacks::find( 'summer-threads' )['threads'] ?? null,
			'An empty row and a removed row are left out.'
		);
		self::assertContains( $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $this->wpdb->options, ThreadPacks::OPTION ) ), array( 'no', 'off' ) );

		$audit = (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT context FROM %i WHERE action = %s ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ), 'settings_updated' ) );

		self::assertStringContainsString( 'summer-threads', $audit );
		self::assertStringContainsString( 'A1,B2', $audit );
		self::assertFalse( $this->packs->save( 'summer-threads', 'Summer threads', '2', self::threads() ), 'Nothing changed.' );
	}

	public function test_a_saved_pack_keeps_its_id_when_it_is_renamed(): void {
		$this->packs->save( '', 'Summer', '2', self::threads() );
		$this->packs->save( 'summer', 'Autumn', '1', self::threads() );

		self::assertSame( array( 'Autumn', 1 ), array( ThreadPacks::find( 'summer' )['name'] ?? null, ThreadPacks::find( 'summer' )['max'] ?? null ) );
	}

	public function test_a_pack_without_a_name_threads_or_a_fitting_limit_is_refused(): void {
		$refused = array(
			array( '', '2', self::threads() ),
			array( 'Summer', '3', self::threads() ),
			array( 'Summer', '0', self::threads() ),
			array( 'Summer', 'many', self::threads() ),
			array( 'Summer', '1', array() ),
			array(
				'Summer',
				'1',
				array(
					array(
						'code' => 'A1',
						'name' => 'Lime',
						'hex'  => 'lime',
					),
				),
			),
			array(
				'Summer',
				'1',
				array(
					array(
						'code' => 'A 1',
						'name' => 'Lime',
						'hex'  => '#aabb00',
					),
				),
			),
		);

		foreach ( $refused as [ $name, $max, $rows ] ) {
			try {
				$this->packs->save( '', $name, $max, $rows );
				self::fail( 'Accepted: ' . $name . ' ' . $max );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::THREAD_PACK, $e->reason() );
			}
		}

		self::assertSame( array( 'gelato' ), array_column( ThreadPacks::all(), 'id' ) );
		self::assertFalse( get_option( ThreadPacks::OPTION ) );
	}

	public function test_only_a_manager_of_the_designer_changes_packs(): void {
		wp_set_current_user( 0 );

		$this->expectExceptionObject( new DesignerException( DesignerException::NOT_AUTHORIZED ) );
		$this->packs->save( '', 'Summer', '2', self::threads() );
	}

	public function test_a_side_chooses_a_pack_which_makes_a_new_version_and_keeps_the_pack_in_use(): void {
		$this->packs->save( '', 'Summer', '2', self::threads() );

		$id = $this->base( '' );

		self::assertSame( '', $this->repository->find( $id )?->views[0]->thread_pack );

		$key     = (string) $this->repository->find( $id )?->views[0]->key;
		$version = (int) $this->repository->find( $id )?->version;

		$this->save_side( $id, $key, array( 'thread_pack' => 'summer' ) );
		self::assertSame( 'summer', $this->repository->find( $id )?->views[0]->thread_pack );
		self::assertSame( $version + 1, $this->repository->find( $id )?->version );

		$this->save_side( $id, $key, array() );
		self::assertSame( 'summer', $this->repository->find( $id )?->views[0]->thread_pack, 'A field left out keeps its value.' );

		try {
			$this->packs->delete( 'summer' );
			self::fail( 'A pack in use was deleted.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::THREAD_PACK_IN_USE, $e->reason() );
		}

		try {
			$this->save_side( $id, $key, array( 'thread_pack' => 'unknown' ) );
			self::fail( 'An unknown pack was accepted.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::THREAD_PACK, $e->reason() );
		}

		$this->save_side( $id, $key, array( 'thread_pack' => '' ) );
		$this->packs->delete( 'summer' );

		self::assertNull( ThreadPacks::find( 'summer' ) );
	}

	/**
	 * Saves a base product's side.
	 *
	 * @param int                  $id     Base product.
	 * @param string               $key    The side.
	 * @param array<string, mixed> $fields Extra fields.
	 */
	private function save_side( int $id, string $key, array $fields ): void {
		$this->bases->save(
			$id,
			array(
				'name'        => 'Beanie',
				'description' => '',
				'base_price'  => '99',
				'category_id' => '0',
				'colors'      => 'Black #111111',
				'sizes'       => 'One size',
			),
			array( array_merge( self::side( $key ), $fields ) )
		);
	}

	/**
	 * A base product with one side.
	 *
	 * @param string $pack The side's pack.
	 */
	private function base( string $pack ): int {
		return $this->bases->save(
			null,
			array(
				'name'        => 'Beanie',
				'description' => '',
				'base_price'  => '99',
				'category_id' => '0',
				'colors'      => 'Black #111111',
				'sizes'       => 'One size',
			),
			array(
				array_merge(
					self::side( '' ),
					array(
						'thread_pack' => $pack,
						'uploads'     => array( 'color' => AlertImagesTest::upload( AlertImagesTest::png( 400, 400 ), 'color.png' ) ),
					)
				),
			)
		);
	}

	/**
	 * The fields of a side.
	 *
	 * @param string $key The side's key.
	 * @return array<string, mixed>
	 */
	private static function side( string $key ): array {
		return array(
			'key'       => $key,
			'name'      => 'Front',
			'x'         => '100',
			'y'         => '100',
			'width'     => '200',
			'height'    => '200',
			'width_mm'  => '30',
			'height_mm' => '40',
			'dpi'       => '100',
			'remove'    => false,
			'clear'     => array(),
			'uploads'   => array(),
		);
	}

	/**
	 * The rows of a pack's form: two threads, an empty row and a removed one.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function threads(): array {
		return array(
			array(
				'code' => 'A1',
				'name' => 'Lime',
				'hex'  => '#AABB00',
			),
			array(
				'code' => ' B2 ',
				'name' => 'Sea',
				'hex'  => '#0088aa',
			),
			array(
				'code' => '',
				'name' => '',
				'hex'  => '#000000',
			),
			array(
				'code'   => 'C3',
				'name'   => 'Gone',
				'hex'    => '#123456',
				'remove' => true,
			),
		);
	}
}
