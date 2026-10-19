<?php
/**
 * Renders an alert's text from a template.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use Closure;
use MerchBoard\Modules\Alerts\AlertEvent;

defined( 'ABSPATH' ) || exit;

/**
 * MerchAlert's template variables (ADR-065 pkt. 3): `$UserName`,
 * `$FirstName`, `$OrderNumber`, `$Product`, `$Quantity`, `$Category` and
 * `$Variation`. The result is plain text; the overlay inserts it as text,
 * never as HTML.
 *
 * - **No customer name (owner's decision 2026-10-19):** `$FirstName` and
 *   `$UserName` both give the customer's voluntary username, or "Anonymous"
 *   (the word MerchAlert uses), so templates written for MerchAlert keep
 *   working without showing a name.
 * - **One pass:** all variables are replaced at once (`strtr()`), so a
 *   product name that contains `$Category` is never expanded again (it was
 *   in MerchAlert). Unknown variables stay as they are.
 * - **Categories** are named at render time from the stored term ids, so a
 *   renamed category shows its new name.
 */
final class AlertTemplate {

	/**
	 * Shown instead of a missing username.
	 */
	public const ANONYMOUS = 'Anonymous';

	/**
	 * The name of a product category, or null when it no longer exists.
	 *
	 * @var Closure(int): ?string
	 */
	private Closure $category_name;

	/**
	 * Constructor.
	 *
	 * @param (Closure(int): ?string)|null $category_name Category name lookup; defaults to `product_cat` terms.
	 */
	public function __construct( ?Closure $category_name = null ) {
		$this->category_name = $category_name ?? static function ( int $term_id ): ?string {
			$term = get_term( $term_id, 'product_cat' );

			return $term instanceof \WP_Term ? $term->name : null;
		};
	}

	/**
	 * The text of an alert.
	 *
	 * @param string     $template Template.
	 * @param AlertEvent $event    Alert.
	 */
	public function render( string $template, AlertEvent $event ): string {
		if ( '' === $template ) {
			return '';
		}

		$username = '' !== $event->customer_username ? $event->customer_username : self::ANONYMOUS;

		return strtr(
			$template,
			array(
				'$UserName'    => $username,
				'$FirstName'   => $username,
				'$OrderNumber' => $event->order_number,
				'$Product'     => $event->product_name,
				'$Quantity'    => (string) $event->quantity,
				'$Category'    => $this->categories( $event->category_ids ),
				'$Variation'   => $event->variation,
			)
		);
	}

	/**
	 * The names of the categories that still exist, comma-separated.
	 *
	 * @phpstan-param list<int> $term_ids
	 *
	 * @param int[] $term_ids Category ids.
	 */
	private function categories( array $term_ids ): string {
		$names = array();

		foreach ( $term_ids as $term_id ) {
			$name = ( $this->category_name )( $term_id );

			if ( null !== $name && '' !== $name ) {
				$names[] = $name;
			}
		}

		return implode( ', ', $names );
	}
}
