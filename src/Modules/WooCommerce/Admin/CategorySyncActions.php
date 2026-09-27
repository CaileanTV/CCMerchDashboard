<?php
/**
 * Starting the full category reconciliation (admin-post.php).
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
use MerchBoard\Modules\WooCommerce\CategoryReconciliationBatch;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationScheduler;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationTrigger;
use MerchBoard\Modules\WooCommerce\ReconciliationScope;

defined( 'ABSPATH' ) || exit;

/**
 * Starts ADR-033's full reconciliation after the admin confirmed a dry run.
 * Order of checks:
 *
 * 1. capability `merchboard_manage_creators` → 403 otherwise;
 * 2. nonce → 403 otherwise;
 * 3. the explicit confirmation (`confirm` = 1) and the fingerprint of the dry
 *    run the admin saw;
 * 4. a fresh dry run (CategoryReconciliationBatch::preview(), read-only): if
 *    it differs from the confirmed one, nothing starts and the admin gets the
 *    new preview, so a stale preview is never taken as authoritative;
 * 5. the run is queued through the existing Action Scheduler chain
 *    (CategoryReconciliationScheduler::run() with the full scope; each job
 *    runs one page through CategoryReconciliationBatch::run_page()), never
 *    in this request. Identical pending starts are deduplicated.
 *
 * Nothing here writes product meta, mappings or audit records.
 */
final class CategorySyncActions {

	/**
	 * The admin-post action that starts the full reconciliation.
	 */
	public const ACTION = 'merchboard_category_full_sync';

	/**
	 * The Creators page view (`action=category-sync`).
	 */
	public const VIEW = 'category-sync';

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_sync_result';

	/**
	 * Query argument: show a dry run.
	 */
	public const PREVIEW_ARG = 'preview';

	/**
	 * Constructor.
	 *
	 * @param CategoryReconciliationBatch     $batch     Dry runs.
	 * @param CategoryReconciliationScheduler $scheduler Queues the run.
	 * @param Logger                          $logger    Technical log.
	 */
	public function __construct(
		private CategoryReconciliationBatch $batch,
		private CategoryReconciliationScheduler $scheduler,
		private Logger $logger
	) {
	}

	/**
	 * The Creators page view with query arguments.
	 *
	 * @param array<string, int|string> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return CreatorsAdminPage::url( array( 'action' => self::VIEW ) + $args );
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in start() before any input is used.
		wp_safe_redirect( $this->start( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in start().
		exit;
	}

	/**
	 * Queues the full reconciliation after the confirmed dry run.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function start( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$nonce = self::scalar( $input, '_wpnonce' );

		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$fingerprint = self::scalar( $input, 'fingerprint' );

		if ( '1' !== self::scalar( $input, 'confirm' ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $fingerprint ) ) {
			return self::back( 'confirmation_required' );
		}

		$user_id = get_current_user_id();
		$user_id = $user_id > 0 ? $user_id : null;

		try {
			$current = $this->batch->preview( CategoryReconciliationTrigger::FullSync, $user_id );
		} catch ( DatabaseException | LogicException $e ) {
			$this->logger->error( 'The dry run before a full category reconciliation failed.', array( 'exception' => $e ) );

			return self::back( 'error' );
		}

		if ( ! hash_equals( $current->fingerprint(), $fingerprint ) ) {
			return self::back( 'preview_outdated' );
		}

		$queued = $this->scheduler->run( ReconciliationScope::all(), CategoryReconciliationTrigger::FullSync, $user_id );

		return self::back( $queued ? 'sync_started' : 'queue_unavailable', ! $queued );
	}

	/**
	 * The redirect back to the view.
	 *
	 * @param string $result  Outcome code.
	 * @param bool   $preview Whether to show a (new) dry run.
	 */
	private static function back( string $result, bool $preview = true ): string {
		$args = array( self::RESULT_ARG => $result );

		if ( $preview && 'sync_started' !== $result ) {
			$args[ self::PREVIEW_ARG ] = 1;
		}

		return self::url( $args );
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
