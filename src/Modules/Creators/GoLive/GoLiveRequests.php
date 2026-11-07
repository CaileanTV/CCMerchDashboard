<?php
/**
 * Creators who signed up ask to go live; an administrator decides.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\GoLive;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Agreement\Agreement;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\Signup\FrontGroup;
use MerchBoard\Modules\Creators\Signup\SignupSettings;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-091 pkt. 9 and ADR-093:
 * - A creator who is not live asks once the agreement is accepted; one
 *   request is pending at a time. The administrator is told.
 * - Approving needs the parent category and the shop: the creator goes live
 *   (in one transaction with the decision and the audit record), then the
 *   shop creates the category and sets the markup. Once the category is
 *   there, `merchboard_creator_went_live` lets the designer put the waiting
 *   designs in the shop, also when the markup was not set (review 32.7). If
 *   the category cannot be made, the creator is live without it and the
 *   administrator tries again from the Go-live tab.
 * - Declining needs a note, which the creator gets; they can ask again.
 */
class GoLiveRequests {

	public const WENT_LIVE = 'merchboard_creator_went_live';

	/** The category was made and the markup set. */
	public const PROVISIONED = 'provisioned';

	/** The category was made; the markup was not set. */
	public const NO_MARKUP = 'no_markup';

	/** The category could not be made (try again). */
	public const NO_CATEGORY = 'no_category';

	private const MAX_NOTE = 500;

