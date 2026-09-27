<?php
/**
 * State-changing requests of the creator's product links (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductCreatorLinkException;
use MerchBoard\Modules\WooCommerce\ProductLinkSource;

defined( 'ABSPATH' ) || exit;

/**
 * Links products to a creator, removes links and keeps category-managed
 * links as manual, from the creator's edit page (ADR-032, ADR-033). Order
 * of checks for every request:
 *
 * 1. capability `merchboard_manage_creators` → 403 otherwise;
 * 2. nonce, bound to the action AND the creator id → 403 otherwise;
 * 3. input: absint ids only, at most MAX_PRODUCTS per request; "Keep as
 *    manual" also needs the explicit confirmation (`confirm` = 1) and a
 *    product currently linked to this creator;
 * 4. ProductCreatorLink for each product, which checks `edit_product` for
 *    that product and every business rule, and writes the audit record.
 *
 * Nothing here writes product meta or audit records, and no rule is decided
 * here: a product linked to another creator is only taken over when the
 * admin ticked "take over" for exactly that product.
 *
 * The outcome of every product is reported as "id:code" in the redirect URL
 * (Post/Redirect/Get). Codes are whitelisted when rendered; technical
 * failures are logged with safe context and shown neutrally.
 */
final class CreatorProductsActions {

	/**
	 * The admin-post action that links selected products.
	 */
	public const LINK_ACTION = 'merchboard_link_creator_products';

	/**
	 * The admin-post action that removes one product link.
	 */
	public const UNLINK_ACTION = 'merchboard_unlink_creator_product';

	/**
	 * The admin-post action that keeps one category-managed link as manual.
	 */
	public const KEEP_ACTION = 'merchboard_keep_creator_product_manual';

	/**
	 * Outcome when the explicit confirmation is missing.
	 */
	public const CONFIRMATION_REQUIRED = 'confirmation_required';

	/**
	 * Query argument carrying the per-product outcomes after a redirect.
	 */
	public const RESULTS_ARG = 'merchboard_product_results';

	/**
	 * Query argument of the product search on the edit page.
	 */
	public const SEARCH_ARG = 'product_search';

	/**
	 * Most products handled in one request.
	 */
	public const MAX_PRODUCTS = 50;

	/**
	 * Outcome when nothing was selected.
	 */
	public const NO_SELECTION = 'no_selection';

	/**
	 * Constructor.
	 *
	 * @param ProductCreatorLink $link   The authoritative product link service.
	 * @param Logger             $logger Technical log.
	 */
	public function __construct(
		private ProductCreatorLink $link,
		private Logger $logger
	) {
	}

	/**
	 * Nonce action for linking products to one creator.
	 *
	 * @param int $creator_id Creator id.
	 */
	public static function link_nonce_action( int $creator_id ): string {
		return self::LINK_ACTION . '_' . $creator_id;
	}

	/**
	 * Nonce action for removing product links of one creator.
	 *
	 * @param int $creator_id Creator id.
	 */
	public static function unlink_nonce_action( int $creator_id ): string {
		return self::UNLINK_ACTION . '_' . $creator_id;
	}

	/**
	 * Nonce action for keeping links of one creator as manual.
	 *
	 * @param int $creator_id Creator id.
	 */
	public static function keep_nonce_action( int $creator_id ): string {
		return self::KEEP_ACTION . '_' . $creator_id;
	}

