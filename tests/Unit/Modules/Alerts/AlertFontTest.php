<?php
/**
 * Tests for a Google Fonts family.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use MerchBoard\Modules\Alerts\Fonts\AlertFont;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ADR-067: only plain family names reach a style sheet or a URL, and the
 * stylesheet URL asks only for weights the family has.
 */
#[CoversClass( AlertFont::class )]
final class AlertFontTest extends TestCase {

	public function test_google_variants_become_sorted_upright_weights(): void {
		$font = AlertFont::from_array(
			array(
				'family'   => 'Open Sans',
				'category' => 'sans-serif',
				'variants' => array( '700', 'regular', 'italic', '300italic', '300', '700', 'bogus', '950' ),
			)
		);

		self::assertNotNull( $font );
		self::assertSame( array( 300, 400, 700 ), $font->weights );
		self::assertSame( 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;700&display=swap', $font->css_url() );
		self::assertSame(
			array(
				'family'   => 'Open Sans',
				'category' => 'sans-serif',
				'weights'  => array( 300, 400, 700 ),
			),
			$font->to_array()
		);
		self::assertEquals( $font, AlertFont::from_array( $font->to_array() ), 'Stored data reads back the same.' );
	}

	public function test_missing_weights_and_unknown_categories_get_safe_defaults(): void {
		$font = AlertFont::from_array(
			array(
				'family'   => 'Press Start 2P',
				'category' => '<b>',
				'variants' => array( 'italic' ),
			)
		);

		self::assertNotNull( $font );
		self::assertSame( 'sans-serif', $font->category );
		self::assertSame( array( 400 ), $font->weights );
	}

	/**
	 * Invalid data.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid(): array {
		return array(
			'not an array'      => array( 'Roboto' ),
			'no family'         => array( array( 'category' => 'serif' ) ),
			'family not string' => array( array( 'family' => 5 ) ),
			'css injection'     => array( array( 'family' => 'Roboto";}body{x' ) ),
			'url injection'     => array( array( 'family' => 'Roboto&family=X' ) ),
			'trailing newline'  => array( array( 'family' => "Roboto\n" ) ),
			'leading space'     => array( array( 'family' => ' Roboto' ) ),
			'reserved'          => array( array( 'family' => 'Inherit' ) ),
			'too long'          => array( array( 'family' => str_repeat( 'a', 65 ) ) ),
		);
	}

	/**
	 * Rejected data.
	 *
	 * @param mixed $data Data.
	 */
	#[DataProvider( 'invalid' )]
	public function test_invalid_data_is_rejected( mixed $data ): void {
		self::assertNull( AlertFont::from_array( $data ) );
	}
}
