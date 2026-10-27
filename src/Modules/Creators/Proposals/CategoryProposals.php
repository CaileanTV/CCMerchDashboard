<?php
/**
 * A creator's proposals for their product category.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The rules of the category proposals (phase 28, ADR-077).
 *
 * - **Creator:** proposes the name, description, category image, banner,
 *   mobile banner, link and social links of their own category (the current
 *   user's active creator, or the one an administrator views as, ADR-075).
 *   One pending proposal per field: a new one replaces it; a value equal to
 *   the current one withdraws it. '' proposes to remove the current value.
 * - **Administrator** (`merchboard_manage_creators`): approves (the value is
 *   written, an image first copied into the Media Library) or rejects with
 *   an optional note for the creator. Nothing changes before that, also not
 *   the name in Merch Dashboard.
 * - Every change is audited in the same transaction.
 */
final class CategoryProposals {

	/**
	 * Longest description, in characters.
	 */
	public const DESCRIPTION_MAX_LENGTH = 1000;

	/**
	 * Longest banner link.
	 */
	public const LINK_MAX_LENGTH = 500;

	/**
	 * Longest note on a rejection.
	 */
	public const NOTE_MAX_LENGTH = 500;

	/**
	 * Term meta that holds images of categories (the category image and the
	 * banner plugin's banners): an image still in use is never deleted.
	 */
	private const IMAGE_TERM_META = array( 'thumbnail_id', 'ccm_banner_id', 'ccm_banner_mobile_id' );

	/**
	 * Constructor.
	 *
	 * @param CategoryProposalRepository $proposals   Storage.
	 * @param ProposalFiles              $files       Proposed images.
	 * @param CreatorRepository          $creators    Creators.
	 * @param AuditTrail                 $audit       Audit trail.
	 * @param Transaction                $transaction Database transaction.
	 * @param wpdb                       $wpdb        Database (images in use).
	 * @param Closure                    $target      The category, looked up on use (null without WooCommerce).
	 * @param Closure                    $clock       Now, in UTC.
	 *
	 * @phpstan-param Closure(): ?CategoryProfileTarget $target
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 */
	public function __construct(
		private CategoryProposalRepository $proposals,
		private ProposalFiles $files,
		private CreatorRepository $creators,
		private AuditTrail $audit,
		private Transaction $transaction,
		private wpdb $wpdb,
		private Closure $target,
		private Closure $clock
	) {
	}

	/**
	 * The fields that can be proposed on this site: the name always, the
	 * description and image with WooCommerce, the rest with the banner plugin.
	 *
	 * @return list<ProposalField>
	 */
	public function available_fields(): array {
		return array_values( array_filter( ProposalField::cases(), fn( ProposalField $field ): bool => $this->available( $field ) ) );
	}

