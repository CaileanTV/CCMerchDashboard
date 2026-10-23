<?php
/**
 * The admin-post handler of the news.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\News\NewsException;
use MerchBoard\Modules\News\NewsService;

defined( 'ABSPATH' ) || exit;

/**
 * Saves, previews, publishes, withdraws and deletes news messages (ADR-072).
 * Order of checks: capability `merchboard_manage_creators` → nonce → the
 * service (which checks again). Publishing also needs the confirmation
 * checkbox. Post/Redirect/Get to the news page with an outcome code.
 */
final class NewsAdminActions {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'merchboard_news';

	/**
	 * Constructor.
	 *
	 * @param NewsService $news   The news.
	 * @param Logger      $logger Technical log.
	 */
	public function __construct( private NewsService $news, private Logger $logger ) {
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle(); the text is cleaned by NewsHtml.
		exit;
	}

	/**
	 * Processes a request.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the news.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$text  = static fn( string $key ): string => is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';
		$id    = absint( $text( 'news_id' ) );
		$do    = sanitize_key( $text( 'do' ) );
		$nonce = $text( '_wpnonce' );

		if ( 1 !== wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			switch ( $do ) {
				case 'save':
				case 'preview':
					if ( $id > 0 ) {
						$this->news->update( $id, $text( 'title' ), $text( 'body' ) );
					} else {
						$id = $this->news->create( $text( 'title' ), $text( 'body' ) );
					}

					return NewsAdminPage::edit_url( $id, 'preview' === $do ? 'preview' : 'saved' );
				case 'publish':
					if ( '1' !== $text( 'confirm' ) ) {
						return NewsAdminPage::edit_url( $id, 'confirm_required' );
					}

					$this->news->publish( $id );

					return NewsAdminPage::view_url( $id, 'published' );
				case 'withdraw':
					$this->news->withdraw( $id );

					return NewsAdminPage::view_url( $id, 'withdrawn' );
				case 'delete':
					$this->news->delete( $id );

					return NewsAdminPage::url( 'deleted' );
				default:
					return NewsAdminPage::url( 'error' );
			}
		} catch ( NewsException $e ) {
			// The form shows the refused text again, so nothing typed is lost.
			if ( in_array( $do, array( 'save', 'preview' ), true ) ) {
				NewsAdminPage::keep_input( $text( 'title' ), $text( 'body' ) );
			}

			return $id > 0 ? NewsAdminPage::edit_url( $id, $e->reason() ) : NewsAdminPage::new_url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Changing a news message failed.', array_merge( $e->context(), array( 'news_id' => $id ) ) );

			return NewsAdminPage::url( 'error' );
		}
	}
}
