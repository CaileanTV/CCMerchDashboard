<?php
/**
 * Creator dashboard: access not active (HTTP 403).
 *
 * Shown to logged-in users without creator access: no capability, no linked
 * creator, an inactive creator, or a failed access check. Deliberately says
 * nothing about which of these applies and links only to logout, never to
 * wp-admin. Every value is escaped here.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from ShellController.
 */

declare( strict_types=1 );

use MerchBoard\Modules\Dashboard\FallbackStyles;

defined( 'ABSPATH' ) || exit;

$merchboard_site   = (string) ( $merchboard_view['site_name'] ?? '' );
$merchboard_logout = (string) ( $merchboard_view['logout_url'] ?? '' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( __( 'Access not active', 'merchboard' ) . ' · ' . $merchboard_site ); ?></title>
<style><?php echo FallbackStyles::css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS without input. ?></style>
</head>
<body>
<main class="merchboard-fallback">
	<div class="merchboard-fallback__card">
		<h1><?php esc_html_e( 'Your access is not active', 'merchboard' ); ?></h1>
		<?php /* translators: %s: site name. */ ?>
		<p><?php echo esc_html( sprintf( __( 'Your creator dashboard access is not active. Please contact %s.', 'merchboard' ), $merchboard_site ) ); ?></p>
		<p><a href="<?php echo esc_url( $merchboard_logout ); ?>"><?php esc_html_e( 'Log out', 'merchboard' ); ?></a></p>
	</div>
</main>
</body>
</html>
