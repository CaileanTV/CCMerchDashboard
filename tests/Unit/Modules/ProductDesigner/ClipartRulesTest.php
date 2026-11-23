<?php
/**
 * Tests for who may use a clipart (ADR-121, step 56.7).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use MerchBoard\Modules\ProductDesigner\Files\Clipart;
use MerchBoard\Modules\ProductDesigner\Files\ClipartRules;
use MerchBoard\Modules\ProductDesigner\Files\CreatorUploads;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ClipartRules::class )]
final class ClipartRulesTest extends TestCase {

	public function test_the_images_of_a_design_are_those_of_sides_shared_formats_and_colour_designs(): void {
		$design = '{
			"version": 1,
			"sides": {"front": [{"type": "image", "asset": 1}, {"type": "text", "asset": 90}, {"type": "image", "asset": 2, "hidden": true}]},
			"formats": {"shared": {"a": {"objects": [{"type": "image", "asset": 3}]}}},
			"designs": [{"sides": {"back": [{"type": "image", "asset": 4}]}, "formats": {"shared": {"b": {"objects": [{"type": "image", "asset": 5}]}}}}]
		}';

		self::assertSame( array( 1, 2, 3, 4, 5 ), ClipartRules::used( $design ) );
		self::assertSame( array( 1, 2, 3, 4, 5, 6 ), ClipartRules::used( $design, null, '{"sides": {"front": [{"type": "image", "asset": 6}, {"type": "image", "asset": 1}]}}' ) );
	}

	public function test_the_colours_chosen_for_parts_are_listed_by_clipart(): void {
		$design = '{"sides": {"front": [{"type": "image", "asset": 3, "paints": [{"type": "solid", "color": "#ff0000"}, {"type": "gradient", "from": "#00ff00", "to": "#ff0000", "angle": 4}]}, {"type": "image", "asset": 5}]}, "designs": [{"sides": {"back": [{"type": "image", "asset": 3, "paints": [{"type": "solid", "color": "#0000ff"}, 7]}]}}]}';

		self::assertSame( array( 3 => array( '#ff0000', '#00ff00', '#0000ff' ) ), ClipartRules::colors( $design ) );
		self::assertSame( array(), ClipartRules::colors( '{' ) );
	}

	public function test_anything_unreadable_keeps_nothing(): void {
		self::assertSame( array(), ClipartRules::used() );
		self::assertSame( array(), ClipartRules::used( null, '', '{', '3', '{"sides": "x"}', '{"sides": {"front": [{"type": "image", "asset": "7"}]}}', array( 'sides' => array() ) ) );
	}

	public function test_a_design_keeps_a_removed_clipart_and_a_new_one_cannot_choose_it(): void {
		$removed = new Clipart( 8, DesignerAssetRepository::CLIPART, 'Star', 'a.png', 'image/png', 10, 10, '2026-11-22 20:00:00', null );

		self::assertTrue( ClipartRules::may_use( $removed, 4, array( 8 ) ) );
		self::assertFalse( ClipartRules::may_use( $removed, 4, array( 9 ) ) );
	}

	public function test_keeping_never_makes_an_unreadable_artwork_usable(): void {
		$broken = new Clipart( 8, DesignerAssetRepository::ARTWORK, 'Bottle', 'a.png', 'image/png', 10, 10, null, null );

		self::assertFalse( ClipartRules::may_use( $broken, 4, array( 8 ) ) );
	}

	public function test_only_the_administrators_cliparts_are_cliparts(): void {
		$asset = static fn( ?int $creator, string $kind ): array => array(
			'creator_id' => $creator,
			'kind'       => $kind,
		);

		self::assertTrue( ClipartRules::is_clipart( $asset( null, DesignerAssetRepository::CLIPART ) ) );
		self::assertTrue( ClipartRules::is_clipart( $asset( null, DesignerAssetRepository::ARTWORK ) ) );
		self::assertFalse( ClipartRules::is_clipart( $asset( null, DesignerAssetRepository::LAYER ) ) );
		self::assertFalse( ClipartRules::is_clipart( $asset( 7, DesignerAssetRepository::ARTWORK ) ) );
		self::assertFalse( ClipartRules::is_clipart( $asset( 7, CreatorUploads::UPLOAD ) ) );
	}
}
