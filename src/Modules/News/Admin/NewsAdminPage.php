<?php
/**
 * Merch Dashboard → News.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\News\NewsException;
use MerchBoard\Modules\News\NewsHtml;
use MerchBoard\Modules\News\NewsMessage;
use MerchBoard\Modules\News\NewsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The news for the creators (step 25.11, ADR-072), for
 * `merchboard_manage_creators`: the list of messages with how many active
 * creators have seen and closed each, the editor (title and WordPress' own
 * editor with bold, italic, lists and links only), the preview of the
 * cleaned text, and per published message which creators have seen and
 * closed it. Every change goes through NewsAdminActions; everything is
 * escaped, and the body is cleaned again before it is shown.
 */
final class NewsAdminPage {

	/**
	 * The page slug.
	 */
	public const SLUG = 'merchboard-news';

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_news_result';

	/**
	 * Transient prefix of a refused form's text (per user, 10 minutes).
	 */
	private const INPUT_TRANSIENT = 'merchboard_news_input_';

	/**
	 * Constructor.
	 *
	 * @param NewsRepository    $news     Messages.
	 * @param CreatorRepository $creators Creators.
	 */
	public function __construct( private NewsRepository $news, private CreatorRepository $creators ) {
	}

	/**
	 * Adds the submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard News', 'merchboard' ),
			__( 'News', 'merchboard' ),
			Capabilities::MANAGE_CREATORS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The list's URL.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result = '' ): string {
		return self::page_url( array(), $result );
	}

	/**
	 * A new message's URL.
	 *
	 * @param string $result Outcome code.
	 */
	public static function new_url( string $result = '' ): string {
		return self::page_url( array( 'view' => 'edit' ), $result );
	}

	/**
	 * A message's edit URL.
	 *
	 * @param int    $id     Id.
	 * @param string $result Outcome code.
	 */
	public static function edit_url( int $id, string $result = '' ): string {
		return self::page_url(
			array(
				'view' => 'edit',
				'news' => $id,
			),
			$result
		);
	}

	/**
	 * A published message's readers URL.
	 *
	 * @param int    $id     Id.
	 * @param string $result Outcome code.
	 */
	public static function view_url( int $id, string $result = '' ): string {
		return self::page_url(
			array(
				'view' => 'readers',
				'news' => $id,
			),
			$result
		);
	}

