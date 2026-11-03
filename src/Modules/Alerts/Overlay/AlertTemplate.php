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
	 * @param string     $template      Template.
	 * @param AlertEvent $event         Alert.
	 * @param string     $number_format How amounts are written (OverlaySettings::NUMBER_FORMATS).
	 * @param int        $max_name      Longest username in characters; 0 for no limit.
	 */
	public function render( string $template, AlertEvent $event, string $number_format = 'shop', int $max_name = 0 ): string {
		return implode( '', array_column( $this->segments( $template, $event, $number_format, $max_name ), 'text' ) );
	}

	/**
	 * The text of an alert in pieces: the template's own text and the
	 * inserted values (`value`), so the overlay can highlight the values
	 * (ADR-088). Values that are empty are left out. Plain text, never HTML.
	 *
	 * @param string     $template      Template.
	 * @param AlertEvent $event         Alert.
	 * @param string     $number_format How amounts are written (OverlaySettings::NUMBER_FORMATS).
	 * @param int        $max_name      Longest username in characters; 0 for no limit.
	 * @return list<array{text: string, value: bool}>
	 */
	public function segments( string $template, AlertEvent $event, string $number_format = 'shop', int $max_name = 0 ): array {
		if ( '' === $template ) {
			return array();
		}

		$values = $this->values( $event, $number_format, $max_name );
		$parts  = preg_split( '/(' . implode( '|', array_map( static fn( string $name ): string => preg_quote( $name, '/' ), array_keys( $values ) ) ) . ')/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		$segments = array();

		foreach ( false === $parts ? array( $template ) : $parts as $part ) {
			$value = array_key_exists( $part, $values );
			$text  = $value ? $values[ $part ] : $part;

			if ( '' !== $text ) {
				$segments[] = array(
					'text'  => $text,
					'value' => $value,
				);
			}
		}

		return $segments;
	}

	/**
	 * The variables and their values. No name is the start of another, so
	 * the order does not matter.
	 *
	 * @param AlertEvent $event         Alert.
	 * @param string     $number_format How amounts are written.
	 * @param int        $max_name      Longest username; 0 for no limit.
	 * @return array<string, string>
	 */
	private function values( AlertEvent $event, string $number_format, int $max_name ): array {
		$username = '' !== $event->customer_username ? $event->customer_username : self::ANONYMOUS;

		if ( $max_name > 0 && mb_strlen( $username ) > $max_name ) {
			$username = mb_substr( $username, 0, $max_name ) . '…';
		}

		return array(
			// Removed (the project owner's choice 2026-10-23): shows nothing.
			'$OrderNumber' => '',
			'$UserName'    => $username,
			'$FirstName'   => $username,
			'$LineTotal'   => AlertMoney::format( $event->line_total, $event->currency, $number_format ),
			'$Variation'   => $event->variation,
			'$Quantity'    => (string) $event->quantity,
			'$Category'    => $this->categories( $event->category_ids ),
			'$Product'     => $event->product_name,
			'$Price'       => AlertMoney::format( AlertMoney::per_item( $event->line_total, $event->quantity ), $event->currency, $number_format ),
			'$Total'       => AlertMoney::format( $event->creator_total, $event->currency, $number_format ),
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
