<?php
/**
 * Append-only storage of sales-line pricing snapshots.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_sales_line_pricing` (ADR-047, ADR-049). Insert and read only: there is no
 * update or delete, so a snapshot can never change after it was captured
 * (only opt-in uninstall drops the table). A second insert for the same order
 * item keeps the first snapshot.
 */
final class SalesLinePricingRepository {

	private const COLUMNS = 'order_item_id, order_id, creator_id, product_id, variation_id, status, currency, captured_at, share_bps, share_source, price_decimals, vat_registered, base_unit, profit_unit, price_unit';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Stores a snapshot unless the order item already has one.
	 *
	 * @param SalesLinePricing $snapshot Snapshot.
	 * @return bool True when it was stored, false when the item already had a snapshot (kept unchanged).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( SalesLinePricing $snapshot ): bool {
		if ( null !== $this->find( $snapshot->order_item_id ) ) {
			return false;
		}

		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'order_item_id'  => $snapshot->order_item_id,
				'order_id'       => $snapshot->order_id,
				'creator_id'     => $snapshot->creator_id,
				'product_id'     => $snapshot->product_id,
				'variation_id'   => $snapshot->variation_id,
				'status'         => $snapshot->status->value,
				'currency'       => $snapshot->currency,
				'captured_at'    => PricingRows::datetime( $snapshot->captured_at ),
				'share_bps'      => $snapshot->share_bps,
				'share_source'   => $snapshot->share_source?->value,
				'price_decimals' => $snapshot->price_decimals,
				'vat_registered' => null === $snapshot->vat_registered ? null : ( $snapshot->vat_registered ? 1 : 0 ),
				// Designer products only (ADR-080); the columns of ADR-047's withdrawn model.
				'base_unit'      => null === $snapshot->base_units ? null : SalesAmount::format( $snapshot->base_units ),
				'profit_unit'    => null === $snapshot->profit_units ? null : SalesAmount::format( $snapshot->profit_units ),
				'price_unit'     => null === $snapshot->price_units ? null : SalesAmount::format( $snapshot->price_units ),
			),
			// wpdb writes NULL for null values regardless of the format.
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( 1 !== $inserted ) {
			// A concurrent insert for the same item wins; that is not an error.
			if ( null !== $this->find( $snapshot->order_item_id ) ) {
				return false;
			}

			throw new DatabaseException( 'Could not store the sales line pricing.' );
		}

		return true;
	}

	/**
	 * The snapshot of an order item, or null (e.g. lines from before phase 22).
	 *
	 * @param int $order_item_id Order item.
	 * @throws InvalidArgumentException When the id is not positive.
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function find( int $order_item_id ): ?SalesLinePricing {
		if ( $order_item_id < 1 ) {
			throw new InvalidArgumentException( 'Order item id must be positive.' );
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE order_item_id = %d', $this->table(), $order_item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant column list; values are prepared.

		if ( ! is_array( $row ) ) {
			if ( '' !== $wpdb->last_error ) {
				throw new DatabaseException( 'Could not read the sales line pricing.' );
			}

			return null;
		}

		return self::hydrate( $row );
	}

	/**
	 * A snapshot from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): SalesLinePricing {
		try {
			$status = SnapshotStatus::tryFrom( (string) ( $row['status'] ?? '' ) );

			if ( null === $status ) {
				throw new DatabaseException( 'Invalid sales line pricing row.' );
			}

			return new SalesLinePricing(
				PricingRows::id( $row['order_item_id'] ?? null ),
				PricingRows::id( $row['order_id'] ?? null ),
				PricingRows::id( $row['creator_id'] ?? null ),
				PricingRows::id( $row['product_id'] ?? null ),
				is_numeric( $row['variation_id'] ?? null ) ? (int) $row['variation_id'] : -1,
				$status,
				(string) ( $row['currency'] ?? '' ),
				PricingRows::time( $row['captured_at'] ?? null ),
				self::optional_int( $row['share_bps'] ?? null ),
				null === ( $row['share_source'] ?? null ) ? null : ( MarkupSource::tryFrom( (string) $row['share_source'] ) ?? throw new DatabaseException( 'Invalid sales line pricing row.' ) ),
				self::optional_int( $row['price_decimals'] ?? null ),
				match ( isset( $row['vat_registered'] ) ? (string) $row['vat_registered'] : null ) {
					null    => null,
					'0'     => false,
					'1'     => true,
					default => throw new DatabaseException( 'Invalid sales line pricing row.' ),
				},
				// Legacy rows of ADR-047 keep their amounts, but only a designer row reads them.
				SnapshotStatus::Designer === $status ? self::optional_amount( $row['base_unit'] ?? null ) : null,
				SnapshotStatus::Designer === $status ? self::optional_amount( $row['profit_unit'] ?? null ) : null,
				SnapshotStatus::Designer === $status ? self::optional_amount( $row['price_unit'] ?? null ) : null
			);
		} catch ( InvalidArgumentException ) {
			throw new DatabaseException( 'Invalid sales line pricing row.' );
		}
	}

	/**
	 * An optional integer column.
	 *
	 * @param mixed $value Raw value.
	 * @throws DatabaseException When it is neither null nor an integer.
	 */
	private static function optional_int( mixed $value ): ?int {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_numeric( $value ) || (string) (int) $value !== (string) $value ) {
			throw new DatabaseException( 'Invalid sales line pricing row.' );
		}

		return (int) $value;
	}

	/**
	 * An optional decimal amount column in units.
	 *
	 * @param mixed $value Raw value.
	 * @throws DatabaseException When it is neither null nor a decimal.
	 */
	private static function optional_amount( mixed $value ): ?int {
		if ( null === $value ) {
			return null;
		}

		try {
			return SalesAmount::parse( (string) $value );
		} catch ( InvalidArgumentException ) {
			throw new DatabaseException( 'Invalid sales line pricing row.' );
		}
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::SALES_LINE_PRICING );
	}
}
