<?php
/**
 * Plugin Name:       Merch Dashboard
 * Description:       Creator and merchandise management: WordPress admin tools and a secure creator dashboard.
 * Version:           1.0.1
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * WC requires at least: 10.3
 * Author:            CC Merch
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       merchboard
 * Domain Path:       /languages
 *
 * @package MerchBoard
 */

// This file must stay parseable on old PHP versions (PHP 7.2 syntax) so the
// requirement check can show a notice instead of a fatal error.

defined( 'ABSPATH' ) || exit;

// Another copy of the plugin is already loaded.
if ( defined( 'MERCHBOARD_VERSION' ) ) {
	return;
}

define( 'MERCHBOARD_VERSION', '1.0.1' );

require_once __DIR__ . '/src/Core/Requirements.php';

if ( ! \MerchBoard\Core\Requirements::check() ) {
	return;
}

require_once __DIR__ . '/src/Core/Autoloader.php';
\MerchBoard\Core\Autoloader::register( 'MerchBoard\\', __DIR__ . '/src/' );

( new \MerchBoard\Plugin( __FILE__ ) )->register_hooks();
