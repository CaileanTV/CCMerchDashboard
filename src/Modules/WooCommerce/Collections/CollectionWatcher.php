<?php
/**
 * Sees when a requested collection is in the shop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use MerchBoard\Core\Logging\Logger;
use Throwable;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 2: ready is what the shop says, however it got there, also
 * when the administrator did it by hand in the collection plugin.
 *
 * A product being published and a change of the plugin's two keys ask for a
 * check at the end of the request: the status changes before the editor has
 * saved the designs and before the plugin has hidden them. It never throws.
 */
final class CollectionWatcher {

	/**
	 * Whether a check waits for the end of the request.
	 *
	 * @var bool
	 */
	private bool $waiting = false;

	/**
	 * Constructor.
	 *
	 * @param CollectionRequestRepository $repository Storage.
	 * @param CollectionRequests          $requests   The rules.
	 * @param Logger                      $logger     Technical log.
	 */
	public function __construct( private CollectionRequestRepository $repository, private CollectionRequests $requests, private Logger $logger ) {
	}

	/**
	 * Hooks the watcher.
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'status_changed' ), 50, 3 );
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 3 );
	}

	/**
	 * `transition_post_status`.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 */
	public function status_changed( mixed $new_status, mixed $old_status = '', mixed $post = null ): void {
		if ( 'publish' === $new_status && $new_status !== $old_status && $post instanceof WP_Post && 'product' === $post->post_type ) {
			$this->later();
		}
	}

	/**
	 * `added_post_meta` and `updated_post_meta`.
	 *
	 * @param mixed $meta_id  Meta row.
	 * @param mixed $post_id  Post.
	 * @param mixed $meta_key Key.
	 */
	public function meta_changed( mixed $meta_id, mixed $post_id = 0, mixed $meta_key = '' ): void {
		if ( DesignCollections::GROUP_KEY === $meta_key || DesignCollections::MEMBERS_KEY === $meta_key ) {
			$this->later();
		}
	}

	/**
	 * Closes the approved requests the shop has carried out (`shutdown`, and
	 * "Check again").
	 */
	public function check(): void {
		$this->waiting = false;

		try {
			$open = $this->repository->open();
		} catch ( Throwable $e ) {
			$this->logger->error( 'The collection requests could not be checked.', array( 'exception' => $e::class ) );

			return;
		}

		foreach ( $open as $request ) {
			try {
				if ( $this->requests->is_done( $request ) ) {
					$this->requests->mark_ready( $request->id );
				}
			} catch ( Throwable $e ) {
				$this->logger->error(
					'A collection request could not be closed as ready.',
					array(
						'collection_request_id' => $request->id,
						'exception'             => $e::class,
					)
				);
			}
		}
	}

	/**
	 * Checks once at the end of the request.
	 */
	private function later(): void {
		if ( $this->waiting ) {
			return;
		}

		$this->waiting = true;
		// Before the notifications' own shutdown work at 10, which this can add to.
		add_action( 'shutdown', array( $this, 'check' ), 5 );
	}
}
