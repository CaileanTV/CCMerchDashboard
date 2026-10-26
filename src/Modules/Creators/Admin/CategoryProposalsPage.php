<?php
/**
 * Admin page: the creators' category proposals.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\Proposals\CategoryProfile;
use MerchBoard\Modules\Creators\Proposals\CategoryProposal;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalException;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalRepository;
use MerchBoard\Modules\Creators\Proposals\CategoryProposals;
use MerchBoard\Modules\Creators\Proposals\ProposalField;
use MerchBoard\Modules\Creators\Proposals\ProposalLabels;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Category proposals" (phase 28, step 28.4, ADR-077): the
 * creators with pending proposals, oldest first; per proposal the value now
 * and the proposed one side by side (images through ProposalImageDownload),
 * with "Approve" and "Reject" (an optional note the creator sees), and
 * "Approve all" per creator. The menu item shows how many are waiting.
 * Capability `merchboard_manage_creators`.
 */
final class CategoryProposalsPage {

	public const SLUG = 'merchboard-category-proposals';

	/**
	 * Query argument of the outcome notice.
	 */
	public const RESULT_ARG = 'merchboard_proposal_result';

	/**
	 * Hook priority on `admin_menu` (after "Creators").
	 */
	public const PRIORITY = 11;

	/**
	 * Constructor.
	 *
	 * @param CategoryProposals          $proposals  Proposals and categories.
	 * @param CategoryProposalRepository $repository Pending proposals by creator.
	 * @param CreatorRepository          $creators   Creators.
	 * @param Logger                     $logger     Technical log.
	 */
	public function __construct( private CategoryProposals $proposals, private CategoryProposalRepository $repository, private CreatorRepository $creators, private Logger $logger ) {
	}

	/**
	 * URL of the page.
	 *
	 * @param array<string, int|string> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Message and type of an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'warning'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'approved'                                 => array( __( 'The proposal is approved and shown in the shop.', 'merchboard' ), 'success' ),
			'approved_all'                             => array( __( 'All proposals of the creator are approved and shown in the shop.', 'merchboard' ), 'success' ),
			'approved_some'                            => array( __( 'Some proposals are approved; the others could not be approved. They are still waiting below.', 'merchboard' ), 'warning' ),
			'rejected'                                 => array( __( 'The proposal is rejected. The creator sees your note.', 'merchboard' ), 'success' ),
			CategoryProposalException::NO_CATEGORY     => array( __( 'The creator has no product category, so the proposal cannot be approved yet. Map a category on the creator\'s page first.', 'merchboard' ), 'error' ),
			CategoryProposalException::UNAVAILABLE     => array( __( 'This proposal needs WooCommerce and the plugin CCMerch Kategoribannere, which are not active.', 'merchboard' ), 'error' ),
			CategoryProposalException::NOT_PENDING,
			CategoryProposalException::NOT_FOUND       => array( __( 'The proposal is no longer waiting, e.g. because the creator withdrew or replaced it.', 'merchboard' ), 'warning' ),
			CategoryProposalException::NOT_AUTHORIZED  => array( __( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), 'error' ),
			CategoryProposalException::INVALID        => array( __( 'The proposal is no longer valid and was not approved.', 'merchboard' ), 'error' ),
			'error'                                    => array( __( 'A technical error occurred. Nothing was changed. Please try again later.', 'merchboard' ), 'error' ),
			default                                    => null,
		};
	}

	/**
	 * Adds "Category proposals" with the number waiting to the Merch
	 * Dashboard menu.
	 */
	public function add_menu(): void {
		$count = $this->pending_count();
		$title = __( 'Category proposals', 'merchboard' );

		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Category proposals', 'merchboard' ),
			$count > 0 ? esc_html( $title ) . ' <span class="awaiting-mod"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>' : esc_html( $title ),
			Capabilities::MANAGE_CREATORS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Hooks the section on the creator's edit page.
	 */
	public function register(): void {
		add_action( 'merchboard_creators_edit_sections', array( $this, 'render_creator_section' ), 4 );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		echo '<div class="wrap merchboard-proposals">';
		echo '<h1>' . esc_html__( 'Category proposals', 'merchboard' ) . '</h1>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p class="description">' . esc_html__( 'What creators propose for their product category in the creator dashboard. Nothing is shown in the shop, and the creator\'s name does not change, until you approve it. Check images and links for copyright before you approve them.', 'merchboard' ) . '</p>';

		try {
			$waiting = $this->repository->pending_creators();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Reading the category proposals failed.', $e->context() );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The proposals could not be read. Please try again later.', 'merchboard' ) . '</p></div></div>';

			return;
		}

		if ( array() === $waiting ) {
			echo '<p>' . esc_html__( 'No proposals are waiting.', 'merchboard' ) . '</p></div>';

			return;
		}

		foreach ( $waiting as $row ) {
			$creator = $this->creators->find( $row['creator_id'] );

			if ( null !== $creator ) {
				$this->render_creator( $creator );
			}
		}

		echo '</div>';
	}

