<?php
/**
 * Creates notifications when something happened.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

use DateTimeInterface;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\JobQueue;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to `merchboard_notify` (ADR-079 pkt. 2), which the modules fire
 * after their transaction committed, as
 * `do_action( 'merchboard_notify', $type, $creator_id, $data, $dedupe )`.
 *
 * - An unknown type, a type the administrator turned off on both channels
 *   or a missing or deleted creator gives nothing.
 * - The notification is stored at once (at the bell, when that channel is
 *   on); the e-mail is queued (`merchboard_notification_send`) or, without
 *   a queue, sent at the end of the request.
 * - The creator's own choice turns the e-mail off, except for important types.
 * - A dedupe key ('' for none) makes the same event notify the same
 *   recipient once.
 * - It never throws: a notification must not break what triggered it.
 */
class Notifier {

	public const ACTION = 'merchboard_notify';

	/**
	 * Notifications to send at the end of this request (no queue).
	 *
	 * @var list<int>
	 */
	private array $at_shutdown = array();

	/**
	 * Constructor.
	 *
	 * @param NotificationTypes       $types         Catalog.
	 * @param NotificationSettings    $settings      Administrator's choices.
	 * @param NotificationPreferences $preferences   Creators' choices.
	 * @param NotificationRepository  $notifications Storage.
	 * @param CreatorRepository       $creators      Creators.
	 * @param JobQueue                $queue         Queue.
	 * @param NotificationSender      $sender        Sender (no queue).
	 * @param Logger                  $logger        Technical log.
	 */
	public function __construct(
		private NotificationTypes $types,
		private NotificationSettings $settings,
		private NotificationPreferences $preferences,
		private NotificationRepository $notifications,
		private CreatorRepository $creators,
		private JobQueue $queue,
		private NotificationSender $sender,
		private Logger $logger
	) {
	}

	/**
	 * Creates a notification.
	 *
	 * @param mixed $type       Type key.
	 * @param mixed $creator_id Creator (creator types), or null.
	 * @param mixed $data       Values (never secrets).
	 * @param mixed $dedupe     Dedupe key ('' for none).
	 * @return int|null The notification's id, or null when none was created.
	 */
	public function notify( mixed $type, mixed $creator_id = null, mixed $data = array(), mixed $dedupe = '' ): ?int {
		try {
			return $this->create( is_string( $type ) ? $type : '', is_numeric( $creator_id ) ? (int) $creator_id : null, is_array( $data ) ? $data : array(), is_scalar( $dedupe ) ? (string) $dedupe : '' );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'A notification could not be created.',
				array_merge(
					$e instanceof DatabaseException ? $e->context() : array( 'exception' => get_class( $e ) ),
					array( 'type' => is_string( $type ) ? $type : '' )
				)
			);

			return null;
		}
	}

	/**
	 * A published news message (`merchboard_news_published`): one
	 * notification for each current creator who sees it (created at or
	 * before its publication), once per message.
	 *
	 * @param mixed $id        Message.
	 * @param mixed $title     Title.
	 * @param mixed $published Publication time.
	 * @return int The notifications created.
	 */
	public function news_published( mixed $id, mixed $title, mixed $published ): int {
		if ( ! is_numeric( $id ) || ! is_string( $title ) || ! $published instanceof DateTimeInterface ) {
			return 0;
		}

		try {
			$creators = $this->creators->all();
		} catch ( Throwable $e ) {
			$this->logger->error( 'The creators to notify of news could not be read.', array( 'exception' => get_class( $e ) ) );

			return 0;
		}

		$created = 0;

		foreach ( $creators as $creator ) {
			if ( ! $creator->is_deleted() && $creator->created_at <= $published && null !== $this->notify( 'news_published', $creator->id, array( 'title' => $title ), 'news:' . (int) $id ) ) {
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Sends the notifications of this request that had no queue.
	 */
	public function send_pending(): void {
		$ids               = $this->at_shutdown;
		$this->at_shutdown = array();

		foreach ( $ids as $id ) {
			// One failing e-mail never keeps the others of the request from being sent.
			try {
				$this->sender->run( $id );
			} catch ( Throwable $e ) {
				$this->logger->error(
					'A notification e-mail could not be sent at the end of the request.',
					array(
						'notification_id' => $id,
						'exception'       => $e::class,
					)
				);
			}
		}
	}

	/**
	 * Creates a notification.
	 *
	 * @param string               $key        Type key.
	 * @param int|null             $creator_id Creator.
	 * @param array<string, mixed> $data       Values.
	 * @param string               $dedupe     Dedupe key.
	 * @throws DatabaseException When it cannot be stored or read.
	 */
	private function create( string $key, ?int $creator_id, array $data, string $dedupe ): ?int {
		$type = $this->types->get( $key );

		if ( null === $type ) {
			$this->logger->warning( 'Unknown notification type.', array( 'type' => $key ) );

			return null;
		}

		$email     = $this->settings->enabled( $type, NotificationSettings::EMAIL );
		$dashboard = $this->settings->enabled( $type, NotificationSettings::DASHBOARD );
		$reachable = true;

		if ( NotificationType::CREATOR === $type->audience ) {
			$creator = null === $creator_id ? null : $this->creators->find( $creator_id );

			if ( null === $creator || $creator->is_deleted() ) {
				return null;
			}

			$reachable = null !== $creator->user_id;
			$email     = $email && ( ! $reachable || $this->preferences->wants_email( (int) $creator->user_id, $type ) );
		} else {
			// The administrator's e-mail names the creator it is about; the same event of two creators is two e-mails.
			if ( null !== $creator_id ) {
				$data['creator'] ??= $this->creators->find( $creator_id )->display_name ?? '';
				$dedupe            = '' === $dedupe ? '' : 'creator:' . $creator_id . ':' . $dedupe;
			}

			$creator_id = null;
		}

		if ( ! $email && ! $dashboard ) {
			return null;
		}

		$status = $email ? ( $reachable ? Notification::EMAIL_PENDING : Notification::EMAIL_SKIPPED ) : Notification::EMAIL_NONE;
		// Per type and recipient: the same event (a news message) can notify several creators once each.
		$id = $this->notifications->insert( $type->key, $type->audience, $creator_id, $data, '' === $dedupe ? '' : $type->key . ':' . ( $creator_id ?? 0 ) . ':' . $dedupe, $dashboard, $status );

		if ( null === $id ) {
			return null;
		}

		$this->notifications->prune();

		if ( Notification::EMAIL_PENDING === $status ) {
			$this->dispatch( $id );
		}

		return $id;
	}

	/**
	 * Queues the e-mail, or sends it at the end of the request without a queue.
	 *
	 * @param int $id Notification id.
	 */
	private function dispatch( int $id ): void {
		if ( $this->queue->available() && $this->queue->enqueue( NotificationSender::HOOK, array( $id ) ) ) {
			return;
		}

		if ( array() === $this->at_shutdown ) {
			add_action( 'shutdown', array( $this, 'send_pending' ) );
		}

		$this->at_shutdown[] = $id;
	}
}