	/**
	 * Registers the admin-post handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::LINK_ACTION, array( $this, 'handle_link_request' ) );
		add_action( 'admin_post_' . self::UNLINK_ACTION, array( $this, 'handle_unlink_request' ) );
		add_action( 'admin_post_' . self::KEEP_ACTION, array( $this, 'handle_keep_request' ) );
	}

	/**
	 * WordPress entry point for "Keep as manual".
	 */
	public function handle_keep_request(): void {
		// The nonce is verified in keep_as_manual() before any input is used.
		wp_safe_redirect( $this->keep_as_manual( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in keep_as_manual().
		exit;
	}

	/**
	 * WordPress entry point for linking.
	 */
	public function handle_link_request(): void {
		// The nonce is verified in link() before any input is used.
		wp_safe_redirect( $this->link( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in link().
		exit;
	}

	/**
	 * WordPress entry point for removing a link.
	 */
	public function handle_unlink_request(): void {
		// The nonce is verified in unlink() before any input is used.
		wp_safe_redirect( $this->unlink( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in unlink().
		exit;
	}

	/**
	 * Links the selected products to the creator.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function link( array $input ): string {
		$this->require_capability();

		$creator_id = absint( self::scalar( $input, 'creator_id' ) );
		$this->require_nonce( $input, self::link_nonce_action( $creator_id ) );

		$products = self::ids( $input['products'] ?? null );
		$replace  = self::ids( $input['replace'] ?? null );
		$search   = sanitize_text_field( self::scalar( $input, self::SEARCH_ARG ) );

		if ( array() === $products ) {
			return $this->back( $creator_id, array( 0 => self::NO_SELECTION ), $search );
		}

		$results = array();

		foreach ( $products as $product_id ) {
			try {
				$changes = $this->link->link( $product_id, $creator_id, ProductLinkSource::Admin, in_array( $product_id, $replace, true ) );

				if ( array() === $changes ) {
					$results[ $product_id ] = 'unchanged';
				} else {
					$results[ $product_id ] = in_array( AuditAction::ProductCreatorUnlinked, $changes, true ) ? 'replaced' : 'linked';
				}
			} catch ( ProductCreatorLinkException $e ) {
				$results[ $product_id ] = $e->reason();
			} catch ( DatabaseException $e ) {
				$results[ $product_id ] = $this->technical_failure( $e, 'link', $creator_id, $product_id );
			}
		}

		return $this->back( $creator_id, $results, $search );
	}

	/**
	 * Removes one product's link.
	 *
	 * The link is removed from the product whatever creator it points at, so
	 * the request is only accepted for a product that is currently linked to
	 * this creator (or holds an invalid link that shows up on this page).
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function unlink( array $input ): string {
		$this->require_capability();

		$creator_id = absint( self::scalar( $input, 'creator_id' ) );
		$this->require_nonce( $input, self::unlink_nonce_action( $creator_id ) );

		$product_id = absint( self::scalar( $input, 'product_id' ) );

		if ( $product_id < 1 ) {
			return $this->back( $creator_id, array( 0 => self::NO_SELECTION ), '' );
		}

		$current = $this->link->creator_id( $product_id );

		if ( null !== $current && $current !== $creator_id ) {
			return $this->back( $creator_id, array( $product_id => 'linked_elsewhere' ), '' );
		}

		try {
			$changes = $this->link->unlink( $product_id, ProductLinkSource::Admin );
			$result  = array() === $changes ? 'unchanged' : 'unlinked';
		} catch ( ProductCreatorLinkException $e ) {
			$result = $e->reason();
		} catch ( DatabaseException $e ) {
			$result = $this->technical_failure( $e, 'unlink', $creator_id, $product_id );
		}

		return $this->back( $creator_id, array( $product_id => $result ), '' );
	}

	/**
	 * Keeps one product's category-managed link to the creator as a manual
	 * link (ADR-033 point 4), after the admin's explicit confirmation. The
	 * creator stays; only the origin becomes `admin`. ProductCreatorLink
	 * checks `edit_product`, that the link is a link to exactly this creator,
	 * writes through the approved manual context and records the audit.
	 * Accepted only for a product currently linked to this creator.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function keep_as_manual( array $input ): string {
		$this->require_capability();

		$creator_id = absint( self::scalar( $input, 'creator_id' ) );
		$this->require_nonce( $input, self::keep_nonce_action( $creator_id ) );

		$product_id = absint( self::scalar( $input, 'product_id' ) );

		if ( $product_id < 1 ) {
			return $this->back( $creator_id, array( 0 => self::NO_SELECTION ), '' );
		}

		if ( '1' !== self::scalar( $input, 'confirm' ) ) {
			return $this->back( $creator_id, array( $product_id => self::CONFIRMATION_REQUIRED ), '' );
		}

		if ( $this->link->creator_id( $product_id ) !== $creator_id ) {
			return $this->back( $creator_id, array( $product_id => 'linked_elsewhere' ), '' );
		}

		try {
			$result = array() === $this->link->keep_as_manual( $product_id, $creator_id ) ? 'unchanged' : 'kept_manual';
		} catch ( ProductCreatorLinkException $e ) {
			$result = $e->reason();
		} catch ( DatabaseException $e ) {
			$result = $this->technical_failure( $e, 'keep_as_manual', $creator_id, $product_id );
		}

		return $this->back( $creator_id, array( $product_id => $result ), '' );
	}

	/**
	 * Parses the outcomes of a redirect ("id:code,…"). Malformed parts are
	 * dropped; codes are checked against the message whitelist when shown.
	 *
	 * @param string $value Raw query value.
	 * @return array<int, string> Product id (0 for request-level outcomes) => code.
	 */
	public static function parse_results( string $value ): array {
		$results = array();

		foreach ( array_slice( explode( ',', $value ), 0, self::MAX_PRODUCTS ) as $part ) {
			if ( 1 === preg_match( '/^(\d{1,20}):([a-z_]{1,40})$/', $part, $matches ) ) {
				$results[ (int) $matches[1] ] = $matches[2];
			}
		}

		return $results;
	}

	/**
	 * The redirect back to the creator's product section.
	 *
	 * @param int                $creator_id Creator id.
	 * @param array<int, string> $results    Product id => outcome code.
	 * @param string             $search     Search term to keep.
	 */
	private function back( int $creator_id, array $results, string $search ): string {
		$parts = array();

		foreach ( $results as $product_id => $code ) {
			$parts[] = $product_id . ':' . $code;
		}

		$args = array( self::RESULTS_ARG => implode( ',', $parts ) );

		if ( '' !== $search ) {
			$args[ self::SEARCH_ARG ] = $search;
		}

		return add_query_arg( $args, CreatorsAdminPage::edit_url( $creator_id ) ) . '#merchboard-products';
	}

	/**
	 * Stops the request unless the user may manage creators.
	 */
	private function require_capability(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Stops the request unless the nonce is valid for this action and creator.
	 *
	 * @param array<mixed> $input  Request data.
	 * @param string       $action Nonce action.
	 */
	private function require_nonce( array $input, string $action ): void {
		$nonce = self::scalar( $input, '_wpnonce' );

		if ( '' === $nonce || false === wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Logs a technical failure and returns the neutral outcome code.
	 *
	 * @param DatabaseException $e          Failure.
	 * @param string            $operation  Operation name.
	 * @param int               $creator_id Creator id.
	 * @param int               $product_id Product id.
	 */
	private function technical_failure( DatabaseException $e, string $operation, int $creator_id, int $product_id ): string {
		$this->logger->error(
			'Product link administration failed.',
			array_merge(
				$e->context(),
				array(
					'operation'  => $operation,
					'creator_id' => $creator_id,
					'product_id' => $product_id,
					'reason'     => $e->getMessage(),
				)
			)
		);

		return 'error';
	}

	/**
	 * Distinct positive ids from a request array, at most MAX_PRODUCTS.
	 *
	 * @param mixed $value Request value.
	 * @return list<int>
	 */
	private static function ids( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( $value as $item ) {
			$id = is_scalar( $item ) ? absint( $item ) : 0;

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return array_slice( $ids, 0, self::MAX_PRODUCTS );
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
