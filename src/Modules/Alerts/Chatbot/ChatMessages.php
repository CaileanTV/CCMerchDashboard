<?php
/**
 * The text of chat messages.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a creator's template with only the alert variables (ADR-066,
 * status note 2026-10-20): `$UserName` and `$Name` (the customer's voluntary
 * username), `$Product`, `$Quantity`, `$Category`, `$Variation` and
 * `$OrderNumber`; with one message per order also `$Products` (the lines in
 * the administrator's item format, joined with the separator) and
 * `$ItemCount`. `$Amount` from MerchAlert templates is empty: the amount is
 * never shown in chat. The result is one line of plain text, at most
 * Twitch's 500 characters.
 */
final class ChatMessages {

	/**
	 * Variables of one message per line.
	 */
	public const LINE_VARIABLES = array( '$UserName', '$Name', '$Product', '$Quantity', '$Category', '$Variation', '$OrderNumber' );

	/**
	 * Variables of one message per order.
	 */
	public const ORDER_VARIABLES = array( '$UserName', '$Name', '$Products', '$ItemCount', '$OrderNumber' );

	/**
	 * Constructor.
	 *
	 * @param AlertTemplate $template The alerts' variables.
	 */
	public function __construct( private AlertTemplate $template ) {
	}

	/**
	 * The message of one order line.
	 *
	 * @param string     $template Creator's template.
	 * @param AlertEvent $event    The line's alert.
	 */
	public function line( string $template, AlertEvent $event ): string {
		return self::clean( $this->render( $template, $event ) );
	}

	/**
	 * The message of a whole order.
	 *
	 * @phpstan-param list<AlertEvent> $events
	 * @phpstan-param array{mode: string, item: string, separator: string} $format
	 *
	 * @param string                $template Creator's template.
	 * @param AlertEvent[]          $events   The order's alerts of the creator (at least one).
	 * @param array<string, string> $format   The administrator's format.
	 */
	public function order( string $template, array $events, array $format ): string {
		$first = $events[0] ?? null;

		if ( null === $first ) {
			return '';
		}

		$products = implode( $format['separator'], array_map( fn( AlertEvent $event ): string => self::clean( $this->render( $format['item'], $event ) ), $events ) );
		$template = str_replace(
			array( '$Products', '$ItemCount' ),
			array( '{{merchboard-products}}', (string) array_sum( array_map( static fn( AlertEvent $event ): int => $event->quantity, $events ) ) ),
			$template
		);

		// The products are inserted after the variables, so a product name is never read as one.
		return self::clean( str_replace( '{{merchboard-products}}', $products, $this->render( $template, $first ) ) );
	}

	/**
	 * A template with the alert variables of an alert.
	 *
	 * @param string     $template Template.
	 * @param AlertEvent $event    Alert.
	 */
	private function render( string $template, AlertEvent $event ): string {
		// MerchAlert's $Name is the username; $Amount is never shown (ADR-066).
		return $this->template->render( str_replace( array( '$Name', '$Amount' ), array( '$UserName', '' ), $template ), $event );
	}

	/**
	 * One line of plain text of at most Twitch's length.
	 *
	 * @param string $text Text.
	 */
	private static function clean( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );

		return mb_substr( $text, 0, TwitchApi::MESSAGE_MAX );
	}
}
