<?php
/**
 * The migration from Product Vendors.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\VendorImport;

use Closure;
use DateTimeImmutable;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Creators\CreatorException;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CreatorCategoryException;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductCreatorLinkException;
use MerchBoard\Modules\WooCommerce\ProductLinkSource;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Migrates the vendors of a confirmed dry run (steps 26.2 and 26.2.1, with
 * the 26.4 review, ADR-074).
 *
 * One run at a time (a named lock; a second request, e.g. a double click,
 * is refused as busy). A fresh dry run with the same chosen users and
 * categories must have the confirmed fingerprint, which also covers the
 * products to link. There is no transaction around a vendor (the services
 * each run their own, and transactions cannot nest); every step is
 * idempotent and recorded in VendorImportState, so an interrupted run
 * continues where it stopped:
 *
 * 1. the creator: the earlier run's, the chosen user's, or a new active one
 *    through CreatorService (which links the user and gives them the
 *    creator role). The chosen user is written as `pending` before the
 *    creator is made, so a creator an interrupted run made is still known
 *    as the migration's own (and gets its settlement start);
 * 2. a creator the migration made gets its settlement start at the time of
 *    the run, once (PayoutSettings, ADR-054): a start the administrator
 *    changes later is never set again;
 * 3. the dry run's products to link are linked through ProductCreatorLink
 *    (source admin) and recorded, so a later run never links them again;
 * 4. after every vendor's products: the product categories of the dry run
 *    are mapped through CreatorCategoryService (step 26.2.1, ADR-033), so a
 *    category's reconciliation cannot take another vendor's products before
 *    their own run reaches them;
 * 5. `vendor_imported` (creator, counts).
 *
 * A vendor that fails (e.g. its user was linked meanwhile, or its creator is
 * inactive) is counted and the run goes on. At the end the last run and
 * `vendor_import_completed` (counts) are written in one transaction.
 * Product Vendors' data is never written.
 *
 * @phpstan-import-type State from VendorImportState
 */
class VendorImporter {

	/**
	 * The named lock of a run.
	 */
	public const LOCK = 'merchboard_vendor_import';

	/**
	 * Constructor.
	 *
	 * @param VendorImportPlan       $plan        The dry run.
	 * @param CreatorService         $creators    Creators.
	 * @param ProductCreatorLink     $links       Products' creators.
	 * @param CreatorCategoryService $categories  Creators' product categories.
	 * @param PayoutSettings         $payouts     Settlement starts.
	 * @param AuditTrail             $audit       Audit trail.
	 * @param Transaction            $transaction Database transaction.
	 * @param wpdb                   $wpdb        Database (the named lock).
	 * @param Closure                $clock       The time of the run (UTC).
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 */
	public function __construct(
		private VendorImportPlan $plan,
		private CreatorService $creators,
		private ProductCreatorLink $links,
		private CreatorCategoryService $categories,
		private PayoutSettings $payouts,
		private AuditTrail $audit,
		private Transaction $transaction,
		private wpdb $wpdb,
		private Closure $clock
	) {
	}

