<?php
/**
 * The Twitch Extension's terms of service and privacy policy (ADR-112).
 *
 * A complete HTML page without the theme. Every value is escaped here.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from TwitchExtensionLegal.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$merchboard_page     = 'privacy' === ( $merchboard_view['page'] ?? '' ) ? 'privacy' : 'terms';
$merchboard_language = 'da' === ( $merchboard_view['language'] ?? '' ) ? 'da' : 'en';
$merchboard_links    = is_array( $merchboard_view['links'] ?? null ) ? $merchboard_view['links'] : array();
$merchboard_text     = static fn( string $key ): string => is_string( $merchboard_view[ $key ] ?? null ) ? (string) $merchboard_view[ $key ] : '';
$merchboard_site     = '' !== $merchboard_text( 'site' ) ? $merchboard_text( 'site' ) : __( 'our shop', 'merchboard' );
$merchboard_company  = '' !== $merchboard_text( 'company' ) ? $merchboard_text( 'company' ) : $merchboard_site;
$merchboard_who      = $merchboard_company
	. ( '' !== $merchboard_text( 'cvr' ) ? ' (' . sprintf( /* translators: %s: CVR number. */ __( 'CVR %s', 'merchboard' ), $merchboard_text( 'cvr' ) ) . ')' : '' )
	. ( '' !== $merchboard_text( 'address' ) ? ', ' . $merchboard_text( 'address' ) : '' );
