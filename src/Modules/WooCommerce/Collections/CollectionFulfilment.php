<?php
/**
 * Carries approved collection requests out in the shop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 2:
 *
 * - **Approve:** the approval is stored first, then a new collection becomes
 *   a draft, or the designs are appended to an unpublished collection. A
 *   published collection is left as it is.
 * - **Retry:** the same without the approval. It repeats safely after any
 *   failure in between.
 * - **Add and hide now:** the second click of an addition to a published
 *   collection.
 *
 * The requests are ready when the shop says so (CollectionWatcher), not by
 * these clicks.
 */
final class CollectionFulfilment {

	/**
	 * Constructor.
	 *
	 * @param CollectionRequests          $requests   The rules.
	 * @param CollectionRequestRepository $repository Storage.
	 * @param CollectionWriter            $writer     The writer.
	 */
	public function __construct( private CollectionRequests $requests, private CollectionRequestRepository $repository, private CollectionWriter $writer ) {
	}

	/**
	 * Approves and carries out what needs no second click. The approval
	 * stays when that fails; retry() finishes it.
	 *
	 * @param int $id Request.
	 * @return bool Whether it is carried out; false while it waits for the second click.
	 * @throws CollectionRequestException When it is refused.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	public function approve( int $id ): bool {
		$this->requests->approve( $id );

		return $this->retry( $id );
	}

	/**
	 * Carries an approved request out, as far as it needs no second click.
	 *
	 * @param int $id Request.
	 * @return bool Whether it is carried out; false while it waits for the second click.
	 * @throws CollectionRequestException When it is refused.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	public function retry( int $id ): bool {
		return $this->requests->carry_out(
			$id,
			fn( CollectionRequest $request ): bool => $this->write( $request, false ),
			fn( CollectionRequest $request ): bool => null !== $this->writer->existing( $request )
		);
	}

	/**
	 * "Add and hide now": adds the designs to the published collection.
	 *
	 * @param int $id Request.
	 * @return bool Whether it is carried out.
	 * @throws CollectionRequestException When it is refused.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	public function add_now( int $id ): bool {
		return $this->requests->carry_out( $id, fn( CollectionRequest $request ): bool => $this->write( $request, true ) );
	}

	/**
	 * Declines, and takes back what the approval put in an unpublished
	 * collection.
	 *
	 * @param int    $id   Request.
	 * @param string $note Note to the creator.
	 * @throws CollectionRequestException When it is refused.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	public function decline( int $id, string $note ): CollectionRequest {
		return $this->requests->decline( $id, $note, fn( CollectionRequest $request ) => $this->writer->undo( $request ) );
	}

	/**
	 * Writes one request in the shop (under its lock, outside any transaction).
	 *
	 * @param CollectionRequest $request An approved request.
	 * @param bool              $publish Whether a published collection may be changed.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function write( CollectionRequest $request, bool $publish ): bool {
		if ( CollectionRequestKind::Add === $request->kind ) {
			return null !== $request->collection_id && $this->writer->add( $request->collection_id, $request->product_ids(), $publish, $request->id );
		}

		// A recorded draft is the administrator's from then on: what was taken out stays out.
		if ( null !== $request->collection_id ) {
			$this->writer->recorded( $request->collection_id );

			return true;
		}

		$draft = $this->writer->draft( $request );

		if ( ! $this->repository->set_collection( $request->id, $draft ) ) {
			throw new DatabaseException( 'Could not record the collection draft.', array( 'product_id' => $draft ) );
		}

		$this->writer->recorded( $draft );

		return true;
	}
}
