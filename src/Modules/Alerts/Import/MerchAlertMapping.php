<?php
/**
 * How MerchAlert's data becomes Merch Dashboard's.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Import;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\PaidOrder;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * The import's mapping (step 25.10.1), without reading or writing anything:
 *
 * - **Overlay settings:** MerchAlert's keys are ours (OverlaySettings accepts
 *   its string values and the JSON category map); a JSON `layout` is
 *   decoded, and a missing one falls back to the older layout setting (the
 *   global overlay). Templates stay: `$FirstName` is the username already.
 * - **Sounds:** only sounds an overlay may keep stay chosen; the others are
 *   dropped (the overlay plays its other sounds).
 * - **Chat template:** MerchAlert's `$Name` (the customer's first name)
 *   becomes `$UserName`; first names are never shown.
 * - **Alerts:** MerchAlert's alert with the username and variation read
 *   from the order now; never the customer's first name.
 */
final class MerchAlertMapping {

	/**
	 * Overlay settings from MerchAlert's.
	 *
	 * @param array<mixed>      $settings      MerchAlert's settings.
	 * @param array<mixed>|null $legacy_layout The older layout setting, when the settings have none.
	 */
	public static function settings( array $settings, ?array $legacy_layout = null ): OverlaySettings {
		$layout = $settings['layout'] ?? null;

		if ( is_string( $layout ) ) {
			$layout = json_decode( $layout, true );
		}

		if ( ! is_array( $layout ) || array() === $layout ) {
			$layout = $legacy_layout;
		}

		unset( $settings['overlay_id'] );
		$settings['layout'] = $layout;

		return OverlaySettings::from_array( $settings );
	}

	/**
	 * The settings with only the sounds the overlay may keep.
	 *
	 * @phpstan-param Closure(int): bool $keep
	 *
	 * @param OverlaySettings $settings Settings.
	 * @param Closure         $keep     Whether a sound attachment stays.
	 * @return array{0: OverlaySettings, 1: list<int>} The settings and the kept sounds.
	 */
	public static function sounds( OverlaySettings $settings, Closure $keep ): array {
		$values = $settings->to_array();
		$kept   = array();

		if ( $values['audio_default_sound'] > 0 ) {
			if ( $keep( $values['audio_default_sound'] ) ) {
				$kept[] = $values['audio_default_sound'];
			} else {
				$values['audio_default_sound'] = 0;
			}
		}

		foreach ( $values['audio_category_map'] as $term => $sound ) {
			if ( $keep( $sound ) ) {
				$kept[] = $sound;
			} else {
				unset( $values['audio_category_map'][ $term ] );
			}
		}

		return array( OverlaySettings::from_array( $values ), array_values( array_unique( $kept ) ) );
	}

	/**
	 * A chat template from MerchAlert's ('' for the default).
	 *
	 * @param string $template MerchAlert's template.
	 */
	public static function chat_template( string $template ): string {
		return trim( (string) preg_replace( array( '/\$Name\b/', '/\$Amount\b/' ), array( '$UserName', '$Total' ), $template ) );
	}

	/**
	 * An alert from MerchAlert's, for one of our creators.
	 *
	 * @param array{order_id: int, order_number: string, order_item_id: int, quantity: int, product_id: int, variation_id: int, product_name: string, image_id: int, category_ids: list<int>, created_at: string} $event MerchAlert's alert.
	 * @param int                                                                                                                                                                                                 $creator_id Our creator.
	 * @param PaidOrder|null                                                                                                                                                                                      $order      The order now, or null when it is gone.
	 * @param string|null                                                                                                                                                                                         $creator_total What the customer paid for the creator's lines of the order (creator_total()).
	 */
	public static function event( array $event, int $creator_id, ?PaidOrder $order, ?string $creator_total = null ): ?AlertEvent {
		if ( $event['order_id'] < 1 || $event['order_item_id'] < 1 ) {
			return null;
		}

		$variation  = '';
		$line_total = null;

		foreach ( null === $order ? array() : $order->lines as $line ) {
			if ( $line->order_item_id === $event['order_item_id'] ) {
				$variation  = $line->variation;
				$line_total = $line->line_total;
			}
		}

		try {
			$created_at = new DateTimeImmutable( '' === $event['created_at'] ? 'now' : $event['created_at'], new DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			$created_at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		}

		return new AlertEvent(
			$creator_id,
			$event['order_id'],
			'' === $event['order_number'] ? (string) $event['order_id'] : mb_substr( $event['order_number'], 0, 64 ),
			$event['order_item_id'],
			max( 0, $event['product_id'] ),
			max( 0, $event['variation_id'] ),
			mb_substr( $event['product_name'], 0, 255 ),
			$variation,
			max( 1, $event['quantity'] ),
			max( 0, $event['image_id'] ),
			array_slice( $event['category_ids'], 0, 40 ),
			$order->username ?? '',
			$created_at,
			0,
			$line_total,
			null === $line_total ? null : $creator_total,
			$order->currency ?? ''
		);
	}

	/**
	 * What the customer paid for some lines of an order now (a creator's
	 * lines in MerchAlert); null when the order is gone or a line has no
	 * amount.
	 *
	 * @phpstan-param list<int> $order_item_ids
	 *
	 * @param int[]          $order_item_ids The creator's lines.
	 * @param PaidOrder|null $order          The order now.
	 */
	public static function creator_total( array $order_item_ids, ?PaidOrder $order ): ?string {
		$total = 0;
		$found = 0;

		foreach ( null === $order ? array() : $order->lines as $line ) {
			if ( in_array( $line->order_item_id, $order_item_ids, true ) ) {
				if ( null === $line->line_total ) {
					return null;
				}

				$total = SalesAmount::add( $total, SalesAmount::parse( $line->line_total ) );
				++$found;
			}
		}

		return 0 === $found ? null : SalesAmount::format( $total );
	}
}