	/**
	 * Migrates, when the dry run is still the confirmed one.
	 *
	 * @param string          $fingerprint      The confirmed dry run's fingerprint.
	 * @param array<int, int> $choices          The chosen user per vendor of that dry run.
	 * @param array<int, int> $category_choices The chosen product category per vendor of that dry run (0: none).
	 * @return array{created: int, matched: int, categories: int, linked: int, conflict: int, failed: int} What happened.
	 * @throws VendorImportException When refused, busy or the dry run changed.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	public function run( string $fingerprint, array $choices, array $category_choices = array() ): array {
		return $this->locked(
			function () use ( $fingerprint, $choices, $category_choices ): array {
				$report = $this->plan->build( $choices, $category_choices );

				if ( ! hash_equals( $report->fingerprint(), $fingerprint ) ) {
					self::refuse( VendorImportException::OUTDATED );
				}

				return $this->migrate( $report );
			}
		);
	}

	/**
	 * Migrates a confirmed dry run.
	 *
	 * @param VendorImportReport $report The dry run.
	 * @return array{created: int, matched: int, categories: int, linked: int, conflict: int, failed: int}
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	private function migrate( VendorImportReport $report ): array {
		$now     = ( $this->clock )();
		$counts  = array(
			'created'    => 0,
			'matched'    => 0,
			'categories' => 0,
			'linked'     => 0,
			'conflict'   => 0,
			'failed'     => 0,
		);
		$results = array();

		// Creators, settlement starts and products of every vendor first.
		foreach ( $report->vendors as $vendor ) {
			if ( ! VendorImportReport::migrates( $vendor['status'] ) ) {
				continue;
			}

			$creator_id = $this->creator( $vendor['term_id'], $vendor['name'], $vendor['status'], $vendor['user_id'], $vendor['creator_id'], $now, $counts );

			if ( 0 === $creator_id ) {
				continue;
			}

			[ $linked, $failed ] = $this->products( $vendor['term_id'], $creator_id, $vendor['link_ids'] );
			$counts['linked']   += $linked;
			$counts['conflict'] += $vendor['products']['conflict'];
			$counts['failed']   += $failed;
			$results[]           = array(
				'term_id'    => $vendor['term_id'],
				'status'     => $vendor['status'],
				'creator_id' => $creator_id,
				'category'   => VendorImportReport::CATEGORY_MAP === $vendor['category'] ? $vendor['category_id'] : 0,
				'linked'     => $linked,
				'conflict'   => $vendor['products']['conflict'],
				'failed'     => $failed,
			);
		}

		// Then the categories, and each vendor's audit record.
		foreach ( $results as $result ) {
			$mapped = $result['category'] > 0 && $this->category( $result['term_id'], $result['creator_id'], $result['category'] );

			if ( $result['category'] > 0 ) {
				$counts['categories'] += $mapped ? 1 : 0;
				$counts['failed']     += $mapped ? 0 : 1;
			}

			$this->transaction->run(
				fn() => $this->audit->record(
					AuditAction::VendorImported,
					$result['creator_id'],
					array(
						'status'   => $result['status'],
						'category' => $mapped ? $result['category'] : 0,
						'linked'   => $result['linked'],
						'conflict' => $result['conflict'],
						'failed'   => $result['failed'],
					)
				)
			);
		}

		$this->transaction->run(
			function () use ( $counts, $now ): void {
				$state                 = VendorImportState::load();
				$state['completed_at'] = $now->format( 'Y-m-d H:i:s' );
				$state['last']         = $counts;
				VendorImportState::save( $state );
				$this->audit->record( AuditAction::VendorImportCompleted, null, $counts );
			}
		);

		return $counts;
	}

	/**
	 * A vendor's creator, with its settlement start when the migration made
	 * it; 0 when it failed (counted).
	 *
	 * @param int                                                                                         $term_id    Vendor.
	 * @param string                                                                                      $name       Its name.
	 * @param string                                                                                      $status     Its status in the dry run.
	 * @param int                                                                                         $user_id    The chosen user (0: none).
	 * @param int                                                                                         $creator_id Its creator in the dry run (0: a new one).
	 * @param DateTimeImmutable                                                                           $now        The time of the run.
	 * @param array{created: int, matched: int, categories: int, linked: int, conflict: int, failed: int} $counts     Counts so far.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	private function creator( int $term_id, string $name, string $status, int $user_id, int $creator_id, DateTimeImmutable $now, array &$counts ): int {
		try {
			$state = VendorImportState::load();

			if ( 0 === $creator_id ) {
				// Known before the creator exists: an interrupted run's creator is still the migration's own.
				$state['pending'][ $term_id ] = $user_id;
				VendorImportState::save( $state );
				$creator_id = $this->creators->create( $name, CreatorStatus::Active, $user_id > 0 ? $user_id : null )->id;
				$this->remember( $term_id, $creator_id, $user_id, true );
				++$counts['created'];
			} elseif ( VendorImportReport::MATCHED === $status ) {
				// The user's creator: made by an interrupted run of this vendor, or one that existed already.
				$made = ( $state['pending'][ $term_id ] ?? -1 ) === $user_id;
				$this->remember( $term_id, $creator_id, $user_id, $made );
				++$counts[ $made ? 'created' : 'matched' ];
			} else {
				$this->remember( $term_id, $creator_id, $state['users'][ $term_id ] ?? $user_id, false );
			}

			$this->settle( $creator_id, $now );
		} catch ( CreatorException | PayoutException ) {
			++$counts['failed'];

			return 0;
		}

		return $creator_id;
	}

	/**
	 * Sets the settlement start of a creator the migration made, once.
	 *
	 * @param int               $creator_id Creator.
	 * @param DateTimeImmutable $now        The time of the run.
	 * @throws PayoutException When it cannot be set.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	private function settle( int $creator_id, DateTimeImmutable $now ): void {
		$state = VendorImportState::load();

		if ( ! in_array( $creator_id, $state['created'], true ) || in_array( $creator_id, $state['settled'], true ) ) {
			return;
		}

		if ( null === $this->payouts->settlement_start( $creator_id ) ) {
			$this->payouts->set_settlement_start( $creator_id, $now );
		}

		$state['settled'][] = $creator_id;
		VendorImportState::save( $state );
	}

	/**
	 * Links the dry run's products and records them.
	 *
	 * @param int   $term_id    Vendor.
	 * @param int   $creator_id Creator.
	 * @phpstan-param list<int> $product_ids
	 * @param int[] $product_ids Products to link.
	 * @return array{0: int, 1: int} Linked, failed.
	 * @throws DatabaseException When the database cannot be written.
	 */
	private function products( int $term_id, int $creator_id, array $product_ids ): array {
		$linked = array();
		$failed = 0;

		foreach ( $product_ids as $product_id ) {
			try {
				$this->links->link( $product_id, $creator_id, ProductLinkSource::Admin );
				$linked[] = $product_id;
			} catch ( ProductCreatorLinkException ) {
				// E.g. the creator is inactive, or the product was linked meanwhile.
				++$failed;
			}
		}

		if ( array() !== $linked ) {
			$state                         = VendorImportState::load();
			$state['products'][ $term_id ] = array_values( array_unique( array_merge( $state['products'][ $term_id ] ?? array(), $linked ) ) );
			VendorImportState::save( $state );
		}

		return array( count( $linked ), $failed );
	}

