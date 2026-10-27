<?php
/**
 * The notification e-mail (ADR-079): the shop's e-mail design.
 *
 * Shop name or logo above a white card with a colored top border; in the
 * card the heading, greeting, text, an info box, a table, a note, a button
 * and a closing line; below it the footer. Table layout and inline styles
 * for e-mail programs. Rendered by EmailRenderer; every value is escaped
 * here.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from EmailRenderer.
 */

declare( strict_types=1 );

use MerchBoard\Modules\Notifications\Email\EmailStyle;
use MerchBoard\Modules\Notifications\EmailMessage;

defined( 'ABSPATH' ) || exit;

$merchboard_message = $merchboard_view['message'] ?? null;
$merchboard_style   = $merchboard_view['style'] ?? null;

if ( ! $merchboard_message instanceof EmailMessage || ! $merchboard_style instanceof EmailStyle ) {
	return;
}

$merchboard_url      = is_string( $merchboard_view['url'] ?? null ) ? $merchboard_view['url'] : '';
$merchboard_greeting = is_string( $merchboard_view['greeting'] ?? null ) ? $merchboard_view['greeting'] : '';
$merchboard_muted    = is_string( $merchboard_view['muted'] ?? null ) ? $merchboard_view['muted'] : '#6b6b6f';
$merchboard_line     = is_string( $merchboard_view['line'] ?? null ) ? $merchboard_view['line'] : '#e3e3e0';
$merchboard_tint     = is_string( $merchboard_view['tint'] ?? null ) ? $merchboard_view['tint'] : '#f0f5f4';
$merchboard_lang     = is_string( $merchboard_view['lang'] ?? null ) ? $merchboard_view['lang'] : 'en';
$merchboard_font     = 'font-family:' . $merchboard_style->font . ';';
$merchboard_text     = 'color:' . $merchboard_style->text . ';';
$merchboard_p        = 'margin:0 0 16px;font-size:15px;line-height:1.6;' . $merchboard_text;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $merchboard_lang ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?php echo esc_html( $merchboard_message->subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $merchboard_style->background ); ?>;<?php echo esc_attr( $merchboard_font ); ?>">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo esc_attr( $merchboard_style->background ); ?>;">
<tr><td align="center" style="padding:32px 16px;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
		<tr><td style="padding:0 8px 18px;<?php echo esc_attr( $merchboard_font ); ?>">
			<?php if ( '' !== $merchboard_style->logo ) : ?>
				<img src="<?php echo esc_url( $merchboard_style->logo ); ?>" alt="<?php echo esc_attr( $merchboard_style->store ); ?>" style="display:block;max-width:220px;max-height:64px;border:0;">
			<?php else : ?>
				<span style="font-size:20px;font-weight:700;letter-spacing:-0.2px;<?php echo esc_attr( $merchboard_text ); ?>"><?php echo esc_html( $merchboard_style->store ); ?></span>
			<?php endif; ?>
		</td></tr>
		<tr><td style="background:<?php echo esc_attr( $merchboard_style->body ); ?>;border:1px solid <?php echo esc_attr( $merchboard_line ); ?>;border-top:4px solid <?php echo esc_attr( $merchboard_style->base ); ?>;border-radius:10px;padding:40px 44px;<?php echo esc_attr( $merchboard_font ); ?>">
			<h1 style="margin:0 0 24px;font-size:26px;line-height:1.25;font-weight:700;letter-spacing:-0.3px;<?php echo esc_attr( $merchboard_text ); ?>"><?php echo esc_html( $merchboard_message->heading ); ?></h1>

			<?php if ( '' !== $merchboard_greeting ) : ?>
				<p style="<?php echo esc_attr( $merchboard_p ); ?>font-weight:700;"><?php echo esc_html( $merchboard_greeting ); ?></p>
			<?php endif; ?>

			<?php foreach ( $merchboard_message->paragraphs as $merchboard_paragraph ) : ?>
				<p style="<?php echo esc_attr( $merchboard_p ); ?>"><?php echo esc_html( $merchboard_paragraph ); ?></p>
			<?php endforeach; ?>

			<?php if ( array() !== $merchboard_message->details ) : ?>
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;background:<?php echo esc_attr( $merchboard_tint ); ?>;border:1px solid <?php echo esc_attr( $merchboard_line ); ?>;border-radius:10px;">
					<tr><td style="padding:16px 20px;">
						<?php foreach ( $merchboard_message->details as $merchboard_detail ) : ?>
							<p style="margin:0 0 6px;font-size:15px;line-height:1.6;<?php echo esc_attr( $merchboard_text ); ?>"><strong><?php echo esc_html( $merchboard_detail[0] ); ?>:</strong> <?php echo esc_html( $merchboard_detail[1] ); ?></p>
						<?php endforeach; ?>
					</td></tr>
				</table>
			<?php endif; ?>

			<?php if ( null !== $merchboard_message->table && array() !== $merchboard_message->table['rows'] ) : ?>
				<?php $merchboard_last = count( $merchboard_message->table['head'] ) - 1; ?>
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;border-collapse:collapse;">
					<tr>
						<?php foreach ( $merchboard_message->table['head'] as $merchboard_index => $merchboard_head ) : ?>
							<th style="padding:0 0 10px;font-size:12px;font-weight:600;letter-spacing:0.6px;text-transform:uppercase;text-align:<?php echo $merchboard_index === $merchboard_last ? 'right' : ( 0 === $merchboard_index ? 'left' : 'center' ); ?>;color:<?php echo esc_attr( $merchboard_muted ); ?>;border-bottom:1px solid <?php echo esc_attr( $merchboard_line ); ?>;"><?php echo esc_html( $merchboard_head ); ?></th>
						<?php endforeach; ?>
					</tr>
					<?php foreach ( $merchboard_message->table['rows'] as $merchboard_row ) : ?>
						<tr>
							<?php foreach ( $merchboard_row as $merchboard_index => $merchboard_cell ) : ?>
								<td style="padding:14px 0;font-size:15px;line-height:1.4;text-align:<?php echo $merchboard_index === $merchboard_last ? 'right' : ( 0 === $merchboard_index ? 'left' : 'center' ); ?>;<?php echo 0 === $merchboard_index || $merchboard_index === $merchboard_last ? 'font-weight:600;' : ''; ?><?php echo esc_attr( $merchboard_text ); ?>border-bottom:1px solid <?php echo esc_attr( $merchboard_line ); ?>;"><?php echo esc_html( $merchboard_cell ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<?php if ( null !== $merchboard_message->note ) : ?>
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;background:<?php echo esc_attr( $merchboard_tint ); ?>;border-radius:10px;">
					<tr><td style="padding:16px 20px;">
						<p style="margin:0 0 4px;font-size:15px;font-weight:700;<?php echo esc_attr( $merchboard_text ); ?>"><?php echo esc_html( $merchboard_message->note[0] ); ?></p>
						<p style="margin:0;font-size:15px;line-height:1.6;<?php echo esc_attr( $merchboard_text ); ?>"><?php echo nl2br( esc_html( $merchboard_message->note[1] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped, then line breaks. ?></p>
					</td></tr>
				</table>
			<?php endif; ?>

			<?php if ( '' !== $merchboard_message->button && '' !== $merchboard_url ) : ?>
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 28px;">
					<tr><td style="background:<?php echo esc_attr( $merchboard_style->base ); ?>;border-radius:8px;">
						<a href="<?php echo esc_url( $merchboard_url ); ?>" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;<?php echo esc_attr( $merchboard_font ); ?>"><?php echo esc_html( $merchboard_message->button ); ?></a>
					</td></tr>
				</table>
			<?php endif; ?>

			<?php if ( '' !== $merchboard_message->closing ) : ?>
				<p style="margin:24px 0 0;padding-top:20px;border-top:1px solid <?php echo esc_attr( $merchboard_line ); ?>;font-size:14px;line-height:1.6;color:<?php echo esc_attr( $merchboard_muted ); ?>;"><?php echo esc_html( $merchboard_message->closing ); ?></p>
			<?php endif; ?>
		</td></tr>
		<tr><td align="center" style="padding:22px 8px 0;font-size:13px;line-height:1.6;color:<?php echo esc_attr( $merchboard_muted ); ?>;<?php echo esc_attr( $merchboard_font ); ?>">
			<?php echo esc_html( $merchboard_style->footer ); ?><br>
			<?php esc_html_e( 'Questions? Just reply to this e-mail.', 'merchboard' ); ?>
		</td></tr>
	</table>
</td></tr>
</table>
</body>
</html>
