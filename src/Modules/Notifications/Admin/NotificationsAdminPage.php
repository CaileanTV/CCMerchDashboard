<?php
/**
 * Merch Dashboard → Notifications.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Notifications\Email\EmailStyle;
use MerchBoard\Modules\Notifications\Notification;
use MerchBoard\Modules\Notifications\NotificationData;
use MerchBoard\Modules\Notifications\NotificationRepository;
use MerchBoard\Modules\Notifications\NotificationSender;
use MerchBoard\Modules\Notifications\NotificationSettings;
use MerchBoard\Modules\Notifications\NotificationsException;
use MerchBoard\Modules\Notifications\NotificationType;
use MerchBoard\Modules\Notifications\NotificationTypes;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator's notification page (ADR-079 pkt. 8,
 * `merchboard_manage_settings`):
 *
 * - every type grouped by recipient and topic, with "E-mail" and
 *   "Dashboard" switches (important types are marked) and "Show example";
 * - the example: the e-mail with sample data in a sandboxed iframe (no
 *   scripts, no same origin), its text part, the dashboard line and "Send a
 *   test e-mail to me";
 * - the log of the latest e-mails and who gets the administrator's e-mails.
 */
final class NotificationsAdminPage {

	public const SLUG = 'merchboard-notifications';

	public const RESULT_ARG = 'merchboard_notifications_result';

	/**
	 * Between Settings (20) and Payouts (24).
	 */
	public const PRIORITY = 21;

	/**
	 * Entries in the log.
	 */
	private const LOG_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * @param NotificationTypes      $types         Catalog.
	 * @param NotificationSettings   $settings      Administrator's choices.
	 * @param NotificationSender     $sender        Renders the examples.
	 * @param NotificationRepository $notifications The log.
	 * @param CreatorRepository      $creators      Creator names in the log.
	 */
	public function __construct(
		private NotificationTypes $types,
		private NotificationSettings $settings,
		private NotificationSender $sender,
		private NotificationRepository $notifications,
		private CreatorRepository $creators
	) {
	}

	/**
	 * Adds the submenu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Notifications', 'merchboard' ),
			__( 'Notifications', 'merchboard' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The page's URL.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result = '' ): string {
		return self::page_url( array(), $result );
	}

	/**
	 * A type's example.
	 *
	 * @param string $key    Type key.
	 * @param string $result Outcome code.
	 */
	public static function preview_url( string $key, string $result = '' ): string {
		return self::page_url( array( 'preview' => $key ), $result );
	}

	/**
	 * The message of an outcome code, or null.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null Text and notice type.
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                                => array( __( 'The notification settings are saved.', 'merchboard' ), 'success' ),
			'test_sent'                            => array( __( 'The test e-mail was sent to you. Check your inbox (and the spam folder).', 'merchboard' ), 'success' ),
			NotificationsException::NOT_SENT       => array( __( 'The test e-mail could not be sent. Check that the site can send e-mail; the log has the reason.', 'merchboard' ), 'error' ),
			NotificationsException::UNKNOWN_TYPE   => array( __( 'That notification does not exist.', 'merchboard' ), 'error' ),
			NotificationsException::NOT_AUTHORIZED => array( __( 'You are not allowed to change the notifications.', 'merchboard' ), 'error' ),
			'error'                                => array( __( 'A technical error occurred. Nothing was changed; try again or check the log.', 'merchboard' ), 'error' ),
			default                                => null,
		};
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the notifications.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$result  = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$preview = isset( $_GET['preview'] ) ? sanitize_key( wp_unslash( $_GET['preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$type    = '' === $preview ? null : $this->types->get( $preview );

		echo '<div class="wrap merchboard-notifications">';
		echo '<h1>' . esc_html__( 'Notifications', 'merchboard' ) . '</h1>';

		$message = self::result_message( $result );

		if ( null !== $message ) {
			echo '<div class="notice notice-' . esc_attr( $message[1] ) . ' is-dismissible"><p>' . esc_html( $message[0] ) . '</p></div>';
		}

		if ( null !== $type ) {
			$this->render_preview( $type );
		} else {
			$this->render_settings();
			$this->render_log();
		}

		echo '</div>';
	}

	/**
	 * The switches per type.
	 */
	private function render_settings(): void {
		$style = EmailStyle::current();

		echo '<p>' . esc_html__( 'Choose which notifications are sent. Creators get them by e-mail and at the bell in their dashboard; the administrator gets them by e-mail. Creators can turn off e-mails that are not marked as important.', 'merchboard' ) . '</p>';
		echo '<p>';
		/* translators: %s: the site's admin e-mail address. */
		echo esc_html( sprintf( __( 'Administrator e-mails go to the site\'s admin e-mail: %s (Settings → General).', 'merchboard' ), (string) get_option( 'admin_email', '' ) ) );
		echo '<br>';
		/* translators: 1: sender name, 2: sender e-mail. */
		echo esc_html( sprintf( __( 'E-mails are sent from %1$s <%2$s>, in the colors of WooCommerce → Settings → Emails.', 'merchboard' ), $style->from_name, $style->from_email ) );
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( NotificationsAdminActions::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="save">';
		wp_nonce_field( NotificationsAdminActions::ACTION );

		foreach ( NotificationTypes::groups() as $group => $name ) {
			$types = array_filter( $this->types->all(), static fn( NotificationType $type ): bool => $type->group === $group );

			if ( array() === $types ) {
				continue;
			}

			$admin = 'admin' === $group;

			echo '<h2>' . esc_html( $admin ? $name : sprintf( /* translators: %s: group name. */ __( 'To the creator: %s', 'merchboard' ), $name ) ) . '</h2>';
			echo '<table class="widefat striped merchboard-notifications__table"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Notification', 'merchboard' ) . '</th>';
			echo '<th scope="col" style="width:7em">' . esc_html__( 'E-mail', 'merchboard' ) . '</th>';

			if ( ! $admin ) {
				echo '<th scope="col" style="width:7em">' . esc_html__( 'Dashboard', 'merchboard' ) . '</th>';
			}

			echo '<th scope="col" style="width:9em"><span class="screen-reader-text">' . esc_html__( 'Example', 'merchboard' ) . '</span></th>';
			echo '</tr></thead><tbody>';

			foreach ( $types as $type ) {
				$this->render_row( $type, $admin );
			}

			echo '</tbody></table>';
		}

		submit_button( __( 'Save notification settings', 'merchboard' ) );
		echo '</form>';
	}

