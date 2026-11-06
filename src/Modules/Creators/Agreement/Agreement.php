<?php
/**
 * The creators' agreement: the draft, published versions and acceptances.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Agreement;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Alerts\Legal\CompanyDetails;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-092:
 * - The administrator edits a draft (an option) and publishes it as a new
 *   version; a published version never changes. The first version, and a
 *   later one marked material, must be accepted (again) by every creator
 *   with `agreement_required`; a minor version is only announced.
 * - Only creators who signed up have `agreement_required`; existing
 *   creators and those an administrator creates accept nothing.
 * - An acceptance stores the version, the user, the time and the
 *   confirmation of being 18 or older. Nobody accepts for a creator while
 *   viewing as them (ADR-075).
 * - The placeholders [COMPANY], [ADDRESS], [CVR] and [EMAIL] are filled from
 *   the company details when a version is published, and each must have a
 *   value: the version keeps the text creators accepted, also when the
 *   details change later (review 32.7). The draft keeps the placeholders.
 */
class Agreement {

	/**
	 * The administrator's draft (HTML).
	 */
	public const DRAFT_OPTION = 'merchboard_agreement_draft';

	/**
	 * The notification of a new version.
	 */
	public const NOTIFICATION = 'account_agreement_updated';

	private const MAX_LENGTH = 100000;

	/**
	 * Current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param AgreementRepository $agreements  Versions and acceptances.
	 * @param CreatorRepository   $creators    Creators (who must accept).
	 * @param AuditTrail          $audit       Audit trail.
	 * @param Transaction         $transaction Transactions.
	 * @param Closure|null        $clock       Current time (tests); defaults to now.
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 */
	public function __construct( private AgreementRepository $agreements, private CreatorRepository $creators, private AuditTrail $audit, private Transaction $transaction, ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The published version, or null before the first.
	 *
	 * @throws DatabaseException When it cannot be read.
	 */
	public function current(): ?AgreementVersion {
		return $this->agreements->latest();
	}

	/**
	 * The text the administrator edits: the draft, else the published text,
	 * else the plugin's draft.
	 *
	 * @throws DatabaseException When it cannot be read.
	 */
	public function draft(): string {
		$draft = get_option( self::DRAFT_OPTION, '' );

		if ( is_string( $draft ) && '' !== trim( $draft ) ) {
			return wp_kses_post( $draft );
		}

		return $this->current()->body ?? AgreementDefaults::text();
	}

	/**
	 * A text as creators see it: the placeholders with a registered value
	 * are filled in (escaped).
	 *
	 * @param string $body Text with placeholders.
	 */
	public static function rendered( string $body ): string {
		$values = array_filter( CompanyDetails::values(), static fn( string $value ): bool => '' !== $value );

		return wp_kses_post( strtr( $body, array_map( 'esc_html', $values ) ) );
	}

	/**
	 * The placeholders in a text that have no registered value.
	 *
	 * @param string $body Text.
	 * @return list<string>
	 */
	public static function missing_placeholders( string $body ): array {
		$missing = array();

		foreach ( CompanyDetails::values() as $placeholder => $value ) {
			if ( '' === $value && str_contains( $body, $placeholder ) ) {
				$missing[] = $placeholder;
			}
		}

		return $missing;
	}

	/**
	 * Saves the draft without publishing it.
	 *
	 * @param string $text Text (HTML).
	 * @throws AgreementException When the user may not.
	 */
	public function save_draft( string $text ): void {
		self::authorize();
		update_option( self::DRAFT_OPTION, self::clean( $text ), false );
	}

	/**
	 * Publishes a text as the next version.
	 *
	 * @param string $text     Text (HTML).
	 * @param bool   $material Whether creators must accept it again (always for the first version).
	 * @return int The version.
	 * @throws AgreementException When the user may not, the text is empty or a placeholder has no value.
	 * @throws DatabaseException When it cannot be stored (nothing is published).
	 */
	public function publish( string $text, bool $material ): int {
		self::authorize();
		$body = self::clean( $text );

		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			self::refuse( AgreementException::EMPTY_TEXT );
		}

		if ( array() !== self::missing_placeholders( $body ) ) {
			self::refuse( AgreementException::PLACEHOLDERS );
		}

		$material = $material || null === $this->current();
		$frozen   = self::rendered( $body );
		$version  = $this->transaction->run(
			function () use ( $frozen, $material ): int {
				$user    = get_current_user_id();
				$version = $this->agreements->publish( $frozen, $material, $user > 0 ? $user : null, ( $this->clock )() );
				$this->audit->record(
					AuditAction::AgreementPublished,
					null,
					array(
						'version'  => $version,
						'material' => $material,
					)
				);

				return $version;
			}
		);

		update_option( self::DRAFT_OPTION, $body, false );

		// The creators who must accept the agreement hear about a new version (not about the first).
		if ( $version > 1 ) {
			foreach ( $this->creators->agreement_required_ids() as $creator_id ) {
				do_action( 'merchboard_notify', self::NOTIFICATION, $creator_id, array( 'material' => $material ), 'agreement:' . $version );
			}
		}

		return $version;
	}