	/**
	 * Current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param GoLiveRepository  $requests    Requests.
	 * @param CreatorRepository $creators    Creators.
	 * @param Agreement         $agreement   The agreement.
	 * @param Closure           $shop        The shop side (resolved when needed).
	 * @param AuditTrail        $audit       Audit trail.
	 * @param Transaction       $transaction Transactions.
	 * @param CreatorMetaStore  $meta        Creator meta (the front-page group, ADR-097).
	 * @param Closure|null      $clock       Current time (tests).
	 * @phpstan-param Closure(): GoLiveShop $shop
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 */
	public function __construct( private GoLiveRepository $requests, private CreatorRepository $creators, private Agreement $agreement, private Closure $shop, private AuditTrail $audit, private Transaction $transaction, private CreatorMetaStore $meta, ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Where a creator stands: live, the newest request and whether the
	 * agreement allows asking.
	 *
	 * @param Creator $creator Creator.
	 * @return array{live: bool, agreement: bool, request: array{status: string, note: string, requested_at: string}|null}
	 * @throws DatabaseException When it cannot be read.
	 */
	public function status( Creator $creator ): array {
		$latest = $creator->is_live() ? null : $this->requests->latest_for( $creator->id );

		return array(
			'live'      => $creator->is_live(),
			'agreement' => $this->agreement->satisfied( $creator ),
			'request'   => null === $latest ? null : array(
				'status'       => $latest['status'],
				'note'         => GoLiveRepository::DECLINED === $latest['status'] ? $latest['note'] : '',
				'requested_at' => $latest['requested_at'],
			),
		);
	}

	/**
	 * A creator asks to go live.
	 *
	 * @param Creator  $creator Creator.
	 * @param int|null $user_id Who asks.
	 * @throws GoLiveException When it is refused.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function request( Creator $creator, ?int $user_id ): void {
		if ( $creator->is_live() ) {
			self::refuse( GoLiveException::ALREADY_LIVE );
		}

		if ( ! $this->agreement->satisfied( $creator ) ) {
			self::refuse( GoLiveException::AGREEMENT );
		}

		$stored = $this->transaction->run(
			function () use ( $creator, $user_id ): bool {
				if ( ! $this->requests->insert( $creator->id, $user_id, ( $this->clock )() ) ) {
					return false;
				}

				$this->audit->record( AuditAction::GoLiveRequested, $creator->id );

				return true;
			}
		);

		if ( ! $stored ) {
			self::refuse( GoLiveException::PENDING );
		}

		do_action( 'merchboard_notify', 'admin_go_live_requested', $creator->id, array(), 'go_live:' . $creator->id . ':' . ( $this->clock )()->getTimestamp() );
	}

	/**
	 * Approves a pending request.
	 *
	 * @param int    $id       Request.
	 * @param string $name     Category name.
	 * @param string $percent  Markup percentage.
	 * @param string $group    Front-page group (FrontGroup) the administrator chose.
	 * @return string One of PROVISIONED, NO_MARKUP and NO_CATEGORY: the creator is live in all three.
	 * @throws GoLiveException When it is refused (nothing changed).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function approve( int $id, string $name, string $percent, string $group ): string {
		self::authorize();
		$request = $this->requests->find( $id );

		if ( null === $request || GoLiveRepository::PENDING !== $request['status'] ) {
			self::refuse( GoLiveException::NOT_PENDING );
		}

		$creator = $this->creators->find( $request['creator_id'] );
		$name    = trim( sanitize_text_field( $name ) );
		$shop    = ( $this->shop )();
		$parent  = SignupSettings::parent_category();

		if ( null === $creator || CreatorStatus::Active !== $creator->status ) {
			self::refuse( GoLiveException::NOT_PENDING );
		}

		if ( ! $shop->available() ) {
			self::refuse( GoLiveException::SHOP_UNAVAILABLE );
		}

		if ( 0 === $parent ) {
			self::refuse( GoLiveException::PARENT_MISSING );
		}

		if ( '' === $name || mb_strlen( $name ) > 191 ) {
			self::refuse( GoLiveException::NAME );
		}

		$shop->check_markup( $percent );
		self::check_front_group( $group );

		$decided = $this->transaction->run(
			function () use ( $id, $creator, $group ): bool {
				$user = get_current_user_id();

				if ( ! $this->requests->decide( $id, GoLiveRepository::APPROVED, null, $user > 0 ? $user : null, ( $this->clock )() ) ) {
					return false;
				}

				$this->creators->go_live( $creator->id );
				$this->meta->set( $creator->id, CreatorMetaStore::FRONT_GROUP, $group );
				$this->audit->record( AuditAction::GoLiveApproved, $creator->id, array( 'front_group' => $group ) );

				return true;
			}
		);

		if ( ! $decided ) {
			self::refuse( GoLiveException::NOT_PENDING );
		}

		do_action( 'merchboard_notify', 'account_live_approved', $creator->id, array(), 'go_live_approved:' . $id );

		return $this->provision( $creator->id, $name, $percent, $group );
	}

	/**
	 * Tries again to make the category of a creator who went live without it.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $name       Category name.
	 * @param string $percent    Markup percentage.
	 * @param string $group      Front-page group (FrontGroup).
	 * @return string One of PROVISIONED, NO_MARKUP and NO_CATEGORY.
	 * @throws GoLiveException When it is refused.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	public function retry_category( int $creator_id, string $name, string $percent, string $group ): string {
		self::authorize();
		$creator = $this->creators->find( $creator_id );
		$name    = trim( sanitize_text_field( $name ) );

		if ( null === $creator || ! $creator->is_live() || ( $this->shop )()->has_category( $creator_id ) ) {
			self::refuse( GoLiveException::NOT_PENDING );
		}

		if ( '' === $name ) {
			self::refuse( GoLiveException::NAME );
		}

		( $this->shop )()->check_markup( $percent );
		self::check_front_group( $group );
		$this->meta->set( $creator_id, CreatorMetaStore::FRONT_GROUP, $group );

		return $this->provision( $creator_id, $name, $percent, $group );
	}

	/**
	 * The creator's front-page group: their choice at sign-up or the
	 * administrator's at go-live; Content creator when there is none.
	 *
	 * @param int $creator_id Creator.
	 */
	public function front_group( int $creator_id ): string {
		return FrontGroup::or_default( $this->meta->get( $creator_id, CreatorMetaStore::FRONT_GROUP ) );
	}

	/**
	 * Declines a pending request with a note to the creator.
	 *
	 * @param int    $id   Request.
	 * @param string $note Note (required).
	 * @throws GoLiveException When it is refused.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function decline( int $id, string $note ): void {
		self::authorize();
		$note    = trim( sanitize_textarea_field( $note ) );
		$request = $this->requests->find( $id );

		if ( '' === $note || mb_strlen( $note ) > self::MAX_NOTE ) {
			self::refuse( GoLiveException::NOTE );
		}

		if ( null === $request || GoLiveRepository::PENDING !== $request['status'] ) {
			self::refuse( GoLiveException::NOT_PENDING );
		}

		$decided = $this->transaction->run(
			function () use ( $id, $note, $request ): bool {
				$user = get_current_user_id();

				if ( ! $this->requests->decide( $id, GoLiveRepository::DECLINED, $note, $user > 0 ? $user : null, ( $this->clock )() ) ) {
					return false;
				}

				$this->audit->record( AuditAction::GoLiveDeclined, $request['creator_id'] );

				return true;
			}
		);

		if ( ! $decided ) {
			self::refuse( GoLiveException::NOT_PENDING );
		}

		do_action( 'merchboard_notify', 'account_live_declined', $request['creator_id'], array( 'note' => $note ), 'go_live_declined:' . $id );
	}

	/**
	 * The shop side of going live; then the designer's waiting designs.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $name       Category name.
	 * @param string $percent    Markup percentage.
	 * @param string $group      Front-page group.
	 * @return string One of PROVISIONED, NO_MARKUP and NO_CATEGORY.
	 */
	private function provision( int $creator_id, string $name, string $percent, string $group ): string {
		try {
			$markup = ( $this->shop )()->provision( $creator_id, $name, SignupSettings::parent_category(), $percent, $group );
		} catch ( GoLiveException ) {
			return self::NO_CATEGORY;
		}

		do_action( 'merchboard_creator_went_live', $creator_id ); // Same as self::WENT_LIVE. // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- merchboard_creator_went_live.

		return $markup ? self::PROVISIONED : self::NO_MARKUP;
	}

	/**
	 * Refuses a front-page group that is not one of the two.
	 *
	 * @param string $group Value.
	 * @throws GoLiveException When it is not.
	 */
	private static function check_front_group( string $group ): void {
		if ( ! FrontGroup::is_valid( $group ) ) {
			self::refuse( GoLiveException::FRONT_GROUP );
		}
	}

	/**
	 * Requires the capability to manage creators.
	 *
	 * @throws GoLiveException When the user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			self::refuse( GoLiveException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Refuses a request.
	 *
	 * @param string $reason One of GoLiveException's constants.
	 * @throws GoLiveException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new GoLiveException( $reason );
	}
}
