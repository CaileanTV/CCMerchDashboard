<?php
/**
 * Proposals → Go-live: the creators' requests to go live.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\Approvals\ApprovalsPage;
use MerchBoard\Modules\Admin\Approvals\ApprovalTab;
use MerchBoard\Modules\Creators\Agreement\AgreementRepository;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\GoLive\GoLiveException;
use MerchBoard\Modules\Creators\GoLive\GoLiveRepository;
use MerchBoard\Modules\Creators\GoLive\GoLiveRequests;
use MerchBoard\Modules\Creators\GoLive\GoLiveShop;
use MerchBoard\Modules\Creators\Signup\FrontGroup;
use MerchBoard\Modules\Creators\Signup\SignupRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The tab "Go-live" on Merch Dashboard → Proposals (ADR-091): each pending
 * request with the creator, the channel link from the sign-up, the accepted
 * agreement version, and forms to approve (category name and markup) or
 * decline (with a note). Creators who went live but whose category could
 * not be made are listed with "Try again". Forms go through admin-post with
 * a nonce; requires `merchboard_manage_creators`. Everything is escaped.
 */
final class GoLivePage {

	public const TAB = 'go-live';

	public const ACTION = 'merchboard_go_live';

	public const RESULT_ARG = 'merchboard_go_live_result';

	/**
	 * Outcome codes of an approval.
	 *
	 * @var array<string, string>
	 */
	private const APPROVED = array(
		GoLiveRequests::PROVISIONED => 'approved',
		GoLiveRequests::NO_MARKUP   => 'approved_without_markup',
		GoLiveRequests::NO_CATEGORY => 'approved_without_category',
	);

	/**
	 * Outcome codes of a new try at the category.
	 *
	 * @var array<string, string>
	 */
	private const RETRIED = array(
		GoLiveRequests::PROVISIONED => 'category_made',
		GoLiveRequests::NO_MARKUP   => 'category_made_without_markup',
		GoLiveRequests::NO_CATEGORY => GoLiveException::CATEGORY_FAILED,
	);

	/**
	 * Constructor.
	 *
	 * @param GoLiveRequests      $service    Decisions.
	 * @param GoLiveRepository    $requests   Requests.
	 * @param CreatorRepository   $creators   Creators.
	 * @param SignupRepository    $signups    Sign-ups (the channel link).
	 * @param AgreementRepository $agreements Acceptances.
	 * @param GoLiveShop          $shop       The shop side.
	 * @param Logger              $logger     Technical log.
	 */
	public function __construct( private GoLiveRequests $service, private GoLiveRepository $requests, private CreatorRepository $creators, private SignupRepository $signups, private AgreementRepository $agreements, private GoLiveShop $shop, private Logger $logger ) {
	}

	/**
	 * Registers the form handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * The tab with the number waiting.
	 */
	public function approval_tab(): ApprovalTab {
		return new ApprovalTab( self::TAB, __( 'Go-live', 'merchboard' ), Capabilities::MANAGE_CREATORS, fn(): int => $this->requests->pending_count(), fn() => $this->render() );
	}

	/**
	 * Handles a form and redirects back.
	 */
	public function handle_request(): void {
		check_admin_referer( self::ACTION );
		// The nonce is checked above; the values are sanitized by GoLiveRequests.
		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by GoLiveRequests.
		wp_safe_redirect( ApprovalsPage::url( self::TAB, array( self::RESULT_ARG => $this->handle( $input ) ) ) );
		exit;
	}

	/**
	 * Approves, declines or tries the category again.
	 *
	 * @param array<mixed> $input Form input (unslashed).
	 * @return string Outcome code.
	 */
	public function handle( array $input ): string {
		$text = static fn( string $key ): string => is_scalar( $input[ $key ] ?? null ) ? (string) $input[ $key ] : '';
		$id   = (int) $text( 'id' );

		try {
			return match ( $text( 'decision' ) ) {
				'approve' => self::APPROVED[ $this->service->approve( $id, $text( 'category' ), $text( 'markup' ), $text( 'front_group' ) ) ] ?? 'error',
				'decline' => $this->decline( $id, $text( 'note' ) ),
				'retry'   => self::RETRIED[ $this->service->retry_category( (int) $text( 'creator_id' ), $text( 'category' ), $text( 'markup' ), $text( 'front_group' ) ) ] ?? 'error',
				default   => 'error',
			};
		} catch ( GoLiveException $e ) {
			return $e->reason();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A request to go live could not be decided.', $e->context() );

			return 'error';
		}
	}

