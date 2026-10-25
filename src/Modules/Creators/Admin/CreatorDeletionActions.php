<?php
/**
 * Admin entry point of deleting a creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorDeletion;
use MerchBoard\Modules\Creators\CreatorDeletionException;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handler of "Delete creator" (phase 27, step 27.3,
 * ADR-076): POST only; capability → nonce bound to the creator → the typed
 * name → CreatorDeletion. Post/Redirect/Get with a whitelisted notice;
 * technical failures are logged and shown as a neutral notice.
 */
final class CreatorDeletionActions {

	public const ACTION = 'merchboard_delete_creator';

	/**
	 * The field with the typed name.
	 */
	public const CONFIRM_FIELD = 'confirm_name';

	/**
	 * Notices after a deletion.
	 */
	public const DELETED_NOTICE    = 'creator_deleted';
	public const ANONYMIZED_NOTICE = 'creator_anonymized';

	/**
	 * Constructor.
	 *
	 * @param CreatorDeletion $deletion Deletions.
	 * @param Logger          $logger   Technical log.
	 */
	public function __construct( private CreatorDeletion $deletion, private Logger $logger ) {
	}

	/**
	 * The nonce action of one creator's deletion.
	 *
	 * @param int $creator_id Creator.
	 */
	public static function nonce_action( int $creator_id ): string {
		return self::ACTION . '_' . $creator_id;
	}

	/**
	 * The confirmation page of a creator's deletion.
	 *
	 * @param int $creator_id Creator.
	 */
	public static function confirm_url( int $creator_id ): string {
		return CreatorsAdminPage::url(
			array(
				'action'  => 'delete',
				'creator' => $creator_id,
			)
		);
	}

	/**
	 * Registers the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		wp_safe_redirect( $this->process( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in process().
		exit;
	}

	/**
	 * Deletes the creator of the form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function process( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$creator_id = absint( self::scalar( $input, 'creator_id' ) );
		$nonce      = self::scalar( $input, '_wpnonce' );

		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $creator_id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			$mode = $this->deletion->delete( $creator_id, sanitize_text_field( self::scalar( $input, self::CONFIRM_FIELD ) ) );
		} catch ( CreatorDeletionException $e ) {
			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, $e->reason(), self::confirm_url( $creator_id ) );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Deleting a creator failed.', array_merge( $e->context(), array( 'creator_id' => $creator_id ) ) );

			return add_query_arg( CreatorsAdminPage::NOTICE_ARG, 'error', self::confirm_url( $creator_id ) );
		}

		return add_query_arg( CreatorsAdminPage::NOTICE_ARG, CreatorDeletion::MODE_DELETED === $mode ? self::DELETED_NOTICE : self::ANONYMIZED_NOTICE, CreatorsAdminPage::url() );
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