	/**
	 * The creator's category now, or null (no category or no WooCommerce).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function category( int $creator_id ): ?CategoryProfile {
		return $this->target()?->current( $creator_id );
	}

	/**
	 * The creator's pending proposals by field.
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, CategoryProposal>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function pending( int $creator_id ): array {
		return $this->proposals->pending_for( $creator_id );
	}

	/**
	 * The creator's latest rejection per field, unless a later proposal followed.
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, CategoryProposal>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function rejected( int $creator_id ): array {
		return $this->proposals->rejected_for( $creator_id );
	}

	/**
	 * A proposal, or null.
	 *
	 * @param int $proposal_id Proposal id.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function find( int $proposal_id ): ?CategoryProposal {
		return $this->proposals->find( $proposal_id );
	}

	/**
	 * Proposes text values, or removals of images ('' for an image field).
	 * All fields are validated before anything is stored; a value equal to
	 * the current one withdraws a pending proposal for the field.
	 *
	 * @param Creator              $creator Creator from CreatorContext.
	 * @param array<string, mixed> $input   Values by field (ProposalField value); a value that is not a string is invalid.
	 * @return list<CategoryProposal> The new pending proposals.
	 * @throws CategoryProposalException When the creator may not propose (NOT_AUTHORIZED) or a field is invalid (INVALID with errors(); nothing is stored).
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function propose( Creator $creator, array $input ): array {
		$this->authorize( $creator );

		$values = array();
		$errors = array();

		foreach ( $input as $key => $raw ) {
			$field = ProposalField::tryFrom( (string) $key );

			if ( null === $field ) {
				$errors[ sanitize_key( (string) $key ) ] = CategoryProposalException::INVALID;
				continue;
			}

			try {
				$values[ $field->value ] = $this->validated( $field, $raw );
			} catch ( CategoryProposalException $e ) {
				$errors[ $field->value ] = $e->reason();
			}
		}

		if ( array() !== $errors ) {
			self::refuse( CategoryProposalException::INVALID, $errors );
		}

		$category = $this->category( $creator->id );
		$pending  = $this->proposals->pending_for( $creator->id );
		$submit   = array();
		$withdraw = array();

		foreach ( $values as $key => $value ) {
			$field    = ProposalField::from( $key );
			$current  = ProposalField::DisplayName === $field ? $creator->display_name : ( null === $category ? '' : $category->value( $field ) );
			$existing = $pending[ $key ] ?? null;

			if ( $value === $current ) {
				if ( null !== $existing ) {
					$withdraw[] = $existing;
				}
			} elseif ( null === $existing || $existing->value !== $value ) {
				$submit[ $key ] = $value;
			}
		}

		$ids = $this->store( $creator->id, $submit, $withdraw, $pending );

		return array_values( array_filter( array_map( fn( int $id ): ?CategoryProposal => $this->proposals->find( $id ), $ids ) ) );
	}

	/**
	 * Proposes an uploaded image (category image, banner or mobile banner).
	 *
	 * @param Creator       $creator Creator from CreatorContext.
	 * @param ProposalField $field   Image field.
	 * @param array<mixed>  $upload  Upload (one entry of $_FILES).
	 * @throws CategoryProposalException When the creator may not propose, the field is not available or the file is refused (nothing is kept).
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function propose_image( Creator $creator, ProposalField $field, array $upload ): CategoryProposal {
		$this->authorize( $creator );

		if ( ! $field->is_file() ) {
			self::refuse( CategoryProposalException::INVALID );
		}

		$this->assert_available( $field );

		$file   = $this->files->store( $upload, $field );
		$ids    = array();
		$stored = false;

		try {
			$ids    = $this->store( $creator->id, array( $field->value => $file->to_json() ), array(), $this->proposals->pending_for( $creator->id ) );
			$stored = true;
		} finally {
			if ( ! $stored ) {
				$this->files->delete( $file );
			}
		}

		return ( null === ( $ids[0] ?? null ) ? null : $this->proposals->find( $ids[0] ) ) ?? self::fail( 'Stored category proposal could not be read back.' );
	}

	/**
	 * Withdraws the creator's pending proposal for a field.
	 *
	 * @param Creator       $creator Creator from CreatorContext.
	 * @param ProposalField $field   Field.
	 * @return bool False when nothing was pending.
	 * @throws CategoryProposalException When the creator may not.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function withdraw( Creator $creator, ProposalField $field ): bool {
		$this->authorize( $creator );

		$pending  = $this->proposals->pending_for( $creator->id );
		$proposal = $pending[ $field->value ] ?? null;

		if ( null === $proposal ) {
			return false;
		}

		$this->store( $creator->id, array(), array( $proposal ), $pending );

		return true;
	}

	/**
	 * Approves a pending proposal: the value is written to the creator (the
	 * name) and its category.
	 *
	 * @param int $proposal_id Proposal id.
	 * @throws CategoryProposalException When the current user may not, the proposal is not pending, or it cannot be written (no category, banner plugin missing).
	 * @throws DatabaseException When it cannot be written (nothing is kept).
	 */
	public function approve( int $proposal_id ): CategoryProposal {
		[ $proposal, $creator ] = $this->decidable( $proposal_id );
		$field                  = $proposal->field;
		$category               = null;

		if ( $field->needs_category() ) {
			$this->assert_available( $field );
			$category = $this->category( $creator->id ) ?? self::refuse( CategoryProposalException::NO_CATEGORY );
		} elseif ( null !== $this->target() ) {
			$category = $this->category( $creator->id );
		}

		$media = 0;
		$file  = null;

		if ( $field->is_file() && ! $proposal->is_removal() ) {
			$file  = $proposal->file() ?? self::refuse( CategoryProposalException::NOT_FOUND );
			$media = $this->files->to_media( $file, $creator->id, $creator->display_name . ' – ' . ProposalLabels::field( $field ) );
		}

		$replaced = 0;
		$written  = false;

		try {
			$this->transaction->run(
				function () use ( $proposal, $creator, $field, $category, $media, &$replaced ): void {
					// Claimed first: a proposal withdrawn or replaced meanwhile changes nothing in the shop.
					if ( ! $this->proposals->end( $proposal->id, ProposalStatus::Approved, null, self::user(), ( $this->clock )() ) ) {
						self::refuse( CategoryProposalException::NOT_PENDING );
					}

					if ( ProposalField::DisplayName === $field ) {
						$this->creators->rename( $creator->id, $proposal->value );
					}

					$target = $this->target();

					if ( null !== $category && null !== $target ) {
						$replaced = $target->apply( $creator->id, $field, $field->is_file() ? ( $media > 0 ? (string) $media : '' ) : $proposal->value );
					}

					$this->audit->record(
						AuditAction::CategoryProposalApproved,
						$creator->id,
						array(
							'proposal_id'    => $proposal->id,
							'proposal_field' => $field->value,
							'term_id'        => $category->term_id ?? 0,
						)
					);

					if ( ProposalField::DisplayName === $field ) {
						$this->audit->record( AuditAction::CreatorUpdated, $creator->id, array( 'changed_fields' => array( 'display_name' ) ) );
					}
				}
			);
			$written = true;
		} finally {
			// Nothing is kept: the image copied into the Media Library goes again; the waiting file stays for a retry.
			if ( ! $written && $media > 0 ) {
				wp_delete_attachment( $media, true );
			}
		}

		if ( null !== $file ) {
			$this->files->delete( $file );
		}

		if ( $replaced > 0 ) {
			$this->release_image( $replaced );
		}

		do_action( 'merchboard_notify', 'proposal_approved', $creator->id, array( 'field' => $field->value ), 'proposal:' . $proposal->id );

		return $this->proposals->find( $proposal->id ) ?? self::fail( 'Approved category proposal could not be read back.' );
	}

