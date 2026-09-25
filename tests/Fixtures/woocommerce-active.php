<?php
/**
 * Stand-in for an active WooCommerce: its main class and WC_VERSION. Loaded
 * only in isolated test processes (a class and a constant cannot be undone).
 *
 * Set $merchboard_test_wc_version before requiring this file.
 *
 * @package MerchBoard
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile -- Mirrors WooCommerce: the WooCommerce class and the WC_VERSION constant.

/**
 * Mirrors WooCommerce's main class; only its existence matters.
 */
final class WooCommerce {
}

define( 'WC_VERSION', $merchboard_test_wc_version ?? '10.3.8' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WooCommerce's own constant.
