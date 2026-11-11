<?php
/**
 * The claim page of a Twitch gift (ADR-107): the prize, the winner and a
 * button to log in with Twitch.
 *
 * A complete HTML page without the theme and without scripts. Rendered by
 * GiftClaim; every value is escaped here.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from GiftClaim::view().
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$merchboard_text     = static fn( string $key ): string => is_string( $merchboard_view[ $key ] ?? null ) ? $merchboard_view[ $key ] : '';
$merchboard_state    = $merchboard_text( 'state' );
$merchboard_site     = $merchboard_text( 'site' );
$merchboard_product  = $merchboard_text( 'product' );
$merchboard_image    = $merchboard_text( 'image' );
$merchboard_winner   = $merchboard_text( 'winner' );
$merchboard_deadline = $merchboard_text( 'deadline' );
$merchboard_claimed  = true === ( $merchboard_view['claimed'] ?? false );
$merchboard_title    = in_array( $merchboard_state, array( 'login', 'redeem', 'other' ), true ) ? __( 'You won a gift!', 'merchboard' ) : __( 'Twitch gift', 'merchboard' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $merchboard_title . ( '' === $merchboard_site ? '' : ' – ' . $merchboard_site ) ); ?></title>
<style>
body{margin:0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2327;background:#f3f1f8;display:flex;min-height:100vh;align-items:center;justify-content:center}
main{box-sizing:border-box;width:100%;max-width:28rem;margin:1.5rem 1rem;padding:2rem 1.5rem;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);text-align:center}
.merchboard-gift__site{margin:0 0 1rem;color:#50575e;font-size:.9rem}
h1{font-size:1.5rem;line-height:1.3;margin:0 0 .5rem;text-wrap:balance}
.merchboard-gift__image{display:block;max-width:100%;width:14rem;height:auto;margin:1rem auto;border-radius:8px}
.merchboard-gift__product{font-weight:600;font-size:1.1rem;margin:.5rem 0}
.merchboard-gift__meta{color:#50575e;font-size:.95rem;margin:.25rem 0}
.merchboard-gift__button{display:inline-block;margin-top:1.25rem;padding:.75rem 1.5rem;border-radius:6px;background:#9146ff;color:#fff;font-weight:600;text-decoration:none}
.merchboard-gift__button:hover,.merchboard-gift__button:focus{background:#772ce8;color:#fff}
.merchboard-gift__secondary{display:inline-block;margin-top:1rem;color:#2271b1}
@media (prefers-color-scheme:dark){body{background:#101517;color:#e2e4e7}main{background:#1d2327;box-shadow:none}.merchboard-gift__site,.merchboard-gift__meta{color:#a7aaad}.merchboard-gift__secondary{color:#72aee6}}
</style>
</head>
<body>
<main>
	<?php if ( '' !== $merchboard_site ) : ?>
		<p class="merchboard-gift__site"><?php echo esc_html( $merchboard_site ); ?></p>
	<?php endif; ?>
	<h1><?php echo esc_html( $merchboard_title ); ?></h1>
	<?php if ( 'invalid' === $merchboard_state ) : ?>
		<p><?php esc_html_e( 'This gift link is no longer valid. The gift was not claimed in time, or it has been given back.', 'merchboard' ); ?></p>
		<a class="merchboard-gift__secondary" href="<?php echo esc_url( $merchboard_text( 'shop' ) ); ?>"><?php esc_html_e( 'Go to the shop', 'merchboard' ); ?></a>
	<?php elseif ( 'error' === $merchboard_state ) : ?>
		<p><?php esc_html_e( 'The gift could not be shown right now. Please try again in a moment.', 'merchboard' ); ?></p>
	<?php else : ?>
		<?php if ( '' !== $merchboard_image ) : ?>
			<img class="merchboard-gift__image" src="<?php echo esc_url( $merchboard_image ); ?>" alt="<?php echo esc_attr( $merchboard_product ); ?>">
		<?php endif; ?>
		<p class="merchboard-gift__product"><?php echo esc_html( $merchboard_product ); ?></p>
		<p class="merchboard-gift__meta">
			<?php
			/* translators: %s: the winner's Twitch login. */
			echo esc_html( sprintf( __( 'Won by @%s in the Twitch chat.', 'merchboard' ), $merchboard_winner ) );
			?>
		</p>
		<?php if ( ! $merchboard_claimed && '' !== $merchboard_deadline ) : ?>
			<p class="merchboard-gift__meta">
				<?php
				/* translators: %s: date and time. */
				echo esc_html( sprintf( __( 'Claim the gift before %s.', 'merchboard' ), $merchboard_deadline ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( 'other' === $merchboard_state ) : ?>
			<p>
				<?php
				/* translators: 1: the visitor's Twitch login, 2: the winner's Twitch login. */
				echo esc_html( sprintf( __( 'You are logged in with Twitch as %1$s, but the gift was won by %2$s. Log out of Twitch and log in as the winner.', 'merchboard' ), $merchboard_text( 'account' ), $merchboard_winner ) );
				?>
			</p>
			<a class="merchboard-gift__button" href="<?php echo esc_url( $merchboard_text( 'logout' ) ); ?>"><?php esc_html_e( 'Log out of Twitch', 'merchboard' ); ?></a>
		<?php elseif ( 'redeem' === $merchboard_state ) : ?>
			<p><?php echo esc_html( $merchboard_claimed ? __( 'You have claimed the gift. Open it again to see your gift code.', 'merchboard' ) : __( 'You are logged in as the winner. Get the gift and choose size and colour.', 'merchboard' ) ); ?></p>
			<a class="merchboard-gift__button" href="<?php echo esc_url( $merchboard_text( 'redeem' ) ); ?>"><?php echo esc_html( $merchboard_claimed ? __( 'Show my gift code', 'merchboard' ) : __( 'Get the gift', 'merchboard' ) ); ?></a>
		<?php else : ?>
			<p><?php esc_html_e( 'Log in with the Twitch account that won. Then you choose size and colour, and the gift code is used in the cart.', 'merchboard' ); ?></p>
			<a class="merchboard-gift__button" href="<?php echo esc_url( $merchboard_text( 'redeem' ) ); ?>"><?php esc_html_e( 'Log in with Twitch', 'merchboard' ); ?></a>
		<?php endif; ?>
	<?php endif; ?>
</main>
</body>
</html>
