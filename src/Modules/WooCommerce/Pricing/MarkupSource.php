<?php
/**
 * Where a product's creator share comes from.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Exactly one share applies to a product (ADR-049): the product's own
 * percentage, which replaces the creator's default markup, or that default.
 * They are never combined. A designer product (ADR-080) has its own source:
 * its share is profit ÷ list price, recorded only for campaigns and display;
 * outside campaigns its profit follows the base price model.
 */
enum MarkupSource: string {

	case Creator = 'creator';

	case Product = 'product';

	case Designer = 'designer';
}