	/**
	 * One type with its switches.
	 *
	 * @param NotificationType $type  Type.
	 * @param bool             $admin Whether it is an administrator group.
	 */
	private function render_row( NotificationType $type, bool $admin ): void {
		$id = 'merchboard-notification-' . $type->key;

		echo '<tr>';
		echo '<td><strong>' . esc_html( $type->label() ) . '</strong>';

		if ( $type->important ) {
			echo ' <span class="merchboard-notifications__badge" style="display:inline-block;margin-left:6px;padding:0 6px;border-radius:3px;background:#f0f0f1;font-size:12px">' . esc_html__( 'Important', 'merchboard' ) . '</span>';
		}

		echo '<br><span class="description">' . esc_html( $type->description() ) . '</span></td>';

		foreach ( $admin ? array( NotificationSettings::EMAIL ) : array( NotificationSettings::EMAIL, NotificationSettings::DASHBOARD ) as $channel ) {
			echo '<td><label for="' . esc_attr( $id . '-' . $channel ) . '"><input type="checkbox" id="' . esc_attr( $id . '-' . $channel ) . '" name="' . esc_attr( 'notifications[' . $type->key . '][' . $channel . ']' ) . '" value="1"' . checked( $this->settings->enabled( $type, $channel ), true, false ) . '> ';
			echo esc_html__( 'On', 'merchboard' ) . '<span class="screen-reader-text"> ' . esc_html( $type->label() ) . '</span></label></td>';
		}

		echo '<td><a href="' . esc_url( self::preview_url( $type->key ) ) . '">' . esc_html__( 'Show example', 'merchboard' ) . '</a></td>';
		echo '</tr>';
	}

