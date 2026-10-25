<?php
/**
 * Deletes a creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use Closure;
use DateTimeImmutable;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a creator from the admin list (phase 27, step 27.3, ADR-076; the
 * project owner's choices 2026-10-25: every creator can be deleted, one
 * with history is anonymized; `merchboard_manage_creators`).
 *
 * Refused (nothing changes) without the capability, without the creator's
 * exact name typed as confirmation, for a creator already deleted, while a
 * payout of the creator is pending, while WooCommerce is off but products
 * are linked to the creator, or while another deletion runs (a named lock).
 *
 * The deletion runs step by step, each step idempotent, so one that stopped
 * is simply run again:
 *
 * 1. the creator is deactivated (alerts, feeds and access stop);
 * 2. each module's CreatorDataCleaner removes its data (product links and
 *    category mapping, discount code, alerts, news reads, …);
 * 3. the WordPress user is unlinked and loses the creator role (the user
 *    itself is never deleted); the creator's meta is deleted;
 * 4. in one transaction with the audit record `creator_deleted` (`deletion`:
 *    the mode, `former_name` and the counts): without financial history (HISTORY_TABLES)
 *    the row is deleted; with history it stays as "Deleted creator #id",
 *    inactive, without user and marked `deleted_at`, so the ledger,
 *    payouts and settlement documents keep pointing at it (ADR-023,
 *    ADR-037/038, ADR-059). Issued settlement documents keep their own
 *    snapshot with the original name.
 */
class CreatorDeletion {

	public const MODE_DELETED    = 'deleted';
	public const MODE_ANONYMIZED = 'anonymized';

	/**
	 * Tables whose rows are financial history: one row keeps the creator.
	 */
	public const HISTORY_TABLES = array(
		Tables::SALES,
		Tables::SALES_LINE_PRICING,
		Tables::PAYOUTS,
		Tables::SETTLEMENT_DOCUMENTS,
		Tables::PAYOUT_ADJUSTMENTS,
		Tables::RECALCULATION_ITEMS,
		Tables::ORDER_GIFT_CARD_PAYOUTS,
		Tables::CREATOR_VAT_HISTORY,
		Tables::CREATOR_CODES,
		Tables::ORDER_EXCLUSIONS,
	);

	/**
	 * The named lock of a deletion.
	 */
	public const LOCK = 'merchboard_creator_deletion';

	/**
	 * The status of a pending payout (Modules\WooCommerce\Payouts\PayoutStatus::Pending).
	 */
	private const PENDING_PAYOUT = 'pending';

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository    $creators    Creators.
	 * @param CreatorService       $service     Creator changes (status, user).
	 * @param CreatorMetaStore     $meta        Creator meta.
	 * @param AuditTrail           $audit       Audit trail.
	 * @param Transaction          $transaction Database transaction.
	 * @param wpdb                 $wpdb        Database (counts and the lock).
	 * @param CreatorDataCleaner[] $cleaners  The modules' cleaners, in order.
	 * @param Closure              $clock       The time of the deletion (UTC).
	 * @phpstan-param list<CreatorDataCleaner> $cleaners
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 */
	public function __construct(
		private CreatorRepository $creators,
		private CreatorService $service,
		private CreatorMetaStore $meta,
		private AuditTrail $audit,
		private Transaction $transaction,
		private wpdb $wpdb,
		private array $cleaners,
		private Closure $clock
	) {
	}

	/**
	 * What deleting a creator would do: the mode, its financial history per
	 * table, linked products and pending payouts.
	 *
	 * @param Creator $creator Creator.
	 * @return array{mode: string, history: array<string, int>, products: int, pending_payouts: int, ready: bool}
	 * @throws DatabaseException When it cannot be counted.
	 */
	public function preview( Creator $creator ): array {
		$history = $this->history( $creator->id );

		return array(
			'mode'            => array_sum( $history ) > 0 ? self::MODE_ANONYMIZED : self::MODE_DELETED,
			'history'         => $history,
			'products'        => $this->linked_products( $creator->id ),
			'pending_payouts' => $this->pending_payouts( $creator->id ),
			'ready'           => $this->has_cleaner( 'woocommerce' ) || 0 === $this->linked_products( $creator->id ),
		);
	}

	/**
	 * Deletes a creator.
	 *
	 * @param int    $creator_id   Creator.
	 * @param string $confirmation The name the administrator typed.
	 * @return string MODE_DELETED or MODE_ANONYMIZED.
	 * @throws CreatorDeletionException When refused (nothing changed).
	 * @throws DatabaseException When a step cannot be stored (the steps before it are kept; run again).
	 */
	public function delete( int $creator_id, string $confirmation ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			self::refuse( CreatorDeletionException::NOT_AUTHORIZED );
		}

		$creator = $creator_id > 0 ? $this->creators->find( $creator_id ) : null;

		if ( null === $creator ) {
			self::refuse( CreatorDeletionException::NOT_FOUND );
		}

		if ( $creator->is_deleted() ) {
			self::refuse( CreatorDeletionException::ALREADY_DELETED );
		}

		if ( self::comparable( $confirmation ) !== self::comparable( $creator->display_name ) ) {
			self::refuse( CreatorDeletionException::NOT_CONFIRMED );
		}

		$preview = $this->preview( $creator );

		if ( $preview['pending_payouts'] > 0 ) {
			self::refuse( CreatorDeletionException::PAYOUTS_PENDING );
		}

		if ( ! $preview['ready'] ) {
			self::refuse( CreatorDeletionException::NEEDS_WOOCOMMERCE );
		}

		// Before anything changes: every module may refuse (e.g. a right it needs).
		foreach ( $this->cleaners as $cleaner ) {
			$cleaner->check( $creator );
		}

