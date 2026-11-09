<?php
/**
 * Storage of the creators' master data for settlement documents.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Creators\CreatorMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * The creator meta `billing_details` (ADR-062 K09), when and by whom they
 * were last saved (`billing_confirmed`) and a private person's encrypted CPR
 * number (`billing_cpr`, ADR-104). No authorization or audit here
 * (PayoutSettings and BillingDetailsReview do that). Saving empty master data
 * removes the meta; every write is read back (CreatorMetaStore).
 */
final class CreatorBillingDetailsStore {

	/**
	 * Constructor.
	 *
	 * @param CreatorMetaStore $meta Creator meta.
	 */
	public function __construct( private CreatorMetaStore $meta ) {
	}

	/**
	 * The creator's master data (all fields '' when none is registered).
	 *
	 * @param int $creator_id Creator.
	 */
	public function find( int $creator_id ): CreatorBillingDetails {
		return CreatorBillingDetails::from_stored( $this->meta->get( $creator_id, CreatorMetaStore::BILLING_DETAILS ) );
	}

	/**
	 * Stores the creator's master data.
	 *
	 * @param int                   $creator_id Creator.
	 * @param CreatorBillingDetails $details    Master data.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save( int $creator_id, CreatorBillingDetails $details ): void {
		$this->meta->set( $creator_id, CreatorMetaStore::BILLING_DETAILS, $details->to_stored() );
	}

	/**
	 * When and by whom the master data were last saved, or null.
	 *
	 * @param int $creator_id Creator.
	 * @return array{at: string, by: int}|null
	 */
	public function confirmation( int $creator_id ): ?array {
		$stored = $this->meta->get( $creator_id, CreatorMetaStore::BILLING_CONFIRMED );

		return is_array( $stored ) && is_string( $stored['at'] ?? null ) ? array(
			'at' => $stored['at'],
			'by' => is_int( $stored['by'] ?? null ) ? $stored['by'] : 0,
		) : null;
	}

	/**
	 * Records when and by whom the master data were saved.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $at         When (UTC, Y-m-d H:i:s).
	 * @param int    $by         User id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save_confirmation( int $creator_id, string $at, int $by ): void {
		$this->meta->set(
			$creator_id,
			CreatorMetaStore::BILLING_CONFIRMED,
			array(
				'at' => $at,
				'by' => $by,
			)
		);
	}

	/**
	 * The private person's encrypted CPR number, or null.
	 *
	 * @param int $creator_id Creator.
	 * @return array{ciphertext: string, key_id: string}|null
	 */
	public function cpr( int $creator_id ): ?array {
		$stored = $this->meta->get( $creator_id, CreatorMetaStore::BILLING_CPR );

		return is_array( $stored ) && is_string( $stored['ciphertext'] ?? null ) && is_string( $stored['key_id'] ?? null ) ? array(
			'ciphertext' => $stored['ciphertext'],
			'key_id'     => $stored['key_id'],
		) : null;
	}

	/**
	 * Stores the encrypted CPR number.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $ciphertext PayoutCipher's ciphertext.
	 * @param string $key_id     PayoutCipher's key id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save_cpr( int $creator_id, string $ciphertext, string $key_id ): void {
		$this->meta->set(
			$creator_id,
			CreatorMetaStore::BILLING_CPR,
			array(
				'ciphertext' => $ciphertext,
				'key_id'     => $key_id,
			)
		);
	}

	/**
	 * Removes the CPR number (a business, or the creator's deletion).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be removed.
	 */
	public function delete_cpr( int $creator_id ): void {
		$this->meta->set( $creator_id, CreatorMetaStore::BILLING_CPR, array() );
	}

	/**
	 * Removes a submission left from before the confirmation was dropped.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be removed.
	 */
	public function delete_submission( int $creator_id ): void {
		$this->meta->set( $creator_id, CreatorMetaStore::BILLING_SUBMISSION, array() );
	}

	/**
	 * Drops the creator's cached meta (after a rolled-back transaction).
	 *
	 * @param int $creator_id Creator.
	 */
	public function forget( int $creator_id ): void {
		wp_cache_delete( $creator_id, CreatorMetaStore::META_TYPE . '_meta' );
	}
}
