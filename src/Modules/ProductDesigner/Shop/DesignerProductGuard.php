<?php
/**
 * The legacy guard: which WooCommerce products are designer products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Shop;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ProtectedMetaWrite;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-080 pkt. 7. A WooCommerce product is a designer product only when all
 * three hold:
 *
 * 1. a row in `merchboard_designer_products` names it (`wc_product_id`);
 * 2. it carries exactly one protected marker (`_merchboard_designer_token`),
 *    which only this class writes (ProtectedMetaWrite::for_designer());
 * 3. the marker equals the row's secret creation token.
 *
 * Every other product is legacy: guard() gives null and nothing of the
 * designer touches it. A row whose product lacks the marker or has another
 * one is logged (never repaired): it may be a tampered or restored product.
 *
 * claim() takes a draft that WooCommerceDesignerWriter has just created: the
 * draft carries the unprotected pending meta with the token, which proves it
 * was created for this row (the token is secret and never leaves the
 * server). The row is linked first, then marked, so an interrupted claim is
 * finished by the next one and never leaves a marked product without row.
 */
class DesignerProductGuard {

	/**
	 * Unprotected meta of a draft created for a row, until it is claimed.
	 */
	public const PENDING_KEY = Uninstaller::DESIGNER_PENDING_META;

	/**
	 * Constructor.
	 *
	 * @param wpdb                 $wpdb      Database.
	 * @param ProtectedProductMeta $meta_guard The protected meta guard.
	 * @param Logger               $logger    Technical log.
	 */
	public function __construct( private wpdb $wpdb, private ProtectedProductMeta $meta_guard, private Logger $logger ) {
	}

	/**
	 * The proof that a product is a designer product, or null for every
	 * legacy product.
	 *
	 * @param int $wc_product_id WooCommerce parent product.
	 * @throws DatabaseException When the designer's data cannot be read.
	 */
	public function guard( int $wc_product_id ): ?GuardedProduct {
		if ( $wc_product_id < 1 ) {
			return null;
		}

		$row = $this->row_by_product( $wc_product_id );

		if ( null === $row ) {
			return null;
		}

		$markers = get_post_meta( $wc_product_id, ProtectedProductMeta::DESIGNER_KEY, false );

		if ( 'product' !== get_post_type( $wc_product_id ) || ! is_array( $markers ) || 1 !== count( $markers ) || ! is_string( $markers[0] ) || ! hash_equals( $row['creation_token'], $markers[0] ) ) {
			$this->logger->warning(
				'A designer product row points to a product without its marker; the product is treated as legacy.',
				array(
					'design_product_id' => $row['id'],
					'product_id'        => $wc_product_id,
				)
			);

			return null;
		}

		return new GuardedProduct( $row['id'], $wc_product_id, $row['creator_id'] );
	}

	/**
	 * The proof for a row's product, or null while the row has none (or its
	 * product fails the guard).
	 *
	 * @param int $design_product_id Row in `merchboard_designer_products`.
	 * @throws DatabaseException When the designer's data cannot be read.
	 */
	public function for_row( int $design_product_id ): ?GuardedProduct {
		$row = $this->row( $design_product_id );

		return null === $row || null === $row['wc_product_id'] ? null : $this->guard( $row['wc_product_id'] );
	}

	/**
	 * A row's secret creation token, for the pending meta of its draft. Never
	 * leaves the server.
	 *
	 * @param int $design_product_id Row in `merchboard_designer_products`.
	 * @throws DesignerException When the row does not exist.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function token( int $design_product_id ): string {
		$row = $this->row( $design_product_id );

		if ( null === $row ) {
			self::refuse();
		}

		return $row['creation_token'];
	}

	/**
	 * Links and marks a draft created for a designer product row, and proves it.
	 * Repeating it for the same row and product finishes an interrupted claim.
	 *
	 * @param int $design_product_id Row in `merchboard_designer_products`.
	 * @param int $wc_product_id     The draft WooCommerceDesignerWriter created for it.
	 * @throws DesignerException When the product was not created for this row, or the row has another product.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function claim( int $design_product_id, int $wc_product_id ): GuardedProduct {
		$row = $this->row( $design_product_id );

		if ( null === $row || $wc_product_id < 1 || ( null !== $row['wc_product_id'] && $row['wc_product_id'] !== $wc_product_id ) || ! $this->created_for( $wc_product_id, $row['creation_token'] ) ) {
			self::refuse();
		}

		if ( null === $row['wc_product_id'] ) {
			$this->link( $design_product_id, $wc_product_id );
		}

		$token = $row['creation_token'];
		$this->meta_guard->run(
			ProtectedMetaWrite::for_designer( $wc_product_id ),
			static function () use ( $wc_product_id, $token ): void {
				delete_post_meta( $wc_product_id, ProtectedProductMeta::DESIGNER_KEY );
				add_post_meta( $wc_product_id, ProtectedProductMeta::DESIGNER_KEY, $token, true );
			}
		);
		delete_post_meta( $wc_product_id, self::PENDING_KEY );

		return $this->proven( $design_product_id, $wc_product_id );
	}

	/**
	 * Links a row to its product; only one claim wins.
	 *
	 * @param int $design_product_id Row.
	 * @param int $wc_product_id     Product.
	 * @throws DatabaseException When the row cannot be linked.
	 */
	private function link( int $design_product_id, int $wc_product_id ): void {
		$wpdb    = $this->wpdb;
		$sql     = $wpdb->prepare( 'UPDATE %i SET wc_product_id = %d, updated_at = %s WHERE id = %d AND wc_product_id IS NULL', Tables::name( $wpdb, Tables::DESIGNER_PRODUCTS ), $wc_product_id, gmdate( 'Y-m-d H:i:s' ), $design_product_id );
		$updated = is_string( $sql ) ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the designer's own table, conditional, so only one claim wins.

		if ( 1 !== $updated ) {
			throw new DatabaseException( 'Could not link the designer product.', array( 'design_product_id' => $design_product_id ) );
		}
	}

