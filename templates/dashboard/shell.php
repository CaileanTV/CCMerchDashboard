<?php
/**
 * Creator dashboard shell (phase 12 placeholder).
 *
 * A complete HTML page without the theme: no get_header(), no wp_head(), no
 * scripts. Rendered by ShellController only after CreatorContext granted
 * access. Every value is escaped here.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from ShellController.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$merchboard_site    = (string) ( $merchboard_view['site_name'] ?? '' );
$merchboard_creator = (string) ( $merchboard_view['creator_name'] ?? '' );
$merchboard_home    = (string) ( $merchboard_view['dashboard_url'] ?? '' );
$merchboard_logout  = (string) ( $merchboard_view['logout_url'] ?? '' );
$merchboard_path    = (string) ( $merchboard_view['path'] ?? '' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( __( 'Creator dashboard', 'merchboard' ) . ' · ' . $merchboard_site ); ?></title>
<style>
	body { margin: 0; font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f6f7f7; color: #1d2327; }
	header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1rem 1.5rem; background: #fff; border-bottom: 1px solid #dcdcde; }
	header a { color: #2271b1; }
	main { max-width: 48rem; margin: 2rem auto; padding: 0 1.5rem; }
	.card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 1.5rem; }
</style>
</head>
<body>
<header>
	<strong><a href="<?php echo esc_url( $merchboard_home ); ?>"><?php echo esc_html( $merchboard_site ); ?></a></strong>
	<span>
		<?php echo esc_html( $merchboard_creator ); ?>
		· <a href="<?php echo esc_url( $merchboard_logout ); ?>"><?php esc_html_e( 'Log out', 'merchboard' ); ?></a>
	</span>
</header>
<main>
	<div class="card">
		<?php /* translators: %s: creator display name. */ ?>
		<h1><?php echo esc_html( sprintf( __( 'Welcome, %s', 'merchboard' ), $merchboard_creator ) ); ?></h1>
		<p><?php esc_html_e( 'Your creator dashboard is being built. Sales, products and payouts will appear here.', 'merchboard' ); ?></p>
		<?php if ( '' !== $merchboard_path ) : ?>
			<p><?php esc_html_e( 'This page is not available yet.', 'merchboard' ); ?> <a href="<?php echo esc_url( $merchboard_home ); ?>"><?php esc_html_e( 'Go to the start page', 'merchboard' ); ?></a></p>
		<?php endif; ?>
	</div>
</main>
</body>
</html>
