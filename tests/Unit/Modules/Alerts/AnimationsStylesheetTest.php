<?php
/**
 * Static checks of the text animation stylesheet (phase 25, step 6.9).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * A custom property resolves var() where it is declared, not where it is
 * used. The letter animations depend on each letter's --i (and --n, --side), so a
 * variable that uses them must be declared on the letters; declared on the
 * text box it is invalid and the letters do not move. The test runner does
 * not compute CSS, so this is checked statically.
 */
#[CoversNothing]
final class AnimationsStylesheetTest extends TestCase {

	private const STYLESHEET = __DIR__ . '/../../../../assets/src/overlay/animations.css';

	public function test_letter_variables_are_declared_on_the_letters(): void {
		$css = (string) file_get_contents( self::STYLESHEET ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );

		$wrong = array();
		foreach ( $rules as $rule ) {
			$selector = trim( $rule[1] );

			if ( 1 === preg_match( '/--[\w-]+:[^;]*var\(--(?:i|n|side)\)/', $rule[2] ) && ! str_contains( $selector, '.merchboard-anim__letter' ) ) {
				$wrong[] = $selector;
			}
		}

		$this->assertGreaterThan( 20, count( $rules ) );
		$this->assertSame( array(), $wrong );
	}
}
