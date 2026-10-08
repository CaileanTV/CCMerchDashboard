<?php
/**
 * Tests for the dashboard's semantic design tokens and themes (ADR-043).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Dashboard;

use MerchBoard\Modules\Dashboard\FallbackStyles;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Reads the SCSS sources statically: components use only semantic tokens and
 * know no theme names, every used token is defined, every theme overrides
 * every color token, stable roles never derive from an accent, the fallback
 * pages mirror Light, and all six themes meet the contrast and separation
 * requirements of ADR-043. This is token-level verification; it does not
 * replace a visual check in a browser.
 */
#[CoversClass( FallbackStyles::class )]
final class DesignTokensTest extends TestCase {

	private const STYLES = __DIR__ . '/../../../../assets/src/dashboard/styles/';

	/**
	 * The six themes (ADR-043/ADR-044 values).
	 */
	private const THEMES = array( 'light', 'dark-black', 'dark-green', 'dark-blue', 'dark-red', 'dark-pink' );

	/**
	 * Roles that must be the same in every dark theme and never derive from
	 * the accent.
	 */
	private const STABLE_ROLES = array( 'success', 'success-bg', 'warning', 'warning-bg', 'danger', 'danger-bg', 'on-danger', 'info', 'info-bg', 'negative', 'focus', 'focus-on-inverse' );

	/**
	 * Phase 21 step 1 accent drafts rejected because they were too close to
	 * danger. They must not return.
	 */
	private const REJECTED_ACCENTS = array(
		'dark-red'  => '#e09a9e',
		'dark-pink' => '#e3a0c3',
	);

