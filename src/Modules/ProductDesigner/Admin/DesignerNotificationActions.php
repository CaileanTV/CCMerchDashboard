<?php
/**
 * Form handler of the designer's notifications.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Notifications\NotificationSender;
use MerchBoard\Modules\Notifications\NotificationSettings;
use MerchBoard\Modules\Notifications\NotificationsException;
use MerchBoard\Modules\Notifications\NotificationTypes;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=merchboard_designer_notifications` (ADR-080 pkt. 3):
 * saves the administrator's choices of the designer's notification types
 * (`do=save`; the other types keep theirs) or sends one's sample e-mail to
 * the logged-in administrator (`do=test`). Capability
 * (`merchboard_manage_settings`, as the Notifications page) first, then the
 * nonce; Post/Redirect/Get to Designs → Notifications with a result code.
 */
final class DesignerNotificationActions {

	public const ACTION = 'merchboard_designer_notifications';

	/**
	 * Constructor.
	 *
	 * @param NotificationSettings $settings Administrator's choices.
	 * @param NotificationTypes    $types    Catalog.
	 * @param NotificationSender   $sender   Sender (test e-mail).
	 * @param Logger               $logger   Technical log.
	 */
	public function __construct( private NotificationSettings $settings, private NotificationTypes $types, private NotificationSender $sender, private Logger $logger ) {
	}

	/**
	 * Hooks the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Handles the request and redirects.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle(); only known keys and booleans are read.
		exit;
	}

	/**
	 * Handles the form.
	 *
	 * @param array<mixed> $input Unslashed POST data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) || ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the notifications.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$text = static fn( string $key ): string => is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';

		if ( 1 !== wp_verify_nonce( $text( '_wpnonce' ), self::ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			if ( 'test' === $text( 'do' ) ) {
				return $this->test( sanitize_key( $text( 'type' ) ) );
			}

			$this->settings->save( is_array( $input['notifications'] ?? null ) ? $input['notifications'] : array(), array( DesignerNotifications::GROUP ) );

			return DesignsPage::notifications_url( '', 'saved' );
		} catch ( NotificationsException $e ) {
			return DesignsPage::notifications_url( '', $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving the designer\'s notification settings failed.', $e->context() );

			return DesignsPage::notifications_url( '', 'error' );
		}
	}

	/**
	 * Sends a designer type's sample e-mail to the logged-in administrator.
	 *
	 * @param string $key Type key.
	 * @return string Redirect URL.
	 */
	private function test( string $key ): string {
		$type = $this->types->in_groups( array( DesignerNotifications::GROUP ) )[ $key ] ?? null;
		$user = wp_get_current_user();

		if ( null === $type ) {
			return DesignsPage::notifications_url( '', NotificationsException::UNKNOWN_TYPE );
		}

		$error = $this->sender->send_to( $type, $type->sample, $user->user_email, $user->display_name );

		if ( null !== $error ) {
			$this->logger->warning(
				'A test notification e-mail was not sent.',
				array(
					'type'   => $key,
					'reason' => $error,
				)
			);
		}

		return DesignsPage::notifications_url( $key, null === $error ? 'test_sent' : NotificationsException::NOT_SENT );
	}
}
