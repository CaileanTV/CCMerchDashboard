<?php
/**
 * " - " before the creator's addition in the names of existing designer
 * products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;
use MerchBoard\Modules\WooCommerce\JobQueue;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-102 (the owner's choice 2026-11-08): a name with the creator's addition
 * is "Creator Base product - Addition". Once after the update, the Action
 * Scheduler job `merchboard_designer_names` goes through every designer
 * product, 50 at a time, under the product's named lock (as the repricing
 * and the apply pipeline):
 *
 * - its drafts, waiting and approved versions get " - " before the addition,
 *   each worked out by DesignerNames and written only while the version
 *   still has the name read (a name that would pass 120 characters loses
 *   the end of its addition);
 * - a product in the shop gets its new name there first, only through
 *   WooCommerceDesignerWriter on the guard's proof (legacy products never),
 *   so a failure there leaves the versions for the next run.
 *
 * A name without an addition, one with the separator and one from before
 * the naming rule stay. A new name another product already has is skipped
 * and logged. The names count as done when the last batch has run; a chain
 * that stopped starts again from the first product at the next admin page
 * (nothing is renamed twice). Nothing is audited, as with a creator's new
 * name reaching their products. It never throws.
 */
class DesignerNameSeparator {

	public const HOOK = 'merchboard_designer_names';

	/**
	 * The names' format reached (an option without autoload).
	 */
	public const OPTION = 'merchboard_designer_name_format';

	public const FORMAT = 2;

	private const BATCH = 50;

	private const LOCK = 'merchboard_designer_product_';

	private const RETRY_DELAY = 60;

	/**
	 * The longest name a creator can save (DesignerDrafts::save()).
	 */
	private const MAX_TITLE = 120;

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb     Database (the named lock).
	 * @param DesignerProductRepository $products Designer products.
	 * @param BaseProductRepository     $bases    Base products.
	 * @param CreatorRepository         $creators Creators.
	 * @param DesignerProductGuard      $guard    The legacy guard.
	 * @param WooCommerceDesignerWriter $writer   The only writer of designer products.
	 * @param JobQueue                  $queue    Job queue.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct(
		private wpdb $wpdb,
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private CreatorRepository $creators,
		private DesignerProductGuard $guard,
		private WooCommerceDesignerWriter $writer,
		private JobQueue $queue,
		private Logger $logger
	) {
	}

	/**
	 * Whether the names still have the old format.
	 */
	public static function due(): bool {
		return (int) get_option( self::OPTION, 0 ) < self::FORMAT;
	}

	/**
	 * Queues the job (`admin_init` after the update) unless it is queued or
	 * done; never throws.
	 */
	public function start(): void {
		if ( ! self::due() || ! $this->queue->available() || $this->queue->has_any( self::HOOK ) ) {
			return;
		}

		$this->queue->enqueue( self::HOOK, array( 0 ) );
	}

	/**
	 * Renames the products after an id and queues the next ones (the job);
	 * never throws.
	 *
	 * @param mixed $after The last product done.
	 */
	public function run( mixed $after = 0 ): void {
		$after = is_numeric( $after ) ? max( 0, (int) $after ) : 0;

		try {
			$rows = $this->products->name_batch( $after, self::BATCH );
		} catch ( Throwable $e ) {
			$this->logger->error( 'The designer products could not be read for their names.', array( 'exception' => $e::class ) );
			$this->queue->enqueue( self::HOOK, array( $after ), self::RETRY_DELAY );

			return;
		}

		foreach ( $rows as $row ) {
			if ( ! $this->rename( $row ) ) {
				// Busy: this product and the rest a little later.
				$this->queue->enqueue( self::HOOK, array( $row['id'] - 1 ), self::RETRY_DELAY );

				return;
			}
		}

		if ( count( $rows ) === self::BATCH ) {
			$this->queue->enqueue( self::HOOK, array( $rows[ self::BATCH - 1 ]['id'] ) );

			return;
		}

		// The last batch: done (autoloaded, as it is read on every admin page).
		update_option( self::OPTION, self::FORMAT, true );
	}

	/**
	 * Renames one product; false when another job holds it.
	 *
	 * @param array{id: int, creator_id: int, base_id: int, status: string} $row Product.
	 */
	private function rename( array $row ): bool {
		$wpdb     = $this->wpdb;
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK . $row['id'], 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		if ( ! is_numeric( $acquired ) || 1 !== (int) $acquired ) {
			return false;
		}

		try {
			$this->separate( $row );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'A designer product could not get " - " in its name.',
				array(
					'design_product_id' => $row['id'],
					'exception'         => $e::class,
				)
			);
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK . $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
		}

		return true;
	}

	/**
	 * The product's versions and, in the shop, its name there.
	 *
	 * @param array{id: int, creator_id: int, base_id: int, status: string} $row Product.
	 * @throws Throwable When it cannot be read or written.
	 */
	private function separate( array $row ): void {
		$creator = $this->creators->find( $row['creator_id'] );
		$base    = $this->bases->find( $row['base_id'] );

		if ( null === $creator || null === $base ) {
			return;
		}

		$prefix  = DesignerNames::prefix( $creator->display_name, $base->name );
		$renames = array();

		foreach ( $this->products->named_versions( $row['id'] ) as $version ) {
			$wanted = $this->wanted( $version['title'], $prefix, $base->name );

			if ( null === $wanted ) {
				continue;
			}

			if ( $this->products->name_taken( $wanted, $row['id'] ) ) {
				$this->logger->warning( 'Another designer product has the name with " - ": this one keeps its name.', array( 'design_product_id' => $row['id'] ) );

				return;
			}

			$renames[] = array( $version, $wanted );
		}

		if ( array() === $renames ) {
			return;
		}

		$live  = DesignerProductRepository::LIVE === $row['status'] ? $this->products->live( $row['id'] ) : null;
		$proof = null === $live ? null : $this->guard->for_row( $row['id'] );

		foreach ( $renames as [ $version, $wanted ] ) {
			// The shop first: if it fails, the versions keep the old name for the next run.
			if ( null !== $proof && (int) ( $live['id'] ?? 0 ) === $version['id'] ) {
				$this->writer->set_name( $proof, $wanted );
			}
		}

		foreach ( $renames as [ $version, $wanted ] ) {
			$this->products->retitle( $version['id'], $version['title'], $wanted );
		}
	}

	/**
	 * A version's name with " - " before the addition, or null when it stays:
	 * no addition, the separator already there, or a name from before the
	 * naming rule.
	 *
	 * @param string $title  The stored name.
	 * @param string $prefix The fixed part.
	 * @param string $base   The base product's name.
	 */
	private function wanted( string $title, string $prefix, string $base ): ?string {
		if ( ! str_starts_with( $title, $prefix . ' ' ) ) {
			return null;
		}

		$addition = DesignerNames::postfix( $title, $prefix, $base );
		$room     = self::MAX_TITLE - mb_strlen( $prefix ) - mb_strlen( DesignerNames::SEPARATOR );
		$wanted   = DesignerNames::full( $prefix, mb_substr( $addition, 0, max( 1, $room ) ) );

		return $wanted === $title ? null : $wanted;
	}
}
