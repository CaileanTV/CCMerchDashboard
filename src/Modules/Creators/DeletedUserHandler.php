<?php
/**
 * Reacts to deleted WordPress users.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Listener for `deleted_user` (fires after WordPress removed the user, from
 * wp-admin, WP-CLI, the REST API or other plugins alike).
 *
 * The user is already gone when this runs, so a database failure must not
 * break the deleting request. It is logged instead; the creator then keeps a
 * link to a user id that no longer exists, which grants nothing (access needs
 * a user with the dashboard capability) and shows as "Unknown user" in admin.
 */
final class DeletedUserHandler {

	/**
	 * Constructor.
	 *
	 * @param CreatorService $creators Creator use cases.
	 * @param Logger         $logger   Technical log.
	 */
	public function __construct( private CreatorService $creators, private Logger $logger ) {
	}

	/**
	 * Releases the creator of the deleted user, if any.
	 *
	 * @param mixed $user_id Hook argument: id of the deleted user.
	 */
	public function handle( mixed $user_id ): void {
		$user_id = is_numeric( $user_id ) ? (int) $user_id : 0;

		try {
			$this->creators->release_deleted_user( $user_id );
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'Could not release the creator of a deleted WordPress user.',
				array_merge(
					$e->context(),
					array(
						'user_id' => $user_id,
						'reason'  => $e->getMessage(),
					)
				)
			);
		}
	}
}