	/**
	 * Rejects a pending proposal with an optional note for the creator.
	 *
	 * @param int    $proposal_id Proposal id.
	 * @param string $note        Note ('' for none).
	 * @throws CategoryProposalException When the current user may not or the proposal is not pending.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function reject( int $proposal_id, string $note ): CategoryProposal {
		[ $proposal, $creator ] = $this->decidable( $proposal_id );
		$note                   = mb_substr( trim( sanitize_text_field( $note ) ), 0, self::NOTE_MAX_LENGTH );

		$this->transaction->run(
			function () use ( $proposal, $creator, $note ): void {
				if ( ! $this->proposals->end( $proposal->id, ProposalStatus::Rejected, $note, self::user(), ( $this->clock )() ) ) {
					self::refuse( CategoryProposalException::NOT_PENDING );
				}

				$context = array(
					'proposal_id'    => $proposal->id,
					'proposal_field' => $proposal->field->value,
				);

				if ( '' !== $note ) {
					$context['decision_note'] = $note;
				}

				$this->audit->record( AuditAction::CategoryProposalRejected, $creator->id, $context );
			}
		);

		$file = $proposal->file();

		if ( null !== $file ) {
			$this->files->delete( $file );
		}

		do_action(
			'merchboard_notify',
			'proposal_rejected',
			$creator->id,
			array(
				'field' => $proposal->field->value,
				'note'  => $note,
			),
			'proposal:' . $proposal->id
		);

		return $this->proposals->find( $proposal->id ) ?? self::fail( 'Rejected category proposal could not be read back.' );
	}

	/**
	 * Ends and stores proposals in one transaction with their audit records:
	 * the withdrawn ones, then each new value (replacing a pending proposal
	 * for its field). Files of ended proposals are deleted afterwards.
	 *
	 * @param int                             $creator_id Creator.
	 * @param array<string, string>           $submit     New values by field.
	 * @param array<int, CategoryProposal>    $withdraw   Pending proposals to withdraw.
	 * @param array<string, CategoryProposal> $pending    The creator's pending proposals by field.
	 * @return list<int> The new proposals' ids.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	private function store( int $creator_id, array $submit, array $withdraw, array $pending ): array {
		if ( array() === $submit && array() === $withdraw ) {
			return array();
		}

		$ended = array();
		$ids   = $this->transaction->run(
			function () use ( $creator_id, $submit, $withdraw, $pending, &$ended ): array {
				$now = ( $this->clock )();
				$ids = array();

				foreach ( $withdraw as $proposal ) {
					if ( ! $this->proposals->end( $proposal->id, ProposalStatus::Withdrawn, null, self::user(), $now ) ) {
						self::fail( 'The category proposal is no longer pending.' );
					}

					$this->audit->record( AuditAction::CategoryProposalWithdrawn, $creator_id, self::context( $proposal->id, $proposal->field ) );
					$ended[] = $proposal;
				}

				foreach ( $submit as $key => $value ) {
					$field    = ProposalField::from( $key );
					$existing = $pending[ $key ] ?? null;

					if ( null !== $existing ) {
						if ( ! $this->proposals->end( $existing->id, ProposalStatus::Replaced, null, self::user(), $now ) ) {
							self::fail( 'The category proposal is no longer pending.' );
						}

						$ended[] = $existing;
					}

					$id    = $this->proposals->insert( $creator_id, $field, $value, self::user(), $now );
					$ids[] = $id;
					$this->audit->record( AuditAction::CategoryProposalSubmitted, $creator_id, self::context( $id, $field ) );
				}

				return $ids;
			}
		);

		foreach ( $ended as $proposal ) {
			$file = $proposal->file();

			if ( null !== $file ) {
				$this->files->delete( $file );
			}
		}

		// One e-mail to the administrator per submission, naming its fields.
		if ( array() !== $ids ) {
			do_action( 'merchboard_notify', 'admin_proposal_submitted', $creator_id, array( 'field' => implode( ',', array_keys( $submit ) ) ), 'proposal:' . $ids[0] );
		}

		return $ids;
	}

	/**
	 * A pending proposal and its creator, for the current administrator.
	 *
	 * @param int $proposal_id Proposal id.
	 * @return array{0: CategoryProposal, 1: Creator}
	 * @throws CategoryProposalException When the current user may not, or it is unknown or not pending.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function decidable( int $proposal_id ): array {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			self::refuse( CategoryProposalException::NOT_AUTHORIZED );
		}

		$proposal = $this->proposals->find( $proposal_id ) ?? self::refuse( CategoryProposalException::NOT_FOUND );

		if ( ProposalStatus::Pending !== $proposal->status ) {
			self::refuse( CategoryProposalException::NOT_PENDING );
		}

		$creator = $this->creators->find( $proposal->creator_id );

		if ( null === $creator || $creator->is_deleted() ) {
			self::refuse( CategoryProposalException::NOT_FOUND );
		}

		return array( $proposal, $creator );
	}

	/**
	 * The normalized value of a field.
	 *
	 * @param ProposalField $field Field.
	 * @param mixed         $raw   Input.
	 * @throws CategoryProposalException When it is invalid or the field is not available.
	 */
	private function validated( ProposalField $field, mixed $raw ): string {
		$this->assert_available( $field );

		if ( ! is_string( $raw ) ) {
			self::refuse( CategoryProposalException::INVALID );
		}

		if ( $field->is_file() ) {
			// Only a removal; an image is uploaded with propose_image().
			if ( '' !== trim( $raw ) ) {
				self::refuse( CategoryProposalException::INVALID );
			}

			return '';
		}

		$platform = $field->platform();

		if ( null !== $platform ) {
			return SocialPlatforms::normalize( $platform, $raw ) ?? self::refuse( CategoryProposalException::INVALID_URL );
		}

		return match ( $field ) {
			ProposalField::DisplayName => self::display_name( $raw ),
			ProposalField::Description => self::description( $raw ),
			default                    => self::link( $raw ),
		};
	}

