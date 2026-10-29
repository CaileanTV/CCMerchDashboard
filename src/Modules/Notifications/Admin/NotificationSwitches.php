<?php
/**
 * The switches and the example of notification types on an admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications\Admin;

use Closure;
use MerchBoard\Modules\Notifications\NotificationData;
use MerchBoard\Modules\Notifications\NotificationSender;
use MerchBoard\Modules\Notifications\NotificationSettings;
use MerchBoard\Modules\Notifications\NotificationType;

defined( 'ABSPATH' ) || exit;

/**
 * Renders notification types for the administrator (ADR-079 pkt. 8): a
 * table with "E-mail" (and, for creator types, "Dashboard") switches and
 * "Show example", and the example itself with "Send a test e-mail to me".
 * The Notifications page uses it for the catalog; a module's own page for
 * its types (the product designer, ADR-080 pkt. 3). The page owns the form,
 * its admin-post action and its URLs.
 */
final class NotificationSwitches {

	/**
	 * Constructor.
	 *
	 * @param NotificationSettings $settings Administrator's choices.
	 * @param NotificationSender   $sender   Renders the examples.
	 */
	public function __construct( private NotificationSettings $settings, private NotificationSender $sender ) {
	}

	/**
	 * A table of types with their switches (inside the page's form).
	 *
	 * @param string             $heading Heading.
	 * @param NotificationType[] $types   Types.
	 * @param bool               $admin   Whether they are administrator types (e-mail only).
	 * @param Closure            $preview The example's URL of a type key.
	 *
	 * @phpstan-param Closure(string): string $preview
	 */
	public function table( string $heading, array $types, bool $admin, Closure $preview ): void {
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		echo '<table class="widefat striped merchboard-notifications__table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Notification', 'merchboard' ) . '</th>';
		echo '<th scope="col" style="width:7em">' . esc_html__( 'E-mail', 'merchboard' ) . '</th>';

		if ( ! $admin ) {
			echo '<th scope="col" style="width:7em">' . esc_html__( 'Dashboard', 'merchboard' ) . '</th>';
		}

		echo '<th scope="col" style="width:9em"><span class="screen-reader-text">' . esc_html__( 'Example', 'merchboard' ) . '</span></th>';
		echo '</tr></thead><tbody>';

		foreach ( $types as $type ) {
			$this->row( $type, $admin, $preview( $type->key ) );
		}

		echo '</tbody></table>';
	}

	/**
	 * A type's example: the e-mail, its text part, the dashboard line and a
	 * form that sends the test e-mail (`do=test`, `type`) to the page's action.
	 *
	 * @param NotificationType $type   Type.
	 * @param string           $back   URL of the list.
	 * @param string           $action The page's admin-post action (also the nonce action).
	 */
	public function preview( NotificationType $type, string $back, string $action ): void {
		$mail = $this->sender->compose( $type, $type->sample, NotificationType::ADMIN === $type->audience ? '' : __( 'Nova', 'merchboard' ) );

		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All notifications', 'merchboard' ) . '</a></p>';
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
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="do" value="test">';
		echo '<input type="hidden" name="type" value="' . esc_attr( $type->key ) . '">';
		wp_nonce_field( $action );
		submit_button( __( 'Send a test e-mail to me', 'merchboard' ), 'secondary', 'submit', false );
		/* translators: %s: the administrator's e-mail address. */
		echo ' <span class="description">' . esc_html( sprintf( __( 'To %s, with the sample data.', 'merchboard' ), wp_get_current_user()->user_email ) ) . '</span>';
		echo '</form>';
	}

	/**
	 * One type with its switches.
	 *
	 * @param NotificationType $type    Type.
	 * @param bool             $admin   Whether it is an administrator type.
	 * @param string           $preview The example's URL.
	 */
	private function row( NotificationType $type, bool $admin, string $preview ): void {
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

		echo '<td><a href="' . esc_url( $preview ) . '">' . esc_html__( 'Show example', 'merchboard' ) . '</a></td>';
		echo '</tr>';
	}
}
