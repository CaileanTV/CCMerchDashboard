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
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductCreatorLinkException;
use MerchBoard\Modules\WooCommerce\ProductLinkSource;

defined( 'ABSPATH' ) || exit;

/**
 * Migrates the vendors of a confirmed dry run (step 26.2, ADR-074).
 *
 * A fresh dry run with the same chosen users must have the confirmed
 * fingerprint. Per vendor that is migrated, with no transaction around it
 * (the services each run their own, and transactions cannot nest), every
 * step is idempotent, so an interrupted run continues where it stopped:
 *
 * 1. the creator: the earlier run's (OPTION), the chosen user's, or a new
 *    active one through CreatorService (which links the user and gives them
 *    the creator role); a new one is written to OPTION at once;
 * 2. a creator the migration made gets its settlement start at the time of
 *    the run, once (PayoutSettings, ADR-054): a start the administrator
 *    changes later is never set again;
 * 3. the vendor's products without a creator are linked through
 *    ProductCreatorLink (source admin); products of another creator are
 *    left (never replaced), products already linked to it are skipped;
 * 4. `vendor_imported` (creator, counts).
 *
 * A vendor that fails (e.g. its user was linked meanwhile) is counted and
 * the run goes on. At the end the last run and `vendor_import_completed`
 * (counts) are written in one transaction. Product Vendors' data is never
 * written.
 */
class VendorImporter {

	/**
	 * Constructor.
	 *
	 * @param VendorImportPlan     $plan        The dry run.
	 * @param ProductVendorsSource $source      Product Vendors.
	 * @param CreatorService       $creators    Creators.
	 * @param ProductCreatorLink   $links       Products' creators.
	 * @param PayoutSettings       $payouts     Settlement starts.
	 * @param AuditTrail           $audit       Audit trail.
	 * @param Transaction          $transaction Database transaction.
	 * @param Closure              $clock       The time of the run (UTC).
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 */
	public function __construct(
		private VendorImportPlan $plan,
		private ProductVendorsSource $source,
		private CreatorService $creators,
		private ProductCreatorLink $links,
		private PayoutSettings $payouts,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Closure $clock
	) {
	}

