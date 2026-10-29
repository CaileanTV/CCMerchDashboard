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
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulException;

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
 * administrator) and the creator's receipt. Step 30.10: approved and in the
 * shop, change approved, rejected with the note (to the creator) and "could
 * not be put in the shop" (to the administrator). Step 30.11: a new price
 * after a new base price and "taken out of the shop" (a retired base
 * product), to the creator. Step 30.12: "mockups could not be made"
 * (Printful), to the administrator. The data never hold files,
 * only the product's title, the version, the price and its currency (and
 * the note or the reason).
 */
final class DesignerNotifications {

	public const GROUP = 'designer';

	public const SUBMITTED = 'admin_designer_submitted';

	public const CHANGE_SUBMITTED = 'admin_designer_change_submitted';

	public const RECEIPT = 'designer_submitted';

	public const APPROVED = 'designer_approved';

	public const CHANGE_APPROVED = 'designer_change_approved';

	public const REJECTED = 'designer_rejected';

	public const APPLY_FAILED = 'admin_designer_apply_failed';

	public const PRICE_CHANGED = 'designer_price_changed';

	public const UNPUBLISHED = 'designer_unpublished';

	public const MOCKUPS_FAILED = 'admin_designer_mockups_failed';

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
			self::approved( false ),
			self::approved( true ),
			self::rejected(),
			self::price_changed(),
			self::unpublished(),
			self::submitted(),
			self::change_submitted(),
			self::mockups_failed(),
			self::apply_failed(),
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
	 * A version was approved and is in the shop (a new product or a change).
	 *
	 * @param bool $change Whether it changed a product in the shop.
	 */
	private static function approved( bool $change ): NotificationType {
		$label   = $change ? static fn(): string => __( 'Designer product change approved', 'merchboard' ) : static fn(): string => __( 'Designer product approved', 'merchboard' );
		$heading = $change ? __( 'Your change is in the shop', 'merchboard' ) : __( 'Your product is in the shop', 'merchboard' );

		return new NotificationType(
			$change ? self::CHANGE_APPROVED : self::APPROVED,
			NotificationType::CREATOR,
			self::GROUP,
			$label,
			$change
				? static fn(): string => __( 'When an administrator approves a change of the creator\'s designer product and it is in the shop.', 'merchboard' )
				: static fn(): string => __( 'When an administrator approves the creator\'s new designer product and it is in the shop.', 'merchboard' ),
			true,
			true,
			false,
			'designer',
			self::sample(),
			static fn( NotificationData $d ): string => $change
				/* translators: %s: product title. */
				? sprintf( __( 'Your change of %s was approved and is in the shop.', 'merchboard' ), $d->text( 'title' ) )
				/* translators: %s: product title. */
				: sprintf( __( '%s was approved and is in the shop.', 'merchboard' ), $d->text( 'title' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				$change
					/* translators: %s: product title. */
					? sprintf( __( 'Your change of %s is in the shop', 'merchboard' ), $d->text( 'title' ) )
					/* translators: %s: product title. */
					: sprintf( __( '%s is in the shop', 'merchboard' ), $d->text( 'title' ) ),
				$heading,
				array( $change ? __( 'We approved your change. The product in the shop shows it now.', 'merchboard' ) : __( 'We approved your design, and the product is now for sale in the shop.', 'merchboard' ) ),
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
	 * A version was rejected, with the administrator's note.
	 */
	private static function rejected(): NotificationType {
		return new NotificationType(
			self::REJECTED,
			NotificationType::CREATOR,
			self::GROUP,
			static fn(): string => __( 'Design rejected', 'merchboard' ),
			static fn(): string => __( 'When an administrator rejects a design the creator sent, with the note.', 'merchboard' ),
			true,
			true,
			false,
			'designer',
			array_merge( self::sample(), array( 'note' => 'Please use a picture with a higher resolution.' ) ),
			/* translators: 1: product title, 2: the administrator's note. */
			static fn( NotificationData $d ): string => sprintf( __( '%1$s was not approved: %2$s', 'merchboard' ), $d->text( 'title' ), $d->text( 'note' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: product title. */
				sprintf( __( '%s was not approved', 'merchboard' ), $d->text( 'title' ) ),
				__( 'Your design was not approved', 'merchboard' ),
				array( __( 'We could not approve your design as it is. Our note is below. You can change the design in the designer and send it again.', 'merchboard' ) ),
				array( array( __( 'Product', 'merchboard' ), $d->text( 'title' ) ) ),
				null,
				array( __( 'Note', 'merchboard' ), $d->text( 'note' ) ),
				__( 'Open the designer', 'merchboard' ),
				'',
				__( 'Thank you for creating with us.', 'merchboard' )
			)
		);
	}

	/**
	 * A product in the shop has a new price: the base price changed, the
	 * creator's percent or amount stays.
	 */
	private static function price_changed(): NotificationType {
		return new NotificationType(
			self::PRICE_CHANGED,
			NotificationType::CREATOR,
			self::GROUP,
			static fn(): string => __( 'New price of a designer product', 'merchboard' ),
			static fn(): string => __( 'When the base price of a designer product changes, so the customer\'s price in the shop changes too. The creator\'s percent or amount stays the same.', 'merchboard' ),
			true,
			true,
			false,
			'designer',
			array_merge( self::sample(), array( 'previous_price' => '229.00' ) ),
			/* translators: 1: product title, 2: new price, 3: previous price. */
			static fn( NotificationData $d ): string => sprintf( __( '%1$s now costs %2$s in the shop (before: %3$s), because its base price changed.', 'merchboard' ), $d->text( 'title' ), $d->money( 'price' ), $d->money( 'previous_price' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: product title. */
				sprintf( __( 'New price of %s', 'merchboard' ), $d->text( 'title' ) ),
				__( 'Your product has a new price', 'merchboard' ),
				array( __( 'The base price of your product changed, so its price in the shop changed too. Your profit setting (percent or amount) is the same. Orders placed before keep their price.', 'merchboard' ) ),
				array(
					array( __( 'Product', 'merchboard' ), $d->text( 'title' ) ),
					array( __( 'New customer price incl. VAT', 'merchboard' ), $d->money( 'price' ) ),
					array( __( 'Before', 'merchboard' ), $d->money( 'previous_price' ) ),
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
	 * A product was taken out of the shop because its base product is retired.
	 */
	private static function unpublished(): NotificationType {
		return new NotificationType(
			self::UNPUBLISHED,
			NotificationType::CREATOR,
			self::GROUP,
			static fn(): string => __( 'Designer product taken out of the shop', 'merchboard' ),
			static fn(): string => __( 'When a designer product leaves the shop because its base product is no longer offered.', 'merchboard' ),
			true,
			true,
			true,
			'designer',
			self::sample(),
			/* translators: %s: product title. */
			static fn( NotificationData $d ): string => sprintf( __( '%s was taken out of the shop: its base product is no longer offered.', 'merchboard' ), $d->text( 'title' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: product title. */
				sprintf( __( '%s was taken out of the shop', 'merchboard' ), $d->text( 'title' ) ),
				__( 'Your product was taken out of the shop', 'merchboard' ),
				array( __( 'We no longer offer the base product of your design, so the product was taken out of the shop. Your earnings from earlier orders are not affected. You are welcome to design on another product.', 'merchboard' ) ),
				array( array( __( 'Product', 'merchboard' ), $d->text( 'title' ) ) ),
				null,
				null,
				__( 'Open the designer', 'merchboard' ),
				'',
				__( 'Thank you for creating with us.', 'merchboard' )
			)
		);
	}

	/**
	 * An approved version could not be put in the shop.
	 */
	private static function apply_failed(): NotificationType {
		return new NotificationType(
			self::APPLY_FAILED,
			NotificationType::ADMIN,
			self::GROUP,
			static fn(): string => __( 'Designer product could not be put in the shop', 'merchboard' ),
			static fn(): string => __( 'When an approved designer product or change could not be put in the shop. It can be tried again under Designs.', 'merchboard' ),
			true,
			false,
			false,
			DesignsPage::SLUG,
			array_merge( self::sample(), array( 'reason' => 'prices_excl_tax' ) ),
			/* translators: 1: product title, 2: creator name. */
			static fn( NotificationData $d ): string => sprintf( __( '%1$s by %2$s could not be put in the shop.', 'merchboard' ), $d->text( 'title' ), $d->text( 'creator' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: product title. */
				sprintf( __( '%s could not be put in the shop', 'merchboard' ), $d->text( 'title' ) ),
				__( 'A designer product could not be put in the shop', 'merchboard' ),
				array( __( 'An approved design could not be put in the shop. Nothing in the shop changed for it. Fix the cause and choose "Try again" under Designs.', 'merchboard' ) ),
				array(
					array( __( 'Creator', 'merchboard' ), $d->text( 'creator' ) ),
					array( __( 'Product', 'merchboard' ), $d->text( 'title' ) ),
					array( __( 'Reason', 'merchboard' ), self::reason( $d->text( 'reason' ) ) ),
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

	/**
	 * Printful could not make the mockups of a submitted design.
	 */
	private static function mockups_failed(): NotificationType {
		return new NotificationType(
			self::MOCKUPS_FAILED,
			NotificationType::ADMIN,
			self::GROUP,
			static fn(): string => __( 'Mockups could not be made', 'merchboard' ),
			static fn(): string => __( 'When Printful could not make the mockups of a submitted design. The mockups the creator\'s browser made stay, and the design can still be approved.', 'merchboard' ),
			true,
			false,
			false,
			DesignsPage::SLUG,
			array_merge( self::sample(), array( 'reason' => PrintfulException::FAILED ) ),
			/* translators: 1: product title, 2: creator name. */
			static fn( NotificationData $d ): string => sprintf( __( 'Printful could not make the mockups of %1$s by %2$s.', 'merchboard' ), $d->text( 'title' ), $d->text( 'creator' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: product title. */
				sprintf( __( 'Mockups of %s could not be made', 'merchboard' ), $d->text( 'title' ) ),
				__( 'Printful could not make the mockups', 'merchboard' ),
				array( __( 'Printful could not make the mockups of a design waiting for approval. The mockups the creator\'s browser made are used instead; check them under Designs before you approve.', 'merchboard' ) ),
				array(
					array( __( 'Creator', 'merchboard' ), $d->text( 'creator' ) ),
					array( __( 'Product', 'merchboard' ), $d->text( 'title' ) ),
					array( __( 'Reason', 'merchboard' ), self::reason( $d->text( 'reason' ) ) ),
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

	/**
	 * Why an apply failed, in words.
	 *
	 * @param string $reason Code (a DesignerException reason or "error").
	 */
	public static function reason( string $reason ): string {
		return match ( $reason ) {
			DesignerException::PRICES_EXCL_TAX      => __( 'The shop enters prices excluding VAT (WooCommerce → Settings → Tax); designer prices include VAT.', 'merchboard' ),
			DesignerException::BASE_UNKNOWN         => __( 'The base product no longer exists.', 'merchboard' ),
			DesignerException::NOT_DESIGNER_PRODUCT => __( 'The product in the shop is no longer the designer product (changed or deleted in WooCommerce).', 'merchboard' ),
			'creator'                               => __( 'The creator is no longer active.', 'merchboard' ),
			'link'                                  => __( 'The product could not be linked to the creator.', 'merchboard' ),
			'reprice'                               => __( 'The new base price could not be written to the product; it keeps its earlier price.', 'merchboard' ),
			PrintfulException::NOT_CONFIGURED       => __( 'Printful is switched off or has no API token (Settings → Product designer).', 'merchboard' ),
			PrintfulException::UNAUTHORIZED         => __( 'Printful refused the API token. Enter a new one under Settings → Product designer.', 'merchboard' ),
			PrintfulException::NOT_FOUND            => __( 'Printful does not know the product.', 'merchboard' ),
			PrintfulException::INVALID              => __( 'Printful answered with something that cannot be used.', 'merchboard' ),
			PrintfulException::FAILED              => __( 'Printful could not be reached or answered with an error.', 'merchboard' ),
			'task'                                  => __( 'Printful could not make a mockup (or took too long).', 'merchboard' ),
			default                                 => __( 'A technical error; the log has the details.', 'merchboard' ),
		};
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
