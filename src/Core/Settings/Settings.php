<?php
/**
 * Plugin settings.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The single option `merchboard_settings` (one autoloaded array,
 * docs/DATABASE.md). Every value is read through sanitize(), so a forged or
 * corrupted option can never produce an unexpected value.
 *
 * Only change settings through SettingsService (authorization and audit);
 * this class performs no authorization.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'merchboard_settings';

	/**
	 * Delete all Merch Dashboard data when the plugin is deleted (default off).
	 * Enabled only by exactly boolean true.
	 */
	public const DELETE_DATA_ON_UNINSTALL = 'delete_data_on_uninstall';

	/**
	 * URL slug of the creator dashboard (phase 12).
	 */
	public const DASHBOARD_SLUG = 'dashboard_slug';

	/**
	 * Default dashboard slug (open question 8, decided in phase 12).
	 */
	public const DEFAULT_DASHBOARD_SLUG = 'creator-dashboard';

	/**
	 * Order statuses (slugs without `wc-`) whose orders count as sales in the
	 * sales ledger (fase 18, trin 6; ADR-038 S1-S14 and the status note
	 * 2026-09-30). Core only checks the format; the WooCommerce module decides
	 * which statuses are registered and allowed.
	 */
	public const COUNTED_ORDER_STATUSES = 'counted_order_statuses';

	/**
	 * Default counted order statuses.
	 */
	public const DEFAULT_COUNTED_ORDER_STATUSES = array( 'processing', 'completed' );

	/**
	 * Longest order status slug (without `wc-`). WordPress' `wp_posts.post_status`
	 * (legacy) and WooCommerce' `wc_orders.status` (HPOS) are both varchar(20)
	 * and store the status with its `wc-` prefix, so 20 - 3 characters work
	 * the same in both stores (ADR-038, status note 2026-09-30).
	 */
	public const STATUS_SLUG_MAX_LENGTH = 17;

	/**
	 * The admin-chosen logo (ADR-042): a Media Library attachment id, 0 for
	 * none (the built-in default logo). Only the id is stored; whether it is a
	 * usable logo is checked when it is saved and again whenever it is shown.
	 */
	public const LOGO_ATTACHMENT_ID = 'logo_attachment_id';

	/**
	 * Payout holding period in days (planning decision 2, ADR-054): a ledger
	 * row with a payout ≥ 0 counts when it is older than this.
	 */
	public const PAYOUT_HOLDING_DAYS = 'payout_holding_days';

	/**
	 * Default holding period in days.
	 */
	public const DEFAULT_PAYOUT_HOLDING_DAYS = 14;

	/**
	 * Longest holding period in days.
	 */
	public const MAX_PAYOUT_HOLDING_DAYS = 365;

	/**
	 * The gift card provider chosen for payout gift cards (ADR-057): a
	 * provider id, '' for none (the default). Merch Dashboard never chooses a
	 * provider by itself because a plugin is installed; whether the id belongs
	 * to a registered, available provider is checked where it is used.
	 */
	public const PAYOUT_GIFT_CARD_PROVIDER = 'payout_gift_card_provider';

	/**
	 * The CVR number of the business that issues the settlement documents
	 * (ADR-062 K09): eight digits, '' when not registered (the default). Copied
	 * into each settlement document when it is issued.
	 */
	public const COMPANY_CVR = 'company_cvr';

	/**
	 * The business' legal name (phase 25, step 8.5): fills `[COMPANY]` in the
	 * YouTube chatbot's privacy policy and terms (ADR-071). '' when not
	 * registered; at most COMPANY_NAME_MAX_LENGTH characters, one line.
	 */
	public const COMPANY_NAME = 'company_name';

	/**
	 * The business' contact e-mail for privacy questions (phase 25, step 8.5):
	 * fills `[EMAIL]` (ADR-071). '' when not registered.
	 */
	public const COMPANY_EMAIL = 'company_email';

	/**
	 * Longest company name.
	 */
	public const COMPANY_NAME_MAX_LENGTH = 200;

	/**
	 * Whether purchase alerts are sent (ADR-065 pkt. 6). Off by default, also
	 * after an update; enabled only by exactly boolean true.
	 */
	public const ALERTS_ENABLED = 'alerts_enabled';

	/**
	 * Whether customers get an optional "username" field in the checkout
	 * (ADR-065 pkt. 9, step 25.9.1). Off by default; shown only while alerts
	 * are on; enabled only by exactly boolean true.
	 */
	public const CHECKOUT_USERNAME_ENABLED = 'checkout_username_enabled';

	/**
	 * The label of the checkout username field: one line, '' for the default.
	 */
	public const CHECKOUT_USERNAME_LABEL = 'checkout_username_label';

	/**
	 * The help text under the checkout username field: one line, '' for the
	 * default (that the name is shown publicly).
	 */
	public const CHECKOUT_USERNAME_HELP = 'checkout_username_help';

	/**
	 * Longest checkout username label.
	 */
	public const CHECKOUT_USERNAME_LABEL_MAX_LENGTH = 100;

	/**
	 * Longest checkout username help text.
	 */
	public const CHECKOUT_USERNAME_HELP_MAX_LENGTH = 300;

	/**
	 * Slugs WordPress, WooCommerce or common setups already use for their own
	 * URLs; the dashboard must never shadow them.
	 */
	private const RESERVED_SLUGS = array(
		'wp-admin',
		'wp-login',
		'wp-login.php',
		'wp-json',
		'wp-content',
		'wp-includes',
		'admin',
		'login',
		'logout',
		'register',
		'feed',
		'rss',
		'comments',
		'page',
		'category',
		'tag',
		'author',
		'search',
		'embed',
		'attachment',
		'sitemap',
		'shop',
		'cart',
		'checkout',
		'my-account',
		'product',
		'product-category',
		'product-tag',
	);

	/**
	 * Defaults.
	 *
	 * @return array{delete_data_on_uninstall: bool, dashboard_slug: string, counted_order_statuses: list<string>, logo_attachment_id: int, payout_holding_days: int, payout_gift_card_provider: string, company_cvr: string, company_name: string, company_email: string, alerts_enabled: bool, checkout_username_enabled: bool, checkout_username_label: string, checkout_username_help: string}
	 */
	public static function defaults(): array {
		return array(
			self::DELETE_DATA_ON_UNINSTALL  => false,
			self::DASHBOARD_SLUG            => self::DEFAULT_DASHBOARD_SLUG,
			self::COUNTED_ORDER_STATUSES    => self::DEFAULT_COUNTED_ORDER_STATUSES,
			self::LOGO_ATTACHMENT_ID        => 0,
			self::PAYOUT_HOLDING_DAYS       => self::DEFAULT_PAYOUT_HOLDING_DAYS,
			self::PAYOUT_GIFT_CARD_PROVIDER => '',
			self::COMPANY_CVR               => '',
			self::COMPANY_NAME              => '',
			self::COMPANY_EMAIL             => '',
			self::ALERTS_ENABLED            => false,
			self::CHECKOUT_USERNAME_ENABLED => false,
			self::CHECKOUT_USERNAME_LABEL   => '',
			self::CHECKOUT_USERNAME_HELP    => '',
		);
	}

	/**
	 * Keeps only known keys with valid values. Destructive flags count only when
	 * they are exactly true: "1", "yes" or 1 (e.g. written by other code or a
	 * database edit) do not enable deletion. An invalid slug falls back to the
	 * default.
	 *
	 * @param array<mixed> $values Raw values.
	 * @return array{delete_data_on_uninstall: bool, dashboard_slug: string, counted_order_statuses: list<string>, logo_attachment_id: int, payout_holding_days: int, payout_gift_card_provider: string, company_cvr: string, company_name: string, company_email: string, alerts_enabled: bool, checkout_username_enabled: bool, checkout_username_label: string, checkout_username_help: string}
	 */
	public static function sanitize( array $values ): array {
		$slug  = $values[ self::DASHBOARD_SLUG ] ?? null;
		$logo  = $values[ self::LOGO_ATTACHMENT_ID ] ?? null;
		$days  = $values[ self::PAYOUT_HOLDING_DAYS ] ?? null;
		$card  = $values[ self::PAYOUT_GIFT_CARD_PROVIDER ] ?? null;
		$cvr   = $values[ self::COMPANY_CVR ] ?? null;
		$name  = $values[ self::COMPANY_NAME ] ?? null;
		$mail  = $values[ self::COMPANY_EMAIL ] ?? null;
		$label = $values[ self::CHECKOUT_USERNAME_LABEL ] ?? null;
		$help  = $values[ self::CHECKOUT_USERNAME_HELP ] ?? null;

		return array(
			self::DELETE_DATA_ON_UNINSTALL  => true === ( $values[ self::DELETE_DATA_ON_UNINSTALL ] ?? false ),
			self::DASHBOARD_SLUG            => is_string( $slug ) && self::valid_slug( $slug ) ? $slug : self::DEFAULT_DASHBOARD_SLUG,
			self::COUNTED_ORDER_STATUSES    => self::status_list( $values[ self::COUNTED_ORDER_STATUSES ] ?? null ),
			self::LOGO_ATTACHMENT_ID        => is_int( $logo ) && $logo > 0 ? $logo : 0,
			self::PAYOUT_HOLDING_DAYS       => is_int( $days ) && self::valid_holding_days( $days ) ? $days : self::DEFAULT_PAYOUT_HOLDING_DAYS,
			self::PAYOUT_GIFT_CARD_PROVIDER => is_string( $card ) && self::valid_provider_id( $card ) ? $card : '',
			self::COMPANY_CVR               => is_string( $cvr ) && self::valid_cvr( $cvr ) ? $cvr : '',
			self::COMPANY_NAME              => is_string( $name ) && self::valid_company_name( $name ) ? $name : '',
			self::COMPANY_EMAIL             => is_string( $mail ) && self::valid_company_email( $mail ) ? $mail : '',
			self::ALERTS_ENABLED            => true === ( $values[ self::ALERTS_ENABLED ] ?? false ),
			self::CHECKOUT_USERNAME_ENABLED => true === ( $values[ self::CHECKOUT_USERNAME_ENABLED ] ?? false ),
			self::CHECKOUT_USERNAME_LABEL   => is_string( $label ) && self::valid_line( $label, self::CHECKOUT_USERNAME_LABEL_MAX_LENGTH ) ? $label : '',
			self::CHECKOUT_USERNAME_HELP    => is_string( $help ) && self::valid_line( $help, self::CHECKOUT_USERNAME_HELP_MAX_LENGTH ) ? $help : '',
		);
	}

	/**
	 * Whether a string is a valid stored company name: '' or one line of at
	 * most COMPANY_NAME_MAX_LENGTH characters without control characters,
	 * trimmed.
	 *
	 * @param string $name Company name.
	 */
	public static function valid_company_name( string $name ): bool {
		return self::valid_line( $name, self::COMPANY_NAME_MAX_LENGTH );
	}

	/**
	 * Whether a string is a valid stored line of text: '' or one trimmed line
	 * of at most $max characters without control characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest length in characters.
	 */
	public static function valid_line( string $text, int $max ): bool {
		return 1 !== preg_match( '/^\s|\s$/u', $text )
			&& mb_strlen( $text ) <= $max
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/u', $text );
	}

	/**
	 * Whether the optional username field is shown in the checkout (when
	 * alerts are switched on, too).
	 */
	public static function checkout_username_enabled(): bool {
		return self::all()[ self::CHECKOUT_USERNAME_ENABLED ];
	}

	/**
	 * The label of the checkout username field ('' for the default).
	 */
	public static function checkout_username_label(): string {
		return self::all()[ self::CHECKOUT_USERNAME_LABEL ];
	}

	/**
	 * The help text of the checkout username field ('' for the default).
	 */
	public static function checkout_username_help(): string {
		return self::all()[ self::CHECKOUT_USERNAME_HELP ];
	}

	/**
	 * Whether a string is a valid stored contact e-mail: '' or an e-mail
	 * address.
	 *
	 * @param string $email E-mail.
	 */
	public static function valid_company_email( string $email ): bool {
		return '' === $email || ( strlen( $email ) <= 254 && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) );
	}

	/**
	 * The business' legal name, '' when not registered.
	 */
	public static function company_name(): string {
		return self::all()[ self::COMPANY_NAME ];
	}

	/**
	 * The business' contact e-mail for privacy questions, '' when not
	 * registered.
	 */
	public static function company_email(): string {
		return self::all()[ self::COMPANY_EMAIL ];
	}

	/**
	 * Whether a slug is well-formed and not reserved: 3-50 characters, lowercase
	 * letters, digits and single hyphens, not starting or ending with a hyphen.
	 *
	 * @param string $slug Slug.
	 */
	public static function valid_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug )
			&& strlen( $slug ) >= 3
			&& strlen( $slug ) <= 50
			&& ! in_array( $slug, self::RESERVED_SLUGS, true );
	}

	/**
	 * Current settings.
	 *
	 * @return array{delete_data_on_uninstall: bool, dashboard_slug: string, counted_order_statuses: list<string>, logo_attachment_id: int, payout_holding_days: int, payout_gift_card_provider: string, company_cvr: string, company_name: string, company_email: string, alerts_enabled: bool, checkout_username_enabled: bool, checkout_username_label: string, checkout_username_help: string}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Whether permanent deletion on uninstall was explicitly enabled.
	 */
	public static function delete_data_on_uninstall(): bool {
		return self::all()[ self::DELETE_DATA_ON_UNINSTALL ];
	}

	/**
	 * Counted order statuses as stored (format-checked; the WooCommerce module's
	 * CountedOrderStatuses applies the deny list and its own fallback).
	 *
	 * @return list<string>
	 */
	public static function counted_order_statuses(): array {
		return self::all()[ self::COUNTED_ORDER_STATUSES ];
	}

	/**
	 * Whether a string is a well-formed order status slug: not empty, only the
	 * characters WordPress' `sanitize_key()` keeps (lowercase letters, digits,
	 * `_` and `-`), without the `wc-` prefix and at most
	 * STATUS_SLUG_MAX_LENGTH characters. Says nothing about whether
	 * WooCommerce knows the status (ADR-038, status note 2026-09-30).
	 *
	 * @param string $status Status slug.
	 */
	public static function valid_status_slug( string $status ): bool {
		return 1 === preg_match( '/^[a-z0-9_-]+$/', $status )
			&& strlen( $status ) <= self::STATUS_SLUG_MAX_LENGTH
			&& ! str_starts_with( $status, 'wc-' );
	}

	/**
	 * A list of well-formed status slugs: invalid entries are dropped, duplicates
	 * removed (first occurrence kept, so the order stays deterministic); an
	 * empty result falls back to the default.
	 *
	 * @param mixed $value Stored value.
	 * @return list<string>
	 */
	private static function status_list( mixed $value ): array {
		$statuses = array();

		foreach ( is_array( $value ) ? $value : array() as $status ) {
			if ( is_string( $status ) && self::valid_status_slug( $status ) && ! in_array( $status, $statuses, true ) ) {
				$statuses[] = $status;
			}
		}

		return array() === $statuses ? self::DEFAULT_COUNTED_ORDER_STATUSES : $statuses;
	}

	/**
	 * Whether a holding period is allowed (0 … MAX_PAYOUT_HOLDING_DAYS days).
	 *
	 * @param int $days Days.
	 */
	public static function valid_holding_days( int $days ): bool {
		return $days >= 0 && $days <= self::MAX_PAYOUT_HOLDING_DAYS;
	}

	/**
	 * The payout holding period in days.
	 */
	public static function payout_holding_days(): int {
		return self::all()[ self::PAYOUT_HOLDING_DAYS ];
	}

	/**
	 * Whether a string is a well-formed gift card provider id: '' (none) or
	 * 1–32 lowercase letters, digits and hyphens.
	 *
	 * @param string $id Provider id.
	 */
	public static function valid_provider_id( string $id ): bool {
		return '' === $id || 1 === preg_match( '/^[a-z0-9-]{1,32}$/', $id );
	}

	/**
	 * The chosen payout gift card provider id, '' for none.
	 */
	public static function payout_gift_card_provider(): string {
		return self::all()[ self::PAYOUT_GIFT_CARD_PROVIDER ];
	}

	/**
	 * Whether a string is a valid stored CVR number: '' (not registered) or
	 * eight digits.
	 *
	 * @param string $cvr CVR number.
	 */
	public static function valid_cvr( string $cvr ): bool {
		return '' === $cvr || 1 === preg_match( '/^\d{8}$/', $cvr );
	}

	/**
	 * The CVR number of the business that issues the settlement documents,
	 * '' when not registered.
	 */
	public static function company_cvr(): string {
		return self::all()[ self::COMPANY_CVR ];
	}

	/**
	 * Whether purchase alerts are switched on (ADR-065).
	 */
	public static function alerts_enabled(): bool {
		return self::all()[ self::ALERTS_ENABLED ];
	}

	/**
	 * The stored logo attachment id (0 = the default logo). Not validated
	 * against the Media Library here; see Modules\Dashboard\Branding.
	 */
	public static function logo_attachment_id(): int {
		return self::all()[ self::LOGO_ATTACHMENT_ID ];
	}

	/**
	 * Current dashboard slug.
	 */
	public static function dashboard_slug(): string {
		return self::all()[ self::DASHBOARD_SLUG ];
	}

	/**
	 * Stores sanitized settings.
	 *
	 * @param array<mixed> $values Values.
	 * @return bool Whether the option was written.
	 */
	public static function save( array $values ): bool {
		return update_option( self::OPTION, self::sanitize( $values ), true );
	}
}
