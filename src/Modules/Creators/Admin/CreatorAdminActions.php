<?php
/**
 * State-changing creator admin requests (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorException;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\UserAccounts;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the creator form (POST) and the activate/deactivate row action
 * (GET link with nonce). Order of checks for every request:
 *
 * 1. capability (`merchboard_manage_creators`) → 403 otherwise;
 * 2. nonce, bound to the action AND the creator id → 403 otherwise;
 * 3. input: absint ids, whitelisted status, sanitized text;
 * 4. CreatorService (checks the capability again, validates business rules,
 *    writes change + audit record in one transaction).
 *
 * Results are reported with a whitelisted notice code in the redirect URL
 * (Post/Redirect/Get); no user input, SQL or exception text reaches the UI.
 * Technical failures are logged with safe context and shown as a neutral notice.
 */
final class CreatorAdminActions {

	/**
	 * The admin-post action for create/edit.
	 */
	public const SAVE_ACTION = 'merchboard_save_creator';

	/**
	 * The admin-post action for activate/deactivate.
	 */
	public const STATUS_ACTION = 'merchboard_set_creator_status';

	/**
	 * Form field for the WordPress user: a login, "#<user id>", or empty for
	 * no user. Resolved to a user id server-side; the service then checks that
	 * the user exists and is not linked elsewhere.
	 */
	public const USER_FIELD = 'user';

	/**
	 * Constructor.
	 *
	 * @param CreatorService $service Use cases.
	 * @param UserAccounts   $users   User lookups (login → id).
	 * @param Logger         $logger  Technical log.
	 */
	public function __construct(
		private CreatorService $service,
		private UserAccounts $users,
		private Logger $logger
	) {
	}

	/**
	 * Nonce action for the form of one creator (0 = new creator).
	 *
	 * @param int $creator_id Creator id.
	 */
	public static function save_nonce_action( int $creator_id ): string {
		return self::SAVE_ACTION . '_' . $creator_id;
	}

	/**
	 * Nonce action for a status link of one creator.
	 *
	 * @param int $creator_id Creator id.
	 */
	public static function status_nonce_action( int $creator_id ): string {
		return self::STATUS_ACTION . '_' . $creator_id;
	}

	/**
	 * Registers the admin-post handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_request' ) );
		add_action( 'admin_post_' . self::STATUS_ACTION, array( $this, 'handle_status_request' ) );
	}

	/**
	 * WordPress entry point for the form.
	 */
	public function handle_save_request(): void {
		// The nonce is verified in save() before any input is used.
		wp_safe_redirect( $this->save( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in save().
		exit;
	}

	/**
	 * WordPress entry point for the status row action.
	 */
	public function handle_status_request(): void {
		// The nonce is verified in set_status() before any input is used.
		wp_safe_redirect( $this->set_status( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in set_status().
		exit;
	}

	/**
	 * Processes the create/edit form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save( array $input ): string {
		$this->require_capability();

		$creator_id = absint( self::scalar( $input, 'creator_id' ) );
		$this->require_nonce( $input, self::save_nonce_action( $creator_id ) );

		$display_name = sanitize_text_field( self::scalar( $input, 'display_name' ) );
		$status       = CreatorStatus::tryFrom( sanitize_key( self::scalar( $input, 'status' ) ) );
		$user_id      = $this->user_id( self::scalar( $input, self::USER_FIELD ) );
		$back         = 0 === $creator_id ? CreatorsAdminPage::url( array( 'action' => 'new' ) ) : CreatorsAdminPage::edit_url( $creator_id );

		if ( null === $status ) {
			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, CreatorException::INVALID_STATUS, $back );
		}

		if ( 0 === $user_id ) {
			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, CreatorException::USER_NOT_FOUND, $back );
		}

		try {
			if ( 0 === $creator_id ) {
				$creator = $this->service->create( $display_name, $status, $user_id );

				return add_query_arg( CreatorsAdminPage::NOTICE_ARG, 'created', CreatorsAdminPage::edit_url( $creator->id ) );
			}

			$changes = $this->service->update( $creator_id, $display_name, $status, $user_id );

			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, array() === $changes ? 'no_changes' : 'updated', $back );
		} catch ( CreatorException $e ) {
			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, $e->reason(), $back );
		} catch ( DatabaseException $e ) {
			return $this->technical_failure( $e, 'save', $creator_id, $back );
		}
	}

	/**
	 * Resolves the user field to a user id.
	 *
	 * @param string $value Raw field value.
	 * @return int|null Null for "no user", 0 for an unknown user.
	 */
	private function user_id( string $value ): ?int {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		if ( 1 === preg_match( '/^#(\d+)$/', $value, $matches ) ) {
			return absint( $matches[1] );
		}

		return $this->users->find_by_login( sanitize_user( $value ) ) ?? 0;
	}

	/**
	 * Processes the activate/deactivate row action.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function set_status( array $input ): string {
		$this->require_capability();

		$creator_id = absint( self::scalar( $input, 'creator' ) );
		$this->require_nonce( $input, self::status_nonce_action( $creator_id ) );

		$status = CreatorStatus::tryFrom( sanitize_key( self::scalar( $input, 'status' ) ) );
		$list   = CreatorsAdminPage::url();

		if ( null === $status ) {
			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, CreatorException::INVALID_STATUS, $list );
		}

		try {
			$changes = $this->service->set_status( $creator_id, $status );
		} catch ( CreatorException $e ) {
			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, $e->reason(), $list );
		} catch ( DatabaseException $e ) {
			return $this->technical_failure( $e, 'set_status', $creator_id, $list );
		}

		$notice = array() === $changes ? 'no_changes' : ( CreatorStatus::Active === $status ? 'activated' : 'deactivated' );

		return add_query_arg( CreatorsAdminPage::NOTICE_ARG, $notice, $list );
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
	 * Logs a technical failure once and returns the neutral-error redirect.
	 *
	 * @param DatabaseException $e          Failure.
	 * @param string            $operation  Operation name.
	 * @param int               $creator_id Creator id (0 when creating).
	 * @param string            $back       Redirect target.
	 */
	private function technical_failure( DatabaseException $e, string $operation, int $creator_id, string $back ): string {
		$this->logger->error(
			'Creator administration failed.',
			array_merge(
				$e->context(),
				array(
					'operation'  => $operation,
					'creator_id' => $creator_id,
					'reason'     => $e->getMessage(),
				)
			)
		);

		return add_query_arg( CreatorsAdminPage::NOTICE_ARG, 'error', $back );
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