	/**
	 * Token source.
	 */
	private static function tokens_scss(): string {
		return (string) file_get_contents( self::STYLES . '_tokens.scss' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
	}

	/**
	 * `--name: value` declarations in a block body.
	 *
	 * @param string $body Block body.
	 * @return array<string, string>
	 */
	private static function parse( string $body ): array {
		preg_match_all( '/^\s*(--merchboard-[a-z0-9-]+):\s*([^;]+);/m', $body, $matches, PREG_SET_ORDER );

		$tokens = array();
		foreach ( $matches as $match ) {
			$tokens[ $match[1] ] = trim( $match[2] );
		}

		return $tokens;
	}

	/**
	 * Light declarations: the plain `:root` block.
	 *
	 * @return array<string, string>
	 */
	private static function light(): array {
		if ( 1 !== preg_match( '/^:root\s*\{(.*?)\n\}/ms', self::tokens_scss(), $block ) ) {
			self::fail( '_tokens.scss has no :root block.' );
		}

		return self::parse( $block[1] );
	}

	/**
	 * Mixin bodies by name.
	 *
	 * @return array<string, string>
	 */
	private static function mixins(): array {
		preg_match_all( '/^@mixin ([a-z0-9-]+) \{(.*?)\n\}/ms', self::tokens_scss(), $matches, PREG_SET_ORDER );

		$mixins = array();
		foreach ( $matches as $match ) {
			$mixins[ $match[1] ] = $match[2];
		}

		return $mixins;
	}

	/**
	 * Declarations of a mixin, with its includes expanded first.
	 *
	 * @param string $name Mixin name.
	 * @return array<string, string>
	 */
	private static function mixin_tokens( string $name ): array {
		$mixins = self::mixins();
		self::assertArrayHasKey( $name, $mixins, 'Mixin ' . $name . ' exists.' );

		$tokens = array();
		preg_match_all( '/@include ([a-z0-9-]+);/', $mixins[ $name ], $includes );
		foreach ( $includes[1] as $include ) {
			$tokens = array_merge( $tokens, self::mixin_tokens( $include ) );
		}

		return array_merge( $tokens, self::parse( $mixins[ $name ] ) );
	}

	/**
	 * A theme's declarations: Light, overridden by the theme's mixin.
	 *
	 * @param string $theme Theme.
	 * @return array<string, string>
	 */
	private static function theme( string $theme ): array {
		if ( 'light' === $theme ) {
			return self::light();
		}

		return array_merge( self::light(), self::mixin_tokens( 'merchboard-theme-' . $theme ) );
	}

	/**
	 * A role's color in a theme, with `var()` aliases resolved.
	 *
	 * @param string $theme Theme.
	 * @param string $role  Role without prefix.
	 */
	private static function color( string $theme, string $role ): string {
		$tokens = self::theme( $theme );
		$value  = $tokens[ '--merchboard-' . $role ] ?? '';

		while ( 1 === preg_match( '/^var\((--merchboard-[a-z0-9-]+)\)$/', $value, $alias ) ) {
			$value = $tokens[ $alias[1] ] ?? '';
		}

		return $value;
	}

	/**
	 * Component styles without comments.
	 */
	private static function component_styles(): string {
		$scss = (string) file_get_contents( self::STYLES . 'index.scss' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		return (string) preg_replace( '#//[^\n]*#', '', $scss );
	}

	/**
	 * The themes as a data provider.
	 *
	 * @return array<string, array{string}>
	 */
	public static function themes(): array {
		return array_combine( self::THEMES, array_map( static fn( string $theme ): array => array( $theme ), self::THEMES ) );
	}

	public function test_components_use_no_color_literals_raw_palette_or_theme_names(): void {
		$scss = self::component_styles();

		self::assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}\b/i', $scss, 'No hex colors outside the tokens.' );
		self::assertDoesNotMatchRegularExpression( '/\b(?:rgba?|hsla?|oklch|color-mix)\(/i', $scss, 'No color functions outside the tokens.' );
		self::assertDoesNotMatchRegularExpression( '/:\s*(?:white|black)\b/i', $scss, 'No named colors.' );
		self::assertDoesNotMatchRegularExpression( '/--merchboard-(?:gray|primary)-\d/', $scss, 'No raw palette steps.' );
		self::assertDoesNotMatchRegularExpression( '/data-theme|prefers-color-scheme|dark-(?:black|green|blue|red|pink)/', $scss, 'Components know no themes.' );
	}

	public function test_every_token_used_by_components_is_defined(): void {
		$scss    = self::component_styles();
		$defined = self::light();

		preg_match_all( '/var\((--merchboard-[a-z0-9-]+)\)/', $scss, $used );
		$names = $used[1];

		// The alert tones are interpolated: var(--merchboard-#{$tone}) and -bg.
		if ( 1 !== preg_match( '/@each \$tone in ([a-z, ]+) \{/', $scss, $tones ) ) {
			self::fail( 'The alert tone loop was not found.' );
		}

		foreach ( array_map( 'trim', explode( ',', $tones[1] ) ) as $tone ) {
			$names[] = '--merchboard-' . $tone;
			$names[] = '--merchboard-' . $tone . '-bg';
		}

		foreach ( array_unique( $names ) as $name ) {
			self::assertArrayHasKey( $name, $defined, $name . ' is defined in _tokens.scss.' );
		}
	}

	public function test_the_six_themes_are_wired_without_aliases(): void {
		$scss = self::tokens_scss();

		foreach ( array_slice( self::THEMES, 1 ) as $theme ) {
			self::assertStringContainsString( ':root[data-theme="' . $theme . '"] {' . "\n\n\t@include merchboard-theme-" . $theme . ";\n}", $scss, $theme . ' has its selector.' );
		}

		preg_match_all( '/data-theme="([^"]+)"/', $scss, $selectors );
		self::assertSame( array_slice( self::THEMES, 1 ), $selectors[1], 'Exactly the five dark theme selectors; Light is :root.' );

		preg_match_all( '/^@mixin merchboard-theme-([a-z-]+)/m', $scss, $mixins );
		self::assertSame( array_slice( self::THEMES, 1 ), $mixins[1], 'No theme mixins beyond the six themes.' );
	}

	public function test_dark_system_preference_gives_dark_black_only_without_an_explicit_theme(): void {
		self::assertStringContainsString(
			"@media (prefers-color-scheme: dark) {\n\n\t:root:not([data-theme]) {\n\n\t\t@include merchboard-theme-dark-black;\n\t}\n}",
			self::tokens_scss()
		);
		self::assertSame( 1, substr_count( self::tokens_scss(), 'prefers-color-scheme' ), 'No other system-preference rule.' );
	}

	/**
	 * Every theme overrides every color token, so no Light color leaks.
	 *
	 * @param string $theme Theme.
	 */
	#[DataProvider( 'themes' )]
	public function test_theme_overrides_every_color_token( string $theme ): void {
		if ( 'light' === $theme ) {
			self::assertStringContainsString( "color-scheme: light;\n", self::tokens_scss() );
			$this->addToAssertionCount( 1 );
			return;
		}

		$own = self::mixin_tokens( 'merchboard-theme-' . $theme );

		foreach ( self::light() as $name => $value ) {
			if ( 1 === preg_match( '/#[0-9a-f]{3,8}\b|rgb\(/i', $value ) ) {
				self::assertArrayHasKey( $name, $own, $theme . ' overrides ' . $name . '.' );
			}
		}
	}

	public function test_stable_roles_are_shared_and_independent_of_the_accent(): void {
		$light  = self::light();
		$shared = self::mixin_tokens( 'merchboard-dark-roles' );
		$mixins = self::mixins();

		self::assertStringContainsString( 'color-scheme: dark;', $mixins['merchboard-dark-roles'] );

		foreach ( self::STABLE_ROLES as $role ) {
			$name = '--merchboard-' . $role;

			self::assertMatchesRegularExpression( '/^#[0-9a-f]{3,6}$/', $light[ $name ], 'Light ' . $role . ' is its own value.' );
			self::assertMatchesRegularExpression( '/^#[0-9a-f]{3,6}$/', $shared[ $name ] ?? '', 'Dark ' . $role . ' is defined once for all dark themes.' );

			foreach ( array_slice( self::THEMES, 1 ) as $theme ) {
				self::assertArrayNotHasKey( $name, self::parse( $mixins[ 'merchboard-theme-' . $theme ] ), $theme . ' must not override the stable ' . $role . '.' );
				self::assertNotSame( self::color( $theme, 'primary' ), self::color( $theme, $role ), $theme . ': ' . $role . ' is not the accent.' );
			}
		}

		self::assertSame( 'var(--merchboard-negative)', $light['--merchboard-chart-negative'] );
		self::assertSame( 'var(--merchboard-border-strong)', $light['--merchboard-chart-baseline'] );
	}

	public function test_light_values_from_step_3_are_unchanged(): void {
		$expected = array(
			'bg'             => '#f8fafc',
			'surface'        => '#fff',
			'text'           => '#0f172a',
			'primary'        => '#4f46e5',
			'link'           => '#4338ca',
			'focus'          => '#4f46e5',
			'control-border' => '#7c8799',
			'danger'         => '#b91c1c',
			'negative'       => '#64748b',
		);

		foreach ( $expected as $role => $value ) {
			self::assertSame( $value, self::color( 'light', $role ), 'Light ' . $role );
		}
	}

	/**
	 * The logo plate (ADR-042): none on Light, a clearly light plate on every
	 * dark theme, so a logo made for light backgrounds stays readable.
	 *
	 * @param string $theme Theme.
	 */
	#[DataProvider( 'themes' )]
	public function test_the_logo_plate_suits_every_theme( string $theme ): void {
		$plate = self::color( $theme, 'logo-plate' );

		if ( 'light' === $theme ) {
			self::assertSame( 'transparent', $plate );
			return;
		}

		self::assertSame( self::color( 'light', 'bg' ), $plate, 'The same light surface the logo was made for.' );
		self::assertGreaterThanOrEqual( 3.0, self::contrast( $plate, self::color( $theme, 'surface' ) ), $theme . ': the plate stands out from the top bar.' );
		self::assertGreaterThanOrEqual( 3.0, self::contrast( $plate, self::color( $theme, 'bg' ) ) );
	}

	public function test_the_login_stylesheet_mirrors_the_light_and_dark_black_tokens(): void {
		$css = (string) file_get_contents( self::STYLES . '../../../css/login.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local asset.

		if ( 1 !== preg_match( '/^body\.merchboard-login \{(.*?)\n\}/ms', $css, $light ) || 1 !== preg_match( '/@media \(prefers-color-scheme: dark\) \{\s*body\.merchboard-login \{(.*?)\n\t\}/s', $css, $dark ) ) {
			self::fail( 'login.css has no light or dark token block.' );
		}

		$pairs = array(
			'light'      => self::parse( $light[1] ),
			'dark-black' => self::parse( $dark[1] ),
		);

		foreach ( $pairs as $theme => $tokens ) {
			self::assertGreaterThanOrEqual( 12, count( $tokens ), $theme );

			foreach ( $tokens as $name => $value ) {
				self::assertSame( self::color( $theme, substr( $name, strlen( '--merchboard-' ) ) ), $value, 'login.css ' . $theme . ' ' . $name );
			}
		}
	}

	public function test_fallback_pages_mirror_the_light_tokens(): void {
		$tokens = self::light();

		foreach ( FallbackStyles::TOKENS as $name => $value ) {
			self::assertSame( $tokens[ $name ] ?? null, $value, $name . ' matches _tokens.scss.' );
		}

		self::assertStringNotContainsString( '<', FallbackStyles::css(), 'Cannot close the style element.' );
	}

	/**
	 * Pairs and minimum contrast (WCAG 2.1: text 4.5:1; UI components,
	 * focus and essential graphics 3:1).
	 *
	 * @return array<string, array{string, string, float}>
	 */
	private static function pairs(): array {
		return array(
			'text on bg'                  => array( 'text', 'bg', 4.5 ),
			'text on surface'             => array( 'text', 'surface', 4.5 ),
			'text on surface-muted'       => array( 'text', 'surface-muted', 4.5 ),
			'text on hover'               => array( 'text', 'hover-bg', 4.5 ),
			'text-muted on surface'       => array( 'text-muted', 'surface', 4.5 ),
			'text-muted on bg'            => array( 'text-muted', 'bg', 4.5 ),
			'text-muted on surface-muted' => array( 'text-muted', 'surface-muted', 4.5 ),
			'text-subtle on surface'      => array( 'text-subtle', 'surface', 4.5 ),
			'text-subtle on bg'           => array( 'text-subtle', 'bg', 4.5 ),
			'link on surface'             => array( 'link', 'surface', 4.5 ),
			'link on bg'                  => array( 'link', 'bg', 4.5 ),
			'link on surface-muted'       => array( 'link', 'surface-muted', 4.5 ),
			'selected text'               => array( 'selected-text', 'selected-bg', 4.5 ),
			'on-primary on primary'       => array( 'on-primary', 'primary', 4.5 ),
			'on-primary on primary-hover' => array( 'on-primary', 'primary-hover', 4.5 ),
			'on-danger on danger'         => array( 'on-danger', 'danger', 4.5 ),
			'on-inverse on inverse'       => array( 'on-inverse', 'inverse-surface', 4.5 ),
			'success on its background'   => array( 'success', 'success-bg', 4.5 ),
			'warning on its background'   => array( 'warning', 'warning-bg', 4.5 ),
			'danger on its background'    => array( 'danger', 'danger-bg', 4.5 ),
			'info on its background'      => array( 'info', 'info-bg', 4.5 ),
			'danger text on surface'      => array( 'danger', 'surface', 4.5 ),
			'control text on control'     => array( 'text', 'control-bg', 4.5 ),
			'control border on control'   => array( 'control-border', 'control-bg', 3.0 ),
			'control border on bg'        => array( 'control-border', 'bg', 3.0 ),
			'border-strong on surface'    => array( 'border-strong', 'surface', 3.0 ),
			'primary button on surface'   => array( 'primary', 'surface', 3.0 ),
			'focus on surface'            => array( 'focus', 'surface', 3.0 ),
			'focus on bg'                 => array( 'focus', 'bg', 3.0 ),
			'focus on surface-muted'      => array( 'focus', 'surface-muted', 3.0 ),
			'focus on selected'           => array( 'focus', 'selected-bg', 3.0 ),
			'focus on inverse'            => array( 'focus-on-inverse', 'inverse-surface', 3.0 ),
			'negative on surface'         => array( 'negative', 'surface', 3.0 ),
			'chart series on surface'     => array( 'chart-series', 'surface', 3.0 ),
			'chart negative on surface'   => array( 'chart-negative', 'surface', 3.0 ),
			'chart baseline on surface'   => array( 'chart-baseline', 'surface', 3.0 ),
		);
	}

	/**
	 * Every theme × pair.
	 *
	 * @return array<string, array{string, string, string, float}>
	 */
	public static function contrast_cases(): array {
		$cases = array();

		foreach ( self::THEMES as $theme ) {
			foreach ( self::pairs() as $label => $pair ) {
				$cases[ $theme . ': ' . $label ] = array( $theme, $pair[0], $pair[1], $pair[2] );
			}
		}

		return $cases;
	}

	/**
	 * Contrast in every theme.
	 *
	 * @param string $theme      Theme.
	 * @param string $foreground Foreground role.
	 * @param string $background Background role.
	 * @param float  $minimum    Minimum ratio.
	 */
	#[DataProvider( 'contrast_cases' )]
	public function test_contrast( string $theme, string $foreground, string $background, float $minimum ): void {
		$ratio = self::contrast( self::color( $theme, $foreground ), self::color( $theme, $background ) );

		self::assertGreaterThanOrEqual( $minimum, $ratio, sprintf( '%s: %s on %s is %.2f:1', $theme, $foreground, $background, $ratio ) );
	}

	/**
	 * ADR-043: the accent is distinct from danger and negative (and negative
	 * from danger) in every theme.
	 *
	 * @param string $theme Theme.
	 */
	#[DataProvider( 'themes' )]
	public function test_accent_is_distinct_from_danger_and_negative( string $theme ): void {
		foreach ( array( array( 'primary', 'danger' ), array( 'primary', 'negative' ), array( 'negative', 'danger' ), array( 'chart-series', 'chart-negative' ) ) as $pair ) {
			$distance = self::oklab_distance( self::color( $theme, $pair[0] ), self::color( $theme, $pair[1] ) );

			self::assertGreaterThanOrEqual( 15.0, $distance, sprintf( '%s: %s vs %s, OKLab ΔE %.1f.', $theme, $pair[0], $pair[1], $distance ) );
		}
	}

	public function test_rejected_step_1_accents_do_not_return(): void {
		foreach ( self::REJECTED_ACCENTS as $theme => $rejected ) {
			self::assertLessThan( 15.0, self::oklab_distance( $rejected, self::color( $theme, 'danger' ) ), $theme . ': the rejected draft would still break ADR-043 (guard is meaningful).' );
			self::assertGreaterThanOrEqual( 5.0, self::oklab_distance( $rejected, self::color( $theme, 'primary' ) ), $theme . ': the accent is not the rejected draft or a near copy.' );

			foreach ( array( 'primary', 'primary-hover', 'link', 'selected-text' ) as $role ) {
				self::assertNotSame( $rejected, self::color( $theme, $role ), $theme . ' ' . $role );
			}
		}
	}

	/**
	 * Linear sRGB channels of a hex color.
	 *
	 * @param string $hex `#rgb` or `#rrggbb`.
	 * @return array{float, float, float}
	 */
	private static function linear( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		self::assertMatchesRegularExpression( '/^[0-9a-f]{6}$/', $hex, 'A hex color.' );

		$channel = static function ( string $pair ): float {
			$value = hexdec( $pair ) / 255;

			return $value <= 0.04045 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
		};

		return array( $channel( substr( $hex, 0, 2 ) ), $channel( substr( $hex, 2, 2 ) ), $channel( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * WCAG 2.1 contrast ratio.
	 *
	 * @param string $first  Hex color.
	 * @param string $second Hex color.
	 */
	private static function contrast( string $first, string $second ): float {
		$luminance = static function ( string $hex ): float {
			[ $r, $g, $b ] = self::linear( $hex );

			return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
		};

		$a = $luminance( $first );
		$b = $luminance( $second );

		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	/**
	 * OKLab distance (×100) between two hex colors.
	 *
	 * @param string $first  Hex color.
	 * @param string $second Hex color.
	 */
	private static function oklab_distance( string $first, string $second ): float {
		$oklab = static function ( string $hex ): array {
			[ $r, $g, $b ] = self::linear( $hex );

			$l = ( 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b ) ** ( 1 / 3 );
			$m = ( 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b ) ** ( 1 / 3 );
			$s = ( 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b ) ** ( 1 / 3 );

			return array(
				0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
				1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
				0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
			);
		};

		$a = $oklab( $first );
		$b = $oklab( $second );

		return 100 * sqrt( ( $a[0] - $b[0] ) ** 2 + ( $a[1] - $b[1] ) ** 2 + ( $a[2] - $b[2] ) ** 2 );
	}
}