	/**
	 * The section on a creator's edit page: how many proposals wait, with a
	 * link to them.
	 *
	 * @param Creator $creator Creator.
	 */
	public function render_creator_section( Creator $creator ): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return;
		}

		try {
			$count = count( $this->proposals->pending( $creator->id ) );
		} catch ( DatabaseException ) {
			return;
		}

		echo '<hr><h2 id="merchboard-category-proposals">' . esc_html__( 'Category proposals', 'merchboard' ) . '</h2><p>';

		if ( 0 === $count ) {
			echo esc_html__( 'No proposals from the creator are waiting.', 'merchboard' );
		} else {
			echo esc_html(
				sprintf(
					/* translators: %d: number of proposals. */
					_n( '%d proposal from the creator is waiting for approval.', '%d proposals from the creator are waiting for approval.', $count, 'merchboard' ),
					$count
				)
			) . ' <a href="' . esc_url( self::url() . '#creator-' . $creator->id ) . '">' . esc_html__( 'Review the proposals', 'merchboard' ) . '</a>';
		}

		echo '</p>';
	}

	/**
	 * One creator's pending proposals.
	 *
	 * @param Creator $creator Creator.
	 */
	private function render_creator( Creator $creator ): void {
		$pending  = $this->proposals->pending( $creator->id );
		$category = $this->proposals->category( $creator->id );

		echo '<div class="merchboard-proposals__creator" id="creator-' . esc_attr( (string) $creator->id ) . '">';
		echo '<h2><a href="' . esc_url( CreatorsAdminPage::edit_url( $creator->id ) ) . '">' . esc_html( $creator->display_name ) . '</a></h2>';

		if ( null === $category ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The creator has no product category. Only a new name can be approved; the other proposals wait until a category is mapped on the creator\'s page.', 'merchboard' ) . '</p></div>';
		} else {
			echo '<p>' . esc_html__( 'Product category:', 'merchboard' ) . ' ';
			echo '' !== $category->edit_url ? '<a href="' . esc_url( $category->edit_url ) . '">' . esc_html( $category->name ) . '</a>' : esc_html( $category->name );
			echo '</p>';
		}

		echo '<table class="widefat striped merchboard-proposals__table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Change', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Now', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Proposed', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Decision', 'merchboard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $pending as $proposal ) {
			$this->render_row( $creator, $category, $proposal );
		}

		echo '</tbody></table>';

		if ( count( $pending ) > 1 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="merchboard-proposals__all">';
			echo '<input type="hidden" name="action" value="' . esc_attr( CategoryProposalActions::APPROVE_ALL ) . '">';
			echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
			echo '<input type="hidden" name="' . esc_attr( CategoryProposalActions::SHOWN_FIELD ) . '" value="' . esc_attr( implode( ',', array_map( static fn( CategoryProposal $proposal ): string => (string) $proposal->id, $pending ) ) ) . '">';
			wp_nonce_field( CategoryProposalActions::nonce_action( CategoryProposalActions::APPROVE_ALL, $creator->id ) );
			submit_button(
				sprintf(
					/* translators: %s: creator name. */
					__( 'Approve all from %s', 'merchboard' ),
					$creator->display_name
				),
				'secondary',
				'submit',
				false
			);
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * One proposal: what, now, proposed and the decision forms.
	 *
	 * @param Creator              $creator  Creator.
	 * @param CategoryProfile|null $category The category now.
	 * @param CategoryProposal     $proposal Proposal.
	 */
	private function render_row( Creator $creator, ?CategoryProfile $category, CategoryProposal $proposal ): void {
		$field = $proposal->field;

		$when = wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $proposal->created_at->getTimestamp() );

		echo '<tr><th scope="row">' . esc_html( ucfirst( ProposalLabels::field( $field ) ) );
		echo '<br><span class="description">' . esc_html( false === $when ? '' : $when ) . '</span></th>';
		echo '<td>' . self::current_value( $creator, $category, $field ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		echo '<td>' . self::proposed_value( $proposal ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		echo '<td class="merchboard-proposals__decision">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CategoryProposalActions::APPROVE ) . '">';
		echo '<input type="hidden" name="proposal_id" value="' . esc_attr( (string) $proposal->id ) . '">';
		wp_nonce_field( CategoryProposalActions::nonce_action( CategoryProposalActions::APPROVE, $proposal->id ) );
		submit_button( __( 'Approve', 'merchboard' ), 'primary small', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CategoryProposalActions::REJECT ) . '">';
		echo '<input type="hidden" name="proposal_id" value="' . esc_attr( (string) $proposal->id ) . '">';
		wp_nonce_field( CategoryProposalActions::nonce_action( CategoryProposalActions::REJECT, $proposal->id ) );
		echo '<label class="screen-reader-text" for="merchboard-note-' . esc_attr( (string) $proposal->id ) . '">' . esc_html__( 'Note to the creator (optional)', 'merchboard' ) . '</label>';
		echo '<input type="text" class="regular-text" id="merchboard-note-' . esc_attr( (string) $proposal->id ) . '" name="' . esc_attr( CategoryProposalActions::NOTE_FIELD ) . '" maxlength="' . esc_attr( (string) CategoryProposals::NOTE_MAX_LENGTH ) . '" placeholder="' . esc_attr__( 'Note to the creator (optional)', 'merchboard' ) . '"> ';
		submit_button( __( 'Reject', 'merchboard' ), 'secondary small', 'submit', false );
		echo '</form>';

		echo '</td></tr>';
	}

	/**
	 * The value now, escaped HTML.
	 *
	 * @param Creator              $creator  Creator.
	 * @param CategoryProfile|null $category The category now.
	 * @param ProposalField        $field    Field.
	 */
	private static function current_value( Creator $creator, ?CategoryProfile $category, ProposalField $field ): string {
		if ( ProposalField::DisplayName === $field ) {
			return esc_html( $creator->display_name );
		}

		if ( null === $category ) {
			return '<em>' . esc_html__( 'No category', 'merchboard' ) . '</em>';
		}

		$image = match ( $field ) {
			ProposalField::Image        => $category->image_url,
			ProposalField::Banner       => $category->banner_url,
			ProposalField::BannerMobile => $category->banner_mobile_url,
			default                     => null,
		};

		if ( null !== $image ) {
			if ( '' === $image ) {
				return '<em>' . esc_html__( 'None', 'merchboard' ) . '</em>';
			}

			$html = self::image( $image );

			return ProposalField::Banner === $field && $category->default_banner() ? $html . '<br><span class="description">' . esc_html__( 'Standard banner', 'merchboard' ) . '</span>' : $html;
		}

		return self::text( $field, $category->value( $field ) );
	}

	/**
	 * The proposed value, escaped HTML.
	 *
	 * @param CategoryProposal $proposal Proposal.
	 */
	private static function proposed_value( CategoryProposal $proposal ): string {
		if ( $proposal->is_removal() ) {
			return '<em>' . esc_html__( 'Remove', 'merchboard' ) . '</em>';
		}

		$file = $proposal->file();

		if ( $proposal->field->is_file() ) {
			return null === $file ? '<em>' . esc_html__( 'The image is missing.', 'merchboard' ) . '</em>' : self::image( ProposalImageDownload::url( $proposal->id ) ) . '<br><span class="description">' . esc_html( sprintf( '%d × %d px', $file->width, $file->height ) ) . '</span>';
		}

		return self::text( $proposal->field, $proposal->value );
	}

	/**
	 * A text or link value, escaped HTML.
	 *
	 * @param ProposalField $field Field.
	 * @param string        $value Value.
	 */
	private static function text( ProposalField $field, string $value ): string {
		if ( '' === $value ) {
			return '<em>' . esc_html__( 'None', 'merchboard' ) . '</em>';
		}

		if ( ProposalField::BannerLink === $field || null !== $field->platform() ) {
			return '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
		}

		return nl2br( esc_html( $value ) );
	}

	/**
	 * An image, escaped HTML.
	 *
	 * @param string $url Image URL.
	 */
	private static function image( string $url ): string {
		return '<img class="merchboard-proposals__image" src="' . esc_url( $url ) . '" alt="" style="max-width:20rem;max-height:8rem;height:auto;">';
	}

	/**
	 * Proposals waiting (0 when they cannot be counted).
	 */
	private function pending_count(): int {
		try {
			return $this->repository->pending_count();
		} catch ( DatabaseException ) {
			return 0;
		}
	}
}
