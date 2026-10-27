<?php
/**
 * Form handler of the notifications admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Notifications\NotificationSender;
use MerchBoard\Modules\Notifications\NotificationSettings;
use MerchBoard\Modules\Notifications\NotificationsException;
use MerchBoard\Modules\Notifications\NotificationTypes;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=merchboard_notifications` (ADR-079 pkt. 8): saves
 * the administrator's choices (`do=save`) or sends a type's sample e-mail
 * to the logged-in administrator (`do=test`). Capability first, then the
 * nonce; Post/Redirect/Get with a result code.
 */
final class NotificationsAdminActions {

	public const ACTION = 'merchboard_notifications';

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
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the notifications.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$text  = static fn( string $key ): string => is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';
		$nonce = $text( '_wpnonce' );

		if ( 1 !== wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			if ( 'test' === $text( 'do' ) ) {
				return $this->test( sanitize_key( $text( 'type' ) ) );
			}

			$this->settings->save( is_array( $input['notifications'] ?? null ) ? $input['notifications'] : array() );

			return NotificationsAdminPage::url( 'saved' );
		} catch ( NotificationsException $e ) {
			return NotificationsAdminPage::url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving the notification settings failed.', $e->context() );

			return NotificationsAdminPage::url( 'error' );
		}
	}

	/**
	 * Sends a type's sample e-mail to the logged-in administrator.
	 *
	 * @param string $key Type key.
	 * @return string Redirect URL.
	 */
	private function test( string $key ): string {
		$type = $this->types->get( $key );
		$user = wp_get_current_user();

		if ( null === $type ) {
			return NotificationsAdminPage::url( NotificationsException::UNKNOWN_TYPE );
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

		return NotificationsAdminPage::preview_url( $key, null === $error ? 'test_sent' : NotificationsException::NOT_SENT );
	}
}
