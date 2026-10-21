<?php
/**
 * Public page of the YouTube chatbot: overview, privacy policy or terms
 * (ADR-071).
 *
 * A complete HTML page without the theme and without scripts. Rendered by
 * LegalPage in the page's language. The policy and terms are limited to
 * wp_kses_post(); every other value is escaped here.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from LegalPage.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$merchboard_page      = is_string( $merchboard_view['page'] ?? null ) ? $merchboard_view['page'] : 'home';
$merchboard_language  = 'da' === ( $merchboard_view['language'] ?? null ) ? 'da' : 'en';
$merchboard_site      = is_string( $merchboard_view['site'] ?? null ) ? $merchboard_view['site'] : '';
$merchboard_body      = is_string( $merchboard_view['body'] ?? null ) ? $merchboard_view['body'] : '';
$merchboard_links     = is_array( $merchboard_view['links'] ?? null ) ? $merchboard_view['links'] : array();
$merchboard_other     = is_string( $merchboard_view['other'] ?? null ) ? $merchboard_view['other'] : '';
$merchboard_version   = is_int( $merchboard_view['version'] ?? null ) ? $merchboard_view['version'] : 0;
$merchboard_published = is_int( $merchboard_view['published'] ?? null ) ? $merchboard_view['published'] : 0;
$merchboard_link      = static fn( string $name ): string => is_string( $merchboard_links[ $name ] ?? null ) ? $merchboard_links[ $name ] : '';
$merchboard_title     = match ( $merchboard_page ) {
	'privacy' => __( 'Privacy policy of the YouTube chatbot', 'merchboard' ),
	'terms'   => __( 'Terms of service of the YouTube chatbot', 'merchboard' ),
	default   => __( 'YouTube chatbot', 'merchboard' ),
};
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $merchboard_language ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $merchboard_title . ( '' === $merchboard_site ? '' : ' – ' . $merchboard_site ) ); ?></title>
<style>
body{margin:0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2327;background:#f6f7f7}
main{max-width:46rem;margin:0 auto;padding:1.5rem 1rem 3rem;background:#fff}
nav{display:flex;flex-wrap:wrap;gap:.25rem 1rem;font-size:.95rem;border-bottom:1px solid #dcdcde;padding-bottom:.75rem;margin-bottom:1rem}
nav a[aria-current]{font-weight:600;color:#1d2327;text-decoration:none}
a{color:#2271b1}
h1{font-size:1.6rem;line-height:1.3}
h2{font-size:1.2rem;margin-top:2rem}
.merchboard-legal__meta{color:#50575e;font-size:.9rem}
footer{margin-top:3rem;font-size:.9rem;color:#50575e}
@media (prefers-color-scheme:dark){body{background:#101517;color:#e2e4e7}main{background:#1d2327}nav{border-color:#3c434a}nav a[aria-current]{color:#e2e4e7}a{color:#72aee6}.merchboard-legal__meta,footer{color:#a7aaad}}
</style>
</head>
<body>
<main>
	<nav aria-label="<?php esc_attr_e( 'YouTube chatbot', 'merchboard' ); ?>">
		<?php
		foreach ( array(
			'home'    => __( 'YouTube chatbot', 'merchboard' ),
			'privacy' => __( 'Privacy policy', 'merchboard' ),
			'terms'   => __( 'Terms of service', 'merchboard' ),
		) as $merchboard_name => $merchboard_label ) {
			printf( '<a href="%1$s"%2$s>%3$s</a>', esc_url( $merchboard_link( $merchboard_name ) ), $merchboard_name === $merchboard_page ? ' aria-current="page"' : '', esc_html( $merchboard_label ) );
		}
		printf( '<a href="%1$s" hreflang="%2$s" lang="%2$s">%3$s</a>', esc_url( $merchboard_link( 'other' ) ), esc_attr( 'da' === $merchboard_language ? 'en' : 'da' ), esc_html( $merchboard_other ) );
		?>
	</nav>
	<h1><?php echo esc_html( $merchboard_title ); ?></h1>
	<?php if ( 'home' === $merchboard_page ) : ?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: name of the shop. */
					__( 'The YouTube chatbot of %s writes a message in a creator\'s YouTube live chat when someone buys the creator\'s merchandise.', 'merchboard' ),
					'' === $merchboard_site ? __( 'our shop', 'merchboard' ) : $merchboard_site
				)
			);
			?>
		</p>
		<p><?php esc_html_e( 'Creators connect their own YouTube channel in the creator dashboard and give read-only access (youtube.readonly), so the chatbot can see when they are live. The messages are written by our own bot account. Creators can remove the bot at any time.', 'merchboard' ); ?></p>
		<p><?php esc_html_e( 'The chatbot uses YouTube API Services. How we handle data, and the terms of use:', 'merchboard' ); ?></p>
		<ul>
			<li><a href="<?php echo esc_url( $merchboard_link( 'privacy' ) ); ?>"><?php esc_html_e( 'Privacy policy', 'merchboard' ); ?></a></li>
			<li><a href="<?php echo esc_url( $merchboard_link( 'terms' ) ); ?>"><?php esc_html_e( 'Terms of service', 'merchboard' ); ?></a></li>
			<li><a href="https://www.youtube.com/t/terms"><?php esc_html_e( 'YouTube Terms of Service', 'merchboard' ); ?></a></li>
			<li><a href="https://policies.google.com/privacy"><?php esc_html_e( 'Google Privacy Policy', 'merchboard' ); ?></a></li>
		</ul>
	<?php else : ?>
		<p class="merchboard-legal__meta">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: version number, 2: date. */
					__( 'Version %1$d, published %2$s', 'merchboard' ),
					$merchboard_version,
					wp_date( (string) get_option( 'date_format' ), $merchboard_published )
				)
			);
			?>
		</p>
		<?php echo wp_kses_post( $merchboard_body ); ?>
	<?php endif; ?>
	<footer><a href="<?php echo esc_url( $merchboard_link( 'dashboard' ) ); ?>"><?php esc_html_e( 'Creator dashboard', 'merchboard' ); ?></a></footer>
</main>
</body>
</html>
