<?php
/**
 * Read access to the actual tax of sales ledger rows.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `merchboard_sales_tax` (ADR-048) for read models such as the payout.
 * Written only by SalesRepository::write() in the ledger's transaction; this
 * class never writes.
 */
final class SalesTaxRepository {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The actual tax of one ledger row, or null when none is stored (rows
	 * registered before schema version 7, or with unknown tax). `campaign_id`
	 * is the company's campaign that covers a designer product's row (ADR-080
	 * pkt. 9), null for none. `designer_profit` and `designer_payout` are the
	 * amounts stored with a designer product's row (null: none or unknown),
	 * which RealizedProfit and RealizedPayout return as they are.
	 *
	 * @param string $event_key The row's event key.
	 * @return array{tax: int, currency: string, campaign_id?: int|null, designer_profit?: int|null, designer_payout?: int|null}|null Signed tax in 1/10000 units and its currency.
	 * @throws DatabaseException When the query fails or the stored amount is invalid.
	 * @phpstan-impure Reads the database.
	 */
	public function find( string $event_key ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT tax_amount, currency, campaign_id, designer_profit, designer_payout FROM %i WHERE event_key = %s', Tables::name( $wpdb, Tables::SALES_TAX ), $event_key ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			if ( '' !== $wpdb->last_error ) {
				throw new DatabaseException( 'Could not read the sales tax.' );
			}

			return null;
		}

		$amount = (string) ( $row['tax_amount'] ?? '' );

		if ( 1 !== preg_match( '/^-?\d{1,15}(?:\.\d{1,4})?$/', $amount ) ) {
			throw new DatabaseException( 'Invalid sales tax row.' );
		}

		$stored = static fn( mixed $value ): ?int => is_string( $value ) && 1 === preg_match( '/^-?\d{1,15}(?:\.\d{1,4})?$/', $value ) ? SalesAmount::parse( $value ) : null;

		return array(
			'tax'             => SalesAmount::parse( $amount ),
			'currency'        => (string) ( $row['currency'] ?? '' ),
			'campaign_id'     => is_numeric( $row['campaign_id'] ?? null ) ? (int) $row['campaign_id'] : null,
			'designer_profit' => $stored( $row['designer_profit'] ?? null ),
			'designer_payout' => $stored( $row['designer_payout'] ?? null ),
		);
	}
}
