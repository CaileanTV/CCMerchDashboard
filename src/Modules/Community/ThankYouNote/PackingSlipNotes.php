<?php
/**
 * The creators' thank-you notes for a packing slip.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\ThankYouNote;

use Closure;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorStatus;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The public filter `merchboard_packing_slip_notes` (phase 58): a packing-slip
 * plugin calls
 *
 *     $notes = apply_filters( 'merchboard_packing_slip_notes', array(), $order );
 *
 * with a `WC_Order` (or its id) and gets one note per active creator whose
 * products are in the order: `array{creator_id: int, name: string, text: string}`,
 * in the order of the lines, each creator once. Only a note that is switched
 * on and not empty is given; collections are skipped. Merch Dashboard never
 * writes to the other plugin, and a failure gives no notes instead of
 * breaking the packing slip.
 */
final class PackingSlipNotes {

	/**
	 * The filter.
	 */
	public const FILTER = 'merchboard_packing_slip_notes';

	/**
	 * The creator of a product.
	 *
	 * @var Closure(int): ?int
	 */
	private Closure $creator_of;

	/**
	 * A creator.
	 *
	 * @var Closure(int): ?Creator
	 */
	private Closure $creator;

	/**
	 * Whether a product is a collection.
	 *
	 * @var Closure(int): bool
	 */
	private Closure $is_collection;

	/**
	 * A creator's note.
	 *
	 * @var Closure(int): ThankYouNoteSettings
	 */
	private Closure $note;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?int $creator_of
	 * @phpstan-param Closure(int): ?Creator $creator
	 * @phpstan-param Closure(int): bool $is_collection
	 * @phpstan-param Closure(int): ThankYouNoteSettings $note
	 *
	 * @param Closure $creator_of    The creator of a product.
	 * @param Closure $creator       A creator.
	 * @param Closure $is_collection Whether a product is a collection.
	 * @param Closure $note          A creator's note.
	 */
	public function __construct( Closure $creator_of, Closure $creator, Closure $is_collection, Closure $note ) {
		$this->creator_of    = $creator_of;
		$this->creator       = $creator;
		$this->is_collection = $is_collection;
		$this->note          = $note;
	}

	/**
	 * Adds the filter.
	 */
	public function register(): void {
		add_filter( self::FILTER, array( $this, 'filter' ), 10, 2 );
	}

	/**
	 * The filter: the notes given by others, then the creators' notes.
	 *
	 * @param mixed $notes Notes so far.
	 * @param mixed $order The order or its id.
	 * @return array<mixed>
	 */
	public function filter( mixed $notes, mixed $order = null ): array {
		$notes = is_array( $notes ) ? $notes : array();

		if ( is_int( $order ) || ( is_string( $order ) && ctype_digit( $order ) ) ) {
			$order = wc_get_order( (int) $order );
		}

		if ( ! $order instanceof WC_Order ) {
			return $notes;
		}

		try {
			return array_merge( $notes, $this->notes( $order ) );
		} catch ( Throwable ) {
			return $notes;
		}
	}

	/**
	 * The creators' notes for an order.
	 *
	 * @param WC_Order $order The order.
	 * @return list<array{creator_id: int, name: string, text: string}>
	 */
	public function notes( WC_Order $order ): array {
		$notes = array();
		$seen  = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = (int) $item->get_product_id( 'edit' );

			if ( $product_id < 1 || ( $this->is_collection )( $product_id ) ) {
				continue;
			}

			$creator_id = ( $this->creator_of )( $product_id );

			if ( null === $creator_id || isset( $seen[ $creator_id ] ) ) {
				continue;
			}

			$seen[ $creator_id ] = true;
			$creator             = ( $this->creator )( $creator_id );

			if ( null === $creator || CreatorStatus::Active !== $creator->status || null !== $creator->deleted_at ) {
				continue;
			}

			$text = ( $this->note )( $creator_id )->printed();

			if ( '' !== $text ) {
				$notes[] = array(
					'creator_id' => $creator_id,
					'name'       => $creator->display_name,
					'text'       => $text,
				);
			}
		}

		return $notes;
	}
}
