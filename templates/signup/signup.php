<?php
/**
 * The public sign-up page for creators (ADR-091).
 *
 * States: `form` (with an error code and the values to show again), `sent`
 * (check your e-mail), `closed` (404) and `error` (503). A plain form
 * without scripts. Every value is escaped here; the agreement text was
 * sanitized with wp_kses_post().
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from SignupPage.
 */

declare( strict_types=1 );

use MerchBoard\Modules\Creators\Signup\FrontGroup;
use MerchBoard\Modules\Creators\Signup\SignupException;
use MerchBoard\Modules\Dashboard\FallbackStyles;

defined( 'ABSPATH' ) || exit;

$merchboard_site   = (string) ( $merchboard_view['site_name'] ?? '' );
$merchboard_state  = (string) ( $merchboard_view['state'] ?? 'closed' );
$merchboard_values = is_array( $merchboard_view['values'] ?? null ) ? $merchboard_view['values'] : array();
$merchboard_value  = static fn( string $key ): string => is_string( $merchboard_values[ $key ] ?? null ) ? $merchboard_values[ $key ] : '';
$merchboard_group  = FrontGroup::or_default( $merchboard_values['front_group'] ?? null );
$merchboard_error  = match ( (string) ( $merchboard_view['error'] ?? '' ) ) {
	''                             => '',
	SignupException::NAME          => __( 'Enter your creator name or the name of your association or community (2 to 60 characters).', 'merchboard' ),
	SignupException::EMAIL         => __( 'Enter a valid e-mail address.', 'merchboard' ),
	SignupException::EMAIL_TAKEN   => __( 'There is already an account with this e-mail. Log in, and sign up as a creator from there.', 'merchboard' ),
	SignupException::USERNAME      => __( 'Choose a username of 3 to 60 characters: letters, digits, and _ . - @ only.', 'merchboard' ),
	SignupException::USERNAME_TAKEN => __( 'This username is taken. Please choose another one.', 'merchboard' ),
	SignupException::CHANNEL       => __( 'Enter the link to your channel, starting with https://.', 'merchboard' ),
	SignupException::ADULT         => __( 'You must be 18 or older to sign up.', 'merchboard' ),
	SignupException::AGREEMENT     => __( 'Please accept the agreement to sign up.', 'merchboard' ),
	SignupException::OUTDATED      => __( 'The agreement was updated while you filled in the form. Please read it again and send the form.', 'merchboard' ),
	SignupException::TOO_MANY      => __( 'Too many sign-ups from here. Please try again in an hour.', 'merchboard' ),
	SignupException::TOO_FAST      => __( 'Please take a moment to read the form, then send it again.', 'merchboard' ),
	SignupException::ALREADY       => __( 'Your account is a creator already.', 'merchboard' ),
	SignupException::FRONT_GROUP   => __( 'Choose what describes you best.', 'merchboard' ),
	SignupException::EXPIRED_FORM  => __( 'The form expired. Please send it again.', 'merchboard' ),
	default                        => __( 'Something went wrong. Nothing was saved; please try again later.', 'merchboard' ),
};
$merchboard_title = match ( $merchboard_state ) {
	'sent'  => __( 'Check your e-mail', 'merchboard' ),
	'form'  => __( 'Sign up as a creator, association or community', 'merchboard' ),
	default => __( 'Sign-up is not open', 'merchboard' ),
};
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php echo apply_filters( 'merchboard_page_icons', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- BrandIcons::links() escapes every tag. ?>
<title><?php echo esc_html( $merchboard_title . ' · ' . $merchboard_site ); ?></title>
<style><?php echo FallbackStyles::css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS without input. ?>
.merchboard-fallback { max-width: 40rem; }
.merchboard-signup label { display: block; margin: 1rem 0 0.25rem; font-weight: 600; }
.merchboard-signup input[type="text"], .merchboard-signup input[type="email"], .merchboard-signup input[type="url"] { box-sizing: border-box; width: 100%; padding: 0.5rem; font: inherit; border: 1px solid var(--merchboard-border); border-radius: 4px; }
.merchboard-signup .merchboard-signup__check { display: flex; gap: 0.5rem; align-items: flex-start; font-weight: 400; }
.merchboard-signup__group { margin: 1rem 0 0; padding: 0; border: 0; }
.merchboard-signup__group legend { padding: 0; font-weight: 600; }
.merchboard-signup .merchboard-signup__group .merchboard-signup__check { margin: 0.4rem 0 0; }
.merchboard-signup__agreement { max-height: 20rem; overflow: auto; margin-top: 1rem; padding: 0 1rem; border: 1px solid var(--merchboard-border); border-radius: 4px; }
.merchboard-signup__error { padding: 0.75rem 1rem; border-left: 4px solid #b91c1c; background: #fef2f2; }
.merchboard-signup__trap { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
.merchboard-signup button { margin-top: 1.5rem; padding: 0.6rem 1.2rem; font: inherit; font-weight: 600; color: #fff; background: var(--merchboard-link); border: 0; border-radius: 4px; cursor: pointer; }
</style>
</head>
<body>
<main class="merchboard-fallback">
	<div class="merchboard-fallback__card merchboard-signup">
		<h1><?php echo esc_html( $merchboard_title ); ?></h1>
<?php if ( 'sent' === $merchboard_state ) : ?>
		<p><?php esc_html_e( 'Thank you! We have sent you an e-mail. Use the link in it to choose a password; then you can log in and prepare your shop. The link works for 7 days.', 'merchboard' ); ?></p>
		<p><?php esc_html_e( 'Nothing in your inbox? Check your spam folder.', 'merchboard' ); ?></p>
<?php elseif ( 'form' === $merchboard_state ) : ?>
		<p><?php esc_html_e( 'Get your own merch shop. Sign up, accept our agreement and prepare your shop; it goes live when we have approved your request.', 'merchboard' ); ?></p>
	<?php if ( '' !== $merchboard_error ) : ?>
		<p class="merchboard-signup__error" role="alert"><?php echo esc_html( $merchboard_error ); ?></p>
	<?php endif; ?>
		<form method="post" action="<?php echo esc_url( (string) ( $merchboard_view['action'] ?? '' ) ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $merchboard_view['nonce'] ?? '' ) ); ?>">
			<input type="hidden" name="started" value="<?php echo esc_attr( (string) ( $merchboard_view['started'] ?? '' ) ); ?>">
			<input type="hidden" name="version" value="<?php echo esc_attr( (string) ( $merchboard_view['version'] ?? '' ) ); ?>">
			<div class="merchboard-signup__trap" aria-hidden="true">
				<label for="merchboard-signup-website"><?php esc_html_e( 'Leave this field empty', 'merchboard' ); ?></label>
				<input type="text" id="merchboard-signup-website" name="website" value="" tabindex="-1" autocomplete="off">
			</div>
			<label for="merchboard-signup-name"><?php esc_html_e( 'Creator name / name of association or community', 'merchboard' ); ?></label>
			<input type="text" id="merchboard-signup-name" name="name" required minlength="2" maxlength="60" autocomplete="nickname" value="<?php echo esc_attr( $merchboard_value( 'name' ) ); ?>">
	<?php if ( true !== ( $merchboard_view['logged_in'] ?? false ) ) : ?>
			<label for="merchboard-signup-username"><?php esc_html_e( 'Username (for logging in)', 'merchboard' ); ?></label>
			<input type="text" id="merchboard-signup-username" name="username" required minlength="3" maxlength="60" autocomplete="username" value="<?php echo esc_attr( $merchboard_value( 'username' ) ); ?>">
			<label for="merchboard-signup-email"><?php esc_html_e( 'E-mail', 'merchboard' ); ?></label>
			<input type="email" id="merchboard-signup-email" name="email" required maxlength="100" autocomplete="email" value="<?php echo esc_attr( $merchboard_value( 'email' ) ); ?>">
	<?php endif; ?>
			<label for="merchboard-signup-channel"><?php esc_html_e( 'Link to your channel (e.g. Twitch or YouTube)', 'merchboard' ); ?></label>
			<input type="url" id="merchboard-signup-channel" name="channel" required maxlength="255" placeholder="https://" value="<?php echo esc_attr( $merchboard_value( 'channel' ) ); ?>">
			<fieldset class="merchboard-signup__group">
				<legend><?php esc_html_e( 'What describes you best?', 'merchboard' ); ?></legend>
				<?php foreach ( FrontGroup::VALUES as $merchboard_option ) : ?>
				<label class="merchboard-signup__check"><input type="radio" name="front_group" value="<?php echo esc_attr( $merchboard_option ); ?>"<?php checked( $merchboard_group, $merchboard_option ); ?>> <?php echo esc_html( FrontGroup::label( $merchboard_option ) ); ?></label>
				<?php endforeach; ?>
			</fieldset>
			<h2><?php esc_html_e( 'Our agreement', 'merchboard' ); ?></h2>
			<div class="merchboard-signup__agreement"><?php echo wp_kses_post( (string) ( $merchboard_view['agreement'] ?? '' ) ); ?></div>
			<label class="merchboard-signup__check"><input type="checkbox" name="adult" value="1" required> <?php esc_html_e( 'I am 18 or older.', 'merchboard' ); ?></label>
			<label class="merchboard-signup__check"><input type="checkbox" name="agree" value="1" required> <?php esc_html_e( 'I have read and accept the agreement.', 'merchboard' ); ?></label>
			<button type="submit"><?php esc_html_e( 'Sign up', 'merchboard' ); ?></button>
		</form>
		<p><a href="<?php echo esc_url( (string) ( $merchboard_view['login_url'] ?? wp_login_url() ) ); ?>"><?php esc_html_e( 'Already a creator? Log in', 'merchboard' ); ?></a></p>
<?php elseif ( 'error' === $merchboard_state ) : ?>
		<p><?php esc_html_e( 'The page could not be shown right now. Please try again later.', 'merchboard' ); ?></p>
<?php else : ?>
		<?php /* translators: %s: site name. */ ?>
		<p><?php echo esc_html( sprintf( __( 'It is not possible to sign up as a creator right now. Please contact %s.', 'merchboard' ), $merchboard_site ) ); ?></p>
<?php endif; ?>
	</div>
</main>
</body>
</html>