	/**
	 * Maps the dry run's product category and records it.
	 *
	 * @param int $term_id    Vendor.
	 * @param int $creator_id Creator.
	 * @param int $category   Product category.
	 * @return bool Whether the category is the creator's now.
	 * @throws DatabaseException When the database cannot be written.
	 */
	private function category( int $term_id, int $creator_id, int $category ): bool {
		try {
			$this->categories->link( $creator_id, $category );
		} catch ( CreatorCategoryException ) {
			// E.g. the creator is inactive or the category was mapped meanwhile.
			return false;
		}

		$state                           = VendorImportState::load();
		$state['categories'][ $term_id ] = $category;
		VendorImportState::save( $state );

		return true;
	}

	/**
	 * Records a vendor's creator and user (and that the migration made it).
	 *
	 * @param int  $term_id    Vendor.
	 * @param int  $creator_id Creator.
	 * @param int  $user_id    The migrated user (0: none).
	 * @param bool $created    Whether the migration made the creator.
	 */
	private function remember( int $term_id, int $creator_id, int $user_id, bool $created ): void {
		$state                        = VendorImportState::load();
		$state['vendors'][ $term_id ] = $creator_id;
		$state['users'][ $term_id ]   = $user_id;
		unset( $state['pending'][ $term_id ] );

		if ( $created && ! in_array( $creator_id, $state['created'], true ) ) {
			$state['created'][] = $creator_id;
		}

		VendorImportState::save( $state );
	}

	/**
	 * Runs the work under the named lock; refuses a second run.
	 *
	 * @template T
	 * @param callable(): T $work The run.
	 * @return T
	 * @throws DatabaseException When the lock cannot be taken (and VendorImportException BUSY when another run holds it).
	 */
	private function locked( callable $work ): mixed {
		$wpdb     = $this->wpdb;
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK, 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		if ( ! is_numeric( $acquired ) ) {
			throw new DatabaseException( 'Could not take the migration lock.' );
		}

		if ( 1 !== (int) $acquired ) {
			self::refuse( VendorImportException::BUSY );
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
	 * @param string $reason VendorImportException constant.
	 * @throws VendorImportException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new VendorImportException( $reason );
	}
}
