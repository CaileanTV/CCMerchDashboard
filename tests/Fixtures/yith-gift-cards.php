<?php
/**
 * Stand-in for an active YITH WooCommerce Gift Cards 4.38.x (free): its
 * version constants, its main function and the card class with the meta key
 * constants the adapter's self-test compares. YWGC_META_GIFT_CARD_ORDERS is
 * left to each test. Loaded only in isolated test processes (global
 * constants and classes cannot be undone).
 *
 * @package MerchBoard
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting.FunctionComment.Missing -- Mirrors the plugin's global names.

define( 'YITH_YWGC_VERSION', '4.38.0' );
define( 'YITH_YWGC_FREE', true );

/**
 * The plugin's card class (only its meta key constants).
 */
class YITH_YWGC_Gift_Card {

	const META_AMOUNT_TOTAL = '_ywgc_amount_total';

	const META_BALANCE_TOTAL = '_ywgc_balance_total';

	const META_CURRENCY = '_ywgc_currency';

	const META_EXPIRATION = '_ywgc_expiration';
}

function YITH_YWGC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- The plugin's name.
	return null;
}
