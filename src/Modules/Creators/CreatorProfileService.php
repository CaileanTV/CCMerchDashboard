<?php
/**
 * Creator self-service profile.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use MerchBoard\Core\Security\ImpersonationSession;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and changes a creator's own profile (phase 14, docs/API.md).
 *
 * - The creator is always the one CreatorContext resolved for the current
 *   user; the service re-checks that it is linked to the current user and
 *   active, so it can never be pointed at another creator.
 * - Only the whitelisted fields `display_name`, `bio` and `social_links` can
 *   change. Status, user link and role are never touched.
 * - All fields are validated before anything is written; one invalid field
 *   rejects the whole update.
 * - Changes and one `creator_updated` audit record (`changed_fields`, actor =
 *   the creator's user) are written in one transaction (ADR-023/024).
 */
final class CreatorProfileService {

	/**
	 * Maximum bio length in characters.
	 */
	public const BIO_MAX_LENGTH = 1000;

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository $creators    Creator storage.
	 * @param CreatorMetaStore  $meta        Creator meta.
	 * @param AuditTrail        $audit       Audit trail.
	 * @param Transaction       $transaction Database transaction.
	 */
	public function __construct(
		private CreatorRepository $creators,
		private CreatorMetaStore $meta,
		private AuditTrail $audit,
		private Transaction $transaction
	) {
	}

	/**
	 * The creator's profile.
	 *
	 * @param Creator $creator Creator from CreatorContext.
	 * @throws DatabaseException When the profile cannot be read.
	 */
	public function profile( Creator $creator ): CreatorProfile {
		$bio = $this->meta->get( $creator->id, CreatorMetaStore::BIO );

		return new CreatorProfile(
			$creator->display_name,
			is_string( $bio ) ? $bio : '',
			SocialLinks::from_stored( $this->meta->get( $creator->id, CreatorMetaStore::SOCIAL_LINKS ) )
		);
	}

	/**
	 * Applies a partial update of the whitelisted fields. Unknown fields are
	 * ignored; social links not included keep their value.
	 *
	 * @param Creator              $creator Creator from CreatorContext.
	 * @param array<string, mixed> $input   Request fields.
	 * @return CreatorProfile The profile after the update.
	 * @throws CreatorException        When the creator is not the current user's active creator.
	 * @throws CreatorProfileException When a field is invalid (nothing is saved).
	 * @throws DatabaseException       When the change cannot be stored (nothing is kept).
	 */
	public function update( Creator $creator, array $input ): CreatorProfile {
		$this->authorize( $creator );

		$current = $this->profile( $creator );
		$next    = $this->validated( $current, $input );
		$changed = array_keys( array_diff_assoc( array_map( 'wp_json_encode', $next->to_array() ), array_map( 'wp_json_encode', $current->to_array() ) ) );

		if ( array() === $changed ) {
			return $current;
		}

		$this->transaction->run(
			function () use ( $creator, $next, $changed ): void {
				if ( in_array( 'display_name', $changed, true ) ) {
					$this->creators->rename( $creator->id, $next->display_name );
				}

				if ( in_array( 'bio', $changed, true ) ) {
					$this->meta->set( $creator->id, CreatorMetaStore::BIO, $next->bio );
				}

				if ( in_array( 'social_links', $changed, true ) ) {
					$links = array_filter( $next->social_links, static fn( string $url ): bool => '' !== $url );
					$this->meta->set( $creator->id, CreatorMetaStore::SOCIAL_LINKS, $links );
				}

				$this->audit->record( AuditAction::CreatorUpdated, $creator->id, array( 'changed_fields' => $changed ) );
			}
		);

		return $next;
	}

	/**
	 * The creator must be the active creator linked to the current user, or
	 * the one an administrator views and acts as (ADR-075).
	 *
	 * @param Creator $creator Creator.
	 * @throws CreatorException When it is not.
	 */
	private function authorize( Creator $creator ): void {
		$user_id = get_current_user_id();

		$own = $user_id > 0 && $creator->user_id === $user_id;

		if ( ( ! $own && ImpersonationSession::creator_id() !== $creator->id ) || CreatorStatus::Active !== $creator->status ) {
			throw new CreatorException( CreatorException::NOT_AUTHORIZED );
		}
	}

	/**
	 * The profile with the input applied, or every field error at once.
	 *
	 * @param CreatorProfile       $current Current profile.
	 * @param array<string, mixed> $input   Request fields.
	 * @throws CreatorProfileException When a field is invalid.
	 */
	private function validated( CreatorProfile $current, array $input ): CreatorProfile {
		$errors       = array();
		$display_name = $current->display_name;
		$bio          = $current->bio;
		$links        = $current->social_links;

		if ( array_key_exists( 'display_name', $input ) ) {
			try {
				$display_name = CreatorRepository::normalize_display_name( is_string( $input['display_name'] ) ? $input['display_name'] : '' );
			} catch ( InvalidArgumentException ) {
				$errors['display_name'] = is_string( $input['display_name'] ) && '' === trim( $input['display_name'] ) ? CreatorProfileException::REQUIRED : CreatorProfileException::INVALID;
			}
		}

		if ( array_key_exists( 'bio', $input ) ) {
			if ( ! is_string( $input['bio'] ) ) {
				$errors['bio'] = CreatorProfileException::INVALID;
			} else {
				// Plain text: tags removed, line breaks kept.
				$bio = trim( sanitize_textarea_field( $input['bio'] ) );

				if ( mb_strlen( $bio ) > self::BIO_MAX_LENGTH ) {
					$errors['bio'] = CreatorProfileException::TOO_LONG;
				}
			}
		}

		if ( array_key_exists( 'social_links', $input ) ) {
			if ( ! is_array( $input['social_links'] ) ) {
				$errors['social_links'] = CreatorProfileException::INVALID;
			} else {
				foreach ( $input['social_links'] as $platform => $url ) {
					$field = 'social_links.' . ( is_string( $platform ) ? sanitize_key( $platform ) : 'invalid' );

					if ( ! is_string( $platform ) || ! in_array( $platform, SocialLinks::PLATFORMS, true ) ) {
						$errors[ $field ] = CreatorProfileException::INVALID;
						continue;
					}

					$normalized = is_string( $url ) ? SocialLinks::normalize_url( $url ) : null;

					if ( null === $normalized ) {
						$errors[ $field ] = CreatorProfileException::INVALID_URL;
						continue;
					}

					$links[ $platform ] = $normalized;
				}
			}
		}

		if ( array() !== $errors ) {
			throw new CreatorProfileException( $errors );
		}

		return new CreatorProfile( $display_name, $bio, $links );
	}
}
