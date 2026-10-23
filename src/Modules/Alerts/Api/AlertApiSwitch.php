<?php
/**
 * The administrator's switch of the alert API.
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

defined( 'ABSPATH' ) || exit;

/**
 * Whether creators can make API keys and bots can fetch alerts (ADR-073):
 * off until the administrator switches it on (Merch Dashboard → Alerts →
 * API). Its own option, not autoloaded and read past WordPress' option
 * cache; a change is audited (`alert_api_switched`). Off stops every key at
 * once without revoking them.
 */
class AlertApiSwitch {

	/**
	 * The option ('1' when on; absent when off).
	 */
	public const OPTION = 'merchboard_alerts_api_enabled';

	/**
	 * Constructor.
	 *
	 * @param AuditTrail  $audit       Audit log.
	 * @param Transaction $transaction Database transaction.
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * Whether the API is on.
	 */
	public function enabled(): bool {
		return self::on();
	}

	/**
	 * Whether the API is on, without the service (the dashboard section).
	 */
	public static function on(): bool {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return '1' === get_option( self::OPTION, '' );
	}

	/**
	 * Switches the API on or off.
	 *
	 * @param bool $enabled On.
	 * @return bool False when nothing changed.
	 * @throws AlertApiException When the current user may not manage alerts.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set( bool $enabled ): bool {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			self::refuse();
		}

		if ( $this->enabled() === $enabled ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $enabled ): void {
				$this->audit->record( AuditAction::AlertApiSwitched, null, array( 'enabled' => $enabled ) );

				if ( $enabled ) {
					update_option( self::OPTION, '1', false );
				} else {
					delete_option( self::OPTION );
				}

				if ( $this->enabled() !== $enabled ) {
					throw new DatabaseException( 'Could not switch the alert API.' );
				}
			}
		);

		return true;
	}

	/**
	 * Refuses a change by someone who may not manage alerts.
	 *
	 * @throws AlertApiException Always.
	 */
	private static function refuse(): never {
		throw new AlertApiException( AlertApiException::NOT_AUTHORIZED );
	}
}
