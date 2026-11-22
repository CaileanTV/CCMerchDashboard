<?php
/**
 * The decisions on collection requests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\Collections\CollectionFulfilment;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestException;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequests;
use MerchBoard\Modules\WooCommerce\Collections\CollectionTypeChanges;
use MerchBoard\Modules\WooCommerce\Collections\CollectionWatcher;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php` forms of the "Collections" tab (ADR-120 pkt. 2): the
 * capability, then a nonce per action and request, then one step.
 */
final class CollectionRequestActions {

	public const APPROVE = 'merchboard_approve_collection';
	public const RETRY   = 'merchboard_retry_collection';
	public const ADD_NOW = 'merchboard_add_to_collection_now';
	public const CHECK   = 'merchboard_check_collection';
	public const DECLINE = 'merchboard_decline_collection';
	public const RELEASE = 'merchboard_release_collection';

	/**
	 * The field with the note on a decline.
	 */
	public const NOTE_FIELD = 'note';

	private const ACTIONS = array( self::APPROVE, self::RETRY, self::ADD_NOW, self::CHECK, self::DECLINE, self::RELEASE );

	/**
	 * Constructor.
	 *
	 * @param CollectionRequests         $requests   The rules.
	 * @param CollectionFulfilment       $fulfilment Carries out in the shop.
	 * @param CollectionWatcher          $watcher    Sees what is ready.
	 * @param Logger                     $logger     Technical log.
	 * @param CollectionTypeChanges|null $types Releases a product that is no longer a collection.
	 */
	public function __construct( private CollectionRequests $requests, private CollectionFulfilment $fulfilment, private CollectionWatcher $watcher, private Logger $logger, private ?CollectionTypeChanges $types = null ) {
	}

	/**
	 * The nonce action of a decision on one request.
	 *
	 * @param string $action     One of the actions.
	 * @param int    $request_id Request.
	 */
	public static function nonce_action( string $action, int $request_id ): string {
		return $action . '_' . $request_id;
	}

	/**
	 * Registers the handlers.
	 */
	public function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'handle_request' ) );
		}
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		wp_safe_redirect( $this->process( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in process().
		exit;
	}

	/**
	 * Does the form's step.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function process( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$action = self::scalar( $input, 'action' );
		$id     = absint( self::scalar( $input, 'request_id' ) );
		$nonce  = self::scalar( $input, '_wpnonce' );

		if ( ! in_array( $action, self::ACTIONS, true ) || '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $action, $id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			return self::result( $this->step( $action, $id, self::scalar( $input, self::NOTE_FIELD ) ) );
		} catch ( CollectionRequestException $e ) {
			return self::result( $e->reason() );
		} catch ( Throwable $e ) {
			// A product save can throw what WooCommerce and other plugins throw.
			$this->logger->error(
				'A step of a collection request failed.',
				array(
					'collection_request_id' => $id,
					'exception'             => $e::class,
				)
			);

			return self::result( 'error' );
		}
	}

	/**
	 * One step.
	 *
	 * @param string $action One of the actions.
	 * @param int    $id     Request.
	 * @param string $note   Note to the creator.
	 * @return string Outcome code.
	 * @throws CollectionRequestException When it is refused.
	 */
	private function step( string $action, int $id, string $note ): string {
		if ( self::DECLINE === $action ) {
			$this->requests->decline( $id, $note );

			return 'declined';
		}

		if ( self::RELEASE === $action ) {
			if ( null === $this->types ) {
				throw new CollectionRequestException( CollectionRequestException::NOT_FOUND );
			}

			// The form's id is the product's.
			$this->types->release( $id );

			return 'released';
		}

		if ( self::CHECK === $action ) {
			$this->watcher->check();

			return 'checked';
		}

		$done = match ( $action ) {
			self::APPROVE => $this->fulfilment->approve( $id ),
			self::ADD_NOW => $this->fulfilment->add_now( $id ),
			default       => $this->fulfilment->retry( $id ),
		};

		// What was in the shop already closes now: no change of it will ask for a check.
		$this->watcher->check();

		if ( ! $done ) {
			return 'approved_waiting';
		}

		return self::ADD_NOW === $action ? 'added' : 'approved';
	}

	/**
	 * The tab with an outcome code.
	 *
	 * @param string $code Outcome code.
	 */
	private static function result( string $code ): string {
		return CollectionRequestsPage::url( array( CollectionRequestsPage::RESULT_ARG => $code ) );
	}

	/**
	 * A scalar request value as a string ('' otherwise).
	 *
	 * @param array<mixed> $input Request data.
	 * @param string       $key   Key.
	 */
	private static function scalar( array $input, string $key ): string {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
	}
}
