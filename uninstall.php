<?php
/**
 * Uninstall handler, run by WordPress when the plugin is deleted.
 *
 * Data policy (docs/DATABASE.md, ADR-025):
 * - Deactivation never touches data.
 * - Deleting the plugin KEEPS all data by default. Creator records and the
 *   audit trail are business data.
 * - Only if an administrator explicitly enabled "Delete all Merch Dashboard
 *   data when the plugin is deleted" (Merch Dashboard → Settings) does
 *   MerchBoard\Core\Lifecycle\Uninstaller remove the plugin's own tables,
 *   options, role and capabilities for the current site, except the history
 *   it keeps (ADR-059). WordPress users, other roles/capabilities, content and
 *   WooCommerce data are never touched.
 * - With the opt-in and a pending payout (or a failed count), nothing is
 *   deleted and this request is ended (UninstallHalt), so WordPress keeps the
 *   plugin's files (ADR-059).
 *
 * WordPress defines WP_UNINSTALL_PLUGIN only inside uninstall_plugin(), after
 * checking that the user may delete plugins; a direct request exits here.
 *
 * This file runs without the plugin being loaded and must stay parseable on
 * PHP 7.2 (checked in CI).
 *
 * @package MerchBoard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Only when WordPress is uninstalling this very plugin.
if ( plugin_basename( __DIR__ . '/merchboard.php' ) !== WP_UNINSTALL_PLUGIN ) {
	return;
}

require_once __DIR__ . '/src/Core/Requirements.php';

// On an unsupported PHP/WordPress version the plugin code cannot run; keep the data.
if ( ! \MerchBoard\Core\Requirements::check() ) {
	return;
}

require_once __DIR__ . '/src/Core/Autoloader.php';
\MerchBoard\Core\Autoloader::register( 'MerchBoard\\', __DIR__ . '/src/' );

$merchboard_logger = \MerchBoard\Core\Logging\PluginLogger::for_wordpress();

$merchboard_uninstaller = new \MerchBoard\Core\Lifecycle\Uninstaller(
	$GLOBALS['wpdb'],
	new \MerchBoard\Core\Database\SchemaEraser( $GLOBALS['wpdb'], $merchboard_logger ),
	$merchboard_logger
);

try {
	$merchboard_uninstaller->run();
} catch ( \MerchBoard\Core\Lifecycle\UninstallBlocked $merchboard_blocked ) {
	// Keep the plugin's files: end the deletion request before WordPress removes them.
	\MerchBoard\Core\Lifecycle\UninstallHalt::respond( $merchboard_blocked, WP_UNINSTALL_PLUGIN );
}
