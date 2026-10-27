<?php
/**
 * The values of one notification.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, forgiving access to a notification's stored data: a missing or
 * wrongly typed value gives a neutral default, so an old notification never
 * breaks the bell or an e-mail. Amounts are decimal strings with a currency
 * and are formatted for the current language.
 */
final class NotificationData {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $values Stored values.
	 * @param string               $name   The recipient's name for the greeting ('' for none).
	 */
	public function __construct( private array $values, public readonly string $name = '' ) {
	}

	/**
	 * The raw values.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->values;
	}

	/**
	 * A text value ('' when missing).
	 *
	 * @param string $key Key.
	 */
	public function text( string $key ): string {
		$value = $this->values[ $key ] ?? '';

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A whole number (0 when missing).
	 *
	 * @param string $key Key.
	 */
	public function int( string $key ): int {
		$value = $this->values[ $key ] ?? 0;

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * A flag.
	 *
	 * @param string $key Key.
	 */
	public function flag( string $key ): bool {
		return true === ( $this->values[ $key ] ?? false );
	}

	/**
	 * An amount with the notification's currency, e.g. "499,00 kr." ('' when missing).
	 *
	 * @param string $key Key of a decimal string.
	 */
	public function money( string $key ): string {
		$amount = $this->text( $key );

		return '' === $amount || ! is_numeric( $amount ) ? '' : self::format_money( (float) $amount, $this->text( 'currency' ) );
	}

	/**
	 * Lines of a table, e.g. the items of an order: each a list of texts.
	 *
	 * @param string   $key    Key of a list of records.
	 * @param string[] $fields Fields of each record, in column order.
	 * @phpstan-param list<string> $fields
	 * @return list<list<string>>
	 */
	public function rows( string $key, array $fields ): array {
		$list = $this->values[ $key ] ?? array();
		$rows = array();

		foreach ( is_array( $list ) ? $list : array() as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$row = array();

			foreach ( $fields as $field ) {
				$value = $record[ $field ] ?? '';
				$row[] = is_scalar( $value ) ? (string) $value : '';
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * An amount in a currency: "kr." after Danish kroner, the code otherwise.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO code.
	 */
	public static function format_money( float $amount, string $currency ): string {
		$number = number_format_i18n( $amount, 2 );

		return 'DKK' === strtoupper( $currency ) || '' === $currency ? $number . ' kr.' : $number . ' ' . strtoupper( $currency );
	}
}
