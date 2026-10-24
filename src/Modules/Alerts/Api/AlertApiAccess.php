<?php
/**
 * A creator's access to the alert API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Api;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a creator may use API keys and webhooks (step 25.12.4, ADR-073
 * pkt. 10). Off until the administrator gives access on the admin tab
 * Merch Dashboard → Alerts → API; the global switch (AlertApiSwitch) still
 * has to be on. Without access the creator's keys and webhooks are kept but
 * do not work. Migration 28 gave access to the creators that already had a
 * working key or webhook.
 *
 * Stored as creator meta `alert_api_access` (`{ keys: bool, webhooks: bool
 * }`); changed only with `merchboard_manage_alerts`, audited
 * (`alert_api_access_changed`) in the same transaction.
 */
class AlertApiAccess {

	/**
	 * Constructor.
	 *
	 * @param CreatorMetaStore  $meta        Creator meta.
	 * @param CreatorRepository $creators    Creators.
	 * @param AuditTrail        $audit       Audit log.
	 * @param Transaction       $transaction Transactions.
	 */
	public function __construct( private CreatorMetaStore $meta, private CreatorRepository $creators, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * A creator's access.
	 *
	 * @param int $creator_id Creator.
	 * @return array{keys: bool, webhooks: bool}
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function for_creator( int $creator_id ): array {
		$stored = $this->meta->get( $creator_id, CreatorMetaStore::ALERT_API_ACCESS );

		return array(
			'keys'     => is_array( $stored ) && true === ( $stored['keys'] ?? null ),
			'webhooks' => is_array( $stored ) && true === ( $stored['webhooks'] ?? null ),
		);
	}

	/**
	 * Whether a creator may use API keys.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function keys( int $creator_id ): bool {
		return $this->for_creator( $creator_id )['keys'];
	}

	/**
	 * Whether a creator may use webhooks.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function webhooks( int $creator_id ): bool {
		return $this->for_creator( $creator_id )['webhooks'];
	}

	/**
	 * Gives or takes a creator's access.
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $keys       API keys.
	 * @param bool $webhooks   Webhooks.
	 * @return bool Whether anything changed.
	 * @throws AlertApiException When not allowed or the creator does not exist.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function set( int $creator_id, bool $keys, bool $webhooks ): bool {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			self::refuse( AlertApiException::NOT_AUTHORIZED );
		}

		if ( null === $this->creators->find( $creator_id ) ) {
			self::refuse( AlertApiException::UNKNOWN_CREATOR );
		}

		$access = array(
			'keys'     => $keys,
			'webhooks' => $webhooks,
		);

		if ( $this->for_creator( $creator_id ) === $access ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $creator_id, $access ): void {
				$this->audit->record( AuditAction::AlertApiAccessChanged, $creator_id, $access );
				// No access at all removes the row (CreatorMetaStore deletes an empty value).
				$this->meta->set( $creator_id, CreatorMetaStore::ALERT_API_ACCESS, $access['keys'] || $access['webhooks'] ? $access : array() );
			}
		);

		return true;
	}

	/**
	 * Refuses a change.
	 *
	 * @param string $reason AlertApiException constant.
	 * @throws AlertApiException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new AlertApiException( $reason );
	}
}
