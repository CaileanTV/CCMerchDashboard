<?php
/**
 * The creator's own choice of e-mails.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Which e-mails a creator has turned off (ADR-079 pkt. 8): the keys of
 * types that are not important, in the user meta META. Important types are
 * sent whatever is stored here.
 */
final class NotificationPreferences {

	public const META = 'merchboard_notification_email_off';

	/**
	 * The types whose e-mail the user turned off.
	 *
	 * @param int $user_id WordPress user.
	 * @return list<string>
	 */
	public function email_off( int $user_id ): array {
		$stored = $user_id > 0 ? get_user_meta( $user_id, self::META, true ) : array();

		return array_values( array_filter( is_array( $stored ) ? $stored : array(), static fn( mixed $key ): bool => is_string( $key ) && 1 === preg_match( '/^[a-z_]{1,64}$/', $key ) ) );
	}

	/**
	 * Whether the user wants this type's e-mail.
	 *
	 * @param int              $user_id WordPress user.
	 * @param NotificationType $type    Type.
	 */
	public function wants_email( int $user_id, NotificationType $type ): bool {
		return $type->important || ! in_array( $type->key, $this->email_off( $user_id ), true );
	}
}
