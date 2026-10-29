<?php
/**
 * The product designer's notifications.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner;

use Closure;
use MerchBoard\Modules\Notifications\EmailMessage;
use MerchBoard\Modules\Notifications\NotificationData;
use MerchBoard\Modules\Notifications\NotificationType;
use MerchBoard\Modules\Notifications\NotificationTypes;
use MerchBoard\Modules\ProductDesigner\Admin\DesignsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The designer's notification types (ADR-080 pkt. 3, the owner's choice
 * 2026-10-28): defined here, added to ADR-079's catalog through
 * `merchboard_notification_types` in the group `designer`, and switched on
 * and off on Merch Dashboard → Designs (tab Notifications), not on the
 * Notifications page. Sending, the bell, the queue and the creator's e-mail
 * choices are phase 29's.
 *
 * Step 30.9: a new product or a change waiting for approval (to the
 * administrator) and the creator's receipt. The data never hold files, only
 * the product's title, the version, the price and its currency.
 */
final class DesignerNotifications {

	public const GROUP = 'designer';

	public const SUBMITTED = 'admin_designer_submitted';

	public const CHANGE_SUBMITTED = 'admin_designer_change_submitted';

	public const RECEIPT = 'designer_submitted';

	/**
	 * Adds the types to the catalog.
	 */
	public static function register(): void {
		add_filter(
			NotificationTypes::FILTER,
			static fn( mixed $types ): array => array_merge( is_array( $types ) ? $types : array(), self::all() )
		);
	}

	/**
	 * The designer's types: the creator's first.
	 *
	 * @return list<NotificationType>
	 */
	public static function all(): array {
		return array(
			self::receipt(),
			self::submitted(),
			self::change_submitted(),
		);
	}

	/**
	 * The sample data of every type.
	 *
	 * @return array<string, string>
	 */
	private static function sample(): array {
		return array(
			'creator'  => 'Nova',
			'title'    => 'Stream tee',
			'number'   => '1',
			'price'    => '249.00',
			'currency' => 'DKK',
		);
	}

	/**
	 * The creator's receipt for a submission.
	 */
	private static function receipt(): NotificationType {
		return new NotificationType(
			self::RECEIPT,
			NotificationType::CREATOR,
			self::GROUP,
			static fn(): string => __( 'Design sent for approval', 'merchboard' ),
			static fn(): string => __( 'When the creator sends a new product or a change from the designer for approval (a receipt).', 'merchboard' ),
			true,
			true,
			false,
			'designer',
			self::sample(),
			/* translators: %s: product title. */
			static fn( NotificationData $d ): string => sprintf( __( '%s was sent for approval. We will let you know when it has been reviewed.', 'merchboard' ), $d->text( 'title' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: product title. */
				sprintf( __( '%s was sent for approval', 'merchboard' ), $d->text( 'title' ) ),
				__( 'Your design was sent for approval', 'merchboard' ),
				array( __( 'Thank you! We review your design and let you know when it is approved. Until then, nothing changes in the shop. You can withdraw it in the designer while it waits.', 'merchboard' ) ),
				array(
					array( __( 'Product', 'merchboard' ), $d->text( 'title' ) ),
					array( __( 'Customer price incl. VAT', 'merchboard' ), $d->money( 'price' ) ),
				),
				null,
				null,
				__( 'Open the designer', 'merchboard' ),
				'',
				__( 'Thank you for creating with us.', 'merchboard' )
			)
		);
	}

	/**
	 * A new designer product waits for approval.
	 */
	private static function submitted(): NotificationType {
		return self::admin_type(
			self::SUBMITTED,
			static fn(): string => __( 'New designer product to approve', 'merchboard' ),
			static fn(): string => __( 'When a creator sends a new designer product for approval.', 'merchboard' ),
			/* translators: 1: creator name, 2: product title. */
			static fn( NotificationData $d ): string => sprintf( __( '%1$s sent the new product %2$s for approval.', 'merchboard' ), $d->text( 'creator' ), $d->text( 'title' ) ),
			/* translators: %s: creator name. */
			static fn( NotificationData $d ): string => sprintf( __( 'New designer product from %s to approve', 'merchboard' ), $d->text( 'creator' ) ),
			static fn(): string => __( 'A creator designed a new product. It is not in the shop until you approve it.', 'merchboard' )
		);
	}

	/**
	 * A change of a designer product in the shop waits for approval.
	 */
	private static function change_submitted(): NotificationType {
		return self::admin_type(
			self::CHANGE_SUBMITTED,
			static fn(): string => __( 'Designer product change to approve', 'merchboard' ),
			static fn(): string => __( 'When a creator sends a change of a designer product in the shop for approval. The shop keeps the approved version until you approve the change.', 'merchboard' ),
			/* translators: 1: creator name, 2: product title. */
			static fn( NotificationData $d ): string => sprintf( __( '%1$s sent a change of %2$s for approval.', 'merchboard' ), $d->text( 'creator' ), $d->text( 'title' ) ),
			/* translators: %s: creator name. */
			static fn( NotificationData $d ): string => sprintf( __( 'Designer product change from %s to approve', 'merchboard' ), $d->text( 'creator' ) ),
			static fn(): string => __( 'A creator changed a designer product in the shop. The shop keeps the approved version until you approve the change.', 'merchboard' )
		);
	}

	/**
	 * An administrator type: e-mail only, a plain message with the facts and
	 * a button to Designs.
	 *
	 * @param string  $key       Key.
	 * @param Closure $label     Admin name (also the e-mail's heading).
	 * @param Closure $describe  When it is sent.
	 * @param Closure $text      One-line summary.
	 * @param Closure $subject   E-mail subject.
	 * @param Closure $paragraph E-mail text.
	 *
	 * @phpstan-param Closure(): string $label
	 * @phpstan-param Closure(): string $describe
	 * @phpstan-param Closure(NotificationData): string $text
	 * @phpstan-param Closure(NotificationData): string $subject
	 * @phpstan-param Closure(): string $paragraph
	 */
	private static function admin_type( string $key, Closure $label, Closure $describe, Closure $text, Closure $subject, Closure $paragraph ): NotificationType {
		return new NotificationType(
			$key,
			NotificationType::ADMIN,
			self::GROUP,
			$label,
			$describe,
			true,
			false,
			false,
			DesignsPage::SLUG,
			self::sample(),
			$text,
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				$subject( $d ),
				$label(),
				array( $paragraph() ),
				array_values(
					array_filter(
						array(
							array( __( 'Creator', 'merchboard' ), $d->text( 'creator' ) ),
							array( __( 'Product', 'merchboard' ), $d->text( 'title' ) ),
							array( __( 'Customer price incl. VAT', 'merchboard' ), $d->money( 'price' ) ),
						),
						static fn( array $line ): bool => '' !== $line[1]
					)
				),
				null,
				null,
				__( 'Open Designs', 'merchboard' ),
				'',
				__( 'This message was sent by Merch Dashboard.', 'merchboard' ),
				false
			)
		);
	}
}