	/**
	 * Keeps a refused form's text for the next page view.
	 *
	 * @param string $title Title.
	 * @param string $body  Body.
	 */
	public static function keep_input( string $title, string $body ): void {
		set_transient(
			self::INPUT_TRANSIENT . get_current_user_id(),
			array(
				'title' => NewsHtml::title( $title ),
				'body'  => NewsHtml::clean( $body ),
			),
			10 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Message and notice type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                       => array( __( 'The message was saved.', 'merchboard' ), 'success' ),
			'preview'                     => array( __( 'The message was saved. The preview below shows it as creators will see it.', 'merchboard' ), 'info' ),
			'published'                   => array( __( 'The message was published. Every active creator sees it once on their next visit to the dashboard.', 'merchboard' ), 'success' ),
			'withdrawn'                   => array( __( 'The message was withdrawn. Creators no longer see it.', 'merchboard' ), 'success' ),
			'deleted'                     => array( __( 'The draft was deleted.', 'merchboard' ), 'success' ),
			'confirm_required'            => array( __( 'Tick the box to confirm that every active creator should see the message.', 'merchboard' ), 'error' ),
			NewsException::INVALID_TITLE  => array( __( 'Enter a title.', 'merchboard' ), 'error' ),
			NewsException::INVALID_BODY   => array( __( 'Write the message.', 'merchboard' ), 'error' ),
			NewsException::UNKNOWN        => array( __( 'The message no longer exists.', 'merchboard' ), 'error' ),
			NewsException::NOT_DRAFT      => array( __( 'Only a draft can be deleted, and a published message is published already.', 'merchboard' ), 'error' ),
			NewsException::NOT_PUBLISHED  => array( __( 'Only a published message can be withdrawn.', 'merchboard' ), 'error' ),
			NewsException::NOT_AUTHORIZED => array( __( 'You are not allowed to change the news.', 'merchboard' ), 'error' ),
			'error'                       => array( __( 'A technical error occurred. Nothing was changed.', 'merchboard' ), 'error' ),
			default                       => null,
		};
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the news.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only parameters; nothing is changed by this request.
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$id     = isset( $_GET['news'] ) ? absint( $_GET['news'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'News', 'merchboard' ) . '</h1>';

		if ( 'edit' !== $view ) {
			echo ' <a class="page-title-action" href="' . esc_url( self::new_url() ) . '">' . esc_html__( 'Write a message', 'merchboard' ) . '</a>';
		}

		echo '<hr class="wp-header-end">';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		try {
			$message = $id > 0 ? $this->news->find( $id ) : null;

			match ( true ) {
				'edit' === $view => $this->render_edit( $message, 'preview' === $code ),
				'readers' === $view && null !== $message => $this->render_readers( $message ),
				default => $this->render_list(),
			};
		} catch ( DatabaseException ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The news could not be read. Please try again later.', 'merchboard' ) . '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * The list of messages.
	 *
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function render_list(): void {
		echo '<p>' . esc_html__( 'Messages to the creators, e.g. what is new after an update. A published message is shown once as a popup to every active creator on their next visit to the dashboard, and stays on the page "News" in their dashboard. Creators created later do not see older messages.', 'merchboard' ) . '</p>';

		$messages = $this->news->all();

		if ( array() === $messages ) {
			echo '<p>' . esc_html__( 'No messages yet.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Title', 'merchboard' ) . '</th><th>' . esc_html__( 'Status', 'merchboard' ) . '</th><th>' . esc_html__( 'Published', 'merchboard' ) . '</th><th>' . esc_html__( 'Seen / closed', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $messages as $message ) {
			$link = NewsMessage::DRAFT === $message->status ? self::edit_url( $message->id ) : self::view_url( $message->id );

			printf(
				'<tr><td><a href="%1$s"><strong>%2$s</strong></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_url( $link ),
				esc_html( $message->title ),
				esc_html( self::status_label( $message->status ) ),
				esc_html( null === $message->published_at ? '—' : self::date( $message->published_at->format( 'Y-m-d H:i:s' ) ) ),
				esc_html( NewsMessage::DRAFT === $message->status ? '—' : $this->counts( $message ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The editor.
	 *
	 * @param NewsMessage|null $message The message; null for a new one.
	 * @param bool             $preview Whether to show the preview.
	 */
	private function render_edit( ?NewsMessage $message, bool $preview ): void {
		$kept  = get_transient( self::INPUT_TRANSIENT . get_current_user_id() );
		$title = $message->title ?? '';
		$body  = $message->body ?? '';

		if ( is_array( $kept ) ) {
			delete_transient( self::INPUT_TRANSIENT . get_current_user_id() );
			$title = is_string( $kept['title'] ?? null ) ? $kept['title'] : $title;
			$body  = is_string( $kept['body'] ?? null ) ? $kept['body'] : $body;
		}

		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← All messages', 'merchboard' ) . '</a></p>';

		if ( null !== $message && NewsMessage::DRAFT !== $message->status ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This message is published or withdrawn. A correction is shown to creators at once, but the popup is not shown again.', 'merchboard' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( NewsAdminActions::ACTION ) . '">';
		echo '<input type="hidden" name="news_id" value="' . esc_attr( (string) ( $message->id ?? 0 ) ) . '">';
		wp_nonce_field( NewsAdminActions::ACTION );
		echo '<p><label for="merchboard-news-title"><strong>' . esc_html__( 'Title', 'merchboard' ) . '</strong></label><br>';
		echo '<input type="text" id="merchboard-news-title" name="title" class="large-text" maxlength="' . esc_attr( (string) NewsHtml::TITLE_MAX ) . '" required value="' . esc_attr( $title ) . '"></p>';
		echo '<p><strong>' . esc_html__( 'Message', 'merchboard' ) . '</strong></p>';
		wp_editor(
			$body,
			'merchboard_news_body',
			array(
				'textarea_name' => 'body',
				'media_buttons' => false,
				'teeny'         => false,
				'quicktags'     => false,
				'textarea_rows' => 12,
				'tinymce'       => array(
					'toolbar1'      => 'bold,italic,bullist,numlist,link,unlink,undo,redo',
					'toolbar2'      => '',
					'block_formats' => 'Paragraph=p',
				),
			)
		);
		echo '<p class="description">' . esc_html__( 'Paragraphs, bold, italic, lists and links. Other formatting is removed.', 'merchboard' ) . '</p>';
		echo '<p><button type="submit" name="do" value="save" class="button">' . esc_html__( 'Save', 'merchboard' ) . '</button> ';
		echo '<button type="submit" name="do" value="preview" class="button">' . esc_html__( 'Save and preview', 'merchboard' ) . '</button></p>';

		if ( null !== $message && NewsMessage::PUBLISHED !== $message->status ) {
			echo '<hr><p><label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'Show the message to every active creator', 'merchboard' ) . '</label></p>';
			echo '<p><button type="submit" name="do" value="publish" class="button button-primary">' . esc_html__( 'Publish', 'merchboard' ) . '</button></p>';
		}

		if ( null !== $message && NewsMessage::DRAFT === $message->status ) {
			echo '<p><button type="submit" name="do" value="delete" class="button-link button-link-delete">' . esc_html__( 'Delete draft', 'merchboard' ) . '</button></p>';
		}

		echo '</form>';

		if ( $preview && null !== $message ) {
			echo '<h2>' . esc_html__( 'Preview', 'merchboard' ) . '</h2>';
			echo '<div class="card" style="max-width:40rem"><h3>' . esc_html( $message->title ) . '</h3>' . wp_kses_post( NewsHtml::clean( $message->body ) ) . '</div>';
		}
	}

	/**
	 * Which active creators have seen and closed a message.
	 *
	 * @param NewsMessage $message The message.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function render_readers( NewsMessage $message ): void {
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← All messages', 'merchboard' ) . '</a></p>';
		echo '<h2>' . esc_html( $message->title ) . '</h2>';
		printf(
			'<p>%1$s · %2$s · <a href="%3$s">%4$s</a></p>',
			esc_html( self::status_label( $message->status ) ),
			esc_html( null === $message->published_at ? '—' : self::date( $message->published_at->format( 'Y-m-d H:i:s' ) ) ),
			esc_url( self::edit_url( $message->id ) ),
			esc_html__( 'Edit', 'merchboard' )
		);

		if ( NewsMessage::PUBLISHED === $message->status ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( NewsAdminActions::ACTION ) . '"><input type="hidden" name="do" value="withdraw"><input type="hidden" name="news_id" value="' . esc_attr( (string) $message->id ) . '">';
			wp_nonce_field( NewsAdminActions::ACTION );
			echo '<p><button type="submit" class="button">' . esc_html__( 'Withdraw', 'merchboard' ) . '</button></p></form>';
		}

		$reads = $this->news->reads( $message->id );

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Creator', 'merchboard' ) . '</th><th>' . esc_html__( 'Status', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $this->audience( $message ) as $creator ) {
			$read = $reads[ $creator->id ] ?? null;

			printf(
				'<tr><td>%1$s</td><td>%2$s</td></tr>',
				esc_html( $creator->display_name ),
				esc_html(
					match ( true ) {
						null !== ( $read['closed_at'] ?? null ) => sprintf(
							/* translators: %s: date and time. */
							__( 'Closed %s', 'merchboard' ),
							self::date( (string) $read['closed_at'] )
						),
						null !== ( $read['seen_at'] ?? null ) => sprintf(
							/* translators: %s: date and time. */
							__( 'Seen %s', 'merchboard' ),
							self::date( (string) $read['seen_at'] )
						),
						default => __( 'Not seen', 'merchboard' ),
					}
				)
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * "Seen X / closed Y of Z" for a message.
	 *
	 * @param NewsMessage $message The message.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function counts( NewsMessage $message ): string {
		$reads  = $this->news->reads( $message->id );
		$seen   = 0;
		$closed = 0;
		$total  = 0;

		foreach ( $this->audience( $message ) as $creator ) {
			++$total;
			$seen   += null !== ( $reads[ $creator->id ]['seen_at'] ?? null ) ? 1 : 0;
			$closed += null !== ( $reads[ $creator->id ]['closed_at'] ?? null ) ? 1 : 0;
		}

		return sprintf(
			/* translators: 1: creators who saw it, 2: creators who closed it, 3: active creators. */
			__( '%1$d seen / %2$d closed of %3$d', 'merchboard' ),
			$seen,
			$closed,
			$total
		);
	}

	/**
	 * The active creators who see a message: created at or before it was published.
	 *
	 * @param NewsMessage $message The message.
	 * @return list<Creator>
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function audience( NewsMessage $message ): array {
		$published = $message->published_at;

		return array_values(
			array_filter(
				$this->creators->all(),
				static fn( Creator $creator ): bool => $creator->is_active() && ( null === $published || $creator->created_at <= $published )
			)
		);
	}

	/**
	 * A status in words.
	 *
	 * @param string $status NewsMessage constant.
	 */
	private static function status_label( string $status ): string {
		return match ( $status ) {
			NewsMessage::PUBLISHED => __( 'Published', 'merchboard' ),
			NewsMessage::WITHDRAWN => __( 'Withdrawn', 'merchboard' ),
			default                => __( 'Draft', 'merchboard' ),
		};
	}

	/**
	 * A UTC time in the site's format and time zone.
	 *
	 * @param string $utc `Y-m-d H:i:s`.
	 */
	private static function date( string $utc ): string {
		return get_date_from_gmt( $utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * A URL of the page.
	 *
	 * @param array<string, int|string> $args   Query arguments.
	 * @param string                    $result Outcome code.
	 */
	private static function page_url( array $args, string $result ): string {
		$args['page'] = self::SLUG;

		if ( '' !== $result ) {
			$args[ self::RESULT_ARG ] = $result;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