$merchboard_email    = $merchboard_text( 'email' );
$merchboard_title    = 'privacy' === $merchboard_page ? __( 'Merch panel for Twitch: privacy policy', 'merchboard' ) : __( 'Merch panel for Twitch: terms of service', 'merchboard' );
$merchboard_link     = static fn( string $name ): string => is_string( $merchboard_links[ $name ] ?? null ) ? (string) $merchboard_links[ $name ] : '';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $merchboard_language ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $merchboard_title . ' – ' . $merchboard_site ); ?></title>
<style>
body{margin:0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2327;background:#f6f7f7}
main{max-width:46rem;margin:0 auto;padding:1.5rem 1rem 3rem;background:#fff}
nav{display:flex;flex-wrap:wrap;gap:.25rem 1rem;font-size:.95rem;border-bottom:1px solid #dcdcde;padding-bottom:.75rem;margin-bottom:1rem}
nav a[aria-current]{font-weight:600;color:#1d2327;text-decoration:none}
a{color:#2271b1}
h1{font-size:1.6rem;line-height:1.3}
h2{font-size:1.2rem;margin-top:2rem}
.meta{color:#50575e;font-size:.9rem}
@media (prefers-color-scheme:dark){body{background:#101517;color:#e2e4e7}main{background:#1d2327}nav{border-color:#3c434a}nav a[aria-current]{color:#e2e4e7}a{color:#72aee6}.meta{color:#a7aaad}}
</style>
</head>
<body>
<main>
	<nav aria-label="<?php esc_attr_e( 'Merch panel for Twitch', 'merchboard' ); ?>">
		<a href="<?php echo esc_url( $merchboard_link( 'terms' ) ); ?>"<?php echo 'terms' === $merchboard_page ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Terms of service', 'merchboard' ); ?></a>
		<a href="<?php echo esc_url( $merchboard_link( 'privacy' ) ); ?>"<?php echo 'privacy' === $merchboard_page ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Privacy policy', 'merchboard' ); ?></a>
		<a href="<?php echo esc_url( $merchboard_link( 'other' ) ); ?>" hreflang="<?php echo esc_attr( 'da' === $merchboard_language ? 'en' : 'da' ); ?>"><?php echo esc_html( 'da' === $merchboard_language ? 'English' : 'Dansk' ); ?></a>
	</nav>
	<h1><?php echo esc_html( $merchboard_title ); ?></h1>
	<p class="meta"><?php esc_html_e( 'Last updated: 15 November 2026', 'merchboard' ); ?></p>

	<?php if ( 'terms' === $merchboard_page ) : ?>
		<h2><?php esc_html_e( 'About the extension', 'merchboard' ); ?></h2>
		<p>
			<?php
			/* translators: 1: the company with CVR and address, 2: the shop's name. */
			echo esc_html( sprintf( __( 'The merch panel for Twitch ("the extension") is provided by %1$s, which runs %2$s. It shows products that a creator sells at %2$s in a panel on the creator\'s Twitch channel.', 'merchboard' ), $merchboard_who, $merchboard_site ) );
			?>
		</p>
		<h2><?php esc_html_e( 'Use', 'merchboard' ); ?></h2>
		<p>
			<?php
			/* translators: %s: the shop's name. */
			echo esc_html( sprintf( __( 'Creators with a creator account at %s can install the extension on their Twitch channel and choose which of their products it shows and a short note. Viewers can look at the panel.', 'merchboard' ), $merchboard_site ) );
			?>
		</p>
		<p><?php esc_html_e( 'The extension only shows products. It has no prices, no links and no purchases, and nothing can be bought in it.', 'merchboard' ); ?></p>
		<h2><?php esc_html_e( 'Content', 'merchboard' ); ?></h2>
		<p><?php esc_html_e( 'Creators are responsible for the note they write. We may remove products or notes that break these terms, our shop\'s terms or Twitch\'s rules, and we may switch the extension off for a channel.', 'merchboard' ); ?></p>
		<h2><?php esc_html_e( 'Twitch', 'merchboard' ); ?></h2>
		<p><?php esc_html_e( 'Your use of Twitch is governed by Twitch\'s own terms of service. The extension is not made, endorsed or sponsored by Twitch.', 'merchboard' ); ?></p>
		<h2><?php esc_html_e( 'Availability and liability', 'merchboard' ); ?></h2>
		<p><?php esc_html_e( 'The extension is provided as it is and free of charge, without any warranty. We may change or stop it at any time. To the extent the law allows, we are not liable for indirect losses from using or not being able to use it.', 'merchboard' ); ?></p>
		<h2><?php esc_html_e( 'Changes and law', 'merchboard' ); ?></h2>
		<p><?php esc_html_e( 'We may update these terms; the date above shows the current version. Danish law applies.', 'merchboard' ); ?></p>
	<?php else : ?>
		<h2><?php esc_html_e( 'Who is responsible', 'merchboard' ); ?></h2>
		<p>
			<?php
			/* translators: 1: the company with CVR and address, 2: the shop's name. */
			echo esc_html( sprintf( __( '%1$s, which runs %2$s, is responsible for the data the merch panel for Twitch processes.', 'merchboard' ), $merchboard_who, $merchboard_site ) );
			?>
		</p>
		<h2><?php esc_html_e( 'What the extension receives', 'merchboard' ); ?></h2>
		<p>
			<?php
			/* translators: %s: the shop's name. */
			echo esc_html( sprintf( __( 'When the panel loads, Twitch gives it a signed token with the channel\'s id, an anonymous viewer id and the viewer\'s role. The panel sends the token to %s only to find the channel\'s creator and the products to show.', 'merchboard' ), $merchboard_site ) );
			?>
		</p>
		<p><?php esc_html_e( 'We read only the channel\'s id. We do not store the token, the anonymous viewer id or anything else about viewers, and the extension never asks viewers to share their Twitch identity.', 'merchboard' ); ?></p>
		<h2><?php esc_html_e( 'No tracking', 'merchboard' ); ?></h2>
		<p><?php esc_html_e( 'The extension uses no cookies, analytics, tracking or advertising. Like any website, our server may briefly log the IP address and time of a request for security and operation.', 'merchboard' ); ?></p>
		<h2><?php esc_html_e( 'Creators', 'merchboard' ); ?></h2>
		<p><?php esc_html_e( 'The products and the note a creator chooses for the panel are stored in the creator\'s account with us, as described in our shop\'s privacy policy.', 'merchboard' ); ?></p>
		<h2><?php esc_html_e( 'Twitch', 'merchboard' ); ?></h2>
		<p><?php esc_html_e( 'Twitch hosts the extension\'s files and shows the panel. Twitch\'s own privacy notice applies to your use of Twitch.', 'merchboard' ); ?></p>
		<h2><?php esc_html_e( 'Your rights', 'merchboard' ); ?></h2>
		<p><?php esc_html_e( 'Under the GDPR you can ask for access to, correction of and deletion of data about you, and you can complain to the Danish Data Protection Agency (Datatilsynet).', 'merchboard' ); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Contact', 'merchboard' ); ?></h2>
	<p>
		<?php if ( '' !== $merchboard_email ) : ?>
			<a href="<?php echo esc_url( 'mailto:' . $merchboard_email ); ?>"><?php echo esc_html( $merchboard_email ); ?></a><br>
		<?php endif; ?>
		<a href="<?php echo esc_url( $merchboard_text( 'home' ) ); ?>"><?php echo esc_html( $merchboard_site ); ?></a>
	</p>
</main>
</body>
</html>
