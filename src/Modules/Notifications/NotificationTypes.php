<?php
/**
 * The catalog of notification types.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

use MerchBoard\Modules\Notifications\Catalog\AdminTypes;
use MerchBoard\Modules\Notifications\Catalog\CreatorTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Every notification Merch Dashboard can send (ADR-079,
 * docs/features/notifications.md), built once per request when first needed.
 *
 * A module may add its own types through `merchboard_notification_types`
 * (a list of NotificationType), e.g. the product designer (ADR-080 pkt. 3).
 * Such a type must use a group of its own, never one of groups(): the
 * Notifications page shows and saves only the catalog's groups, and the
 * module's own admin page shows its types. A key that is already taken is
 * ignored. Sending, the bell, the queue and the creator's e-mail choices are
 * the same for every type.
 */
final class NotificationTypes {

	public const FILTER = 'merchboard_notification_types';

	/**
	 * The types by key.
	 *
	 * @var array<string, NotificationType>|null
	 */
	private ?array $types = null;

	/**
	 * All types, creator types first, in catalog order.
	 *
	 * @return array<string, NotificationType>
	 */
	public function all(): array {
		if ( null === $this->types ) {
			$this->types = array();

			foreach ( array_merge( CreatorTypes::all(), AdminTypes::all() ) as $type ) {
				$this->types[ $type->key ] = $type;
			}

			$added  = apply_filters( 'merchboard_notification_types', array() );
			$groups = self::groups();

			foreach ( is_array( $added ) ? $added : array() as $type ) {
				if ( $type instanceof NotificationType && ! isset( $this->types[ $type->key ] ) && ! isset( $groups[ $type->group ] ) ) {
					$this->types[ $type->key ] = $type;
				}
			}
		}

		return $this->types;
	}

	/**
	 * The types of some groups, in catalog order.
	 *
	 * @phpstan-param list<string> $groups
	 *
	 * @param string[] $groups Group keys.
	 * @return array<string, NotificationType>
	 */
	public function in_groups( array $groups ): array {
		return array_filter( $this->all(), static fn( NotificationType $type ): bool => in_array( $type->group, $groups, true ) );
	}

	/**
	 * A type, or null for an unknown key.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): ?NotificationType {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * The groups' names, in display order.
	 *
	 * @return array<string, string>
	 */
	public static function groups(): array {
		return array(
			'sales'   => __( 'Sales', 'merchboard' ),
			'payouts' => __( 'Payouts', 'merchboard' ),
			'profile' => __( 'Profile and category', 'merchboard' ),
			'code'    => __( 'Discount code', 'merchboard' ),
			'account' => __( 'Account and prices', 'merchboard' ),
			'news'    => __( 'News', 'merchboard' ),
			'alerts'  => __( 'Alerts', 'merchboard' ),
			'admin'   => __( 'To the administrator', 'merchboard' ),
		);
	}
}
