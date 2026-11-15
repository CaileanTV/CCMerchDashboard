<?php
/**
 * The Twitch subscriber discount card in the cart and the checkout
 * (ADR-111 pkt. 4): log in with Twitch, or the logged-in account and
 * whether the discount applies. Same logic and links as before; a modern
 * card instead of a WooCommerce notice.
 *
 * Rendered by TwitchSubShopCard::html(); every value is escaped here. The
 * CSS is scoped to `.merchboard-twitch-card` and works on light and dark
 * themes (Twitch purple only on the Twitch parts).
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_card Card data from TwitchSubShopCard::html().
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$merchboard_text   = static fn( string $key ): string => is_scalar( $merchboard_card[ $key ] ?? null ) ? (string) $merchboard_card[ $key ] : '';
$merchboard_tiers  = is_array( $merchboard_card['tiers'] ?? null ) ? $merchboard_card['tiers'] : array();
$merchboard_login  = $merchboard_text( 'account' );
$merchboard_result = $merchboard_text( 'result' );
?>
<style>
.merchboard-twitch-card{--mb-twitch:#9146ff;--mb-twitch-dark:#772ce8;--mb-ok:#1f8a4c;box-sizing:border-box;display:flex;flex-wrap:wrap;align-items:center;gap:.9rem 1.25rem;margin:0 0 1.5rem;padding:1rem 1.25rem;border:1px solid color-mix(in srgb,currentColor 14%,transparent);border-left:4px solid var(--mb-twitch);border-radius:12px;background:color-mix(in srgb,var(--mb-twitch) 5%,transparent);color:inherit;font-size:.95rem;line-height:1.5}
.merchboard-twitch-card *{box-sizing:border-box}
.merchboard-twitch-card__icon{flex:none;display:flex;align-items:center;justify-content:center;width:2.75rem;height:2.75rem;border-radius:10px;background:var(--mb-twitch);color:#fff}
.merchboard-twitch-card__icon svg{width:1.4rem;height:1.4rem}
.merchboard-twitch-card__avatar{flex:none;display:flex;align-items:center;justify-content:center;width:2.75rem;height:2.75rem;border-radius:50%;background:var(--mb-twitch);color:#fff;font-weight:800;font-size:1.15rem;text-transform:uppercase}
.merchboard-twitch-card__body{flex:1 1 16rem;min-width:0}
.merchboard-twitch-card__title{margin:0;font-weight:700;font-size:1.02rem}
.merchboard-twitch-card__text{margin:.15rem 0 0;opacity:.85}
.merchboard-twitch-card__chips{display:flex;flex-wrap:wrap;gap:.4rem;margin:.55rem 0 0;padding:0;list-style:none}
.merchboard-twitch-card__chip{padding:.15rem .6rem;border-radius:999px;background:color-mix(in srgb,var(--mb-twitch) 14%,transparent);font-size:.82rem;font-weight:600;white-space:nowrap}
.merchboard-twitch-card__pill{display:inline-block;margin-top:.35rem;padding:.12rem .6rem;border-radius:999px;font-size:.82rem;font-weight:600}
.merchboard-twitch-card__pill--ok{background:color-mix(in srgb,var(--mb-ok) 15%,transparent);color:var(--mb-ok)}
.merchboard-twitch-card__pill--none{background:color-mix(in srgb,currentColor 10%,transparent)}
.merchboard-twitch-card__actions{flex:0 0 auto;display:flex;align-items:center;gap:.75rem}
.merchboard-twitch-card__button{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.2rem;border-radius:999px;background:var(--mb-twitch);color:#fff!important;font-weight:700;text-decoration:none!important;white-space:nowrap}
.merchboard-twitch-card__button:hover{background:var(--mb-twitch-dark)}
.merchboard-twitch-card__button svg{width:1.05rem;height:1.05rem}
.merchboard-twitch-card__logout{color:inherit!important;font-size:.85rem;opacity:.75;text-decoration:underline}
.merchboard-twitch-card__button:focus-visible,.merchboard-twitch-card__logout:focus-visible{outline:2px solid var(--mb-twitch);outline-offset:2px}
.merchboard-twitch-card__result{flex:1 0 100%;margin:0;padding:.45rem .75rem;border-radius:8px;background:color-mix(in srgb,currentColor 7%,transparent);font-size:.88rem}
@media (max-width:520px){.merchboard-twitch-card__actions{flex:1 0 100%}.merchboard-twitch-card__button{flex:1;justify-content:center}}
</style>
<div class="merchboard-twitch-card merchboard-twitch-sub" role="status">
	<?php if ( '' !== $merchboard_result ) : ?>
		<p class="merchboard-twitch-card__result"><?php echo esc_html( $merchboard_result ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $merchboard_login ) : ?>
		<span class="merchboard-twitch-card__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $merchboard_login, 0, 1 ) ); ?></span>
		<div class="merchboard-twitch-card__body">
			<p class="merchboard-twitch-card__title">
				<?php
				/* translators: %s: the customer's Twitch name. */
				echo esc_html( sprintf( __( 'Logged in as %s', 'merchboard' ), $merchboard_login ) );
				?>
			</p>
			<?php if ( true === ( $merchboard_card['applies'] ?? false ) ) : ?>
				<span class="merchboard-twitch-card__pill merchboard-twitch-card__pill--ok"><?php esc_html_e( '✓ Your subscriber discount is taken off', 'merchboard' ); ?></span>
			<?php else : ?>
				<span class="merchboard-twitch-card__pill merchboard-twitch-card__pill--none"><?php esc_html_e( 'No subscriber discount on these products', 'merchboard' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="merchboard-twitch-card__actions">
			<a class="merchboard-twitch-card__logout" href="<?php echo esc_url( $merchboard_text( 'logout' ) ); ?>"><?php esc_html_e( 'Log out of Twitch', 'merchboard' ); ?></a>
		</div>
	<?php else : ?>
		<span class="merchboard-twitch-card__icon" aria-hidden="true">
			<svg viewBox="0 0 24 28" focusable="false"><path fill="currentColor" d="M2 0 0 5v19h6v4h3.5l3.5-4h5l6-6V0zm20 17-3.5 3.5H13L9.5 24v-3.5H5V2h17z"/><path fill="currentColor" d="M17 6h2v7h-2zm-6 0h2v7h-2z"/></svg>
		</span>
		<div class="merchboard-twitch-card__body">
			<p class="merchboard-twitch-card__title"><?php esc_html_e( 'Twitch subscriber discount', 'merchboard' ); ?></p>
			<p class="merchboard-twitch-card__text"><?php esc_html_e( 'Do you subscribe on Twitch? Log in and the discount is taken off the eligible products.', 'merchboard' ); ?></p>
			<?php if ( array() !== $merchboard_tiers ) : ?>
				<ul class="merchboard-twitch-card__chips">
					<?php foreach ( $merchboard_tiers as $merchboard_tier => $merchboard_percent ) : ?>
						<li class="merchboard-twitch-card__chip">
							<?php
							/* translators: 1: subscriber tier (1–3), 2: discount in percent. */
							echo esc_html( sprintf( __( 'Tier %1$d · up to %2$s %%', 'merchboard' ), (int) $merchboard_tier, (string) $merchboard_percent ) );
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<div class="merchboard-twitch-card__actions">
			<a class="merchboard-twitch-card__button" href="<?php echo esc_url( $merchboard_text( 'login' ) ); ?>">
				<svg viewBox="0 0 24 28" aria-hidden="true" focusable="false"><path fill="currentColor" d="M2 0 0 5v19h6v4h3.5l3.5-4h5l6-6V0zm20 17-3.5 3.5H13L9.5 24v-3.5H5V2h17z"/><path fill="currentColor" d="M17 6h2v7h-2zm-6 0h2v7h-2z"/></svg>
				<?php
				$merchboard_remembered = $merchboard_text( 'remembered' );
				echo esc_html(
					'' === $merchboard_remembered
						? __( 'Log in with Twitch', 'merchboard' )
						/* translators: %s: the customer's remembered Twitch name. */
						: sprintf( __( 'Check my subscription as %s', 'merchboard' ), $merchboard_remembered )
				);
				?>
			</a>
		</div>
	<?php endif; ?>
</div>
