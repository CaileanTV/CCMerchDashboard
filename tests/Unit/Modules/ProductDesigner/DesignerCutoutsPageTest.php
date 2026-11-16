<?php
/**
 * Tests for the keys of the images without background.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use MerchBoard\Modules\ProductDesigner\Admin\DesignerCutoutsPage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A waiting image is shown by its key: a side of the base product or a
 * provider's photo (the owner's test 2026-11-16: provider photos showed a
 * broken image until they were approved).
 */
#[CoversClass( DesignerCutoutsPage::class )]
final class DesignerCutoutsPageTest extends TestCase {

	public function test_a_side_or_a_provider_photo_names_an_image(): void {
		foreach ( array( 'front:black', 'sleeve-left:sand', '_photo-4717:white', 'front:hot%20pink' ) as $key ) {
			self::assertTrue( DesignerCutoutsPage::valid_key( $key ), $key );
		}

		foreach ( array( '', 'front', '_close-front:black', '../x:black', 'Front:black', '_photo-x:white' ) as $key ) {
			self::assertFalse( DesignerCutoutsPage::valid_key( $key ), $key );
		}
	}
}
