<?php
/**
 * Styles for the dashboard pages that render without the bundle.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * The no-access page and the shell's "unavailable" box render without the
 * dashboard bundle (no access, or a missing build), so they cannot use the
 * bundle's design tokens. They get this small, static stylesheet instead: the
 * same semantic token names with the Light values from
 * `assets/src/dashboard/styles/_tokens.scss` (a unit test keeps the values in
 * sync) and one card rule. The shell's CSP allows inline styles.
 */
final class FallbackStyles {

	/**
	 * Light token values used by the fallback pages, by token name.
	 */
	public const TOKENS = array(
		'--merchboard-bg'      => '#f8fafc',
		'--merchboard-surface' => '#fff',
		'--merchboard-border'  => '#e2e8f0',
		'--merchboard-text'    => '#0f172a',
		'--merchboard-link'    => '#4338ca',
		'--merchboard-focus'   => '#4f46e5',
		'--merchboard-font'    => 'system-ui, -apple-system, "Segoe UI", roboto, "Helvetica Neue", arial, sans-serif',
	);

	/**
	 * The stylesheet: static, no user input. Classes are prefixed, and the
	 * `body` rule only applies on these standalone pages.
	 */
	public static function css(): string {
		$tokens = '';

		foreach ( self::TOKENS as $name => $value ) {
			$tokens .= $name . ': ' . $value . '; ';
		}

		return ':root { ' . $tokens . '}'
			. ' body { margin: 0; font: 16px/1.5 var(--merchboard-font); color: var(--merchboard-text); background: var(--merchboard-bg); }'
			. ' .merchboard-fallback { max-width: 32rem; margin: 4rem auto; padding: 0 1.5rem; }'
			. ' .merchboard-fallback__card { padding: 1.5rem; background: var(--merchboard-surface); border: 1px solid var(--merchboard-border); border-radius: 4px; }'
			. ' .merchboard-fallback a { color: var(--merchboard-link); }'
			. ' .merchboard-fallback :focus-visible { outline: 3px solid var(--merchboard-focus); outline-offset: 2px; }';
	}
}
