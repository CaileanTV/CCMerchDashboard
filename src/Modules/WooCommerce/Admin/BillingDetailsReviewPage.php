<?php
/**
 * The "Master data" tab on Proposals.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\Approvals\ApprovalsPage;
use MerchBoard\Modules\Admin\Approvals\ApprovalTab;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\BillingDetailsReview;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetails;

defined( 'ABSPATH' ) || exit;

/**
 * Master data creators submitted for settlement documents (ADR-104): the
 * submitted values beside the confirmed ones with the changed fields marked,
 * whether a first payout waits, and "Confirm" or "Ask for corrections" with
 * a note. Only `merchboard_manage_payouts`. Submissions the creator is asked
 * to correct are listed below, without actions.
 */
final class BillingDetailsReviewPage {

	/**
	 * The tab on "Merch Dashboard → Proposals".
	 */
	public const TAB = 'master-data';

	/**
	 * Query argument of the outcome notice.
	 */
	public const RESULT_ARG = 'merchboard_billing_review_result';

	/**
	 * Constructor.
	 *
	 * @param BillingDetailsReview $review   Master data and their review.
	 * @param CreatorRepository    $creators Creators.
	 * @param Logger               $logger   Technical log.
	 */
	public function __construct( private BillingDetailsReview $review, private CreatorRepository $creators, private Logger $logger ) {
	}

	/**
	 * URL of the tab.
	 *
	 * @param array<string, int|string> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return ApprovalsPage::url( self::TAB, $args );
	}

	/**
	 * The fields' labels.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'type'       => __( 'Paid as', 'merchboard' ),
			'legal_name' => __( 'Legal name (person or business)', 'merchboard' ),
			'address_1'  => __( 'Address', 'merchboard' ),
			'address_2'  => __( 'Address, line 2', 'merchboard' ),
			'postcode'   => __( 'Postcode', 'merchboard' ),
			'city'       => __( 'City', 'merchboard' ),
			'country'    => __( 'Country', 'merchboard' ),
			'cvr'        => __( 'CVR/SE number (if any)', 'merchboard' ),
		);
	}

	/**
	 * How a creator is paid, in words (ADR-104).
	 *
	 * @param string $type CreatorBillingDetails::BUSINESS, ::PRIVATE or ''.
	 */
	public static function type_label( string $type ): string {
		return match ( $type ) {
			CreatorBillingDetails::BUSINESS => __( 'Registered business (CVR/SE number)', 'merchboard' ),
			CreatorBillingDetails::PRIVATE  => __( 'Private person (CPR number with each payout)', 'merchboard' ),
			default                         => '',
		};
	}

	/**
	 * Message and type of an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'confirmed'                                    => array( __( 'The master data are confirmed. New settlement documents use them, and a waiting first payout can now be completed.', 'merchboard' ), 'success' ),
			'changes_requested'                            => array( __( 'The creator is asked to correct the master data and sees your note.', 'merchboard' ), 'success' ),
			PayoutException::INVALID_NOTE                  => array( __( 'Write what the creator should correct (at most 500 characters).', 'merchboard' ), 'error' ),
			PayoutException::BILLING_SUBMISSION_MISSING    => array( __( 'These master data are no longer waiting.', 'merchboard' ), 'warning' ),
			PayoutException::BILLING_SUBMISSION_STALE      => array( __( 'The creator changed the master data after this page was shown. Check them again below.', 'merchboard' ), 'warning' ),
			PayoutException::BUSY                          => array( __( 'Another payout action for this creator is running. Please try again in a moment.', 'merchboard' ), 'warning' ),
			PayoutException::NOT_AUTHORIZED                => array( __( 'Sorry, you are not allowed to manage payouts.', 'merchboard' ), 'error' ),
			PayoutException::NOT_FOUND                     => array( __( 'The creator could not be found.', 'merchboard' ), 'error' ),
			'error'                                        => array( __( 'A technical error occurred. Nothing was changed. Please try again later.', 'merchboard' ), 'error' ),
			default                                        => null,
		};
	}

	/**
	 * The tab with the number waiting.
	 */
	public function approval_tab(): ApprovalTab {
		return new ApprovalTab( self::TAB, __( 'Master data', 'merchboard' ), Capabilities::MANAGE_PAYOUTS, fn(): int => count( $this->review->waiting() ), fn() => $this->render() );
	}