	/**
	 * The proof right after marking.
	 *
	 * @param int $design_product_id Row.
	 * @param int $wc_product_id     Product.
	 * @throws DatabaseException When the product is not proven after marking.
	 */
	private function proven( int $design_product_id, int $wc_product_id ): GuardedProduct {
		$proof = $this->guard( $wc_product_id );

		if ( null === $proof ) {
			throw new DatabaseException( 'Could not mark the designer product.', array( 'design_product_id' => $design_product_id ) );
		}

		return $proof;
	}

	/**
	 * Whether a product was created for a token: the claimed marker, or the
	 * pending meta of a draft.
	 *
	 * @param int    $wc_product_id Product.
	 * @param string $token         The row's creation token.
	 */
	private function created_for( int $wc_product_id, string $token ): bool {
		if ( 'product' !== get_post_type( $wc_product_id ) ) {
			return false;
		}

		foreach ( array( ProtectedProductMeta::DESIGNER_KEY, self::PENDING_KEY ) as $key ) {
			$values = get_post_meta( $wc_product_id, $key, false );

			if ( is_array( $values ) && 1 === count( $values ) && is_string( $values[0] ) && hash_equals( $token, $values[0] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The row that names a product.
	 *
	 * @param int $wc_product_id Product.
	 * @return array{id: int, creator_id: int, creation_token: string, wc_product_id: int|null}|null
	 * @throws DatabaseException When it cannot be read.
	 */
	private function row_by_product( int $wc_product_id ): ?array {
		return $this->select( 'wc_product_id', $wc_product_id );
	}

	/**
	 * A row by id.
	 *
	 * @param int $design_product_id Row.
	 * @return array{id: int, creator_id: int, creation_token: string, wc_product_id: int|null}|null
	 * @throws DatabaseException When it cannot be read.
	 */
	private function row( int $design_product_id ): ?array {
		return $this->select( 'id', $design_product_id );
	}

	/**
	 * One row by a unique column.
	 *
	 * @phpstan-param 'id'|'wc_product_id' $column
	 *
	 * @param string $column Unique column.
	 * @param int    $value  Its value.
	 * @return array{id: int, creator_id: int, creation_token: string, wc_product_id: int|null}|null
	 * @throws DatabaseException When it cannot be read.
	 */
	private function select( string $column, int $value ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT id, creator_id, creation_token, wc_product_id FROM %i WHERE %i = %d', Tables::name( $wpdb, Tables::DESIGNER_PRODUCTS ), $column, $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table; read live for the guard.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the designer product.' );
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		$token = (string) ( $row['creation_token'] ?? '' );

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) || ! is_numeric( $row['id'] ?? null ) || ! is_numeric( $row['creator_id'] ?? null ) ) {
			throw new DatabaseException( 'Invalid designer product row.' );
		}

		return array(
			'id'             => (int) $row['id'],
			'creator_id'     => (int) $row['creator_id'],
			'creation_token' => $token,
			'wc_product_id'  => is_numeric( $row['wc_product_id'] ?? null ) ? (int) $row['wc_product_id'] : null,
		);
	}

	/**
	 * Refuses a claim.
	 *
	 * @throws DesignerException Always.
	 */
	private static function refuse(): never {
		throw new DesignerException( DesignerException::NOT_DESIGNER_PRODUCT );
	}
}
