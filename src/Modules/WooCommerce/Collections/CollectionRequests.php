<?php
/**
 * The rules of the requests to have designs collected.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\NamedLock;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 1 and 2: a creator asks for a new collection of their own
 * products or to have some added to one of their own collections, and an
 * administrator approves or declines.
 *
 * A request is only a case: nothing here writes a product. A collection is
 * the creator's when every design in it is. The rules are checked again at
 * approval, as products change hands, are hidden and are collected meanwhile.
 * A request and its audit record are stored in one transaction.
 */
class CollectionRequests {

	/**
	 * Fewest designs of a new collection: one design is no choice.
	 */
	public const MIN_NEW = 2;

	/**
	 * Most designs of one request.
	 */
	public const MAX_DESIGNS = 50;

	/**
	 * Longest stored name.
	 */
	public const NAME_MAX_LENGTH = 191;

	/**
	 * Longest note.
	 */
	public const NOTE_MAX_LENGTH = 500;

	/**
	 * Seconds to wait for a request's lock.
	 */
	private const LOCK_TIMEOUT = 5;

	/**
	 * The creator a product is linked to, or null.
	 *
	 * @var Closure(int): ?int
	 */
	private Closure $creator_of;

	/**
	 * A creator by id, or null.
	 *
	 * @var Closure(int): ?Creator
	 */
	private Closure $creator;

