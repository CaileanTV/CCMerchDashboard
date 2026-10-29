<?php
/**
 * Storage of the company's campaigns.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Campaigns;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Pricing\PricingRows;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_designer_campaigns` (ADR-080 pkt. 9). Writes only through
 * CampaignService, which checks the capability and audits; a campaign used
 * by a ledger row (`merchboard_sales_tax.campaign_id`) is never deleted.
 */
class CampaignRepository {

	private const COLUMNS = 'id, name, starts_at, ends_at, coupon_codes, sale_prices';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Every campaign, newest start first.
	 *
	 * @return list<Campaign>
	 * @throws DatabaseException When the campaigns cannot be read.
	 */
	public function all(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i ORDER BY starts_at DESC, id DESC', $this->table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; a custom table.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the campaigns.' );
		}

		return array_map( self::hydrate( ... ), $rows );
	}

	/**
	 * A campaign by id.
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function find( int $id ): ?Campaign {
		if ( $id < 1 ) {
			return null;
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; a custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the campaign.' );
		}

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Stores a new campaign.
	 *
	 * @param Campaign $campaign Campaign without id.
	 * @return int The new id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( Campaign $campaign ): int {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$user = get_current_user_id();
		$done = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			$this->table(),
			array_merge(
				self::columns( $campaign ),
				array(
					'created_at' => $now,
					'created_by' => $user > 0 ? $user : null,
					'updated_at' => $now,
					'updated_by' => $user > 0 ? $user : null,
				)
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d' )
		);

		if ( 1 !== $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the campaign.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Changes a campaign.
	 *
	 * @param int      $id       Id.
	 * @param Campaign $campaign New values.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update( int $id, Campaign $campaign ): void {
		$user = get_current_user_id();
		$done = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A custom table.
			$this->table(),
			array_merge(
				self::columns( $campaign ),
				array(
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
					'updated_by' => $user > 0 ? $user : null,
				)
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d' ),
			array( '%d' )
		);

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not change the campaign.' );
		}
	}

	/**
	 * Deletes a campaign.
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $id ): void {
		$done = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A custom table.

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not delete the campaign.' );
		}
	}

	/**
	 * How many ledger rows the campaign covers.
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function rows( int $id ): int {
		$wpdb  = $this->wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE campaign_id = %d', Tables::name( $wpdb, Tables::SALES_TAX ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The ledger's own table; counted live.

		if ( '' !== $wpdb->last_error || ! is_numeric( $count ) ) {
			throw new DatabaseException( 'Could not count the campaign\'s sales.' );
		}

		return (int) $count;
	}

	/**
	 * The stored columns of a campaign.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return array{name: string, starts_at: string, ends_at: string, coupon_codes: string, sale_prices: int}
	 * @throws DatabaseException When the codes cannot be encoded.
	 */
	private static function columns( Campaign $campaign ): array {
		$codes = wp_json_encode( $campaign->coupon_codes );

		if ( false === $codes ) {
			throw new DatabaseException( 'Could not encode the campaign coupon codes.' );
		}

		return array(
			'name'         => $campaign->name,
			'starts_at'    => PricingRows::datetime( $campaign->starts_at ),
			'ends_at'      => PricingRows::datetime( $campaign->ends_at ),
			'coupon_codes' => $codes,
			'sale_prices'  => $campaign->sale_prices ? 1 : 0,
		);
	}

	/**
	 * A campaign from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): Campaign {
		$decoded = json_decode( (string) ( $row['coupon_codes'] ?? '' ), true );
		$codes   = array();

		foreach ( is_array( $decoded ) ? $decoded : array( null ) as $code ) {
			if ( ! is_string( $code ) ) {
				throw new DatabaseException( 'Invalid campaign row.' );
			}

			$codes[] = $code;
		}

		try {
			return new Campaign(
				PricingRows::id( $row['id'] ?? null ),
				(string) ( $row['name'] ?? '' ),
				PricingRows::time( $row['starts_at'] ?? null ),
				PricingRows::time( $row['ends_at'] ?? null ),
				$codes,
				in_array( $row['sale_prices'] ?? null, array( 1, '1' ), true )
			);
		} catch ( InvalidArgumentException ) {
			throw new DatabaseException( 'Invalid campaign row.' );
		}
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::DESIGNER_CAMPAIGNS );
	}
}
