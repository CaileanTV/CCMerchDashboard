<?php
/**
 * The administrator's choice per notification type and channel.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Which notifications are sent (ADR-079 pkt. 8).
 *
 * The option holds only the administrator's choices: `{ key: { email: bool,
 * dashboard: bool } }`. A missing choice is the type's default. The
 * dashboard channel exists only for creator types.
 */
final class NotificationSettings {

	public const OPTION = 'merchboard_notifications';

	public const EMAIL = 'email';

	public const DASHBOARD = 'dashboard';

	/**
	 * Constructor.
	 *
	 * @param NotificationTypes $types       Catalog.
	 * @param AuditTrail        $audit       Audit log.
	 * @param Transaction       $transaction Transactions.
	 */
	public function __construct( private NotificationTypes $types, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * Whether a type is sent on a channel.
	 *
	 * @param NotificationType $type    Type.
	 * @param string           $channel EMAIL or DASHBOARD.
	 */
	public function enabled( NotificationType $type, string $channel ): bool {
		if ( self::DASHBOARD === $channel && ! $type->has_dashboard() ) {
			return false;
		}

		$stored = self::stored()[ $type->key ][ $channel ] ?? null;

		return is_bool( $stored ) ? $stored : ( self::EMAIL === $channel ? $type->email : $type->dashboard );
	}

	/**
	 * Saves the administrator's choices for the types of a form; one audit
	 * record per change, nothing when nothing changed. The choices of other
	 * types are kept. A DatabaseException from the transaction means nothing
	 * was kept.
	 *
	 * @phpstan-param list<string>|null $groups
	 *
	 * @param array<mixed>  $choices `{ key: { email: bool, dashboard: bool } }`; a missing channel is off.
	 * @param string[]|null $groups  The groups the form shows; null: the catalog's (the Notifications page).
	 * @return int Number of changed choices.
	 * @throws NotificationsException When the current user may not.
	 */
	public function save( array $choices, ?array $groups = null ): int {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			throw new NotificationsException( NotificationsException::NOT_AUTHORIZED );
		}

		$next    = self::stored();
		$changes = array();

		foreach ( $this->types->in_groups( $groups ?? array_keys( NotificationTypes::groups() ) ) as $type ) {
			$chosen = is_array( $choices[ $type->key ] ?? null ) ? $choices[ $type->key ] : array();

			foreach ( array( self::EMAIL, self::DASHBOARD ) as $channel ) {
				if ( self::DASHBOARD === $channel && ! $type->has_dashboard() ) {
					continue;
				}

				$value = true === ( $chosen[ $channel ] ?? false ) || '1' === ( $chosen[ $channel ] ?? '' );

				$next[ $type->key ][ $channel ] = $value;

				if ( $this->enabled( $type, $channel ) !== $value ) {
					$changes[ $type->key . '.' . $channel ] = $value;
				}
			}
		}

		if ( array() === $changes ) {
			return 0;
		}

		$this->transaction->run(
			function () use ( $next, $changes ): void {
				foreach ( $changes as $setting => $value ) {
					$this->audit->record(
						AuditAction::SettingsUpdated,
						null,
						array(
							'setting'        => 'notifications.' . $setting,
							'previous_value' => ! $value,
							'value'          => $value,
						)
					);
				}

				update_option( self::OPTION, $next, false );
			}
		);

		return count( $changes );
	}

	/**
	 * The stored choices.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$result = array();

		foreach ( is_array( $stored ) ? $stored : array() as $key => $channels ) {
			if ( is_string( $key ) && is_array( $channels ) ) {
				$result[ $key ] = $channels;
			}
		}

		return $result;
	}
}
