<?php
/**
 * State-changing requests of the creator's product category (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use LogicException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\WooCommerce\CategoryMappingRemoval;
use MerchBoard\Modules\WooCommerce\CreatorCategoryException;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\MappingRemovalChoice;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes a creator ↔ product category mapping from the
 * creator's edit page (ADR-033 point 10). Order of checks for every request:
 *
 * 1. capability `merchboard_manage_creators` → 403 otherwise;
 * 2. nonce, bound to the action AND the creator id → 403 otherwise;
 * 3. input: absint ids; for a removal a known choice;
 * 4. the explicit confirmation of the dry run the admin was shown
 *    (`confirm` = 1); without it nothing changes;
 * 5. the services, which check every rule again.
 *
 * Nothing here writes a mapping, product meta or audit record itself, and
 * no rule is decided here. The outcome is reported as codes in the redirect
 * URL (Post/Redirect/Get); codes are whitelisted when rendered.
 */
final class CreatorCategoriesActions {

	/**
	 * The admin-post action that maps a category after the dry run.
	 */
	public const MAP_ACTION = 'merchboard_map_creator_category';

	/**
	 * The admin-post action that removes a mapping with a choice.
	 */
	public const UNMAP_ACTION = 'merchboard_unmap_creator_category';

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_category_result';

	/**
	 * Query argument: "converted,failed" counts of a "Keep as manual".
	 */
	public const COUNTS_ARG = 'merchboard_category_counts';

	/**
	 * Query argument: dry run of a new mapping for this term.
	 */
	public const PREVIEW_ARG = 'category_preview';

	/**
	 * Query argument: dry run of removing the mapping of this term.
	 */
	public const REMOVE_ARG = 'category_remove';

	/**
	 * Outcome when the explicit confirmation is missing.
	 */
	public const CONFIRMATION_REQUIRED = 'confirmation_required';

	/**
	 * Outcome when a removal has no valid choice.
	 */
	public const CHOICE_REQUIRED = 'choice_required';

	/**
	 * Constructor.
	 *
	 * @param CreatorCategoryService $mappings Mapping writes.
	 * @param CategoryMappingRemoval $removal  Removal with a choice.
	 * @param Logger                 $logger   Technical log.
	 */
	public function __construct(
		private CreatorCategoryService $mappings,
		private CategoryMappingRemoval $removal,
		private Logger $logger
	) {
	}

	/**
	 * Nonce action for mapping a category to one creator.
	 *
	 * @param int $creator_id Creator id.
	 */
	public static function map_nonce_action( int $creator_id ): string {
		return self::MAP_ACTION . '_' . $creator_id;
	}

	/**
	 * Nonce action for removing a mapping of one creator.
	 *
	 * @param int $creator_id Creator id.
	 */
	public static function unmap_nonce_action( int $creator_id ): string {
		return self::UNMAP_ACTION . '_' . $creator_id;
	}