	/**
	 * Migrates, when the dry run is still the confirmed one.
	 *
	 * @param string          $fingerprint The confirmed dry run's fingerprint.
	 * @param array<int, int> $choices     The chosen user per vendor of that dry run.
	 * @return array{created: int, matched: int, linked: int, conflict: int, failed: int} What happened.
	 * @throws VendorImportException When refused or the dry run changed.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	public function run( string $fingerprint, array $choices ): array {
		$report = $this->plan->build( $choices );

		if ( ! hash_equals( $report->fingerprint(), $fingerprint ) ) {
			self::refuse( VendorImportException::OUTDATED );
		}

		$now    = ( $this->clock )();
		$counts = array(
			'created'  => 0,
			'matched'  => 0,
			'linked'   => 0,
			'conflict' => 0,
			'failed'   => 0,
		);

		foreach ( $report->vendors as $vendor ) {
			if ( VendorImportReport::migrates( $vendor['status'] ) ) {
				$counts = $this->vendor( $vendor['term_id'], $vendor['name'], $vendor['status'], $vendor['user_id'], $vendor['creator_id'], $now, $counts );
			}
		}

		$this->transaction->run(
			function () use ( $counts, $now ): void {
				$stored                 = self::stored();
				$stored['completed_at'] = $now->format( 'Y-m-d H:i:s' );
				$stored['last']         = $counts;
				update_option( VendorImportPlan::OPTION, $stored, false );
				$this->audit->record( AuditAction::VendorImportCompleted, null, $counts );
			}
		);

		return $counts;
	}

	/**
	 * Migrates one vendor.
	 *
	 * @param int                                                                        $term_id    Vendor.
	 * @param string                                                                     $name       Its name.
	 * @param string                                                                     $status     Its status in the dry run.
	 * @param int                                                                        $user_id    The chosen user (0: none).
	 * @param int                                                                        $creator_id Its creator in the dry run (0: a new one).
	 * @param DateTimeImmutable                                                          $now        The time of the run.
	 * @param array{created: int, matched: int, linked: int, conflict: int, failed: int} $counts     Counts so far.
	 * @return array{created: int, matched: int, linked: int, conflict: int, failed: int}
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	private function vendor( int $term_id, string $name, string $status, int $user_id, int $creator_id, DateTimeImmutable $now, array $counts ): array {
		try {
			if ( 0 === $creator_id ) {
				$creator_id = $this->creators->create( $name, CreatorStatus::Active, $user_id > 0 ? $user_id : null )->id;
				self::remember( $term_id, $creator_id, true );
				++$counts['created'];
			} elseif ( VendorImportReport::MATCHED === $status ) {
				++$counts['matched'];
			}

			// Once, for a creator the migration made (also when an earlier run stopped before it).
			$stored = self::stored();

			if ( in_array( $creator_id, $stored['created'], true ) && ! in_array( $creator_id, $stored['settled'], true ) ) {
				if ( null === $this->payouts->settlement_start( $creator_id ) ) {
					$this->payouts->set_settlement_start( $creator_id, $now );
				}

				$stored['settled'][] = $creator_id;
				update_option( VendorImportPlan::OPTION, $stored, false );
			}
		} catch ( CreatorException | PayoutException ) {
			++$counts['failed'];

			return $counts;
		}

		self::remember( $term_id, $creator_id, false );
		$linked   = 0;
		$conflict = 0;
		$failed   = 0;

		foreach ( $this->source->product_ids( $term_id ) as $product_id ) {
			$current = $this->links->creator_id( $product_id );

			if ( null !== $current ) {
				$conflict += $current === $creator_id ? 0 : 1;
				continue;
			}

			try {
				$this->links->link( $product_id, $creator_id, ProductLinkSource::Admin );
				++$linked;
			} catch ( ProductCreatorLinkException ) {
				++$failed;
			}
		}

		$this->transaction->run(
			fn() => $this->audit->record(
				AuditAction::VendorImported,
				$creator_id,
				array(
					'status'   => $status,
					'linked'   => $linked,
					'conflict' => $conflict,
					'failed'   => $failed,
				)
			)
		);

		$counts['linked']   += $linked;
		$counts['conflict'] += $conflict;
		$counts['failed']   += $failed;

		return $counts;
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

	/**
	 * Stores a vendor's creator (and that the migration made it).
	 *
	 * @param int  $term_id    Vendor.
	 * @param int  $creator_id Creator.
	 * @param bool $created    Whether the migration made the creator.
	 */
	private static function remember( int $term_id, int $creator_id, bool $created ): void {
		$stored = self::stored();

		if ( ( $stored['vendors'][ $term_id ] ?? 0 ) === $creator_id && ( ! $created || in_array( $creator_id, $stored['created'], true ) ) ) {
			return;
		}

		$stored['vendors'][ $term_id ] = $creator_id;

		if ( $created ) {
			$stored['created'][] = $creator_id;
		}

		update_option( VendorImportPlan::OPTION, $stored, false );
	}

	/**
	 * The stored option, normalized.
	 *
	 * @return array{vendors: array<int, int>, created: list<int>, settled: list<int>, completed_at?: string, last?: array<string, int>}
	 */
	private static function stored(): array {
		wp_cache_delete( VendorImportPlan::OPTION, 'options' );
		$stored = get_option( VendorImportPlan::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$result = array(
			'vendors' => VendorImportPlan::mapping(),
			'created' => self::ids( $stored['created'] ?? null ),
			'settled' => self::ids( $stored['settled'] ?? null ),
		);

		if ( is_string( $stored['completed_at'] ?? null ) ) {
			$result['completed_at'] = $stored['completed_at'];
		}

		if ( is_array( $stored['last'] ?? null ) ) {
			$result['last'] = array_map( 'intval', array_filter( $stored['last'], 'is_numeric' ) );
		}

		return $result;
	}

	/**
	 * Positive ids from a stored list.
	 *
	 * @param mixed $value Stored value.
	 * @return list<int>
	 */
	private static function ids( mixed $value ): array {
		return array_values( array_filter( is_array( $value ) ? $value : array(), static fn( mixed $id ): bool => is_int( $id ) && $id > 0 ) );
	}
}