	/**
	 * A valid name.
	 *
	 * @param string $raw Input.
	 * @throws CategoryProposalException When it is empty or too long.
	 */
	private static function display_name( string $raw ): string {
		try {
			return CreatorRepository::normalize_display_name( $raw );
		} catch ( InvalidArgumentException ) {
			self::refuse( '' === trim( $raw ) ? CategoryProposalException::REQUIRED : CategoryProposalException::TOO_LONG );
		}
	}

	/**
	 * A valid description: plain text, line breaks kept.
	 *
	 * @param string $raw Input.
	 * @throws CategoryProposalException When it is too long.
	 */
	private static function description( string $raw ): string {
		$description = trim( sanitize_textarea_field( $raw ) );

		if ( mb_strlen( $description ) > self::DESCRIPTION_MAX_LENGTH ) {
			self::refuse( CategoryProposalException::TOO_LONG );
		}

		return $description;
	}

	/**
	 * A valid banner link: '' or an https URL (a missing scheme becomes https).
	 *
	 * @param string $raw Input.
	 * @throws CategoryProposalException When it is not an https URL.
	 */
	private static function link( string $raw ): string {
		$url = trim( $raw );

		if ( '' === $url ) {
			return '';
		}

		if ( 1 !== preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$parts = wp_parse_url( $url );

		if ( mb_strlen( $url ) > self::LINK_MAX_LENGTH || 1 === preg_match( '/[\s\x00-\x1F\x7F<>"\'\\\\]/', $url ) || ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || ! str_contains( (string) ( $parts['host'] ?? '' ), '.' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			self::refuse( CategoryProposalException::INVALID_URL );
		}

		return $url;
	}

	/**
	 * Whether a field can be proposed on this site.
	 *
	 * @param ProposalField $field Field.
	 */
	private function available( ProposalField $field ): bool {
		if ( ! $field->needs_category() ) {
			return true;
		}

		$target = $this->target();

		return null !== $target && ( ! $field->needs_banner_plugin() || $target->has_banner_plugin() );
	}

	/**
	 * Requires the field to be available.
	 *
	 * @param ProposalField $field Field.
	 * @throws CategoryProposalException When it is not.
	 */
	private function assert_available( ProposalField $field ): void {
		if ( ! $this->available( $field ) ) {
			self::refuse( CategoryProposalException::UNAVAILABLE );
		}
	}

	/**
	 * The creator must be the active creator linked to the current user, or
	 * the one an administrator views and acts as (ADR-075).
	 *
	 * @param Creator $creator Creator.
	 * @throws CategoryProposalException When it is not.
	 */
	private function authorize( Creator $creator ): void {
		$user_id = get_current_user_id();
		$own     = $user_id > 0 && $creator->user_id === $user_id;

		if ( ( ! $own && ImpersonationSession::creator_id() !== $creator->id ) || ! $creator->is_active() || $creator->is_deleted() ) {
			self::refuse( CategoryProposalException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Deletes an image the plugin added for an approved proposal once it is
	 * replaced, unless a category still uses it. Other images are never
	 * deleted.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private function release_image( int $attachment_id ): void {
		if ( '' === (string) get_post_meta( $attachment_id, ProposalFiles::MEDIA_META, true ) ) {
			return;
		}

		$wpdb  = $this->wpdb;
		$keys  = implode( ', ', array_fill( 0, count( self::IMAGE_TERM_META ), '%s' ) );
		$inuse = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE meta_key IN ({$keys}) AND meta_value = %s", $wpdb->termmeta, ...array_merge( self::IMAGE_TERM_META, array( (string) $attachment_id ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders only; a decision that must be current.

		if ( is_numeric( $inuse ) && 0 === (int) $inuse ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * The creator's category on this site, or null without WooCommerce.
	 */
	private function target(): ?CategoryProfileTarget {
		return ( $this->target )();
	}

	/**
	 * Refuses (nothing changed).
	 *
	 * @param string                $reason One of CategoryProposalException's constants.
	 * @param array<string, string> $errors Per field.
	 * @throws CategoryProposalException Always.
	 */
	private static function refuse( string $reason, array $errors = array() ): never {
		throw new CategoryProposalException( $reason, $errors );
	}

	/**
	 * Fails on the database.
	 *
	 * @param string $message Technical message.
	 * @throws DatabaseException Always.
	 */
	private static function fail( string $message ): never {
		throw new DatabaseException( $message );
	}

	/**
	 * The audit context of a proposal.
	 *
	 * @param int           $proposal_id Proposal id.
	 * @param ProposalField $field       Field.
	 * @return array{proposal_id: int, proposal_field: string}
	 */
	private static function context( int $proposal_id, ProposalField $field ): array {
		return array(
			'proposal_id'    => $proposal_id,
			'proposal_field' => $field->value,
		);
	}

	/**
	 * The current user, or null.
	 */
	private static function user(): ?int {
		$user_id = get_current_user_id();

		return $user_id > 0 ? $user_id : null;
	}
}
