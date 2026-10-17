<?php
/**
 * State-changing requests of the creator's pricing (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorPricingSettings;
use MerchBoard\Modules\WooCommerce\Pricing\PricingException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Creator markup, VAT status and a product's own markup, from the Pricing
 * section of the creator's edit page (ADR-048, ADR-049). None of them
 * changes a WooCommerce price. Order of checks for every request:
 *
 * 1. capability `merchboard_manage_creator_pricing` → 403 otherwise;
 * 2. nonce, bound to the action AND the creator id → 403 otherwise;
 * 3. input: absint ids and plain text only;
 * 4. CreatorPricingSettings, which checks the capability again,
 *    `edit_product`, ownership (the page's creator id is never trusted as
 *    ownership: it is compared with the server-side owner) and every
 *    business rule, writes and audits.
 *
 * Nothing here writes data or decides a rule. The outcome is reported as
 * "id:code" in the redirect URL (Post/Redirect/Get, id 0 = creator level);
 * codes are whitelisted when rendered, and technical failures are logged
 * with safe context and shown neutrally.
 */
final class CreatorPricingActions {

	public const MARKUP_ACTION                = 'merchboard_save_creator_markup';
	public const VAT_ACTION                   = 'merchboard_save_creator_vat';
	public const INVALID_VAT_STATUS           = 'invalid_vat_status';
	public const PRODUCT_MARKUP_ACTION        = 'merchboard_save_product_markup';
	public const REMOVE_PRODUCT_MARKUP_ACTION = 'merchboard_remove_product_markup';
	public const RESULTS_ARG                  = 'merchboard_pricing_results';

	/**
	 * Every admin-post action of the section.
	 */
	private const ACTIONS = array( self::MARKUP_ACTION, self::VAT_ACTION, self::PRODUCT_MARKUP_ACTION, self::REMOVE_PRODUCT_MARKUP_ACTION );

	/**
	 * Most outcomes parsed from a redirect.
	 */
	private const MAX_RESULTS = 20;

	/**
	 * Constructor.
	 *
	 * @param CreatorPricingSettings $settings Pricing configuration.
	 * @param Logger                 $logger   Technical log.
	 */
	public function __construct(
		private CreatorPricingSettings $settings,
		private Logger $logger
	) {
	}

	/**
	 * Nonce action of an admin-post action for one creator.
	 *
	 * @param string $action     One of the *_ACTION constants.
	 * @param int    $creator_id Creator id.
	 */
	public static function nonce_action( string $action, int $creator_id ): string {
		return $action . '_' . $creator_id;
	}

	/**
	 * Registers the admin-post handlers.
	 */
	public function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'handle_request' ) );
		}
	}

	/**
	 * WordPress entry point for every pricing request.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in handle().
		exit;
	}

	/**
	 * Handles a request.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creator pricing.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$action     = self::scalar( $input, 'action' );
		$creator_id = absint( self::scalar( $input, 'creator_id' ) );
		$nonce      = self::scalar( $input, '_wpnonce' );

		if ( ! in_array( $action, self::ACTIONS, true )
			|| '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $action, $creator_id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$creator_level = in_array( $action, array( self::MARKUP_ACTION, self::VAT_ACTION ), true );
		$id            = absint( self::scalar( $input, $creator_level ? 'creator_id' : 'product_id' ) );

		try {
			$code = match ( $action ) {
				self::MARKUP_ACTION         => $this->settings->set_markup( $creator_id, sanitize_text_field( self::scalar( $input, 'markup' ) ) ) ? 'markup_saved' : 'markup_unchanged',
				self::VAT_ACTION            => $this->save_vat( $creator_id, $input ),
				self::PRODUCT_MARKUP_ACTION => $this->settings->set_product_markup( $id, $creator_id, sanitize_text_field( self::scalar( $input, 'product_markup' ) ) ) ? 'product_markup_saved' : 'product_markup_unchanged',
				default                     => $this->settings->remove_product_markup( $id, $creator_id ) ? 'product_markup_removed' : 'product_markup_none',
			};
		} catch ( PricingException $e ) {
			$code = $e->reason();
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Creator pricing administration failed.',
				array(
					'operation'  => $action,
					'creator_id' => $creator_id,
					'product_id' => $id,
					'error'      => $e::class,
				)
			);
			$code = 'error';
		}

		return self::back( $creator_id, array( $creator_level ? 0 : $id => $code ) );
	}

	/**
	 * Records a registration of the creator's VAT status in its history
	 * (ADR-062 K06): "1" (yes) or "0" (no), the date it applies from, the
	 * check date, the documentation reference and the reason. The service
	 * validates every value.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $input      Request data.
	 * @return string Outcome code.
	 * @throws PricingException When the service rejects the request.
	 */
	private function save_vat( int $creator_id, array $input ): string {
		$value = self::scalar( $input, 'vat_registered' );

		if ( '1' !== $value && '0' !== $value ) {
			return self::INVALID_VAT_STATUS;
		}

		return $this->settings->record_vat_status(
			$creator_id,
			'1' === $value,
			sanitize_text_field( self::scalar( $input, 'effective_from' ) ),
			sanitize_text_field( self::scalar( $input, 'checked_on' ) ),
			sanitize_text_field( self::scalar( $input, 'documentation_reference' ) ),
			sanitize_textarea_field( self::scalar( $input, 'reason' ) )
		);
	}

	/**
	 * Parses the outcomes of a redirect ("id:code,…"). Malformed parts are
	 * dropped; codes are checked against the message whitelist when shown.
	 *
	 * @param string $value Raw query value.
	 * @return array<int, string> Id (0 for the creator level) => code.
	 */
	public static function parse_results( string $value ): array {
		$results = array();

		foreach ( array_slice( explode( ',', $value ), 0, self::MAX_RESULTS ) as $part ) {
			if ( 1 === preg_match( '/^(\d{1,20}):([a-z_]{1,48})$/', $part, $matches ) ) {
				$results[ (int) $matches[1] ] = $matches[2];
			}
		}

		return $results;
	}

	/**
	 * The redirect back to the creator's pricing section.
	 *
	 * @param int                $creator_id Creator id.
	 * @param array<int, string> $results    Id => outcome code.
	 */
	private static function back( int $creator_id, array $results ): string {
		$parts = array();

		foreach ( $results as $id => $code ) {
			$parts[] = $id . ':' . $code;
		}

		return add_query_arg( array( self::RESULTS_ARG => implode( ',', $parts ) ), CreatorsAdminPage::edit_url( $creator_id ) ) . '#merchboard-pricing';
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
