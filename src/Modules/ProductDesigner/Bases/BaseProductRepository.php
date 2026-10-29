<?php
/**
 * Storage of base products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Bases;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_designer_bases` (ADR-080 pkt. 2). Writes only through
 * BaseProductService, which checks the capability and audits.
 */
class BaseProductRepository {

	private const COLUMNS = 'id, status, source, name, description, base_price, currency, category_id, colors, sizes, views, version';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Every base product, by name.
	 *
	 * @return list<BaseProduct>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function all(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i ORDER BY name, id', $this->table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own table.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the base products.' );
		}

		return array_map( self::hydrate( ... ), $rows );
	}

	/**
	 * A base product by id.
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function find( int $id ): ?BaseProduct {
		if ( $id < 1 ) {
			return null;
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the base product.' );
		}

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Stores a new base product.
	 *
	 * @param BaseProduct $base Base product without id.
	 * @return int The new id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( BaseProduct $base ): int {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$user = get_current_user_id();
		$done = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The designer's own table.
			$this->table(),
			array_merge(
				self::columns( $base ),
				array(
					'created_at' => $now,
					'created_by' => $user > 0 ? $user : null,
					'updated_at' => $now,
					'updated_by' => $user > 0 ? $user : null,
				)
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d' )
		);

		if ( 1 !== $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the base product.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Changes a base product.
	 *
	 * @param int         $id   Id.
	 * @param BaseProduct $base New values (with the version to store).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update( int $id, BaseProduct $base ): void {
		$user = get_current_user_id();
		$done = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table(),
			array_merge(
				self::columns( $base ),
				array(
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
					'updated_by' => $user > 0 ? $user : null,
				)
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d' ),
			array( '%d' )
		);

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not change the base product.' );
		}
	}

	/**
	 * Deletes a base product.
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $id ): void {
		$done = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not delete the base product.' );
		}
	}

	/**
	 * Marks a base product as imported from a provider (step 30.12).
	 *
	 * @param int    $id       Id.
	 * @param string $source   `printful` or `gelato`.
	 * @param string $ref      The provider's product id.
	 * @param string $template JSON: the provider's placement by side key.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_provider( int $id, string $source, string $ref, string $template ): void {
		$done = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table(),
			array(
				'source'            => $source,
				'provider_ref'      => $ref,
				'provider_template' => $template,
			),
			array( 'id' => $id )
		);

		if ( false === $done ) {
			throw new DatabaseException( 'Could not store the provider of the base product.' );
		}
	}

	/**
	 * The provider data of a base product: source, product id and the
	 * provider's placement by side key; null for an own base product.
	 *
	 * @param int $id Id.
	 * @return array{source: string, ref: string, placements: array<string, string>}|null
	 * @throws DatabaseException When it cannot be read.
	 */
	public function provider( int $id ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT source, provider_ref, provider_template FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the provider of the base product.' );
		}

		if ( ! is_array( $row ) || 'own' === $row['source'] || ! is_string( $row['provider_ref'] ) || '' === $row['provider_ref'] ) {
			return null;
		}

		$decoded    = json_decode( (string) $row['provider_template'], true );
		$placements = array();

		foreach ( is_array( $decoded ) ? $decoded : array() as $key => $placement ) {
			if ( is_string( $placement ) ) {
				$placements[ (string) $key ] = $placement;
			}
		}

		return array(
			'source'     => (string) $row['source'],
			'ref'        => $row['provider_ref'],
			'placements' => $placements,
		);
	}

	/**
	 * How many designer products use a base product.
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function products( int $id ): int {
		$wpdb  = $this->wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE base_id = %d', Tables::name( $wpdb, Tables::DESIGNER_PRODUCTS ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table; counted live.

		if ( '' !== $wpdb->last_error || ! is_numeric( $count ) ) {
			throw new DatabaseException( 'Could not count the base product\'s designs.' );
		}

		return (int) $count;
	}

	/**
	 * The stored columns.
	 *
	 * @param BaseProduct $base Base product.
	 * @return array<string, int|string|null>
	 * @throws DatabaseException When the JSON cannot be encoded.
	 */
	private static function columns( BaseProduct $base ): array {
		$colors = wp_json_encode( $base->colors );
		$sizes  = wp_json_encode( $base->sizes );
		$views  = wp_json_encode( array_map( static fn( BaseView $view ): array => $view->to_array(), $base->views ) );

		if ( false === $colors || false === $sizes || false === $views ) {
			throw new DatabaseException( 'Could not encode the base product.' );
		}

		return array(
			'status'      => $base->status,
			'source'      => $base->source,
			'name'        => $base->name,
			'description' => $base->description,
			'base_price'  => SalesAmount::format( $base->base_units ),
			'currency'    => $base->currency,
			'category_id' => $base->category_id,
			'colors'      => $colors,
			'sizes'       => $sizes,
			'views'       => $views,
			'version'     => $base->version,
		);
	}

	/**
	 * A base product from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): BaseProduct {
		$colors = json_decode( (string) ( $row['colors'] ?? '' ), true );
		$sizes  = json_decode( (string) ( $row['sizes'] ?? '' ), true );
		$views  = json_decode( (string) ( $row['views'] ?? '' ), true );

		if ( ! is_array( $colors ) || ! is_array( $sizes ) || ! is_array( $views ) ) {
			throw new DatabaseException( 'Invalid base product row.' );
		}

		try {
			return new BaseProduct(
				is_numeric( $row['id'] ?? null ) ? (int) $row['id'] : 0,
				(string) ( $row['status'] ?? '' ),
				(string) ( $row['source'] ?? '' ),
				(string) ( $row['name'] ?? '' ),
				(string) ( $row['description'] ?? '' ),
				SalesAmount::parse( (string) ( $row['base_price'] ?? '' ) ),
				(string) ( $row['currency'] ?? '' ),
				is_numeric( $row['category_id'] ?? null ) ? (int) $row['category_id'] : null,
				array_values(
					array_map(
						static fn( mixed $color ): array => array(
							'key'  => is_array( $color ) && is_string( $color['key'] ?? null ) ? $color['key'] : '',
							'name' => is_array( $color ) && is_string( $color['name'] ?? null ) ? $color['name'] : '',
							'hex'  => is_array( $color ) && is_string( $color['hex'] ?? null ) ? $color['hex'] : '',
						),
						$colors
					)
				),
				array_values( array_map( static fn( mixed $size ): string => is_string( $size ) ? $size : '', $sizes ) ),
				array_values( array_map( BaseView::from_array( ... ), $views ) ),
				is_numeric( $row['version'] ?? null ) ? (int) $row['version'] : 0
			);
		} catch ( InvalidArgumentException ) {
			throw new DatabaseException( 'Invalid base product row.' );
		}
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::DESIGNER_BASES );
	}
}
