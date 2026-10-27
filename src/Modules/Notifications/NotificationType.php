<?php
/**
 * One kind of notification in the catalog.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * A notification type (ADR-079): who gets it, where by default, whether the
 * creator may turn its e-mail off, its sample data and how its dashboard
 * text and e-mail are built from its data.
 *
 * Texts are built by closures, so translations are looked up when the
 * notification is shown or sent (in the recipient's language), never when
 * the catalog is built.
 */
final class NotificationType {

	public const CREATOR = 'creator';

	public const ADMIN = 'admin';

	/**
	 * Constructor.
	 *
	 * @param string               $key        Stable key (stored on notifications).
	 * @param string               $audience   CREATOR or ADMIN.
	 * @param string               $group      Group key (NotificationTypes::groups()).
	 * @param Closure              $label      Name on the admin page.
	 * @param Closure              $describe   One sentence: when it is sent.
	 * @param bool                 $email      E-mail on by default.
	 * @param bool                 $dashboard  At the creator's bell by default (always false for ADMIN).
	 * @param bool                 $important  The creator cannot turn the e-mail off.
	 * @param string               $link       Dashboard path (creator) or admin page slug (admin) the notification leads to.
	 * @param array<string, mixed> $sample     Sample data for the preview.
	 * @param Closure              $text       The dashboard line (plain text).
	 * @param Closure              $message    The e-mail.
	 *
	 * @phpstan-param Closure(): string $label
	 * @phpstan-param Closure(): string $describe
	 * @phpstan-param Closure(NotificationData): string $text
	 * @phpstan-param Closure(NotificationData): EmailMessage $message
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $audience,
		public readonly string $group,
		private Closure $label,
		private Closure $describe,
		public readonly bool $email,
		public readonly bool $dashboard,
		public readonly bool $important,
		public readonly string $link,
		public readonly array $sample,
		private Closure $text,
		private Closure $message
	) {
	}

	/**
	 * Whether the type can be shown at the creator's bell at all.
	 */
	public function has_dashboard(): bool {
		return self::CREATOR === $this->audience;
	}

	/**
	 * Name on the admin page.
	 */
	public function label(): string {
		return ( $this->label )();
	}

	/**
	 * When it is sent.
	 */
	public function description(): string {
		return ( $this->describe )();
	}

	/**
	 * The dashboard line for these data.
	 *
	 * @param NotificationData $data Data.
	 */
	public function text( NotificationData $data ): string {
		return ( $this->text )( $data );
	}

	/**
	 * The e-mail for these data.
	 *
	 * @param NotificationData $data Data.
	 */
	public function message( NotificationData $data ): EmailMessage {
		return ( $this->message )( $data );
	}
}
