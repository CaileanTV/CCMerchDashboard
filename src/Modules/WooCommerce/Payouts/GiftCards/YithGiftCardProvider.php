<?php
/**
 * Gift card adapter for YITH WooCommerce Gift Cards 4.38.x (free version).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\GiftCards;

use Closure;
use DateTimeImmutable;
use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads cards of YITH WooCommerce Gift Cards (ADR-057). The only class of
 * Merch Dashboard that knows the plugin; it never writes to it.
 *
 * What it relies on (verified on 4.38.0, runtime test 2026-10-10/11):
 * - a card is a post of type `gift_card`; its code is the post title;
 * - `_ywgc_amount_total` and `_ywgc_balance_total` hold the amount and the
 *   balance as text exactly as typed or as a float written by the plugin;
 * - "Create Code" in the plugin's admin stores neither `_ywgc_currency` nor
 *   `_ywgc_expiration`, and the plugin applies a card in the cart's currency
 *   without conversion: a card without a currency is in the store's
 *   currency; a card without an expiry does not expire;
 * - `publish` is usable, `ywgc-disabled` and `ywgc-dismissed` are disabled;
 * - `_ywgc_orders` lists the orders paid with the card through the coupon
 *   field or the plugin's form; it is only ever appended to (read of the
 *   plugin's code, 4.38.0; runtime test 16.5 for the paths).
 *
 * It reads the database directly instead of through the plugin's
 * `YITH_YWGC_Gift_Card`: constructing that class writes amount and balance
 * meta for a card that lacks them, and the plugin's lookup by code only
 * sees published cards and silently picks the newest of several. Here a code
 * that several cards (of any status but trash) share is `ambiguous`, and an
 * amount or balance that is missing, has a comma, is negative or has more
 * decimals than the store is `invalid_data`, never 0.
 */
final class YithGiftCardProvider implements GiftCardProvider {

	public const ID = 'yith-wcgc';

	/**
	 * The tested versions.
	 */
	private const VERSION_PREFIX = '4.38.';

	private const POST_TYPE = 'gift_card';

	private const AMOUNT = '_ywgc_amount_total';

	private const BALANCE = '_ywgc_balance_total';

	private const CURRENCY = '_ywgc_currency';

	private const EXPIRATION = '_ywgc_expiration';

	/**
	 * The orders the plugin recorded on a card (`YWGC_META_GIFT_CARD_ORDERS`).
	 */
	private const ORDERS = '_ywgc_orders';

	/**
	 * Post statuses that are no card.
	 */
	private const NO_CARD = "'trash', 'auto-draft', 'inherit'";

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param wpdb                $wpdb  WordPress database object.
	 * @param PricingProductStore $store Store currency and decimals.
	 * @param Closure             $clock Current time (`Closure(): DateTimeImmutable`).
	 */
	public function __construct( private wpdb $wpdb, private PricingProductStore $store, private Closure $clock ) {
	}

	/**
	 * Provider id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * The plugin's name.
	 */
	public function label(): string {
		return 'YITH WooCommerce Gift Cards';
	}

	/**
	 * Installed, active, the free version 4.38.x, and its post type and meta
	 * keys are as expected.
	 */
	public function availability(): GiftCardAvailability {
		if ( ! defined( 'YITH_YWGC_VERSION' ) || ! function_exists( 'YITH_YWGC' ) ) {
			$installed = defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/yith-woocommerce-gift-cards/init.php' );

			return GiftCardAvailability::unavailable( $installed ? GiftCardAvailability::INACTIVE : GiftCardAvailability::NOT_INSTALLED );
		}

		$version = (string) constant( 'YITH_YWGC_VERSION' );

		if ( ! defined( 'YITH_YWGC_FREE' ) || ! str_starts_with( $version, self::VERSION_PREFIX ) ) {
			return GiftCardAvailability::unavailable( GiftCardAvailability::UNSUPPORTED_VERSION, $version );
		}

		if ( ! $this->self_test() ) {
			return GiftCardAvailability::unavailable( GiftCardAvailability::SELF_TEST_FAILED, $version );
		}

		return GiftCardAvailability::available( $version );
	}

	/**
	 * Exactly one card by its code.
	 *
	 * @param string $code The card's code.
	 * @throws GiftCardProviderException When several cards have it, its data is not exact, or the read fails.
	 */
	public function find_by_code( string $code ): ?GiftCardSnapshot {
		$this->require_active();
		$code = trim( $code );

		if ( '' === $code ) {
			return null;
		}

		$wpdb = $this->wpdb;
		// Compared like the plugin compares codes: through the column's collation.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_status FROM %i WHERE post_type = %s AND post_title = %s AND post_status NOT IN (' . self::NO_CARD . ') LIMIT 2', $wpdb->posts, self::POST_TYPE, $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Constant status list; the plugin's live data, never cached.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new GiftCardProviderException( GiftCardProviderException::READ_FAILED );
		}

		if ( count( $rows ) > 1 ) {
			throw new GiftCardProviderException( GiftCardProviderException::AMBIGUOUS );
		}

