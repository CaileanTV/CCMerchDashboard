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
 * The creator meta `billing_details` (ADR-062 K09). No authorization or
 * audit here (PayoutSettings does that). Saving empty master data removes
 * the meta; every write is read back (CreatorMetaStore).
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
	 * The master data the creator submitted for confirmation (ADR-104), or
	 * null when none waits.
	 *
	 * @param int $creator_id Creator.
	 * @return array{values: CreatorBillingDetails, status: string, submitted_at: string, note: string}|null
	 */
	public function submission( int $creator_id ): ?array {
		$stored = $this->meta->get( $creator_id, CreatorMetaStore::BILLING_SUBMISSION );

		if ( ! is_array( $stored ) || ! is_array( $stored['values'] ?? null ) ) {
			return null;
		}

		$status = in_array( $stored['status'] ?? null, array( BillingDetailsReview::PENDING, BillingDetailsReview::CHANGES_REQUESTED ), true ) ? (string) $stored['status'] : BillingDetailsReview::PENDING;

		return array(
			'values'       => CreatorBillingDetails::from_stored( $stored['values'] ),
			'status'       => $status,
			'submitted_at' => is_string( $stored['submitted_at'] ?? null ) ? $stored['submitted_at'] : '',
			'note'         => is_string( $stored['note'] ?? null ) ? $stored['note'] : '',
		);
	}

	/**
	 * Stores the submitted master data (ADR-104).
	 *
	 * @param int                   $creator_id   Creator.
	 * @param CreatorBillingDetails $values       Submitted master data.
	 * @param string                $status       BillingDetailsReview::PENDING or ::CHANGES_REQUESTED.
	 * @param string                $submitted_at When submitted (UTC, Y-m-d H:i:s).
	 * @param string                $note         The administrator's note ('' for none).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save_submission( int $creator_id, CreatorBillingDetails $values, string $status, string $submitted_at, string $note ): void {
		$this->meta->set(
			$creator_id,
			CreatorMetaStore::BILLING_SUBMISSION,
			array(
				'values'       => $values->to_stored(),
				'status'       => $status,
				'submitted_at' => $submitted_at,
				'note'         => $note,
			)
		);
	}

	/**
	 * Removes the submitted master data.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be removed.
	 */
	public function delete_submission( int $creator_id ): void {
		$this->meta->set( $creator_id, CreatorMetaStore::BILLING_SUBMISSION, array() );
	}

	/**
	 * When and by whom the master data were confirmed, or null.
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
	 * Records the confirmation (ADR-104).
	 *
	 * @param int    $creator_id Creator.
	 * @param string $at         When (UTC, Y-m-d H:i:s).
	 * @param int    $by         Administrator (user id).
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
	 * Drops the creator's cached meta (after a rolled-back transaction).
	 *
	 * @param int $creator_id Creator.
	 */
	public function forget( int $creator_id ): void {
		wp_cache_delete( $creator_id, CreatorMetaStore::META_TYPE . '_meta' );
	}

	/**
	 * The creators with submitted master data, in ascending id order.
	 *
	 * @return list<int>
	 * @throws DatabaseException When the read fails.
	 */
	public function submitted_creator_ids(): array {
		return $this->meta->creator_ids_with( CreatorMetaStore::BILLING_SUBMISSION );
	}
}
