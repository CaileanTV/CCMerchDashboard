<?php
/**
 * Alert overlay page for OBS (ADR-065 pkt. 4).
 *
 * A complete HTML page without the theme: no get_header() and no wp_head().
 * Only the overlay bundle is printed, by explicit handle. Rendered by
 * OverlayPage after the secret overlay id was found. The alert is drawn by
 * the overlay script from the looks in the configuration (ADR-088), which
 * validates every value; the configuration is JSON with every
 * HTML-significant character escaped.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from OverlayPage.
 */

declare( strict_types=1 );

use MerchBoard\Modules\Alerts\Overlay\OverlayAssets;

defined( 'ABSPATH' ) || exit;

$merchboard_ready     = true === ( $merchboard_view['ready'] ?? false );
$merchboard_font_urls = is_array( $merchboard_view['font_urls'] ?? null ) ? array_filter( $merchboard_view['font_urls'], 'is_string' ) : array();
$merchboard_config    = wp_json_encode( $merchboard_view['config'] ?? array(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Merch Dashboard alerts</title>
<?php
foreach ( $merchboard_font_urls as $merchboard_font_url ) {
	// The approved Google fonts of the looks (ADR-067), one handle per font; no version argument, Google answers 400 to unknown parameters.
	$merchboard_handle = 'merchboard-overlay-font-' . substr( md5( $merchboard_font_url ), 0, 12 );
	wp_register_style( $merchboard_handle, $merchboard_font_url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- See above.
	wp_print_styles( array( $merchboard_handle ) );
}

if ( $merchboard_ready ) {
	wp_print_styles( array( OverlayAssets::HANDLE ) );
}
?>
</head>
<body class="merchboard-overlay">
<div id="merchboard-overlay-alert" class="merchboard-alert merchboard-alert--hidden">
<div id="merchboard-overlay-image" class="merchboard-alert__image"></div>
<div id="merchboard-overlay-texts" class="merchboard-alert__texts">
<div id="merchboard-overlay-text" class="merchboard-overlay__el merchboard-alert__text"></div>
<div id="merchboard-overlay-secondary" class="merchboard-overlay__el merchboard-alert__text"></div>
</div>
</div>
<script type="application/json" id="merchboard-overlay-config"><?php echo false === $merchboard_config ? '{}' : $merchboard_config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, >, &, ' and " escaped. ?></script>
<?php
if ( $merchboard_ready ) {
	wp_print_scripts( array( OverlayAssets::HANDLE ) );
}
?>
</body>
</html>