	/**
	 * Renders the tab.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::message( $code );

		echo '<h2>' . esc_html__( 'Requests to go live', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p class="description">' . esc_html__( 'Creators who signed up themselves ask here to go live. Until you approve, nothing of theirs is shown in the shop. Approving creates their own product category (at the top level, or under the parent category if one is chosen under Settings → Agreement), sets their markup and puts their approved designs in the shop.', 'merchboard' ) . '</p>';

		try {
			$pending  = $this->requests->pending();
			$approved = array_values( array_filter( $this->requests->approved(), fn( array $row ): bool => ! $this->shop->has_category( $row['creator_id'] ) ) );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The requests to go live could not be read.', $e->context() );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The requests could not be read. Please try again later.', 'merchboard' ) . '</p></div>';

			return;
		}

		if ( array() === $pending ) {
			echo '<p>' . esc_html__( 'No requests are waiting.', 'merchboard' ) . '</p>';
		}

		foreach ( $pending as $row ) {
			$this->render_request( $row );
		}

		if ( array() !== $approved ) {
			echo '<h3>' . esc_html__( 'Live, but the category could not be made', 'merchboard' ) . '</h3>';

			foreach ( $approved as $row ) {
				$this->render_retry( $row['creator_id'] );
			}
		}
	}

	/**
	 * A pending request with its forms.
	 *
	 * @param array{id: int, creator_id: int, status: string, note: string, requested_at: string, decided_at: string|null} $row Request.
	 * @throws DatabaseException When the creator cannot be read.
	 */
	private function render_request( array $row ): void {
		$creator = $this->creators->find( $row['creator_id'] );

		if ( null === $creator ) {
			return;
		}

		$channel  = $this->signups->channel( $creator->id );
		$accepted = $this->agreements->accepted( $creator->id );

		echo '<div class="card" style="max-width:48rem">';
		echo '<h3>' . esc_html( $creator->display_name ) . '</h3>';
		echo '<p>';
		/* translators: %s: date and time. */
		echo esc_html( sprintf( __( 'Asked %s', 'merchboard' ), get_date_from_gmt( $row['requested_at'], 'Y-m-d H:i' ) ) ) . '<br>';

		if ( '' !== $channel ) {
			echo esc_html__( 'Channel:', 'merchboard' ) . ' <a href="' . esc_url( $channel ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $channel ) . '</a><br>';
		}

		/* translators: %d: agreement version. */
		echo esc_html( $accepted > 0 ? sprintf( __( 'Accepted the agreement, version %d', 'merchboard' ), $accepted ) : __( 'Must not accept the agreement', 'merchboard' ) ) . '<br>';
		/* translators: %s: "Content creator" or "Association & community". */
		echo esc_html( sprintf( __( 'Shown on the front page as: %s', 'merchboard' ), FrontGroup::label( $this->service->front_group( $creator->id ) ) ) ) . '<br>';
		echo '<a href="' . esc_url( CreatorsAdminPage::edit_url( $creator->id ) ) . '">' . esc_html__( 'Open the creator', 'merchboard' ) . '</a>';
		echo '</p>';

		$this->form_open( 'approve', $row['id'] );
		echo '<p><label>' . esc_html__( 'Category name', 'merchboard' ) . ' <input type="text" name="category" required maxlength="191" value="' . esc_attr( $creator->display_name ) . '"></label> ';
		echo '<label>' . esc_html__( 'Markup (%)', 'merchboard' ) . ' <input type="text" name="markup" required inputmode="decimal" size="6" value="0"></label> ';
		$this->front_group_select( $creator->id );
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'The front-page group is written on the new category. Later you change it on the category in WooCommerce; the creator cannot change it.', 'merchboard' ) . '</p>';
		submit_button( __( 'Approve and go live', 'merchboard' ), 'primary', 'submit', false );
		echo '</form>';

