<?php
/**
 * "Collections" on "Merch Dashboard → Proposals".
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\Approvals\ApprovalsPage;
use MerchBoard\Modules\Admin\Approvals\ApprovalTab;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Collections\CollectionGiftWarnings;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequest;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestException;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestKind;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestRepository;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestStatus;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 2: the requests to have designs collected, with what the
 * administrator can do next for each. Display only; the forms post to
 * CollectionRequestActions.
 */
final class CollectionRequestsPage {

	/**
	 * The tab on "Merch Dashboard → Proposals", also the notification's page.
	 */
	public const TAB = 'collections';

	/**
	 * The page slug a notification links to; it leads to the tab.
	 */
	public const SLUG = 'merchboard-collections';

	/**
	 * Query argument of the outcome notice.
	 */
	public const RESULT_ARG = 'merchboard_collection_result';

	/**
	 * Constructor.
	 *
	 * @param CollectionRequestRepository $requests    Requests.
	 * @param DesignCollections           $collections The shop's collections.
	 * @param CreatorProducts             $products    The creators' products.
	 * @param CreatorRepository           $creators    Creators.
	 * @param CollectionGiftWarnings      $warnings    Gifts of a collection itself.
	 * @param Logger                      $logger      Technical log.
	 */
	public function __construct( private CollectionRequestRepository $requests, private DesignCollections $collections, private CreatorProducts $products, private CreatorRepository $creators, private CollectionGiftWarnings $warnings, private Logger $logger ) {
	}

	/**
	 * URL of the tab.
	 *
	 * @param array<string, int|string> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return ApprovalsPage::url( self::TAB, $args );
	}

	/**
	 * Message and type of an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'warning'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'approved'                                    => array( __( 'The request is approved and prepared. Check the collection; designs are hidden when it is published.', 'merchboard' ), 'success' ),
			'approved_waiting'                            => array( __( 'The request is approved. Nothing in the shop has changed: use "Add and hide now" when the designs may go into the published collection.', 'merchboard' ), 'success' ),
			'added'                                       => array( __( 'The designs are added to the collection and no longer shown separately in the shop.', 'merchboard' ), 'success' ),
			'checked'                                     => array( __( 'The requests are checked against the shop.', 'merchboard' ), 'success' ),
			'closed_partly'                               => array( __( 'The request is closed. The creator is told which designs are in the collection.', 'merchboard' ), 'success' ),
			CollectionRequestException::NOTHING_IN        => array( __( 'None of the designs are in the published collection yet, so it cannot be closed as partly done.', 'merchboard' ), 'warning' ),
			'released'                                    => array( __( 'The product is no longer a collection and can be sold again.', 'merchboard' ), 'success' ),
			'declined'                                    => array( __( 'The request is declined. The creator sees your note.', 'merchboard' ), 'success' ),
			CollectionRequestException::NOTE_REQUIRED     => array( __( 'Write a note to the creator before you decline the request.', 'merchboard' ), 'error' ),
			CollectionRequestException::CREATOR_NOT_LIVE  => array( __( 'The creator is not active and live in the shop.', 'merchboard' ), 'error' ),
			CollectionRequestException::PRODUCT_NOT_USABLE => array( __( 'A design can no longer be collected: it is hidden, unpublished, in another collection or not the creator\'s. Decline the request with a note, or fix the product and try again.', 'merchboard' ), 'error' ),
			CollectionRequestException::COLLECTION_UNKNOWN => array( __( 'The collection is gone or is no longer only this creator\'s.', 'merchboard' ), 'error' ),
			CollectionRequestException::PLUGIN_INACTIVE   => array( __( 'CCMerch Design-samling is not active, so nothing in the shop was changed. Activate it and try again.', 'merchboard' ), 'error' ),
			CollectionRequestException::DRAFT_UNCLEAR     => array( __( 'Several products look like this request\'s draft, so none was chosen. Delete the copy permanently and try again.', 'merchboard' ), 'error' ),
			CollectionRequestException::EDITING           => array( __( 'The collection is open in the editor, which would overwrite the change. Close the editor, wait up to three minutes and try again.', 'merchboard' ), 'warning' ),
			CollectionRequestException::BUSY              => array( __( 'The request is being handled by someone else. Try again in a moment.', 'merchboard' ), 'warning' ),
			CollectionRequestException::ALREADY_DONE      => array( __( 'The designs are already in the published collection, so the request cannot be declined.', 'merchboard' ), 'warning' ),
			CollectionRequestException::NOT_PENDING,
			CollectionRequestException::NOT_APPROVED,
			CollectionRequestException::NOT_OPEN,
			CollectionRequestException::NOT_FOUND         => array( __( 'The request is no longer waiting for that.', 'merchboard' ), 'warning' ),
			CollectionRequestException::NOT_AUTHORIZED    => array( __( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), 'error' ),
			'error'                                       => array( __( 'A technical error occurred. Check the request and try again; nothing is made twice.', 'merchboard' ), 'error' ),
			default                                       => null,
		};
	}

	/**
	 * The tab with the number waiting.
	 */
	public function approval_tab(): ApprovalTab {
		return new ApprovalTab( self::TAB, __( 'Collections', 'merchboard' ), Capabilities::MANAGE_CREATORS, fn(): int => $this->pending_count(), fn() => $this->render(), array( self::SLUG ) );
	}