	/**
	 * Where a creator stands with the agreement.
	 *
	 * @param Creator $creator Creator.
	 * @return array{required: bool, current: int, accepted: int, must_accept: bool, satisfied: bool}
	 * @throws DatabaseException When it cannot be read.
	 */
	public function status( Creator $creator ): array {
		$current  = $this->current()->version ?? 0;
		$accepted = $creator->agreement_required ? $this->agreements->accepted( $creator->id ) : 0;
		$must     = $creator->agreement_required && $current > 0 && $accepted < max( 1, $this->agreements->latest_material() );

		return array(
			'required'    => $creator->agreement_required,
			'current'     => $current,
			'accepted'    => $accepted,
			'must_accept' => $must,
			// What going live and payouts need: nothing to accept, and an agreement exists when one is required.
			'satisfied'   => ! $creator->agreement_required || ( $current > 0 && ! $must ),
		);
	}

	/**
	 * Whether a creator may go live and request payouts as far as the
	 * agreement is concerned.
	 *
	 * @param Creator $creator Creator.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function satisfied( Creator $creator ): bool {
		return $this->status( $creator )['satisfied'];
	}

	/**
	 * Accepts the current version for a creator.
	 *
	 * @param Creator  $creator Creator.
	 * @param int|null $user_id Who accepts.
	 * @param int      $version The version the creator saw.
	 * @param bool     $adult   Whether they confirmed being 18 or older.
	 * @throws AgreementException When it is refused (nothing is stored).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function accept( Creator $creator, ?int $user_id, int $version, bool $adult ): void {
		if ( null !== ImpersonationSession::creator_id() ) {
			self::refuse( AgreementException::IMPERSONATING );
		}

		if ( ! $adult ) {
			self::refuse( AgreementException::ADULT_REQUIRED );
		}

		$current = $this->current();

		if ( null === $current ) {
			self::refuse( AgreementException::NOT_PUBLISHED );
		}

		if ( $current->version !== $version ) {
			self::refuse( AgreementException::OUTDATED );
		}

		if ( $this->agreements->accepted( $creator->id ) >= $version ) {
			return;
		}

		$this->transaction->run(
			function () use ( $creator, $user_id, $version ): void {
				$this->agreements->accept( $creator->id, $user_id, $version, true, ( $this->clock )() );
				$this->audit->record( AuditAction::AgreementAccepted, $creator->id, array( 'version' => $version ) );
			}
		);
	}

	/**
	 * Requires the capability to manage creators.
	 *
	 * @throws AgreementException When the user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			self::refuse( AgreementException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Refuses a request.
	 *
	 * @param string $reason One of AgreementException's constants.
	 * @throws AgreementException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new AgreementException( $reason );
	}

	/**
	 * A text as stored: sanitized and limited.
	 *
	 * @param string $text Text.
	 */
	private static function clean( string $text ): string {
		return mb_substr( trim( wp_kses_post( $text ) ), 0, self::MAX_LENGTH );
	}
}
