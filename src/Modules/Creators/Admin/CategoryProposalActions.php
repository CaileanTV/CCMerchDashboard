<?php
/**
 * Admin decisions on category proposals.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalException;
use MerchBoard\Modules\Creators\Proposals\CategoryProposals;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handlers of the "Category proposals" page (phase 28, step
 * 28.4, ADR-077): approve or reject one proposal (nonce bound to the
 * proposal) and approve all of a creator's (nonce bound to the creator).
 * POST only; capability → nonce → CategoryProposals. Post/Redirect/Get with
 * a whitelisted outcome code; technical failures are logged and shown as a
 * neutral notice.
 */
final class CategoryProposalActions {

	public const APPROVE     = 'merchboard_approve_category_proposal';
	public const REJECT      = 'merchboard_reject_category_proposal';
	public const APPROVE_ALL = 'merchboard_approve_category_proposals';

	/**
	 * The field with the note on a rejection.
	 */
	public const NOTE_FIELD = 'note';

	/**
	 * Constructor.
	 *
	 * @param CategoryProposals $proposals Proposals.
	 * @param Logger            $logger    Technical log.
	 */
	public function __construct( private CategoryProposals $proposals, private Logger $logger ) {
	}

	/**
	 * The nonce action of a decision on one proposal (approve or reject).
	 *
	 * @param string $action      APPROVE or REJECT.
	 * @param int    $proposal_id Proposal id.
	 */
	public static function nonce_action( string $action, int $proposal_id ): string {
		return $action . '_' . $proposal_id;
	}

	/**
	 * Registers the handlers.
	 */
	public function register(): void {
		foreach ( array( self::APPROVE, self::REJECT, self::APPROVE_ALL ) as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'handle_request' ) );
		}
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		wp_safe_redirect( $this->process( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in process().
		exit;
	}

	/**
	 * Decides the proposal(s) of the form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function process( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$action = self::scalar( $input, 'action' );
		$id     = absint( self::scalar( $input, self::APPROVE_ALL === $action ? 'creator_id' : 'proposal_id' ) );
		$nonce  = self::scalar( $input, '_wpnonce' );

		if ( ! in_array( $action, array( self::APPROVE, self::REJECT, self::APPROVE_ALL ), true ) || '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $action, $id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$approved = 0;

		try {
			if ( self::APPROVE === $action ) {
				$this->proposals->approve( $id );

				return self::result( 'approved' );
			}

			if ( self::REJECT === $action ) {
				$this->proposals->reject( $id, self::scalar( $input, self::NOTE_FIELD ) );

				return self::result( 'rejected' );
			}

			foreach ( $this->proposals->pending( $id ) as $proposal ) {
				$this->proposals->approve( $proposal->id );
				++$approved;
			}

			return self::result( 'approved_all' );
		} catch ( CategoryProposalException $e ) {
			return self::result( $approved > 0 ? 'approved_some' : $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Deciding a category proposal failed.', array_merge( $e->context(), array( 'id' => $id ) ) );

			return self::result( $approved > 0 ? 'approved_some' : 'error' );
		}
	}

	/**
	 * The page with an outcome code.
	 *
	 * @param string $code Outcome code.
	 */
	private static function result( string $code ): string {
		return CategoryProposalsPage::url( array( CategoryProposalsPage::RESULT_ARG => $code ) );
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
