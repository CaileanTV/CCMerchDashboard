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
 */
final class NotificationTypes {

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
		}

		return $this->types;
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
