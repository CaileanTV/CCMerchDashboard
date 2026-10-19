<?php
/**
 * Alert overlay page for OBS (ADR-065 pkt. 4).
 *
 * A complete HTML page without the theme: no get_header() and no wp_head().
 * Only the overlay bundle is printed, by explicit handle. Rendered by
 * OverlayPage after the secret overlay id was found. The CSS values come
 * from OverlaySettings, which only produces validated values (hex colors,
 * integers, a plain font name, one checked text shadow); the configuration
 * is JSON with every HTML-significant character escaped.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from OverlayPage.
 */

declare( strict_types=1 );

use MerchBoard\Modules\Alerts\Overlay\OverlayAssets;

defined( 'ABSPATH' ) || exit;

$merchboard_ready     = true === ( $merchboard_view['ready'] ?? false );
$merchboard_font_url  = is_string( $merchboard_view['font_url'] ?? null ) ? $merchboard_view['font_url'] : '';
$merchboard_variables = is_array( $merchboard_view['variables'] ?? null ) ? $merchboard_view['variables'] : array();
$merchboard_boxes     = is_array( $merchboard_view['boxes'] ?? null ) ? $merchboard_view['boxes'] : array();
$merchboard_config    = wp_json_encode( $merchboard_view['config'] ?? array(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
$merchboard_css       = ':root{';

foreach ( $merchboard_variables as $merchboard_name => $merchboard_value ) {
	$merchboard_css .= '--merchboard-' . preg_replace( '/[^a-z-]/', '', (string) $merchboard_name ) . ':' . str_replace( array( '<', '>', '{', '}', ';' ), '', (string) $merchboard_value ) . ';';
}

$merchboard_css .= '}';

foreach ( array( 'image', 'text', 'secondary' ) as $merchboard_box ) {
	$merchboard_css .= '#merchboard-overlay-' . $merchboard_box . '{' . str_replace( array( '<', '>', '{', '}' ), '', (string) ( $merchboard_boxes[ $merchboard_box ] ?? '' ) ) . '}';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Merch Dashboard alerts</title>
<?php
if ( '' !== $merchboard_font_url ) {
	// The approved Google font (ADR-067); no version argument, Google answers 400 to unknown parameters.
	wp_register_style( 'merchboard-overlay-font', $merchboard_font_url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- See above.
	wp_print_styles( array( 'merchboard-overlay-font' ) );
}
?>
<style><?php echo $merchboard_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated values, with characters that could leave a declaration or the style element removed. ?></style>
<?php
if ( $merchboard_ready ) {
	wp_print_styles( array( OverlayAssets::HANDLE ) );
}
?>
</head>
<body class="merchboard-overlay">
<div id="merchboard-overlay-image" class="merchboard-overlay__el merchboard-overlay__el--hidden"></div>
<div id="merchboard-overlay-text" class="merchboard-overlay__el merchboard-overlay__el--hidden merchboard-overlay__text"></div>
<div id="merchboard-overlay-secondary" class="merchboard-overlay__el merchboard-overlay__el--hidden merchboard-overlay__text"></div>
<script type="application/json" id="merchboard-overlay-config"><?php echo false === $merchboard_config ? '{}' : $merchboard_config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, >, &, ' and " escaped. ?></script>
<?php
if ( $merchboard_ready ) {
	wp_print_scripts( array( OverlayAssets::HANDLE ) );
}
?>
</body>
</html>
