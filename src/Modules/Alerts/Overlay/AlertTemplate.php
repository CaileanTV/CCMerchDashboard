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
use MerchBoard\Modules\Alerts\AlertMoney;

defined( 'ABSPATH' ) || exit;

/**
 * MerchAlert's template variables (ADR-065 pkt. 3): `$UserName`,
 * `$FirstName`, `$Product`, `$Quantity`, `$Category` and `$Variation`, and
 * the amounts (the project owner's choice 2026-10-23): `$Price` (per item),
 * `$LineTotal` (the line) and `$Total` (all the creator's lines of the
 * order), what the customer paid with VAT after discounts (AlertMoney); an
 * alert without stored amounts shows nothing for them. `$OrderNumber` is
 * removed and shows nothing. The result is plain text; the overlay inserts
 * it as text, never as HTML.
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
				// Removed (the project owner's choice 2026-10-23): shows nothing.
				'$OrderNumber' => '',
				'$Product'     => $event->product_name,
				'$Quantity'    => (string) $event->quantity,
				'$Category'    => $this->categories( $event->category_ids ),
				'$Variation'   => $event->variation,
				'$Price'       => AlertMoney::format( AlertMoney::per_item( $event->line_total, $event->quantity ), $event->currency ),
				'$LineTotal'   => AlertMoney::format( $event->line_total, $event->currency ),
				'$Total'       => AlertMoney::format( $event->creator_total, $event->currency ),
			)
		);
	}

	/**
	 * The names of the categories that still exist, comma-separated (also
	 * the alert API's `category`, ADR-073).
	 *
	 * @phpstan-param list<int> $term_ids
	 *
	 * @param int[] $term_ids Category ids.
	 */
	public function categories( array $term_ids ): string {
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