	/**
	 * Renders the tab.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		echo '<div class="merchboard-collections"><h2>' . esc_html__( 'Collections', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p class="description">' . esc_html__( 'Creators ask here to have designs sold from one product page. A new collection becomes a draft that you publish. Adding to a published collection takes a second click. Remove and dissolve collections in the collection plugin.', 'merchboard' ) . '</p>';

		if ( ! $this->collections->active() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'CCMerch Design-samling is not active. Creators cannot send requests, and nothing can be carried out until it is.', 'merchboard' ) . '</p></div>';
		}

		try {
			$open     = $this->requests->open();
			$closed   = $this->requests->closed();
			$warnings = $this->warnings->affected();
			$rows     = array_map( $this->row( ... ), $open );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Reading the collection requests failed.', $e->context() );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The requests could not be read. Please try again later.', 'merchboard' ) . '</p></div></div>';

			return;
		}

		$this->render_warnings( $warnings );
		$this->render_untyped();

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No requests are waiting.', 'merchboard' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Creator', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Request', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Designs', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Requested', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Next step', 'merchboard' ) . '</th></tr></thead><tbody>';
			echo implode( '', $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- row() escapes.
			echo '</tbody></table>';
		}

		if ( array() !== $closed ) {
			$this->render_closed( $closed );
		}

		echo '</div>';
	}

	/**
	 * An open request's row.
	 *
	 * @param CollectionRequest $request The request.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function row( CollectionRequest $request ): string {
		$kind = CollectionRequestKind::Add === $request->kind
			/* translators: %s: collection name. */
			? sprintf( __( 'Add to %s', 'merchboard' ), $request->name )
			/* translators: %s: collection name. */
			: sprintf( __( 'New collection: %s', 'merchboard' ), $request->name );

		$html  = '<tr><td>' . esc_html( $this->creator_name( $request->creator_id ) ) . '</td>';
		$html .= '<td>' . esc_html( $kind );

		if ( null !== $request->collection_id ) {
			$html .= '<br>' . ( self::exists( $request->collection_id )
				? self::edit_link( $request->collection_id, __( 'Open the collection', 'merchboard' ) )
				: '<strong>' . esc_html__( 'The collection is in the trash or gone.', 'merchboard' ) . '</strong>' );
		}

		$html .= '</td><td><ul style="margin:0">';

		foreach ( $request->designs as $design ) {
			$html .= '<li>' . $this->design( $request, $design['id'], $design['name'] ) . '</li>';
		}

