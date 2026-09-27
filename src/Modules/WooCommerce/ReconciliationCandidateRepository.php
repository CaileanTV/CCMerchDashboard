<?php
/**
 * Reconciliation candidates straight from WordPress' tables.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * One read-only query per page. Products are posts (also with HPOS, which
 * only moves orders), categories are term relationships and the link origin
 * is post meta, so WooCommerce's query layer (which cannot combine "in these
 * terms OR with this meta" nor paginate by id) is not needed. MySQL compares
 * meta keys case-insensitively, so origin entries with another key spelling
 * are candidates too; the reconciliation treats them as manual.
 */
final class ReconciliationCandidateRepository implements ReconciliationCandidates {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The next page of candidate ids in the scope after $after_id, ascending.
	 * One static query; the scope only switches its branches on or off.
	 *
	 * @param ReconciliationScope $scope    Which products.
	 * @param int                 $after_id Last id of the previous page (0 to start).
	 * @param int                 $limit    Page size.
	 * @return list<int>
	 * @throws DatabaseException When the query fails.
	 */
	public function after( ReconciliationScope $scope, int $after_id, int $limit ): array {
		$wpdb = $this->wpdb;
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM %i p
				WHERE p.post_type = 'product'
				AND p.post_status NOT IN ('trash', 'auto-draft')
				AND p.ID > %d
				AND (
					(
						%d = 1 AND (
							EXISTS (
								SELECT 1 FROM %i tr
								INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
								INNER JOIN %i m ON m.term_id = tt.term_id
								WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_cat'
							)
							OR EXISTS ( SELECT 1 FROM %i pm WHERE pm.post_id = p.ID AND pm.meta_key = %s )
						)
					)
					OR EXISTS (
						SELECT 1 FROM %i tr
						INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
						WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_cat' AND tt.term_id = %d
					)
					OR (
						%d = 1
						AND EXISTS ( SELECT 1 FROM %i pm WHERE pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s )
						AND EXISTS ( SELECT 1 FROM %i pm WHERE pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s )
					)
				)
				ORDER BY p.ID ASC
				LIMIT %d",
				$wpdb->posts,
				max( 0, $after_id ),
				$scope->is_all() ? 1 : 0,
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				Tables::name( $wpdb, Tables::CREATOR_CATEGORIES ),
				$wpdb->postmeta,
				ProductCreatorLink::SOURCE_META_KEY,
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$scope->term_id ?? 0,
				null === $scope->creator_id ? 0 : 1,
				$wpdb->postmeta,
				ProductCreatorLink::META_KEY,
				(string) ( $scope->creator_id ?? 0 ),
				$wpdb->postmeta,
				ProductCreatorLink::SOURCE_META_KEY,
				ProductLinkSource::Category->value,
				max( 1, $limit )
			)
		);

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read reconciliation candidates.' );
		}

		return array_map( 'intval', $ids );
	}
}