	/**
	 * Renders the tab.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage payouts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		echo '<div class="merchboard-billing-review">';
		echo '<h2>' . esc_html__( 'Master data for settlement documents', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p class="description">' . esc_html__( 'Master data creators filled in themselves. A creator\'s first payout can only be completed once you have confirmed them; later changes do not hold payouts back, and until you confirm them the confirmed master data are used. The CVR/SE number is only checked for its format (eight digits), not looked up.', 'merchboard' ) . '</p>';

		try {
			$ids = $this->review->waiting();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Reading the submitted master data failed.', $e->context() );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The master data could not be read. Please try again later.', 'merchboard' ) . '</p></div></div>';

			return;
		}

		if ( array() === $ids ) {
			echo '<p>' . esc_html__( 'No master data are waiting.', 'merchboard' ) . '</p></div>';

			return;
		}

		foreach ( $ids as $creator_id ) {
			$this->render_creator( $creator_id );
		}

		echo '</div>';
	}

	/**
	 * One creator's submission with the decisions.
	 *
	 * @param int $creator_id Creator.
	 */
	private function render_creator( int $creator_id ): void {
		$creator = $this->creators->find( $creator_id );

		try {
			$state = $this->review->state( $creator_id );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Reading a creator\'s master data failed.', array_merge( $e->context(), array( 'creator_id' => $creator_id ) ) );

			return;
		}

		if ( null === $creator || null === $state->submission ) {
			return;
		}

		$submitted = $state->submission['values']->values();
		$current   = ( $state->confirmed ?? new CreatorBillingDetails() )->values();

		echo '<div class="card" style="max-width:none">';
		printf( '<h3><a href="%1$s">%2$s</a></h3>', esc_url( CreatorsAdminPage::edit_url( $creator->id ) . '#merchboard-billing-details' ), esc_html( $creator->display_name ) );

		if ( $state->payout_waiting ) {
			echo '<p><strong>' . esc_html__( 'The creator\'s first payout waits for these master data.', 'merchboard' ) . '</strong></p>';
		} elseif ( $state->required ) {
			echo '<p>' . esc_html__( 'Needed before the creator\'s first payout.', 'merchboard' ) . '</p>';
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Field', 'merchboard' ) . '</th><th>' . esc_html__( 'Confirmed now', 'merchboard' ) . '</th><th>' . esc_html__( 'Submitted', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( self::labels() as $field => $label ) {
			$changed = $submitted[ $field ] !== $current[ $field ];

			if ( 'type' === $field ) {
				$current[ $field ]   = self::type_label( $current[ $field ] );
				$submitted[ $field ] = self::type_label( $submitted[ $field ] );
			}

			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s%4$s</td></tr>',
				esc_html( $label ),
				'' === $current[ $field ] ? '&mdash;' : esc_html( $current[ $field ] ),
				$changed ? '<strong>' : '',
				( '' === $submitted[ $field ] ? '&mdash;' : esc_html( $submitted[ $field ] ) ) . ( $changed ? ' (' . esc_html__( 'changed', 'merchboard' ) . ')</strong>' : '' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped parts.
			);
		}

		echo '</tbody></table>';

		$action = esc_url( admin_url( 'admin-post.php' ) );

		echo '<form method="post" action="' . $action . '" style="margin-top:1em">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		self::hidden( BillingDetailsReviewActions::CONFIRM, $creator->id, $state->version );
		submit_button( __( 'Confirm', 'merchboard' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . $action . '" style="margin-top:1em">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		self::hidden( BillingDetailsReviewActions::REQUEST_CHANGES, $creator->id, $state->version );
		$id = 'merchboard-billing-note-' . $creator->id;
		echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html__( 'What should the creator correct?', 'merchboard' ) . '</label><br>';
		printf( '<textarea id="%1$s" name="%2$s" rows="3" cols="60" maxlength="%3$d" required></textarea></p>', esc_attr( $id ), esc_attr( BillingDetailsReviewActions::NOTE_FIELD ), (int) BillingDetailsReview::MAX_NOTE_LENGTH );
		submit_button( __( 'Ask for corrections', 'merchboard' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * The hidden fields of a decision.
	 *
	 * @param string $action     Admin-post action.
	 * @param int    $creator_id Creator.
	 * @param string $version    The shown submission's version.
	 */
	private static function hidden( string $action, int $creator_id, string $version ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator_id ) . '">';
		echo '<input type="hidden" name="' . esc_attr( BillingDetailsReviewActions::VERSION_FIELD ) . '" value="' . esc_attr( $version ) . '">';
		wp_nonce_field( BillingDetailsReviewActions::nonce_action( $action, $creator_id ) );
	}
}
