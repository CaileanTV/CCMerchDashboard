<?php
/**
 * The News module's part of deleting a creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Deleting a creator (phase 27, step 27.3, ADR-076): which news the creator
 * saw and closed is deleted; the news stay. Idempotent.
 */
class NewsCreatorCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The key in the audit record.
	 */
	public function key(): string {
		return 'news';
	}

	/**
	 * Nothing to check.
	 *
	 * @param Creator $creator The creator.
	 */
	public function check( Creator $creator ): void {
	}

	/**
	 * Deletes the creator's news reads.
	 *
	 * @param Creator $creator The creator (inactive).
	 * @return array<string, int>
	 * @throws DatabaseException When they cannot be deleted.
	 */
	public function remove( Creator $creator ): array {
		$deleted = $this->wpdb->delete( Tables::name( $this->wpdb, Tables::NEWS_READS ), array( 'creator_id' => $creator->id ), array( '%d' ) );

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not delete the news reads of the deleted creator.' );
		}

		return array( 'reads' => $deleted );
	}
}
