<?php
/**
 * Storage of the creators' VAT status.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_creators.vat_registered` (ADR-048): whether the creator is VAT
 * registered. Business data on the creator entity, never WordPress or
 * WooCommerce user meta. Existing and new creators are not VAT registered
 * (0) until an admin sets it. No authorization or audit here
 * (CreatorPricingSettings does that).
 */
final class CreatorVatRepository {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Whether a creator is VAT registered, or null when the creator does not exist.
	 *
	 * @param int $creator_id Creator.
	 * @throws InvalidArgumentException When the id is not positive.
	 * @throws DatabaseException When the database cannot be read or the value is invalid.
	 * @phpstan-impure Reads the database.
	 */
	public function find( int $creator_id ): ?bool {
		if ( $creator_id < 1 ) {
			throw new InvalidArgumentException( 'Creator id must be positive.' );
		}

		$wpdb  = $this->wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT vat_registered FROM %i WHERE id = %d', $this->table(), $creator_id ) );

		if ( null === $value ) {
			if ( '' !== $wpdb->last_error ) {
				throw new DatabaseException( 'Could not read the creator VAT status.' );
			}

			return null;
		}

		return match ( (string) $value ) {
			'0'     => false,
			'1'     => true,
			default => throw new DatabaseException( 'Invalid creator VAT status.' ),
		};
	}

	/**
	 * Stores a creator's VAT status and verifies it by reading it back.
	 *
	 * @param int  $creator_id     Creator.
	 * @param bool $vat_registered VAT registered.
	 * @throws DatabaseException When the creator does not exist or the value cannot be stored.
	 */
	public function save( int $creator_id, bool $vat_registered ): void {
		if ( null === $this->find( $creator_id ) ) {
			throw new DatabaseException( 'Creator not found.' );
		}

		$updated = $this->wpdb->update( $this->table(), array( 'vat_registered' => $vat_registered ? 1 : 0 ), array( 'id' => $creator_id ), array( '%d' ), array( '%d' ) );

		// 0 rows when the value is unchanged; the read-back decides.
		if ( false === $updated || $this->find( $creator_id ) !== $vat_registered ) {
			throw new DatabaseException( 'Could not store the creator VAT status.' );
		}
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::CREATORS );
	}
}