		return $this->locked( fn(): string => $this->run( $creator->id ) );
	}

	/**
	 * The steps of a deletion.
	 *
	 * @param int $creator_id Creator.
	 * @return string The mode.
	 * @throws DatabaseException When a step cannot be stored (and CreatorDeletionException when it was deleted meanwhile or a payout started; the creator is active again).
	 */
	private function run( int $creator_id ): string {
		// Again under the lock: a second request may have deleted it meanwhile.
		$creator = $this->creators->find( $creator_id );

		if ( null === $creator || $creator->is_deleted() ) {
			self::refuse( CreatorDeletionException::ALREADY_DELETED );
		}

		$name = $creator->display_name;

		if ( $creator->is_active() ) {
			$this->service->set_status( $creator->id, CreatorStatus::Inactive );

			// An inactive creator cannot request a payout; one requested meanwhile stops the deletion.
			if ( $this->pending_payouts( $creator->id ) > 0 ) {
				$this->service->set_status( $creator->id, CreatorStatus::Active );
				self::refuse( CreatorDeletionException::PAYOUTS_PENDING );
			}
		}

		$removed = array();

		foreach ( $this->cleaners as $cleaner ) {
			foreach ( $cleaner->remove( $this->current( $creator->id ) ) as $what => $count ) {
				$removed[ $cleaner->key() . '_' . $what ] = $count;
			}
		}

		// No product may still point at the creator (a new order would create sales for it).
		if ( $this->has_cleaner( 'woocommerce' ) && $this->linked_products( $creator->id ) > 0 ) {
			throw new DatabaseException( 'Products are still linked to the creator being deleted.' );
		}

		$current = $this->current( $creator->id );

		if ( null !== $current->user_id ) {
			$this->service->update( $current->id, $current->display_name, CreatorStatus::Inactive, null );
		}

		$removed['meta'] = $this->meta->delete_all( $creator->id );
		$mode            = array_sum( $this->history( $creator->id ) ) > 0 ? self::MODE_ANONYMIZED : self::MODE_DELETED;
		$now             = ( $this->clock )();

		$this->transaction->run(
			function () use ( $creator, $name, $mode, $removed, $now ): void {
				if ( self::MODE_DELETED === $mode ) {
					$this->creators->delete( $creator->id );
				} else {
					/* translators: %d: creator id. */
					$this->creators->anonymize( $creator->id, sprintf( __( 'Deleted creator #%d', 'merchboard' ), $creator->id ), $now );
				}

				$this->audit->record(
					AuditAction::CreatorDeleted,
					$creator->id,
					array_merge(
						array(
							'deletion'    => $mode,
							'former_name' => $name,
						),
						array_filter( $removed )
					)
				);
			}
		);

		return $mode;
	}

	/**
	 * A name for comparing the typed confirmation: trimmed, whitespace
	 * collapsed (an admin cannot see repeated spaces in the label).
	 *
	 * @param string $name Name.
	 */
	private static function comparable( string $name ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
	}

	/**
	 * The creator as stored now.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it no longer exists.
	 */
	private function current( int $creator_id ): Creator {
		return $this->creators->find( $creator_id ) ?? throw new DatabaseException( 'The creator being deleted disappeared.' );
	}

	/**
	 * Rows of financial history per table.
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, int>
	 * @throws DatabaseException When a table cannot be counted.
	 */
	private function history( int $creator_id ): array {
		$counts = array();

		foreach ( self::HISTORY_TABLES as $table ) {
			$counts[ $table ] = $this->count( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE creator_id = %d', Tables::name( $this->wpdb, $table ), $creator_id ) );
		}

		return $counts;
	}

	/**
	 * Products whose link points at the creator.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When they cannot be counted.
	 */
	private function linked_products( int $creator_id ): int {
		return $this->count( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s AND meta_value = %s', $this->wpdb->postmeta, ProtectedProductMeta::LINK_KEY, (string) $creator_id ) );
	}

	/**
	 * The creator's pending payouts.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When they cannot be counted.
	 */
	private function pending_payouts( int $creator_id ): int {
		return $this->count( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE creator_id = %d AND status = %s', Tables::name( $this->wpdb, Tables::PAYOUTS ), $creator_id, self::PENDING_PAYOUT ) );
	}

	/**
	 * A count query's result (fail closed).
	 *
	 * @param string|null $sql Prepared query.
	 * @throws DatabaseException When it fails.
	 */
	private function count( ?string $sql ): int {
		$value = null === $sql ? null : $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the callers; a decision that must be current.

		if ( ! is_numeric( $value ) || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not count the data of the creator being deleted.' );
		}

		return (int) $value;
	}

	/**
	 * Whether a module's cleaner is present.
	 *
	 * @param string $key Cleaner key.
	 */
	private function has_cleaner( string $key ): bool {
		foreach ( $this->cleaners as $cleaner ) {
			if ( $key === $cleaner->key() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Runs the work under the named lock; refuses a second deletion.
	 *
	 * @param callable $work The deletion.
	 * @phpstan-param callable(): string $work
	 * @throws DatabaseException When the lock cannot be taken (and CreatorDeletionException BUSY when another deletion holds it).
	 */
	private function locked( callable $work ): string {
		$wpdb     = $this->wpdb;
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK, 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		if ( ! is_numeric( $acquired ) ) {
			throw new DatabaseException( 'Could not take the creator deletion lock.' );
		}

		if ( 1 !== (int) $acquired ) {
			self::refuse( CreatorDeletionException::BUSY );
		}

		try {
			return $work();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
		}
	}

	/**
	 * Refuses.
	 *
	 * @param string $reason CreatorDeletionException constant.
	 * @throws CreatorDeletionException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new CreatorDeletionException( $reason );
	}
}
