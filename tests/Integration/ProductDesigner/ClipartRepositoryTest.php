<?php
/**
 * Cliparts with a definition in the database (ADR-121, step 56.6).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\ProductDesigner\Files\Clipart;
use MerchBoard\Modules\ProductDesigner\Files\ClipartDefinition;
use MerchBoard\Modules\ProductDesigner\Files\CreatorUploads;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DesignerAssetRepository::class )]
final class ClipartRepositoryTest extends IntegrationTestCase {

	private DesignerAssetRepository $assets;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->assets = new DesignerAssetRepository( $this->wpdb );
	}

	/**
	 * A stored file.
	 *
	 * @param string $digit The digit its name is made of.
	 * @return array{file: string, mime: string, width: int, height: int, bytes: int}
	 */
	private static function stored( string $digit ): array {
		return array(
			'file'   => str_repeat( $digit, 32 ) . '.png',
			'mime'   => 'image/png',
			'width'  => 200,
			'height' => 300,
			'bytes'  => 1234,
		);
	}

	private static function definition( int $width = 200, int $height = 300 ): ClipartDefinition {
		$rect = array(
			'x'      => 0,
			'y'      => 0,
			'width'  => 100,
			'height' => 100,
		);

		$definition = ClipartDefinition::from_data(
			array(
				'bases'   => array( 4, 9 ),
				'parts'   => array(
					array(
						'label'    => "Låg's 100% \"dråbe\" \\ 🎁",
						'box'      => $rect,
						'stroke'   => null,
						'scale'    => 0.75,
						'paint'    => array( 'type' => 'solid', 'color' => '#ffffff' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
						'gradient' => array( 'type' => 'gradient', 'from' => '#65b6f5', 'to' => '#f1a90c', 'angle' => 45 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
					),
				),
				'steps'   => array(
					array(
						'type'  => 'part',
						'part'  => 0,
						'rect'  => $rect,
						'fill'  => array( 'x' => 0, 'y' => 0 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
						'grown' => null,
					),
				),
				'atlas'   => array( 'file' => str_repeat( 'a', 32 ) . '.png', 'width' => 100, 'height' => 100 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
				'palette' => null,
				'locked'  => true,
			),
			$width,
			$height
		);

		self::assertNotNull( $definition );

		return $definition;
	}

	public function test_an_artwork_keeps_its_definition_and_stays_out_of_the_library_from_before(): void {
		$legacy = $this->assets->insert( DesignerAssetRepository::CLIPART, 'Star', self::stored( '1' ) );
		$id     = $this->assets->insert_artwork( 'Bottle', self::stored( '2' ), self::definition() );
		$layer  = $this->assets->insert( DesignerAssetRepository::LAYER, 'Layer', self::stored( '3' ) );
		$upload = $this->assets->insert( CreatorUploads::UPLOAD, 'Upload', self::stored( '4' ), 7 );

		$artwork = $this->assets->find_clipart( $id );

		self::assertNotNull( $artwork );
		self::assertEquals( self::definition(), $artwork->definition );
		self::assertNull( $artwork->retired_at );
		self::assertNull( $this->assets->find_clipart( $legacy )?->definition, 'A clipart from before is for all products.' );
		self::assertNull( $this->assets->find_clipart( $layer ) );
		self::assertNull( $this->assets->find_clipart( $upload ) );
		self::assertSame( array( $id, $legacy ), array_map( static fn( Clipart $clipart ): int => $clipart->id, $this->assets->cliparts() ) );
		self::assertSame( array( $legacy ), array_column( $this->assets->shared( DesignerAssetRepository::CLIPART ), 'id' ), 'Code from before never lists an artwork.' );
	}

	public function test_only_the_base_products_change(): void {
		$id     = $this->assets->insert_artwork( 'Bottle', self::stored( '2' ), self::definition() );
		$before = $this->assets->find_clipart( $id );

		self::assertNotNull( $before );

		$this->assets->set_bases( $before, array( 12 ) );
		$after = $this->assets->find_clipart( $id );

		self::assertNotNull( $after?->definition );
		self::assertSame( array( 12 ), $after->definition->bases );
		self::assertSame( self::definition()->parts, $after->definition->parts );

		$this->expectException( DatabaseException::class );
		$this->assets->set_bases( $before, array( 0 ) );
	}

	public function test_a_removed_clipart_keeps_its_row_and_is_removed_once(): void {
		$id    = $this->assets->insert( DesignerAssetRepository::CLIPART, 'Star', self::stored( '1' ) );
		$layer = $this->assets->insert( DesignerAssetRepository::LAYER, 'Layer', self::stored( '3' ) );

		self::assertTrue( $this->assets->retire( $id ) );
		self::assertFalse( $this->assets->retire( $id ) );
		self::assertFalse( $this->assets->retire( $layer ) );

		$clipart = $this->assets->find_clipart( $id );

		self::assertNotNull( $clipart?->retired_at );
		self::assertFalse( $clipart->offered_on( 4 ) );
		self::assertNotNull( $this->assets->find( $id ) );
	}

	public function test_an_unreadable_definition_fails_closed(): void {
		$id = $this->assets->insert_artwork( 'Bottle', self::stored( '2' ), self::definition() );
		$this->wpdb->update( Tables::name( $this->wpdb, Tables::DESIGNER_ASSETS ), array( 'definition' => '{' ), array( 'id' => $id ) );

		$artwork = $this->assets->find_clipart( $id );

		self::assertNotNull( $artwork );
		self::assertFalse( $artwork->readable() );
		self::assertFalse( $artwork->offered_on( 4 ) );
	}

	public function test_a_definition_of_another_size_is_not_stored(): void {
		$this->expectException( DatabaseException::class );
		$this->assets->insert_artwork( 'Bottle', self::stored( '2' ), self::definition( 210, 300 ) );
	}
}