	/**
	 * A type's example: the e-mail, its text part and the dashboard line.
	 *
	 * @param NotificationType $type Type.
	 */
	private function render_preview( NotificationType $type ): void {
		$mail = $this->sender->compose( $type, $type->sample, NotificationType::ADMIN === $type->audience ? '' : __( 'Nova', 'merchboard' ) );

		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All notifications', 'merchboard' ) . '</a></p>';
		echo '<h2>' . esc_html( $type->label() ) . '</h2>';
		echo '<p class="description">' . esc_html( $type->description() ) . ' ' . esc_html__( 'The example uses sample data.', 'merchboard' ) . '</p>';

		if ( $type->has_dashboard() ) {
			echo '<h3>' . esc_html__( 'At the bell in the dashboard', 'merchboard' ) . '</h3>';
			echo '<p style="max-width:40rem;padding:10px 14px;background:#fff;border:1px solid #dcdcde;border-radius:6px">' . esc_html( $type->text( new NotificationData( $type->sample ) ) ) . '</p>';
		}

		echo '<h3>' . esc_html__( 'E-mail', 'merchboard' ) . '</h3>';
		/* translators: %s: e-mail subject. */
		echo '<p><strong>' . esc_html( sprintf( __( 'Subject: %s', 'merchboard' ), $mail['subject'] ) ) . '</strong></p>';
		// A sandbox without permissions: no scripts, no forms, no access to this page.
		echo '<iframe title="' . esc_attr__( 'E-mail example', 'merchboard' ) . '" sandbox="" srcdoc="' . esc_attr( $mail['html'] ) . '" style="width:100%;max-width:680px;height:760px;border:1px solid #dcdcde;border-radius:6px;background:#fff"></iframe>';

		echo '<details style="margin:12px 0"><summary>' . esc_html__( 'Text version', 'merchboard' ) . '</summary><pre style="white-space:pre-wrap;max-width:680px">' . esc_html( $mail['text'] ) . '</pre></details>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( NotificationsAdminActions::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="test">';
		echo '<input type="hidden" name="type" value="' . esc_attr( $type->key ) . '">';
		wp_nonce_field( NotificationsAdminActions::ACTION );
		submit_button( __( 'Send a test e-mail to me', 'merchboard' ), 'secondary', 'submit', false );
		/* translators: %s: the administrator's e-mail address. */
		echo ' <span class="description">' . esc_html( sprintf( __( 'To %s, with the sample data.', 'merchboard' ), wp_get_current_user()->user_email ) ) . '</span>';
		echo '</form>';
	}

	/**
	 * The latest e-mails.
	 */
	private function render_log(): void {
		echo '<h2>' . esc_html__( 'Latest e-mails', 'merchboard' ) . '</h2>';

		try {
			$rows = $this->notifications->recent_emails( self::LOG_SIZE );
		} catch ( DatabaseException ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The log could not be read.', 'merchboard' ) . '</p></div>';

			return;
		}

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No notification e-mails yet.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Time', 'merchboard' ), __( 'Notification', 'merchboard' ), __( 'Recipient', 'merchboard' ), __( 'Status', 'merchboard' ), __( 'Attempts', 'merchboard' ), __( 'Reason', 'merchboard' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$names = array();

		foreach ( $rows as $row ) {
			$type = $this->types->get( $row->type );

			echo '<tr>';
			echo '<td>' . esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i' ) ) . '</td>';
			echo '<td>' . esc_html( null === $type ? $row->type : $type->label() ) . '</td>';
			echo '<td>' . esc_html( $this->recipient( $row, $names ) ) . '</td>';
			echo '<td>' . esc_html( self::status( $row->email_status ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row->email_attempts ) . '</td>';
			echo '<td>' . esc_html( (string) $row->email_error ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Who the e-mail went to, in words.
	 *
	 * @param Notification       $row   Notification.
	 * @param array<int, string> $names Creator names read so far.
	 */
	private function recipient( Notification $row, array &$names ): string {
		if ( NotificationType::ADMIN === $row->audience || null === $row->creator_id ) {
			return __( 'Administrator', 'merchboard' );
		}

		if ( ! isset( $names[ $row->creator_id ] ) ) {
			try {
				$names[ $row->creator_id ] = $this->creators->find( $row->creator_id )->display_name ?? '#' . $row->creator_id;
			} catch ( DatabaseException ) {
				$names[ $row->creator_id ] = '#' . $row->creator_id;
			}
		}

		return $names[ $row->creator_id ];
	}

	/**
	 * An e-mail status in words.
	 *
	 * @param string $status Notification::EMAIL_ constant.
	 */
	private static function status( string $status ): string {
		return match ( $status ) {
			Notification::EMAIL_PENDING => __( 'Waiting', 'merchboard' ),
			Notification::EMAIL_SENT    => __( 'Sent', 'merchboard' ),
			Notification::EMAIL_FAILED  => __( 'Failed', 'merchboard' ),
			Notification::EMAIL_SKIPPED => __( 'Not sent (no recipient)', 'merchboard' ),
			default                     => $status,
		};
	}

	/**
	 * A URL of the page.
	 *
	 * @param array<string, string> $args   Query arguments.
	 * @param string                $result Outcome code.
	 */
	private static function page_url( array $args, string $result ): string {
		$args['page'] = self::SLUG;

		if ( '' !== $result ) {
			$args[ self::RESULT_ARG ] = $result;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
