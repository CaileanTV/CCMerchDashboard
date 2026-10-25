<?php
/**
 * Admin entry points of "view as creator".
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handlers of "view as creator" (phase 27, step 27.2, ADR-075):
 *
 * - START: capability → nonce bound to the creator → Impersonation::start()
 *   → the creator dashboard;
 * - END: nonce → Impersonation::end() → the creator's page in admin.
 *
 * Both are links with a nonce (like the creators list's status toggle).
 * Refusals come back as a whitelisted notice code (Post/Redirect/Get);
 * technical failures are logged and shown as a neutral notice.
 */
final class ImpersonationActions {

	public const START = 'merchboard_impersonate';
	public const END   = 'merchboard_impersonate_end';

	/**
	 * Notice after the session ended.
	 */
	public const ENDED_NOTICE = 'impersonation_ended';

	/**
	 * Constructor.
	 *
	 * @param Impersonation  $impersonation Sessions.
	 * @param DashboardRoute $route         The creator dashboard.
	 * @param Logger         $logger        Technical log.
	 */
	public function __construct( private Impersonation $impersonation, private DashboardRoute $route, private Logger $logger ) {
	}

	/**
	 * The link that starts viewing as a creator (raw; escape when printing).
	 *
	 * @param int $creator_id Creator.
	 */
	public static function start_url( int $creator_id ): string {
		return add_query_arg(
			array(
				'action'   => self::START,
				'creator'  => $creator_id,
				'_wpnonce' => wp_create_nonce( self::START . '_' . $creator_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The link that ends the session (raw: the dashboard uses it as an href).
	 */
	public static function end_url(): string {
		return add_query_arg(
			array(
				'action'   => self::END,
				'_wpnonce' => wp_create_nonce( self::END ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Registers the handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::START, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::END, array( $this, 'handle_end' ) );
	}

	/**
	 * WordPress entry point of START.
	 */
	public function handle_start(): void {
		wp_safe_redirect( $this->start( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and the id sanitized in start().
		exit;
	}

	/**
	 * WordPress entry point of END.
	 */
	public function handle_end(): void {
		wp_safe_redirect( $this->end( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in end().
		exit;
	}

	/**
	 * Starts a session.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function start( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$creator_id = absint( self::scalar( $input, 'creator' ) );
		self::require_nonce( $input, self::START . '_' . $creator_id );
		$back = CreatorsAdminPage::edit_url( $creator_id );

		try {
			$this->impersonation->start( $creator_id );
		} catch ( ImpersonationException $e ) {
			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, $e->reason(), $back );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Starting "view as creator" failed.', array_merge( $e->context(), array( 'creator_id' => $creator_id ) ) );

			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, 'error', $back );
		}

		return $this->route->url();
	}

	/**
	 * Ends the session.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function end( array $input ): string {
		self::require_nonce( $input, self::END );

		try {
			$creator_id = $this->impersonation->end( Impersonation::ENDED_BY_ADMIN );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Ending "view as creator" failed.', $e->context() );

			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, 'error', CreatorsAdminPage::url() );
		}

		return add_query_arg( CreatorsAdminPage::NOTICE_ARG, self::ENDED_NOTICE, null === $creator_id ? CreatorsAdminPage::url() : CreatorsAdminPage::edit_url( $creator_id ) );
	}

	/**
	 * Stops the request unless the nonce is valid for the action.
	 *
	 * @param array<mixed> $input  Request data.
	 * @param string       $action Nonce action.
	 */
	private static function require_nonce( array $input, string $action ): void {
		$nonce = self::scalar( $input, '_wpnonce' );

		if ( '' === $nonce || false === wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * A scalar request value as a string ('' otherwise).
	 *
	 * @param array<mixed> $input Request data.
	 * @param string       $key   Key.
	 */
	private static function scalar( array $input, string $key ): string {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
	}
}
