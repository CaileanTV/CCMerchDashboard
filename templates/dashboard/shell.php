<?php
/**
 * Creator dashboard shell (React app, phase 13).
 *
 * A complete HTML page without the theme: no get_header() and no wp_head().
 * Only the dashboard bundle and its WordPress dependencies are printed, by
 * explicit handle. Rendered by ShellController only after CreatorContext
 * granted access. Every value is escaped here. `data-theme` is set only for
 * the user's explicit theme (ADR-044); without it CSS follows the system.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from ShellController.
 */

declare( strict_types=1 );

use MerchBoard\Modules\Dashboard\DashboardAssets;
use MerchBoard\Modules\Dashboard\FallbackStyles;
use MerchBoard\Modules\Dashboard\ThemePreference;

defined( 'ABSPATH' ) || exit;

$merchboard_site   = (string) ( $merchboard_view['site_name'] ?? '' );
$merchboard_logout = (string) ( $merchboard_view['logout_url'] ?? '' );
$merchboard_ready  = true === ( $merchboard_view['assets_ready'] ?? false );
$merchboard_theme  = $merchboard_view['theme'] ?? null;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?><?php echo ThemePreference::is_theme( $merchboard_theme ) ? ' data-theme="' . esc_attr( (string) $merchboard_theme ) . '"' : ''; ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( __( 'Creator dashboard', 'merchboard' ) . ' · ' . $merchboard_site ); ?></title>
<?php
if ( $merchboard_ready ) {
	wp_print_styles( array( DashboardAssets::HANDLE ) );
} else {
	echo '<style>' . FallbackStyles::css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS without input.
}
?>
</head>
<body class="merchboard-body">
<div id="merchboard-dashboard-root" class="merchboard-app">
<?php if ( $merchboard_ready ) : ?>
	<p class="merchboard-shell-loading" role="status"><?php esc_html_e( 'Loading your dashboard…', 'merchboard' ); ?></p>
	<noscript><p><?php esc_html_e( 'The creator dashboard needs JavaScript. Please enable it in your browser.', 'merchboard' ); ?></p></noscript>
<?php else : ?>
	<div class="merchboard-fallback">
		<div role="alert" class="merchboard-fallback__card">
			<p><strong><?php esc_html_e( 'The dashboard is temporarily unavailable.', 'merchboard' ); ?></strong></p>
			<p><?php esc_html_e( 'Please try again later.', 'merchboard' ); ?></p>
			<p><a href="<?php echo esc_url( $merchboard_logout ); ?>"><?php esc_html_e( 'Log out', 'merchboard' ); ?></a></p>
		</div>
	</div>
<?php endif; ?>
</div>
<?php
if ( $merchboard_ready ) {
	wp_print_scripts( array( DashboardAssets::HANDLE ) );
}
?>
</body>
</html>