	/**
	 * Registers the admin-post handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::MAP_ACTION, array( $this, 'handle_map_request' ) );
		add_action( 'admin_post_' . self::UNMAP_ACTION, array( $this, 'handle_unmap_request' ) );
	}

	/**
	 * WordPress entry point for mapping.
	 */
	public function handle_map_request(): void {
		// The nonce is verified in map() before any input is used.
		wp_safe_redirect( $this->map( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in map().
		exit;
	}

	/**
	 * WordPress entry point for removing a mapping.
	 */
	public function handle_unmap_request(): void {
		// The nonce is verified in unmap() before any input is used.
		wp_safe_redirect( $this->unmap( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in unmap().
		exit;
	}

	/**
	 * Maps the category after the admin confirmed the dry run.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function map( array $input ): string {
		$this->require_capability();

		$creator_id = absint( self::scalar( $input, 'creator_id' ) );
		$this->require_nonce( $input, self::map_nonce_action( $creator_id ) );

		$term_id = absint( self::scalar( $input, 'term_id' ) );

		if ( '1' !== self::scalar( $input, 'confirm' ) ) {
			return self::back( $creator_id, self::CONFIRMATION_REQUIRED, array( self::PREVIEW_ARG => $term_id ) );
		}

		try {
			$result = $this->mappings->link( $creator_id, $term_id ) ? 'mapped' : 'unchanged';
		} catch ( CreatorCategoryException $e ) {
			$result = $e->reason();
		} catch ( DatabaseException $e ) {
			$result = $this->technical_failure( $e, 'map', $creator_id, $term_id );
		}

		return self::back( $creator_id, $result );
	}

	/**
	 * Removes the mapping with the admin's explicit choice, after the
	 * confirmation of the dry run.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function unmap( array $input ): string {
		$this->require_capability();

		$creator_id = absint( self::scalar( $input, 'creator_id' ) );
		$this->require_nonce( $input, self::unmap_nonce_action( $creator_id ) );

		$term_id = absint( self::scalar( $input, 'term_id' ) );
		$choice  = MappingRemovalChoice::tryFrom( self::scalar( $input, 'choice' ) );
		$again   = array( self::REMOVE_ARG => $term_id );

		if ( null === $choice ) {
			return self::back( $creator_id, self::CHOICE_REQUIRED, $again );
		}

		if ( '1' !== self::scalar( $input, 'confirm' ) ) {
			return self::back( $creator_id, self::CONFIRMATION_REQUIRED, $again );
		}

		try {
			if ( MappingRemovalChoice::RemoveLinks === $choice ) {
				return self::back( $creator_id, $this->removal->remove_links( $creator_id, $term_id ) ? 'removed' : 'unchanged' );
			}

			$kept = $this->removal->keep_as_manual( $creator_id, $term_id );
		} catch ( CreatorCategoryException $e ) {
			return self::back( $creator_id, $e->reason() );
		} catch ( DatabaseException | LogicException $e ) {
			return self::back( $creator_id, $this->technical_failure( $e, 'unmap', $creator_id, $term_id ) );
		}

		if ( ! $kept->found ) {
			return self::back( $creator_id, 'unchanged' );
		}

		$counts = array( self::COUNTS_ARG => $kept->converted . ',' . count( $kept->failed ) );

		return $kept->removed
			? self::back( $creator_id, 'kept_removed', $counts )
			: self::back( $creator_id, 'kept_partial', $counts + $again );
	}

	/**
	 * Parses the "converted,failed" counts of a redirect.
	 *
	 * @param string $value Raw query value.
	 * @return array{0: int, 1: int}|null
	 */
	public static function parse_counts( string $value ): ?array {
		return 1 === preg_match( '/^(\d{1,9}),(\d{1,9})$/', $value, $m ) ? array( (int) $m[1], (int) $m[2] ) : null;
	}

	/**
	 * The redirect back to the creator's category section.
	 *
	 * @param int                   $creator_id Creator id.
	 * @param string                $result     Outcome code.
	 * @param array<string, scalar> $args       Further query arguments.
	 */
	private static function back( int $creator_id, string $result, array $args = array() ): string {
		return add_query_arg( array( self::RESULT_ARG => $result ) + $args, CreatorsAdminPage::edit_url( $creator_id ) ) . '#merchboard-categories';
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
	 * @param DatabaseException|LogicException $e          Failure.
	 * @param string                           $operation  Operation name.
	 * @param int                              $creator_id Creator id.
	 * @param int                              $term_id    Term id.
	 */
	private function technical_failure( DatabaseException|LogicException $e, string $operation, int $creator_id, int $term_id ): string {
		$this->logger->error(
			'Product category administration failed.',
			array(
				'operation'  => $operation,
				'creator_id' => $creator_id,
				'term_id'    => $term_id,
				'exception'  => $e,
			)
		);

		return 'error';
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