	/**
	 * Clock.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?int $creator_of
	 * @phpstan-param Closure(int): ?Creator $creator
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 *
	 * @param CollectionRequestRepository $requests    Storage.
	 * @param DesignCollections           $collections The shop's collections.
	 * @param CreatorProducts             $products    The creators' products.
	 * @param Transaction                 $transaction The project's transaction helper.
	 * @param AuditTrail                  $audit       Audit trail.
	 * @param NamedLock                   $lock        Named locks.
	 * @param Closure                     $creator_of  The creator a product is linked to, or null.
	 * @param Closure                     $creator     A creator by id, or null.
	 * @param Closure|null                $clock       Time; UTC now by default.
	 */
	public function __construct(
		private CollectionRequestRepository $requests,
		private DesignCollections $collections,
		private CreatorProducts $products,
		private Transaction $transaction,
		private AuditTrail $audit,
		private NamedLock $lock,
		Closure $creator_of,
		Closure $creator,
		?Closure $clock = null
	) {
		$this->creator_of = $creator_of;
		$this->creator    = $creator;
		$this->clock      = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Asks for a new collection.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param Creator $creator     Creator from CreatorContext.
	 * @param string  $name        The collection's name.
	 * @param int[]   $product_ids The designs' products, in the order wanted.
	 * @throws CollectionRequestException When it is refused.
	 * @throws DatabaseException When it cannot be read or stored (nothing is kept).
	 */
	public function request_new( Creator $creator, string $name, array $product_ids ): CollectionRequest {
		$name = mb_substr( trim( sanitize_text_field( $name ) ), 0, self::NAME_MAX_LENGTH );

		if ( '' === $name ) {
			self::refuse( CollectionRequestException::NAME_REQUIRED );
		}

		return $this->store( $creator, CollectionRequestKind::NewCollection, $name, null, $product_ids, self::MIN_NEW );
	}

	/**
	 * Asks to have designs added to one of the creator's collections.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param Creator $creator       Creator from CreatorContext.
	 * @param int     $collection_id The collection.
	 * @param int[]   $product_ids   The designs' products.
	 * @throws CollectionRequestException When it is refused.
	 * @throws DatabaseException When it cannot be read or stored (nothing is kept).
	 */
	public function request_add( Creator $creator, int $collection_id, array $product_ids ): CollectionRequest {
		if ( ! $this->is_creators( $creator->id, $collection_id ) ) {
			self::refuse( CollectionRequestException::COLLECTION_UNKNOWN );
		}

		return $this->store( $creator, CollectionRequestKind::Add, self::title( $collection_id ), $collection_id, $product_ids, 1 );
	}

	/**
	 * Whether a collection is the creator's: it exists outside the trash, has
	 * designs, and all of them are the creator's.
	 *
	 * @param int $creator_id    Creator.
	 * @param int $collection_id Collection.
	 */
	public function is_creators( int $creator_id, int $collection_id ): bool {
		if ( ! $this->collections->is_collection( $collection_id ) || in_array( get_post_status( $collection_id ), array( false, 'trash', 'auto-draft' ), true ) ) {
			return false;
		}

		$members = $this->collections->members( $collection_id );

		foreach ( $members as $member ) {
			if ( ( $this->creator_of )( $member['id'] ) !== $creator_id ) {
				return false;
			}
		}

		return array() !== $members;
	}

	/**
	 * Approves a pending request. Nothing in the shop changes by this.
	 *
	 * @param int $id Request.
	 * @throws CollectionRequestException When it is refused.
	 * @throws DatabaseException When it cannot be read or stored (nothing is kept).
	 */
	public function approve( int $id ): CollectionRequest {
		self::authorize();

		return $this->locked(
			$id,
			function ( CollectionRequest $request ): CollectionRequest {
				if ( CollectionRequestStatus::Pending !== $request->status ) {
					self::refuse( CollectionRequestException::NOT_PENDING );
				}

				$creator = ( $this->creator )( $request->creator_id );

				if ( null === $creator || ! self::sells( $creator ) ) {
					self::refuse( CollectionRequestException::CREATOR_NOT_LIVE );
				}

				if ( CollectionRequestKind::Add === $request->kind && ( null === $request->collection_id || ! $this->is_creators( $creator->id, $request->collection_id ) ) ) {
					self::refuse( CollectionRequestException::COLLECTION_UNKNOWN );
				}

				$this->assert_usable( $creator->id, $request->product_ids(), $request->collection_id );

				$this->transaction->run(
					function () use ( $request ): void {
						if ( ! $this->requests->approve( $request->id, self::user(), ( $this->clock )() ) ) {
							self::refuse( CollectionRequestException::NOT_PENDING );
						}

						$this->audit->record( AuditAction::CollectionApproved, $request->creator_id, self::context( $request ) );
					}
				);

				return $this->read( $request->id );
			}
		);
	}

	/**
	 * Declines an open request with a note to the creator. One whose designs
	 * are already sold from the published collection is done and cannot be
	 * declined.
	 *
	 * @param int    $id   Request.
	 * @param string $note Why.
	 * @throws CollectionRequestException When it is refused.
	 * @throws DatabaseException When it cannot be read or stored (nothing is kept).
	 */
	public function decline( int $id, string $note ): CollectionRequest {
		self::authorize();

		$note = mb_substr( trim( sanitize_text_field( $note ) ), 0, self::NOTE_MAX_LENGTH );

		if ( '' === $note ) {
			self::refuse( CollectionRequestException::NOTE_REQUIRED );
		}

		return $this->locked(
			$id,
			function ( CollectionRequest $request ) use ( $note ): CollectionRequest {
				if ( ! $request->status->open() ) {
					self::refuse( CollectionRequestException::NOT_OPEN );
				}

				if ( CollectionRequestStatus::Approved === $request->status && $this->is_done( $request ) ) {
					self::refuse( CollectionRequestException::ALREADY_DONE );
				}

				$context                  = self::context( $request );
				$context['decision_note'] = $note;

				$this->transaction->run(
					function () use ( $request, $note, $context ): void {
						if ( ! $this->requests->close( $request, CollectionRequestStatus::Declined, $note, self::user(), ( $this->clock )() ) ) {
							self::refuse( CollectionRequestException::NOT_OPEN );
						}

						$this->audit->record( AuditAction::CollectionDeclined, $request->creator_id, $context );
					}
				);

				return $this->read( $request->id );
			}
		);
	}

	/**
	 * Whether an approved request is carried out: the collection is published
	 * and sells at least one of the designs, and every one of them that is
	 * still listed in it. One the administrator took out does not block; one
	 * another collection holds does.
	 *
	 * @param CollectionRequest $request The request.
	 */
	public function is_done( CollectionRequest $request ): bool {
		$id = $request->collection_id;

		if ( CollectionRequestStatus::Approved !== $request->status || null === $id || 'publish' !== get_post_status( $id ) ) {
			return false;
		}

		$listed = array_intersect( $request->product_ids(), array_column( $this->collections->members( $id ), 'id' ) );

		foreach ( $listed as $product_id ) {
			if ( $this->collections->collection_of( $product_id ) !== $id ) {
				return false;
			}
		}

		return array() !== $listed;
	}

	/**
	 * Closes an approved request as ready once it is carried out; called by
	 * the system when the shop changes, and again by "Check again".
	 *
	 * @param int $id Request.
	 * @return CollectionRequest|null The request when this call made it ready.
	 * @throws CollectionRequestException When the request is unknown or busy.
	 * @throws DatabaseException When it cannot be read or stored (nothing is kept).
	 */
	public function mark_ready( int $id ): ?CollectionRequest {
		return $this->locked(
			$id,
			function ( CollectionRequest $request ): ?CollectionRequest {
				if ( ! $this->is_done( $request ) ) {
					return null;
				}

				$closed = $this->transaction->run(
					function () use ( $request ): bool {
						if ( ! $this->requests->close( $request, CollectionRequestStatus::Ready, null, self::user(), ( $this->clock )() ) ) {
							return false;
						}

						$this->audit->record( AuditAction::CollectionReady, $request->creator_id, self::context( $request ) );

						return true;
					}
				);

				return $closed ? $this->read( $request->id ) : null;
			}
		);
	}

	/**
	 * Checks and stores a request.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param Creator               $creator       Creator.
	 * @param CollectionRequestKind $kind          A new collection or an addition.
	 * @param string                $name          The collection's name.
	 * @param int|null              $collection_id The collection added to.
	 * @param int[]                 $product_ids   The designs' products.
	 * @param int                   $min           Fewest designs.
	 * @throws DatabaseException When it cannot be read or stored (nothing is kept).
	 */
	private function store( Creator $creator, CollectionRequestKind $kind, string $name, ?int $collection_id, array $product_ids, int $min ): CollectionRequest {
		if ( ! self::sells( $creator ) ) {
			self::refuse( CollectionRequestException::CREATOR_NOT_LIVE );
		}

		$ids = array_values( array_unique( $product_ids ) );

		if ( count( $ids ) < $min ) {
			self::refuse( CollectionRequestException::TOO_FEW );
		}

		if ( count( $ids ) > self::MAX_DESIGNS ) {
			self::refuse( CollectionRequestException::TOO_MANY );
		}

		$this->assert_usable( $creator->id, $ids, $collection_id );

		if ( array() !== $this->requests->requested_among( $ids ) ) {
			self::refuse( CollectionRequestException::PRODUCT_REQUESTED );
		}

		$designs = array_map(
			static fn( int $id ): array => array(
				'id'   => $id,
				'name' => self::title( $id ),
			),
			$ids
		);
		$token   = bin2hex( random_bytes( 16 ) );

		try {
			$id = $this->transaction->run(
				function () use ( $creator, $kind, $name, $collection_id, $token, $designs, $ids ): int {
					$id = $this->requests->insert( $creator->id, $kind, $name, $collection_id, $token, $designs, self::user(), ( $this->clock )() );

					$this->audit->record(
						AuditAction::CollectionRequested,
						$creator->id,
						array(
							'collection_request_id' => $id,
							'collection_id'         => $collection_id,
							'product_ids'           => $ids,
						)
					);

					return $id;
				}
			);
		} catch ( DatabaseException $e ) {
			// A parallel request may just have taken a design; the UNIQUE key refused this one.
			if ( array() !== $this->requests->requested_among( $ids ) ) {
				self::refuse( CollectionRequestException::PRODUCT_REQUESTED );
			}

			throw $e;
		}

		return $this->read( $id );
	}

	/**
	 * Refuses products that are not the creator's, cannot be collected or are
	 * already in the collection.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @param int      $creator_id    Creator.
	 * @param int[]    $ids           Products.
	 * @param int|null $collection_id The collection added to.
	 */
	private function assert_usable( int $creator_id, array $ids, ?int $collection_id ): void {
		$members = null === $collection_id ? array() : array_column( $this->collections->members( $collection_id ), 'id' );

		foreach ( $ids as $id ) {
			if ( in_array( $id, $members, true ) || ! $this->products->owns( $creator_id, $id ) || ! $this->collections->collectable( $id ) ) {
				self::refuse( CollectionRequestException::PRODUCT_NOT_USABLE );
			}
		}
	}

	/**
	 * Whether the creator is active and live in the shop.
	 *
	 * @param Creator $creator Creator.
	 */
	private static function sells( Creator $creator ): bool {
		return CreatorStatus::Active === $creator->status && $creator->is_live();
	}

	/**
	 * Runs work on a request, read under its lock.
	 *
	 * @template T
	 * @param int                            $id   Request.
	 * @param callable(CollectionRequest): T $work The work.
	 * @return T
	 */
	private function locked( int $id, callable $work ): mixed {
		// Boxed, as null is also how a lock that was not had answers.
		$result = $this->lock->run(
			'merchboard_collection_request_' . $id,
			self::LOCK_TIMEOUT,
			fn(): array => array( $work( $this->requests->find( $id ) ?? self::refuse( CollectionRequestException::NOT_FOUND ) ) )
		);

		if ( null === $result ) {
			self::refuse( CollectionRequestException::BUSY );
		}

		return $result[0];
	}

	/**
	 * A stored request.
	 *
	 * @param int $id Request.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function read( int $id ): CollectionRequest {
		return $this->requests->find( $id ) ?? throw new DatabaseException( 'The collection request could not be read back.' );
	}

	/**
	 * A request's audit context.
	 *
	 * @param CollectionRequest $request The request.
	 * @return array<string, mixed>
	 */
	private static function context( CollectionRequest $request ): array {
		return array(
			'collection_request_id' => $request->id,
			'collection_id'         => $request->collection_id,
			'product_ids'           => $request->product_ids(),
		);
	}

	/**
	 * A product's name as stored in a request.
	 *
	 * @param int $product_id Product.
	 */
	private static function title( int $product_id ): string {
		return mb_substr( sanitize_text_field( (string) get_post_field( 'post_title', $product_id, 'raw' ) ), 0, self::NAME_MAX_LENGTH );
	}

	/**
	 * The current WordPress user, null when none.
	 */
	private static function user(): ?int {
		$user = get_current_user_id();

		return $user > 0 ? $user : null;
	}

	/**
	 * Requires the capability of the creators' pages.
	 *
	 * @throws CollectionRequestException When it is missing.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			self::refuse( CollectionRequestException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Refuses.
	 *
	 * @param string $reason One of CollectionRequestException's constants.
	 * @throws CollectionRequestException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new CollectionRequestException( $reason );
	}
}
