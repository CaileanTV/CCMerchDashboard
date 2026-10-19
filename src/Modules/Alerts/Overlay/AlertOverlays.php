<?php
/**
 * A creator's alert overlay: set up, settings, new id and test alerts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\WooCommerce\ProductStore;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's side of the overlay (ADR-065 pkt. 3, phase 25 step 4).
 * Callers pass the creator that CreatorContext resolved; nothing here takes
 * a creator id from a client.
 *
 * - **Set up** is explicit and idempotent, with an audit record.
 * - **Settings:** only the fields of the overlay form change (EDITABLE and
 *   the visibility of the secondary text); the font, the layout and the
 *   sounds keep their values until their own steps (25.5, 25.6). Everything
 *   is normalized by OverlaySettings.
 * - **New id:** the old OBS URL stops working at once; audited without ids.
 * - **Test alert:** a random published product of the creator, shown by
 *   the overlay within a second (OverlayTestQueue); never stored as an
 *   alert, never sent to Twitch. The username is a fixed sample.
 */
final class AlertOverlays {

	/**
	 * Form fields a creator can change.
	 */
	public const EDITABLE = array( 'template', 'secondary_template', 'font_size', 'font_weight', 'text_color', 'text_shadow', 'text_align', 'image_size', 'bg_color', 'bg_opacity', 'border_radius', 'padding', 'anim_duration', 'display_duration' );

	/**
	 * Username shown by test alerts.
	 */
	public const TEST_USERNAME = 'TestFan';

	/**
	 * The featured image of a product, 0 when none.
	 *
	 * @var Closure(int): int
	 */
	private Closure $image_id;

	/**
	 * Constructor.
	 *
	 * @param AlertOverlayRepository   $overlays    Storage.
	 * @param OverlayFeed              $feed        Alert rendering.
	 * @param OverlayTestQueue         $tests       Test alert queue.
	 * @param ProductStore             $products    The creator's products.
	 * @param AuditTrail               $audit       Audit trail.
	 * @param Transaction              $transaction Database transaction.
	 * @param (Closure(int): int)|null $image_id    Featured image lookup; defaults to get_post_thumbnail_id().
	 */
	public function __construct(
		private AlertOverlayRepository $overlays,
		private OverlayFeed $feed,
		private OverlayTestQueue $tests,
		private ProductStore $products,
		private AuditTrail $audit,
		private Transaction $transaction,
		?Closure $image_id = null
	) {
		$this->image_id = $image_id ?? static function ( int $product_id ): int {
			$id = get_post_thumbnail_id( $product_id );

			return is_int( $id ) ? $id : 0;
		};
	}

	/**
	 * The creator's overlay, or null before it is set up.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find( int $creator_id ): ?AlertOverlay {
		return $this->overlays->find_for_creator( $creator_id );
	}

	/**
	 * Sets up the creator's overlay with MerchAlert's defaults; returns the
	 * existing one when it is already set up.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function create( int $creator_id ): AlertOverlay {
		$existing = $this->overlays->find_for_creator( $creator_id );

		if ( null !== $existing ) {
			return $existing;
		}

		return $this->transaction->run(
			function () use ( $creator_id ): AlertOverlay {
				$overlay = $this->overlays->create( $creator_id, OverlayId::generate(), OverlaySettings::defaults() );
				$this->audit->record( AuditAction::AlertOverlayCreated, $creator_id );

				return $overlay;
			}
		);
	}

	/**
	 * Changes the form fields of the overlay settings.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $input      Submitted fields; unknown keys are ignored.
	 * @return AlertOverlay|null The changed overlay; null before it is set up.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update_settings( int $creator_id, array $input ): ?AlertOverlay {
		$overlay = $this->overlays->find_for_creator( $creator_id );

		if ( null === $overlay ) {
			return null;
		}

		$values = $overlay->settings->to_array();

		foreach ( self::EDITABLE as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$values[ $key ] = $input[ $key ];
			}
		}

		if ( array_key_exists( 'show_secondary', $input ) ) {
			$values['layout']['elements']['secondary_text']['visible'] = true === $input['show_secondary'];
		}

		$this->overlays->update_settings( $overlay->id, OverlaySettings::from_array( $values ) );

		return $this->overlays->find_for_creator( $creator_id );
	}

	/**
	 * Replaces the secret id.
	 *
	 * @param int $creator_id Creator.
	 * @return AlertOverlay|null The overlay with its new id; null before it is set up.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function regenerate( int $creator_id ): ?AlertOverlay {
		$overlay = $this->overlays->find_for_creator( $creator_id );

		if ( null === $overlay ) {
			return null;
		}

		return $this->transaction->run(
			function () use ( $overlay, $creator_id ): ?AlertOverlay {
				$this->overlays->update_overlay_id( $overlay->id, OverlayId::generate() );
				$this->audit->record( AuditAction::AlertOverlayIdRegenerated, $creator_id );

				return $this->overlays->find_for_creator( $creator_id );
			}
		);
	}

	/**
	 * Queues a test alert with a random published product of the creator
	 * (a placeholder product when there is none).
	 *
	 * @param int $creator_id Creator.
	 * @return bool False before the overlay is set up.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function send_test( int $creator_id ): bool {
		$overlay = $this->overlays->find_for_creator( $creator_id );

		if ( null === $overlay ) {
			return false;
		}

		$published = array_values( array_filter( $this->products->linked_to( $creator_id, 50 ), static fn( $product ): bool => 'publish' === $product->status ) );
		$product   = array() === $published ? null : $published[ random_int( 0, count( $published ) - 1 ) ];
		$event     = new AlertEvent(
			$creator_id,
			0,
			'0001',
			0,
			$product->id ?? 0,
			0,
			null === $product ? __( 'Test product', 'merchboard' ) : $product->name,
			'',
			1,
			null === $product ? 0 : ( $this->image_id )( $product->id ),
			null === $product ? array() : $product->category_ids,
			self::TEST_USERNAME,
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
		);

		$this->tests->push( $overlay->id, $this->feed->render( $overlay, $event ) );

		return true;
	}
}
