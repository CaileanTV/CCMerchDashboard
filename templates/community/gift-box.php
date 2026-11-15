<?php
/**
 * The gift box on a product page (ADR-111): the creator is live on Twitch,
 * a number of gifts and the button. A GET form to the product page with the
 * gift's query arguments; GiftPurchase::add() checks the nonce.
 *
 * Rendered by GiftPurchase::button(); every value is escaped here. The CSS
 * is scoped to `.merchboard-gift-box` and needs no JavaScript; the LIVE
 * badge pulses unless the visitor prefers reduced motion.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_box Box data from GiftPurchase::button().
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$merchboard_text  = static fn( string $key ): string => is_scalar( $merchboard_box[ $key ] ?? null ) ? (string) $merchboard_box[ $key ] : '';
$merchboard_max   = max( 1, (int) $merchboard_text( 'max' ) );
$merchboard_name  = $merchboard_text( 'name' );
$merchboard_login = $merchboard_text( 'login' );
$merchboard_url   = $merchboard_text( 'action' );
$merchboard_query = array();

// A plain permalink keeps its own query (e.g. ?product=hoodie): a GET form would drop it.
wp_parse_str( (string) wp_parse_url( $merchboard_url, PHP_URL_QUERY ), $merchboard_query );
?>
<style>
.merchboard-gift-box{--mb-twitch:#9146ff;--mb-twitch-dark:#772ce8;--mb-live:#eb0400;box-sizing:border-box;margin:1.25rem 0;padding:1.1rem 1.25rem 1.25rem;border:1px solid color-mix(in srgb,currentColor 18%,transparent);border-radius:12px;background:color-mix(in srgb,var(--mb-twitch) 6%,transparent);color:inherit;font-size:.95rem;line-height:1.5}
.merchboard-gift-box *{box-sizing:border-box}
.merchboard-gift-box__head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}
.merchboard-gift-box__title{margin:0;font-size:1.2rem;font-weight:700;line-height:1.3}
.merchboard-gift-box__badge{position:relative;flex:none;width:2.4rem;height:2.4rem;margin:0 .35rem .5rem;color:var(--mb-twitch)}
.merchboard-gift-box__badge svg{display:block;width:100%;height:100%}
.merchboard-gift-box__live{position:absolute;bottom:-.6rem;left:50%;white-space:nowrap;transform:translateX(-50%);padding:.05rem .4rem;border-radius:4px;background:var(--mb-live);color:#fff;font-size:.65rem;font-weight:800;letter-spacing:.06em;line-height:1.3;animation:merchboard-live 1.6s ease-in-out infinite}
@keyframes merchboard-live{0%,100%{transform:translateX(-50%) scale(1);box-shadow:0 0 0 0 color-mix(in srgb,var(--mb-live) 55%,transparent)}50%{transform:translateX(-50%) scale(1.12);box-shadow:0 0 0 .35rem transparent}}
.merchboard-gift-box__text{margin:.5rem 0 1rem}
.merchboard-gift-box__text a{color:inherit;text-decoration:underline;text-underline-offset:2px}
.merchboard-gift-box__form{display:flex;flex-wrap:wrap;gap:.6rem;margin:0}
.merchboard-gift-box__quantity{flex:0 0 auto;min-width:4.5rem;height:3rem;padding:0 .75rem;border:1px solid color-mix(in srgb,currentColor 30%,transparent);border-radius:8px;background:transparent;color:inherit;font:inherit}
.merchboard-gift-box__button{flex:1 1 12rem;height:3rem;padding:0 1.25rem;border:0;border-radius:999px;background:var(--mb-twitch);color:#fff;font:inherit;font-weight:700;cursor:pointer}
.merchboard-gift-box__button:hover{background:var(--mb-twitch-dark)}
.merchboard-gift-box__button:focus-visible,.merchboard-gift-box__quantity:focus-visible,.merchboard-gift-box__text a:focus-visible{outline:2px solid var(--mb-twitch);outline-offset:2px}
.merchboard-gift-box__note{margin:.6rem 0 0;opacity:.75;font-size:.85rem}
.merchboard-gift-box .screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
@media (prefers-reduced-motion:reduce){.merchboard-gift-box__live{animation:none}}
</style>
<section class="merchboard-gift-box" aria-labelledby="merchboard-gift-box-title">
	<div class="merchboard-gift-box__head">
		<h3 class="merchboard-gift-box__title" id="merchboard-gift-box-title"><?php esc_html_e( 'Give as a gift in the stream', 'merchboard' ); ?></h3>
		<span class="merchboard-gift-box__badge">
			<svg viewBox="0 0 24 28" aria-hidden="true" focusable="false"><path fill="currentColor" d="M2 0 0 5v19h6v4h3.5l3.5-4h5l6-6V0zm20 17-3.5 3.5H13L9.5 24v-3.5H5V2h17z"/><path fill="currentColor" d="M17 6h2v7h-2zm-6 0h2v7h-2z"/></svg>
			<span class="merchboard-gift-box__live" aria-hidden="true">LIVE</span>
			<span class="screen-reader-text"><?php esc_html_e( 'Live on Twitch', 'merchboard' ); ?></span>
		</span>
	</div>
	<p class="merchboard-gift-box__text">
		<?php if ( '' !== $merchboard_login ) : ?>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: the creator's name, linked to the Twitch channel. */
					__( '%s is live on Twitch right now. Give this product to lucky viewers who are watching!', 'merchboard' ),
					'<a href="' . esc_url( 'https://www.twitch.tv/' . rawurlencode( $merchboard_login ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $merchboard_name ? $merchboard_name : $merchboard_login ) . '</a>'
				),
				array(
					'a' => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
				)
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'The creator is live on Twitch right now. Give this product to lucky viewers who are watching!', 'merchboard' ); ?>
		<?php endif; ?>
	</p>
	<form class="merchboard-gift-box__form" method="get" action="<?php echo esc_url( $merchboard_url ); ?>">
		<?php foreach ( $merchboard_query as $merchboard_key => $merchboard_value ) : ?>
			<?php if ( is_scalar( $merchboard_value ) ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $merchboard_key ); ?>" value="<?php echo esc_attr( (string) $merchboard_value ); ?>">
			<?php endif; ?>
		<?php endforeach; ?>
		<input type="hidden" name="<?php echo esc_attr( $merchboard_text( 'query' ) ); ?>" value="<?php echo esc_attr( $merchboard_text( 'product' ) ); ?>">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $merchboard_text( 'nonce' ) ); ?>">
		<label class="screen-reader-text" for="merchboard-gift-box-quantity"><?php esc_html_e( 'Number of gifts', 'merchboard' ); ?></label>
		<select class="merchboard-gift-box__quantity" id="merchboard-gift-box-quantity" name="<?php echo esc_attr( $merchboard_text( 'quantity' ) ); ?>">
			<?php for ( $merchboard_i = 1; $merchboard_i <= $merchboard_max; $merchboard_i++ ) : ?>
				<option value="<?php echo esc_attr( (string) $merchboard_i ); ?>"><?php echo esc_html( (string) $merchboard_i ); ?></option>
			<?php endfor; ?>
		</select>
		<button class="merchboard-gift-box__button" type="submit"><?php esc_html_e( 'Give as a gift in the stream', 'merchboard' ); ?></button>
	</form>
	<p class="merchboard-gift-box__note">
		<?php
		/* translators: %s: the gift shipping per gift, e.g. 50. */
		echo esc_html( sprintf( __( 'Each gift gets its own winner, who chooses size and colour. Shipping: %s kr. per gift.', 'merchboard' ), $merchboard_text( 'shipping' ) ) );
		?>
	</p>
</section>
