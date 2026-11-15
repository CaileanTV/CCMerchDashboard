<?php
/**
 * The merch ad overlay's page for OBS (ADR-113).
 *
 * A complete HTML page without the theme: no get_header() and no wp_head().
 * Only the overlay's own bundle is printed. The ad is drawn by the script
 * from the configuration, which it validates; the configuration is JSON with
 * every HTML-significant character escaped.
 *
 * @package MerchBoard
 *
 * @var array<string, mixed> $merchboard_view View data from MerchAdPage.
 */

declare( strict_types=1 );

use MerchBoard\Modules\Community\MerchAd\MerchAdPage;

defined( 'ABSPATH' ) || exit;

$merchboard_config = wp_json_encode( $merchboard_view['config'] ?? array(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Merch ad</title>
<?php wp_print_styles( array( MerchAdPage::HANDLE ) ); ?>
</head>
<body class="mb-ad-page">
<div id="mb-ad" class="mb-ad" aria-hidden="true"></div>
<script type="application/json" id="mb-ad-config"><?php echo false === $merchboard_config ? '{}' : $merchboard_config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, >, &, ' and " escaped. ?></script>
<?php wp_print_scripts( array( MerchAdPage::HANDLE ) ); ?>
</body>
</html>