		return $html . '</ul></td><td>' . esc_html( self::time( $request->requested_at ) ) . '</td><td>' . $this->next_step( $request ) . '</td></tr>';
	}

	/**
	 * One design with what stands in the way.
	 *
	 * @param CollectionRequest $request    The request.
	 * @param int               $product_id Product.
	 * @param string            $name       Its name when requested.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function design( CollectionRequest $request, int $product_id, string $name ): string {
		if ( ! self::exists( $product_id ) ) {
			return esc_html( $name ) . ' <strong>' . esc_html__( '(gone)', 'merchboard' ) . '</strong>';
		}

		$html = self::edit_link( $product_id, $name );

		if ( null !== $request->collection_id && in_array( $product_id, array_column( $this->collections->members( $request->collection_id ), 'id' ), true ) ) {
			$html .= ' ' . esc_html__( '(in the collection)', 'merchboard' );

			// In an unpublished one the warnings still count: publishing would put it on sale.
			if ( 'publish' === get_post_status( $request->collection_id ) ) {
				return $html;
			}
		}

		if ( ! $this->products->owns( $request->creator_id, $product_id ) ) {
			return $html . ' <strong>' . esc_html__( '(not the creator\'s)', 'merchboard' ) . '</strong>';
		}

		return $this->collections->collectable( $product_id ) ? $html : $html . ' <strong>' . esc_html__( '(hidden, unpublished or collected)', 'merchboard' ) . '</strong>';
	}

	/**
	 * What can be done with a request now.
	 *
	 * @param CollectionRequest $request The request.
	 */
	private function next_step( CollectionRequest $request ): string {
		$html = '';

		if ( CollectionRequestStatus::Pending === $request->status ) {
			$html .= self::form( CollectionRequestActions::APPROVE, $request->id, __( 'Approve', 'merchboard' ), true );
		} elseif ( null === $request->collection_id ) {
			$html .= '<p>' . esc_html__( 'Approved, but the draft is not made yet.', 'merchboard' ) . '</p>';
			$html .= self::form( CollectionRequestActions::RETRY, $request->id, __( 'Try again', 'merchboard' ), true );
		} else {
			$missing   = array_diff( $request->product_ids(), array_column( $this->collections->members( $request->collection_id ), 'id' ) );
			$published = 'publish' === get_post_status( $request->collection_id );
			$adding    = CollectionRequestKind::Add === $request->kind;

			if ( $adding && $published && count( $missing ) < count( $request->designs ) && array() !== $missing ) {
				$html .= self::form( CollectionRequestActions::PARTLY, $request->id, __( 'Close as partly done', 'merchboard' ) );
			}

			if ( $adding && $published ) {
				$html .= '<p>' . esc_html__( 'Approved. The designs are not sold from the collection until you add them.', 'merchboard' ) . '</p>';

				if ( $this->collections->active() ) {
					$html .= self::form( CollectionRequestActions::ADD_NOW, $request->id, __( 'Add and hide now', 'merchboard' ), true );
				} else {
					$html .= '<p>' . esc_html__( 'Activate CCMerch Design-samling to add them.', 'merchboard' ) . '</p>';
				}
			} elseif ( $adding && array() !== $missing ) {
				$html .= '<p>' . esc_html__( 'Approved, but not all designs are in the collection yet.', 'merchboard' ) . '</p>';
				$html .= self::form( CollectionRequestActions::RETRY, $request->id, __( 'Try again', 'merchboard' ), true );
			} elseif ( ! $published ) {
				$html .= '<p>' . esc_html__( 'Approved. Check the collection and publish it; the creator is told then.', 'merchboard' ) . '</p>';
			} elseif ( ! $adding ) {
				$html .= '<p>' . esc_html__( 'The collection is published, but not all its requested designs are hidden by it, or none is left in it. Open it, check the designs and update it.', 'merchboard' ) . '</p>';
			}

			$html .= self::form( CollectionRequestActions::CHECK, $request->id, __( 'Check again', 'merchboard' ) );
		}

		$note = '<p><label>' . esc_html__( 'Note to the creator', 'merchboard' ) . '<br><input type="text" name="' . esc_attr( CollectionRequestActions::NOTE_FIELD ) . '" required maxlength="500" class="regular-text"></label></p>';

		return $html . self::form( CollectionRequestActions::DECLINE, $request->id, __( 'Decline', 'merchboard' ), false, $this->decline_effect( $request ) . $note );
	}

	/**
	 * What declining changes in the shop, said before the click, escaped.
	 *
	 * @param CollectionRequest $request The request.
	 */
	private function decline_effect( CollectionRequest $request ): string {
		if ( CollectionRequestStatus::Approved !== $request->status || null === $request->collection_id ) {
			return '';
		}

		if ( in_array( get_post_status( $request->collection_id ), array( false, 'publish', 'trash' ), true ) ) {
			return '';
		}

		$effect = CollectionRequestKind::Add === $request->kind
			? __( 'Declining takes the designs this request added out of the unpublished collection.', 'merchboard' )
			: __( 'Declining puts the draft in the trash.', 'merchboard' );

		return '<p class="description">' . esc_html( $effect ) . '</p>';
	}

	/**
	 * The open gifts and giveaways that point at a collection itself.
	 *
	 * @phpstan-param list<array{kind: string, id: int, product_id: int}> $warnings
	 *
	 * @param array[] $warnings The affected ones.
	 */
	private function render_warnings( array $warnings ): void {
		if ( array() === $warnings ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'These open gifts and giveaways point at a collection instead of a design, so they cannot be redeemed for a product. Nothing is changed by itself; decide what to do with each.', 'merchboard' ) . '</p><ul>';

		foreach ( $warnings as $warning ) {
			$label = CollectionGiftWarnings::GIVEAWAY === $warning['kind']
				/* translators: %d: giveaway id. */
				? sprintf( __( 'Giveaway #%d', 'merchboard' ), $warning['id'] )
				/* translators: %d: gift id. */
				: sprintf( __( 'Gift #%d', 'merchboard' ), $warning['id'] );

			echo '<li>' . esc_html( $label ) . ': ' . self::edit_link( $warning['product_id'], (string) get_post_field( 'post_title', $warning['product_id'], 'raw' ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- edit_link() escapes.
		}

		echo '</ul></div>';
	}

	/**
	 * Products that count as collections without having the type.
	 */
	private function render_untyped(): void {
		$untyped = $this->collections->untyped();

		if ( array() === $untyped ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'These products list designs but are not of the collection type, so they cannot be sold themselves. If you changed one into an ordinary product, say so here. If it lost its type while the collection plugin was off, give it the type back in WooCommerce instead: as an ordinary product it would be sold without a design and print files.', 'merchboard' ) . '</p>';

		foreach ( $untyped as $id ) {
			echo '<p>' . self::edit_link( $id, (string) get_post_field( 'post_title', $id, 'raw' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- edit_link() escapes.
			echo self::form( CollectionRequestActions::RELEASE, $id, __( 'No longer a collection', 'merchboard' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form() escapes.
		}

		echo '</div>';
	}

	/**
	 * The latest closed requests.
	 *
	 * @phpstan-param list<CollectionRequest> $requests
	 *
	 * @param CollectionRequest[] $requests Closed requests.
	 */
	private function render_closed( array $requests ): void {
		echo '<h2>' . esc_html__( 'Handled requests', 'merchboard' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Creator', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Collection', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Decision', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Handled', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $requests as $request ) {
			$decision = CollectionRequestStatus::Declined === $request->status
				/* translators: %s: the administrator's note. */
				? sprintf( __( 'Declined: %s', 'merchboard' ), (string) $request->note )
				: ( null === $request->note
					? __( 'In the shop', 'merchboard' )
					/* translators: %s: design names. */
					: sprintf( __( 'Partly in the shop: %s', 'merchboard' ), $request->note ) );

			echo '<tr><td>' . esc_html( $this->creator_name( $request->creator_id ) ) . '</td><td>' . esc_html( $request->name ) . '</td><td>' . esc_html( $decision ) . '</td>';
			echo '<td>' . esc_html( null === $request->closed_at ? '' : self::time( $request->closed_at ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One decision's form, escaped.
	 *
	 * @param string $action     One of CollectionRequestActions' actions.
	 * @param int    $request_id Request.
	 * @param string $button     Button text.
	 * @param bool   $primary    Whether it is the primary button.
	 * @param string $fields     Escaped extra fields.
	 */
	private static function form( string $action, int $request_id, string $button, bool $primary = false, string $fields = '' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:.6em">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request_id ) . '">'
			. wp_nonce_field( CollectionRequestActions::nonce_action( $action, $request_id ), '_wpnonce', true, false )
			. $fields
			. '<button type="submit" class="' . esc_attr( $primary ? 'button button-primary' : 'button' ) . '">' . esc_html( $button ) . '</button></form>';
	}

	/**
	 * A link to a product's editor, escaped.
	 *
	 * @param int    $product_id Product.
	 * @param string $text       Link text.
	 */
	private static function edit_link( int $product_id, string $text ): string {
		return '<a href="' . esc_url( admin_url( 'post.php?post=' . $product_id . '&action=edit' ) ) . '">' . esc_html( $text ) . '</a>';
	}

	/**
	 * Whether a product exists outside the trash.
	 *
	 * @param int $product_id Product.
	 */
	private static function exists( int $product_id ): bool {
		return ! in_array( get_post_status( $product_id ), array( false, 'trash' ), true );
	}

	/**
	 * The creator's name, or their id for a creator that cannot be read.
	 *
	 * @param int $creator_id Creator.
	 */
	private function creator_name( int $creator_id ): string {
		try {
			return $this->creators->find( $creator_id )->display_name ?? '#' . $creator_id;
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Reading a creator for the collection requests failed.', $e->context() );

			return '#' . $creator_id;
		}
	}

	/**
	 * A time in the site's time zone.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function time( DateTimeImmutable $time ): string {
		return (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $time->getTimestamp() );
	}

	/**
	 * The number waiting, 0 when it cannot be read: the menu must not fail.
	 */
	private function pending_count(): int {
		try {
			return $this->requests->pending_count();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Counting the collection requests failed.', $e->context() );

			return 0;
		}
	}
}
