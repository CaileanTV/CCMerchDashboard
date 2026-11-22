<?php
/**
 * What a creator sees of collections.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\Dashboard\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * The body of `GET /me/collections` (ADR-120): names, states and ids of the
 * creator's own. A request's token never leaves the server.
 */
final class CollectionOverview {

	/**
	 * Constructor.
	 *
	 * @param DesignCollections           $collections The shop's collections.
	 * @param CollectionRequests          $requests    The rules.
	 * @param CollectionRequestRepository $repository  Storage.
	 * @param CreatorProducts             $products    The creators' products.
	 */
	public function __construct( private DesignCollections $collections, private CollectionRequests $requests, private CollectionRequestRepository $repository, private CreatorProducts $products ) {
	}

	/**
	 * Whether collections can be requested: the collection plugin is active.
	 */
	public function available(): bool {
		return $this->collections->active();
	}

	/**
	 * The creator's collections, requests and the products that can be chosen.
	 *
	 * @param Creator $creator Creator from CreatorContext.
	 * @return array{available: bool, products: list<array{id: int, name: string}>, collections: list<array{id: int, name: string, published: bool, designs: int}>, requests: list<array<string, mixed>>}
	 * @throws DatabaseException When it cannot be read.
	 */
	public function for_creator( Creator $creator ): array {
		$available = $this->available() && $creator->is_live();

		return array(
			'available'   => $available,
			'products'    => $available ? $this->candidates( $creator->id ) : array(),
			'collections' => $available ? $this->own( $creator->id ) : array(),
			'requests'    => array_map( $this->request( ... ), $this->repository->for_creator( $creator->id ) ),
		);
	}

	/**
	 * The creator's products that can be asked to be collected.
	 *
	 * @param int $creator_id Creator.
	 * @return list<array{id: int, name: string}>
	 * @throws DatabaseException When it cannot be read.
	 */
	private function candidates( int $creator_id ): array {
		$ids   = array();
		$pages = (int) ceil( $this->products->count( $creator_id ) / Pagination::MAX_PER_PAGE );

		for ( $page = 1; $page <= $pages; $page++ ) {
			array_push( $ids, ...$this->products->ids( $creator_id, new Pagination( $page, Pagination::MAX_PER_PAGE ) ) );
		}

		$requested = $this->repository->requested_among( $ids );
		$products  = array();

		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $requested, true ) && $this->collections->collectable( $id ) ) {
				$products[] = array(
					'id'   => $id,
					'name' => self::name( $id ),
				);
			}
		}

		return $products;
	}

	/**
	 * The collections that are the creator's.
	 *
	 * @param int $creator_id Creator.
	 * @return list<array{id: int, name: string, published: bool, designs: int}>
	 */
	private function own( int $creator_id ): array {
		$own = array();

		foreach ( $this->collections->all() as $id ) {
			if ( $this->requests->is_creators( $creator_id, $id ) ) {
				$own[] = array(
					'id'        => $id,
					'name'      => self::name( $id ),
					'published' => 'publish' === get_post_status( $id ),
					'designs'   => count( $this->collections->members( $id ) ),
				);
			}
		}

		return $own;
	}

	/**
	 * One request as the creator sees it.
	 *
	 * @param CollectionRequest $request The request.
	 * @return array<string, mixed>
	 */
	private function request( CollectionRequest $request ): array {
		return array(
			'id'           => $request->id,
			'kind'         => $request->kind->value,
			'name'         => $request->name,
			'status'       => $request->status->value,
			'note'         => $request->note,
			'designs'      => array_column( $request->designs, 'name' ),
			'requested_at' => self::time( $request->requested_at ),
		);
	}

	/**
	 * A product's name as text.
	 *
	 * @param int $product_id Product.
	 */
	private static function name( int $product_id ): string {
		return PlainText::of( (string) get_post_field( 'post_title', $product_id, 'raw' ) );
	}

	/**
	 * A time as the API sends it (UTC).
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function time( DateTimeImmutable $time ): string {
		return $time->format( 'Y-m-d H:i:s' );
	}
}
