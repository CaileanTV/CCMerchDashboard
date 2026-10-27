<?php
/**
 * Sends the e-mail of a stored notification.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Notifications\Email\EmailRenderer;
use MerchBoard\Modules\Notifications\Email\EmailStyle;
use MerchBoard\Modules\Notifications\Email\Mailer;
use MerchBoard\Modules\WooCommerce\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * The Action Scheduler job `merchboard_notification_send` (ADR-079 pkt. 4):
 * renders and sends one pending notification e-mail in the recipient's
 * language. A failed sending is tried again after 1, 5 and 15 minutes, at
 * most MAX_ATTEMPTS times; then the notification is `failed`. Without a
 * queue there is no retry.
 */
class NotificationSender {

	public const HOOK = 'merchboard_notification_send';

	public const MAX_ATTEMPTS = 3;

	/**
	 * Delays before the next attempt (seconds), by attempts so far.
	 */
	private const RETRY = array(
		1 => 60,
		2 => 300,
	);

	/**
	 * Constructor.
	 *
	 * @param NotificationRepository $notifications Notifications.
	 * @param NotificationTypes      $types         Catalog.
	 * @param CreatorRepository      $creators      Creators.
	 * @param EmailRenderer          $renderer      Renderer.
	 * @param Mailer                 $mailer        Mailer.
	 * @param DashboardRoute         $route         Dashboard URLs.
	 * @param JobQueue               $queue         Queue (retries).
	 * @param Logger                 $logger        Technical log.
	 */
	public function __construct(
		private NotificationRepository $notifications,
		private NotificationTypes $types,
		private CreatorRepository $creators,
		private EmailRenderer $renderer,
		private Mailer $mailer,
		private DashboardRoute $route,
		private JobQueue $queue,
		private Logger $logger
	) {
	}

	/**
	 * The job: sends the notification's e-mail if it is still pending.
	 *
	 * @param mixed $id Notification id.
	 */
	public function run( mixed $id ): void {
		$id = is_numeric( $id ) ? (int) $id : 0;

		try {
			$notification = $id > 0 ? $this->notifications->find( $id ) : null;

			if ( null === $notification || Notification::EMAIL_PENDING !== $notification->email_status ) {
				return;
			}

			$this->send( $notification );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A notification e-mail could not be sent.', array_merge( $e->context(), array( 'notification_id' => $id ) ) );
		}
	}

	/**
	 * The e-mail of a notification as it would be sent (subject, HTML and
	 * text), for the admin page's preview and test e-mail.
	 *
	 * @param NotificationType     $type Type.
	 * @param array<string, mixed> $data Values.
	 * @param string               $name Recipient's name.
	 * @return array{subject: string, html: string, text: string}
	 */
	public function compose( NotificationType $type, array $data, string $name ): array {
		$message = $type->message( new NotificationData( $data, $name ) );
		$style   = EmailStyle::current();
		$url     = $this->link( $type );

		return array(
			'subject' => $message->subject,
			'html'    => $this->renderer->html( $message, $style, $url, $name ),
			'text'    => $this->renderer->text( $message, $style, $url, $name ),
		);
	}

	/**
	 * Sends any e-mail of the catalog to an address (the admin page's test).
	 *
	 * @param NotificationType     $type Type.
	 * @param array<string, mixed> $data Values.
	 * @param string               $to   Address.
	 * @param string               $name Recipient's name.
	 * @return string|null Null when sent, otherwise a short reason.
	 */
	public function send_to( NotificationType $type, array $data, string $to, string $name ): ?string {
		$mail = $this->compose( $type, $data, $name );

		return $this->mailer->send( $to, $mail['subject'], $mail['html'], $mail['text'], EmailStyle::current() );
	}

	/**
	 * Sends a pending notification and stores the outcome.
	 *
	 * @param Notification $notification Notification.
	 * @throws DatabaseException When the outcome cannot be stored.
	 */
	private function send( Notification $notification ): void {
		$type = $this->types->get( $notification->type );

		if ( null === $type ) {
			$this->notifications->update_email( $notification->id, Notification::EMAIL_SKIPPED, $notification->email_attempts, NotificationsException::UNKNOWN_TYPE );

			return;
		}

		[ $to, $name, $user_id ] = $this->recipient( $notification );

		if ( '' === $to ) {
			$this->notifications->update_email( $notification->id, Notification::EMAIL_SKIPPED, $notification->email_attempts, NotificationsException::NO_RECIPIENT );

			return;
		}

		$switched = $user_id > 0 && function_exists( 'switch_to_user_locale' ) && switch_to_user_locale( $user_id );

		try {
			$error = $this->send_to( $type, $notification->data, $to, $name );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}

		$attempts = $notification->email_attempts + 1;

		if ( null === $error ) {
			$this->notifications->update_email( $notification->id, Notification::EMAIL_SENT, $attempts, null );

			return;
		}

		$retry = $attempts < self::MAX_ATTEMPTS && $this->queue->available() && $this->queue->enqueue( self::HOOK, array( $notification->id ), self::RETRY[ $attempts ] ?? 900 );

		$this->notifications->update_email( $notification->id, $retry ? Notification::EMAIL_PENDING : Notification::EMAIL_FAILED, $attempts, $error );
		$this->logger->warning(
			'A notification e-mail was not sent.',
			array(
				'notification_id' => $notification->id,
				'type'            => $notification->type,
				'attempts'        => $attempts,
				'retry'           => $retry,
			)
		);
	}

	/**
	 * Where the e-mail goes: the creator's user, or the site's admin e-mail.
	 *
	 * @param Notification $notification Notification.
	 * @return array{0: string, 1: string, 2: int} Address ('' for none), name and user id (0 for none).
	 * @throws DatabaseException When the creator cannot be read.
	 */
	private function recipient( Notification $notification ): array {
		if ( NotificationType::ADMIN === $notification->audience ) {
			$email = (string) get_option( 'admin_email', '' );

			return array( is_email( $email ) ? $email : '', '', 0 );
		}

		$creator = null === $notification->creator_id ? null : $this->creators->find( $notification->creator_id );
		$user    = null === $creator?->user_id ? false : get_userdata( $creator->user_id );

		if ( null === $creator || ! $user instanceof \WP_User || ! is_email( $user->user_email ) ) {
			return array( '', '', 0 );
		}

		return array( $user->user_email, $creator->display_name, $user->ID );
	}

	/**
	 * The button's target: the type's dashboard page or admin page.
	 *
	 * @param NotificationType $type Type.
	 */
	private function link( NotificationType $type ): string {
		return NotificationType::ADMIN === $type->audience
			? admin_url( 'admin.php?page=' . rawurlencode( $type->link ) )
			: $this->route->url( $type->link );
	}
}