		return array() === $rows ? null : $this->snapshot( (int) $rows[0]['ID'], (string) $rows[0]['post_status'] );
	}

	/**
	 * A card by its post id.
	 *
	 * @param string $external_id The card's post id.
	 * @throws GiftCardProviderException When its data is not exact or the read fails.
	 */
	public function find_by_id( string $external_id ): ?GiftCardSnapshot {
		$this->require_active();
		$row = $this->card_row( $external_id );

		return null === $row ? null : $this->snapshot( (int) $row['ID'], (string) $row['post_status'] );
	}

	/**
	 * The card's code: its post title.
	 *
	 * @param string $external_id The card's post id.
	 * @throws GiftCardProviderException When the card or its code cannot be read.
	 */
	public function reveal_code( string $external_id ): string {
		$this->require_active();
		$row  = $this->card_row( $external_id );
		$code = null === $row ? '' : trim( (string) $row['post_title'] );

		if ( '' === $code ) {
			throw new GiftCardProviderException( GiftCardProviderException::READ_FAILED );
		}

		return $code;
	}

	/**
	 * The orders recorded on each card in `_ywgc_orders`. The plugin writes
	 * that list only through `register_order()` and never removes from it:
	 * at checkout for the coupon field (classic and Store API) and for its
	 * own gift card form. An order created in the admin or through the REST
	 * API with the card as a coupon is not recorded there; Merch Dashboard
	 * refuses payout cards on those orders (PayoutGiftCardCouponGuard).
	 *
	 * @phpstan-param list<string> $external_ids
	 *
	 * @param string[] $external_ids The cards' post ids.
	 * @return array<string, list<int>>
	 * @throws GiftCardProviderException When a list is not exact or the read fails.
	 */
	public function orders_paid_with( array $external_ids ): array {
		$this->require_active();
		$ids = array_values( array_unique( array_filter( $external_ids, static fn( string $id ): bool => 1 === preg_match( '/^[1-9]\d{0,18}$/', $id ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$wpdb         = $this->wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = 'SELECT p.ID, m.meta_value FROM %i p LEFT JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND p.post_status NOT IN (' . self::NO_CARD . ') AND p.ID IN (' . $placeholders . ')';
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $wpdb->posts, $wpdb->postmeta, self::ORDERS, self::POST_TYPE, ...array_map( 'intval', $ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- Constant status list and %d placeholders; the plugin's live data, never cached.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new GiftCardProviderException( GiftCardProviderException::READ_FAILED );
		}

		$result = array();

		foreach ( $rows as $row ) {
			$id = (string) $row['ID'];

			// A list stored twice is not exact.
			if ( isset( $result[ $id ] ) ) {
				throw new GiftCardProviderException( GiftCardProviderException::INVALID_DATA );
			}

			$orders = self::registered_orders( $row['meta_value'] );

			if ( null === $orders ) {
				throw new GiftCardProviderException( GiftCardProviderException::INVALID_DATA );
			}

			$result[ $id ] = $orders;
		}

		return $result;
	}

	/**
	 * Parses the plugin's stored order list: missing or empty is no order; a
	 * serialized array of positive order ids is the list (sorted, unique);
	 * anything else is null, never an empty list.
	 *
	 * @param mixed $value Stored meta value (null when the card has none).
	 * @return list<int>|null
	 */
	public static function registered_orders( mixed $value ): ?array {
		if ( null === $value || '' === $value ) {
			return array();
		}

		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return null;
		}

		// Damaged data is "not exact", without a PHP warning.
		set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Restored right below.

		try {
			$list = unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The plugin's own meta; no objects are created.
		} finally {
			restore_error_handler();
		}

		if ( ! is_array( $list ) ) {
			return null;
		}

		$orders = array();

		foreach ( $list as $order ) {
			if ( ( ! is_int( $order ) && ! is_string( $order ) ) || 1 !== preg_match( '/^[1-9]\d{0,18}$/', (string) $order ) ) {
				return null;
			}

			$orders[] = (int) $order;
		}

		$orders = array_values( array_unique( $orders ) );
		sort( $orders );

		return $orders;
	}

	/**
	 * The plugin's admin page for creating a card, or for one card.
	 *
	 * @param string|null $external_id The card's post id, null for "create".
	 */
	public function admin_url( ?string $external_id ): ?string {
		if ( null === $external_id ) {
			return admin_url( 'post-new.php?post_type=' . self::POST_TYPE );
		}

		return 1 === preg_match( '/^[1-9]\d{0,18}$/', $external_id ) ? admin_url( 'post.php?post=' . $external_id . '&action=edit' ) : null;
	}

	/**
	 * Parses an amount the plugin stored: plain digits with an optional
	 * fraction, exact at the store's decimals (extra decimals only zeros).
	 * Anything else, including a missing value, is null: never 0.
	 *
	 * @param mixed $value    Stored value.
	 * @param int   $decimals Store price decimals.
	 */
	public static function exact_amount( mixed $value, int $decimals ): ?int {
		$text = is_string( $value ) ? trim( $value ) : '';

		if ( 1 !== preg_match( '/^\d{1,15}(?:\.(\d+))?$/', $text, $parts ) ) {
			return null;
		}

		$fraction = $parts[1] ?? '';

		if ( '' !== trim( substr( $fraction, max( 0, $decimals ) ), '0' ) || $decimals > SalesAmount::DECIMALS ) {
			return null;
		}

		try {
			return SalesAmount::parse( $text );
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Snapshot of a card row.
	 *
	 * @param int    $id     Post id.
	 * @param string $status Post status.
	 * @throws GiftCardProviderException When its data is not exact or the read fails.
	 */
	private function snapshot( int $id, string $status ): GiftCardSnapshot {
		$meta     = $this->meta( $id );
		$decimals = $this->store->decimals();
		$amount   = self::exact_amount( $meta[ self::AMOUNT ] ?? null, $decimals );
		$balance  = self::exact_amount( $meta[ self::BALANCE ] ?? null, $decimals );
		$currency = trim( (string) ( $meta[ self::CURRENCY ] ?? '' ) );
		$currency = '' === $currency ? $this->store->currency() : $currency;
		$expiry   = trim( (string) ( $meta[ self::EXPIRATION ] ?? '' ) );

		if ( null === $amount || null === $balance || 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) || ( '' !== $expiry && 1 !== preg_match( '/^\d{1,12}$/', $expiry ) ) ) {
			throw new GiftCardProviderException( GiftCardProviderException::INVALID_DATA );
		}

		$expires_at = '' === $expiry || 0 === (int) $expiry ? null : new DateTimeImmutable( '@' . (int) $expiry );
		$card       = match ( $status ) {
			'publish'                         => GiftCardStatus::Active,
			'ywgc-disabled', 'ywgc-dismissed' => GiftCardStatus::Disabled,
			default                           => GiftCardStatus::Other,
		};

		if ( GiftCardStatus::Active === $card && null !== $expires_at && $expires_at <= ( $this->clock )() ) {
			$card = GiftCardStatus::Expired;
		}

		return new GiftCardSnapshot( (string) $id, $amount, $balance, $currency, $card, $expires_at, true );
	}

	/**
	 * The card's meta that the adapter reads; a key stored twice is not exact.
	 *
	 * @param int $id Post id.
	 * @return array<string, string>
	 * @throws GiftCardProviderException When the read fails or a key is stored twice.
	 */
	private function meta( int $id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND meta_key IN (%s, %s, %s, %s)', $wpdb->postmeta, $id, self::AMOUNT, self::BALANCE, self::CURRENCY, self::EXPIRATION ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's live data, never cached.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new GiftCardProviderException( GiftCardProviderException::READ_FAILED );
		}

		$meta = array();

		foreach ( $rows as $row ) {
			$key = (string) $row['meta_key'];

			if ( isset( $meta[ $key ] ) ) {
				throw new GiftCardProviderException( GiftCardProviderException::INVALID_DATA );
			}

			$meta[ $key ] = (string) $row['meta_value'];
		}

		return $meta;
	}

	/**
	 * The card's row by post id, null when it is no (longer a) card.
	 *
	 * @param string $external_id Post id.
	 * @return array<string, mixed>|null
	 * @throws GiftCardProviderException When the read fails.
	 */
	private function card_row( string $external_id ): ?array {
		if ( 1 !== preg_match( '/^[1-9]\d{0,18}$/', $external_id ) ) {
			return null;
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ID, post_status, post_title FROM %i WHERE ID = %d AND post_type = %s AND post_status NOT IN (' . self::NO_CARD . ')', $wpdb->posts, (int) $external_id, self::POST_TYPE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Constant status list; the plugin's live data, never cached.

		if ( '' !== $wpdb->last_error ) {
			throw new GiftCardProviderException( GiftCardProviderException::READ_FAILED );
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The plugin's post type and meta keys are as this adapter expects, and
	 * its cards can be read.
	 */
	private function self_test(): bool {
		$constant = static fn( string $name ): ?string => defined( 'YITH_YWGC_Gift_Card::' . $name ) ? (string) constant( 'YITH_YWGC_Gift_Card::' . $name ) : null;

		if ( ! post_type_exists( self::POST_TYPE ) || ! class_exists( 'YITH_YWGC_Gift_Card' )
			|| self::AMOUNT !== $constant( 'META_AMOUNT_TOTAL' ) || self::BALANCE !== $constant( 'META_BALANCE_TOTAL' )
			|| self::CURRENCY !== $constant( 'META_CURRENCY' ) || self::EXPIRATION !== $constant( 'META_EXPIRATION' )
			|| ! defined( 'YWGC_META_GIFT_CARD_ORDERS' ) || self::ORDERS !== (string) constant( 'YWGC_META_GIFT_CARD_ORDERS' ) ) {
			return false;
		}

		$wpdb = $this->wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, self::POST_TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Self-test of a live read.

		return '' === $wpdb->last_error;
	}

	/**
	 * Refuses reads while the plugin is not active.
	 *
	 * @throws GiftCardProviderException When it is not.
	 */
	private function require_active(): void {
		if ( ! defined( 'YITH_YWGC_VERSION' ) ) {
			throw new GiftCardProviderException( GiftCardProviderException::UNAVAILABLE );
		}
	}
}
