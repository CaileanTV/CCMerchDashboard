<?php
/**
 * The creator's products for the creator dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only selection of `GET /me/products` (ADR-039 P7, P9): parent
 * products (`post_type = 'product'`, so never variations) with status
 * `publish` or `private` that have exactly one creator link entry, and that
 * entry is the creator's id. The rule mirrors ProductRecord::creator_id(): a
 * product with several link entries (also another spelling of the key, which
 * MySQL compares case-insensitively) or an entry that is not exactly the id
 * belongs to nobody, so it is never listed for any creator. The value is
 * compared byte for byte, so "12 " or "012" never match creator 12.
 *
 * Products are posts (also with HPOS, which only moves orders), so the
 * selection is plain SQL with an exact total and a deterministic order
 * (`post_title`, then `ID`); the product data itself is read through
 * WooCommerce CRUD (ProductDetails). The creator id always comes from the
 * caller (CreatorContext on the server), never from a request.
 */
final class CreatorProducts {

	/**
	 * The selection shared by count() and ids(). Placeholders: posts table,
	 * postmeta table, link key, postmeta table, link key, creator id.
	 */
	private const FROM_WHERE = "FROM %i p
		WHERE p.post_type = 'product'
		AND p.post_status IN ('publish', 'private')
		AND ( SELECT COUNT(*) FROM %i lc WHERE lc.post_id = p.ID AND lc.meta_key = %s ) = 1
		AND EXISTS (
			SELECT 1 FROM %i lm
			WHERE lm.post_id = p.ID AND lm.meta_key = %s AND CAST( lm.meta_value AS BINARY ) = CAST( %s AS BINARY )
		)";

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Number of the creator's listed products.
	 *
	 * @param int $creator_id Creator id.
	 * @throws DatabaseException When the query fails.
	 */
	public function count( int $creator_id ): int {
		if ( $creator_id < 1 ) {
			return 0;
		}

		$wpdb  = $this->wpdb;
		$total = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read model of the creator's products; no caching in phase 19 (ADR-039 P12).
			$wpdb->prepare( 'SELECT COUNT(*) ' . self::FROM_WHERE, ...$this->selection_args( $creator_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- FROM_WHERE is a constant with placeholders only; selection_args() fills them.
		);

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not count the creator\'s products.' );
		}

		return is_numeric( $total ) ? (int) $total : 0;
	}

	/**
	 * Ids of the creator's listed products on the requested page, by name and
	 * then id.
	 *
	 * @param int        $creator_id Creator id.
	 * @param Pagination $pagination Requested page.
	 * @return list<int>
	 * @throws DatabaseException When the query fails.
	 */
	public function ids( int $creator_id, Pagination $pagination ): array {
		if ( $creator_id < 1 ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$ids  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read model of the creator's products; no caching in phase 19 (ADR-039 P12).
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- FROM_WHERE's six placeholders come from selection_args(), then LIMIT and OFFSET.
				'SELECT p.ID ' . self::FROM_WHERE . ' ORDER BY p.post_title ASC, p.ID ASC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- FROM_WHERE is a constant with placeholders only.
				...array_merge( $this->selection_args( $creator_id ), array( $pagination->per_page, $pagination->offset() ) )
			)
		);

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the creator\'s products.' );
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Values for the placeholders of FROM_WHERE.
	 *
	 * @param int $creator_id Creator id.
	 * @return list<string>
	 */
	private function selection_args( int $creator_id ): array {
		return array(
			$this->wpdb->posts,
			$this->wpdb->postmeta,
			ProductCreatorLink::META_KEY,
			$this->wpdb->postmeta,
			ProductCreatorLink::META_KEY,
			(string) $creator_id,
		);
	}
}
