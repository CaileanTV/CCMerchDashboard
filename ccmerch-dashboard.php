<?php
/**
 * Plugin Name:       CC Merch Dashboard
 * Description:       Creator and merchandise management: WordPress admin tools and a secure creator dashboard.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            CC Merch
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ccmerch-dashboard
 * Domain Path:       /languages
 *
 * @package CCMerch
 */

// This file must stay parseable on old PHP versions (PHP 7.2 syntax) so the
// requirement check can show a notice instead of a fatal error.

defined( 'ABSPATH' ) || exit;

// Another copy of the plugin is already loaded.
if ( defined( 'CCMERCH_VERSION' ) ) {
	return;
}

define( 'CCMERCH_VERSION', '0.1.0' );

require_once __DIR__ . '/src/Core/Requirements.php';

if ( ! \CCMerch\Core\Requirements::check() ) {
	return;
}

require_once __DIR__ . '/src/Core/Autoloader.php';
\CCMerch\Core\Autoloader::register( 'CCMerch\\', __DIR__ . '/src/' );

( new \CCMerch\Plugin( __FILE__ ) )->register_hooks();
