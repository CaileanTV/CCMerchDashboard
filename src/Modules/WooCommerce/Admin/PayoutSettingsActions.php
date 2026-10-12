<?php
/**
 * State-changing requests of the payout settings (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use DateTimeImmutable;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Settings\Admin\SettingsPage;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Saves the payout holding period and the payout gift card provider
 * (settings screen) and a creator's settlement start (creator edit page).
 * Order of checks: capability
 * `merchboard_manage_payouts` → nonce (bound to the creator for the start)
 * → input → PayoutSettings, which checks the capability again and writes
 * the change and its audit record in one transaction. Post/Redirect/Get
 * with a whitelisted outcome code.
 */
final class PayoutSettingsActions {

	/**
	 * The admin-post action and nonce action of the holding period.
	 */
	public const HOLDING_ACTION = 'merchboard_save_payout_holding_days';

	/**
	 * The admin-post action of the settlement start (nonce: per creator).
	 */
	public const START_ACTION = 'merchboard_save_payout_start';

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_payout_result';

	/**
	 * The holding period field.
	 */
	public const FIELD_HOLDING_DAYS = 'payout_holding_days';

	/**
	 * The settlement start field (Y-m-d in the site's time zone; empty = the whole history).
	 */
	public const FIELD_START = 'payout_start';

	/**
	 * The admin-post action and nonce action of the gift card provider.
	 */
	public const PROVIDER_ACTION = 'merchboard_save_payout_gift_card_provider';

	/**
	 * The gift card provider field ('' = none).
	 */
	public const FIELD_PROVIDER = 'payout_gift_card_provider';

	/**
	 * Constructor.
	 *
	 * @param PayoutSettings         $settings  Validation and saving.
	 * @param Logger                 $logger    Technical log.
	 * @param GiftCardProviders|null $providers Registered gift card adapters; null = none.
	 */
	public function __construct( private PayoutSettings $settings, private Logger $logger, private ?GiftCardProviders $providers = null ) {
	}

	/**
	 * Nonce action of the settlement start of one creator.
	 *
	 * @param int $creator_id Creator id.
	 */
	public static function start_nonce_action( int $creator_id ): string {
		return self::START_ACTION . '_' . $creator_id;
	}

	/**
	 * Registers the admin-post handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::HOLDING_ACTION, array( $this, 'handle_holding_request' ) );
		add_action( 'admin_post_' . self::START_ACTION, array( $this, 'handle_start_request' ) );
		add_action( 'admin_post_' . self::PROVIDER_ACTION, array( $this, 'handle_provider_request' ) );
	}

	/**
	 * WordPress entry point of the gift card provider.
	 */
	public function handle_provider_request(): void {
		// The nonce is verified in save_gift_card_provider() before any input is used.
		wp_safe_redirect( $this->save_gift_card_provider( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and the field validated in save_gift_card_provider().
		exit;
	}

	/**
	 * Saves the gift card provider ('' = none).
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save_gift_card_provider( array $input ): string {
		self::verify( $input, self::PROVIDER_ACTION );
		$value = self::scalar( $input, self::FIELD_PROVIDER );
		$known = null === $this->providers ? array() : array_keys( $this->providers->all() );

		return self::settings_url( $this->run( 'gift_card_provider', 0, fn(): bool => $this->settings->set_gift_card_provider( $value, $known ) ) );
	}

	/**
	 * WordPress entry point of the holding period.
	 */
	public function handle_holding_request(): void {
		// The nonce is verified in save_holding_days() before any input is used.
		wp_safe_redirect( $this->save_holding_days( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in save_holding_days().
		exit;
	}

	/**
	 * WordPress entry point of the settlement start.
	 */
	public function handle_start_request(): void {
		// The nonce is verified in save_start() before any input is used.
		wp_safe_redirect( $this->save_start( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in save_start().
		exit;
	}

	/**
	 * Saves the holding period.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save_holding_days( array $input ): string {
		self::verify( $input, self::HOLDING_ACTION );
		$value = self::scalar( $input, self::FIELD_HOLDING_DAYS );

		if ( 1 !== preg_match( '/^\d{1,3}$/', $value ) ) {
			return self::settings_url( PayoutException::INVALID_HOLDING_DAYS );
		}

		return self::settings_url( $this->run( 'holding_days', 0, fn(): bool => $this->settings->set_holding_days( (int) $value ) ) );
	}

	/**
	 * Saves a creator's settlement start.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save_start( array $input ): string {
		$creator_id = absint( self::scalar( $input, 'creator_id' ) );
		self::verify( $input, self::start_nonce_action( $creator_id ) );
		$value = trim( self::scalar( $input, self::FIELD_START ) );
		$start = null;

		if ( '' !== $value ) {
			$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

			if ( false === $start || $start->format( 'Y-m-d' ) !== $value ) {
				return self::start_url( $creator_id, PayoutException::INVALID_START );
			}
		}

		return self::start_url( $creator_id, $this->run( 'settlement_start', $creator_id, fn(): bool => $this->settings->set_settlement_start( $creator_id, $start ) ) );
	}

	/**
	 * Runs a change and maps the outcome to a code.
	 *
	 * @param string           $operation  Operation for the log.
	 * @param int              $creator_id Creator id for the log (0 = none).
	 * @param callable(): bool $change   The change.
	 */
	private function run( string $operation, int $creator_id, callable $change ): string {
		try {
			return $change() ? 'saved' : 'unchanged';
		} catch ( PayoutException $e ) {
			return $e->reason();
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Saving a payout setting failed.',
				array(
					'operation'  => $operation,
					'creator_id' => $creator_id,
					'error'      => $e::class,
				)
			);

			return 'error';
		}
	}

	/**
	 * Requires the capability and a valid nonce; 403 otherwise.
	 *
	 * @param array<mixed> $input        Request data.
	 * @param string       $nonce_action Nonce action.
	 */
	private static function verify( array $input, string $nonce_action ): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage payouts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$nonce = self::scalar( $input, '_wpnonce' );

		if ( '' === $nonce || 1 !== wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * The settings screen with an outcome code.
	 *
	 * @param string $result Outcome code.
	 */
	public static function settings_url( string $result ): string {
		return add_query_arg( array( self::RESULT_ARG => $result ), SettingsPage::url() ) . '#merchboard-payouts';
	}

	/**
	 * The creator's edit page with an outcome code.
	 *
	 * @param int    $creator_id Creator id.
	 * @param string $result     Outcome code.
	 */
	public static function start_url( int $creator_id, string $result ): string {
		return add_query_arg( array( self::RESULT_ARG => $result ), CreatorsAdminPage::edit_url( $creator_id ) ) . '#merchboard-payout-start';
	}

	/**
	 * A scalar request field as string ('' when missing or not scalar).
	 *
	 * @param array<mixed> $input Request data.
	 * @param string       $key   Field.
	 */
	private static function scalar( array $input, string $key ): string {
		$value = $input[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}
}