		$this->form_open( 'decline', $row['id'] );
		echo '<p><label>' . esc_html__( 'Note to the creator', 'merchboard' ) . '<br><textarea name="note" rows="2" cols="60" maxlength="500" required></textarea></label></p>';
		submit_button( __( 'Decline', 'merchboard' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * A creator who went live without a category, with "Try again".
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the creator cannot be read.
	 */
	private function render_retry( int $creator_id ): void {
		$creator = $this->creators->find( $creator_id );

		if ( null === $creator || ! $creator->is_live() ) {
			return;
		}

		$this->form_open( 'retry', 0 );
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		echo '<p><strong>' . esc_html( $creator->display_name ) . '</strong> ';
		echo '<label>' . esc_html__( 'Category name', 'merchboard' ) . ' <input type="text" name="category" required maxlength="191" value="' . esc_attr( $creator->display_name ) . '"></label> ';
		echo '<label>' . esc_html__( 'Markup (%)', 'merchboard' ) . ' <input type="text" name="markup" required inputmode="decimal" size="6" value="0"></label> ';
		$this->front_group_select( $creator->id );
		echo ' ';
		submit_button( __( 'Try again', 'merchboard' ), 'secondary', 'submit', false );
		echo '</p></form>';
	}

	/**
	 * The list "Shown on the front page", preselected with the creator's group.
	 *
	 * @param int $creator_id Creator.
	 */
	private function front_group_select( int $creator_id ): void {
		$current = $this->service->front_group( $creator_id );

		echo '<label>' . esc_html__( 'Shown on the front page', 'merchboard' ) . ' <select name="front_group">';

		foreach ( FrontGroup::VALUES as $value ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( FrontGroup::label( $value ) ) . '</option>';
		}

		echo '</select></label>';
	}

	/**
	 * Opens a decision form.
	 *
	 * @param string $decision Decision.
	 * @param int    $id       Request.
	 */
	private function form_open( string $decision, int $id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="decision" value="' . esc_attr( $decision ) . '">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( self::ACTION );
	}

	/**
	 * Declines.
	 *
	 * @param int    $id   Request.
	 * @param string $note Note.
	 * @throws GoLiveException|DatabaseException When it is refused or cannot be stored.
	 */
	private function decline( int $id, string $note ): string {
		$this->service->decline( $id, $note );

		return 'declined';
	}

	/**
	 * The message of an outcome code.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	private static function message( string $code ): ?array {
		return match ( $code ) {
			'approved'                         => array( __( 'The creator is live: the category was made, the markup set and their approved designs are on their way to the shop.', 'merchboard' ), 'success' ),
			'approved_without_category'        => array( __( 'The creator is live, but the category could not be made. Try again below.', 'merchboard' ), 'warning' ),
			'approved_without_markup'          => array( __( 'The creator is live and the category was made, but the markup was not set. Set it on the creator\'s pricing.', 'merchboard' ), 'warning' ),
			'category_made'                    => array( __( 'The category was made and the markup set.', 'merchboard' ), 'success' ),
			'category_made_without_markup'     => array( __( 'The category was made, but the markup was not set. Set it on the creator\'s pricing.', 'merchboard' ), 'warning' ),
			'declined'                         => array( __( 'The request was declined. The creator sees your note and can ask again.', 'merchboard' ), 'success' ),
			GoLiveException::NOTE              => array( __( 'Write a note to the creator before you decline.', 'merchboard' ), 'error' ),
			GoLiveException::NAME              => array( __( 'Enter a category name.', 'merchboard' ), 'error' ),
			GoLiveException::MARKUP            => array( __( 'Enter a markup from 0 to 100 %, with at most two decimals.', 'merchboard' ), 'error' ),
			GoLiveException::PARENT_MISSING    => array( __( 'Choose the parent category for creators under Settings → Agreement first.', 'merchboard' ), 'error' ),
			GoLiveException::SHOP_UNAVAILABLE  => array( __( 'Going live needs WooCommerce.', 'merchboard' ), 'error' ),
			GoLiveException::FRONT_GROUP       => array( __( 'Choose where the creator is shown on the front page.', 'merchboard' ), 'error' ),
			GoLiveException::CATEGORY_FAILED   => array( __( 'The category could not be made, e.g. because a category with that name already has products or another creator. Try another name.', 'merchboard' ), 'error' ),
			GoLiveException::NOT_PENDING       => array( __( 'The request is no longer waiting.', 'merchboard' ), 'warning' ),
			GoLiveException::NOT_AUTHORIZED    => array( __( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), 'error' ),
			'error'                            => array( __( 'A technical error occurred. Nothing was changed. Please try again later.', 'merchboard' ), 'error' ),
			default                            => null,
		};
	}
}
