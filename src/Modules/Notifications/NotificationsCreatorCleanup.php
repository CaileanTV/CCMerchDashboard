<?php
/**
 * Deleting a creator: their notifications.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;

defined( 'ABSPATH' ) || exit;

/**
 * Removes a deleted creator's notifications (ADR-076, ADR-079 pkt. 3).
 */
class NotificationsCreatorCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param NotificationRepository $notifications Notifications.
	 */
	public function __construct( private NotificationRepository $notifications ) {
	}

	/**
	 * Key in the deletion's report.
	 */
	public function key(): string {
		return 'notifications';
	}

	/**
	 * Nothing blocks the deletion.
	 *
	 * @param Creator $creator Creator.
	 */
	public function check( Creator $creator ): void {
	}

	/**
	 * Deletes the creator's notifications.
	 *
	 * @param Creator $creator Creator.
	 * @return array<string, int>
	 */
	public function remove( Creator $creator ): array {
		return array( 'notifications' => $this->notifications->delete_for_creator( $creator->id ) );
	}
}
