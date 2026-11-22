<?php
/**
 * When a product stopped being a collection on purpose.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use Throwable;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 7 with the status note of 2026-11-22: the collection plugin
 * never removes a collection's list of designs, so the list alone cannot say
 * whether a simple product is a collection that lost its type or one changed
 * on purpose.
 *
 * - A type change while that plugin is active is on purpose: the product is
 *   marked as no longer a collection. The type given back takes the mark off.
 * - For a product changed before this was recorded the administrator says it
 *   on the "Collections" tab (release()).
 *
 * The plugin's own data is never touched.
 */
final class CollectionTypeChanges {

	/**
	 * Constructor.
	 *
	 * @param DesignCollections $collections The reader.
	 * @param AuditTrail        $audit       Audit trail.
	 * @param Logger            $logger      Technical log.
	 */
	public function __construct( private DesignCollections $collections, private AuditTrail $audit, private Logger $logger ) {
	}

	/**
	 * Hooks the recording in.
	 */
	public function register(): void {
		add_action( 'set_object_terms', array( $this, 'terms_set' ), 10, 6 );
	}

	/**
	 * `set_object_terms`: records a change of the product type.
	 *
	 * @param mixed $object_id  Object id.
	 * @param mixed $terms      Terms as passed.
	 * @param mixed $tt_ids     New term_taxonomy ids.
	 * @param mixed $taxonomy   Taxonomy.
	 * @param mixed $append     Whether appended.
	 * @param mixed $old_tt_ids Previous term_taxonomy ids.
	 */
	public function terms_set( mixed $object_id, mixed $terms = array(), mixed $tt_ids = array(), mixed $taxonomy = '', mixed $append = false, mixed $old_tt_ids = array() ): void {
		unset( $terms, $append );

		if ( 'product_type' !== $taxonomy || ! is_numeric( $object_id ) ) {
			return;
		}

		try {
			$type = get_term_by( 'slug', DesignCollections::TYPE, 'product_type' );

			if ( ! $type instanceof WP_Term ) {
				return;
			}

			$id  = (int) $object_id;
			$was = in_array( (int) $type->term_taxonomy_id, self::ids( $old_tt_ids ), true );
			$is  = in_array( (int) $type->term_taxonomy_id, self::ids( $tt_ids ), true );

			if ( $is ) {
				delete_post_meta( $id, DesignCollections::RELEASED_KEY );
			} elseif ( $was && $this->collections->active() ) {
				update_post_meta( $id, DesignCollections::RELEASED_KEY, '1' );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'A collection\'s change of type could not be recorded.',
				array(
					'product_id' => (int) $object_id,
					'exception'  => $e::class,
				)
			);
		}
	}

	/**
	 * "No longer a collection": for a product changed before the changes were
	 * recorded. A product with the collection type is never released.
	 *
	 * @param int $product_id Product.
	 * @throws CollectionRequestException When it is refused.
	 * @throws DatabaseException When it cannot be recorded.
	 */
	public function release( int $product_id ): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			throw new CollectionRequestException( CollectionRequestException::NOT_AUTHORIZED );
		}

		if ( ! in_array( $product_id, $this->collections->untyped(), true ) ) {
			throw new CollectionRequestException( CollectionRequestException::NOT_FOUND );
		}

		update_post_meta( $product_id, DesignCollections::RELEASED_KEY, '1' );
		wp_cache_delete( $product_id, 'post_meta' );

		if ( $this->collections->is_collection( $product_id ) ) {
			throw new DatabaseException( 'The product could not be released as a collection.', array( 'product_id' => $product_id ) );
		}

		$this->audit->record( AuditAction::CollectionReleased, null, array( 'product_id' => $product_id ) );
	}

	/**
	 * Term ids as integers.
	 *
	 * @param mixed $value Ids as WordPress passes them.
	 * @return list<int>
	 */
	private static function ids( mixed $value ): array {
		return array_values( array_map( 'intval', array_filter( is_array( $value ) ? $value : array(), 'is_numeric' ) ) );
	}
}
