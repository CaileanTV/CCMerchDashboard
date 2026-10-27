<?php
/**
 * One stored notification.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_notifications` (ADR-079). Times are UTC
 * `Y-m-d H:i:s` strings.
 */
final class Notification {

	public const EMAIL_NONE = 'none';

	public const EMAIL_PENDING = 'pending';

	public const EMAIL_SENT = 'sent';

	public const EMAIL_FAILED = 'failed';

	public const EMAIL_SKIPPED = 'skipped';

	/**
	 * Constructor.
	 *
	 * @param int                  $id             Id.
	 * @param string               $type           Type key.
	 * @param string               $audience       NotificationType::CREATOR or ADMIN.
	 * @param int|null             $creator_id     Creator (null for administrator notifications).
	 * @param array<string, mixed> $data           Values.
	 * @param bool                 $in_dashboard   Shown at the creator's bell.
	 * @param string               $created_at     Created (UTC).
	 * @param string|null          $read_at        Read at the bell (UTC), or null.
	 * @param string               $email_status   One of the EMAIL_ constants.
	 * @param int                  $email_attempts Sending attempts.
	 * @param string|null          $email_sent_at  Sent (UTC), or null.
	 * @param string|null          $email_error    Short reason of the last failure.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $audience,
		public readonly ?int $creator_id,
		public readonly array $data,
		public readonly bool $in_dashboard,
		public readonly string $created_at,
		public readonly ?string $read_at,
		public readonly string $email_status,
		public readonly int $email_attempts,
		public readonly ?string $email_sent_at,
		public readonly ?string $email_error
	) {
	}
}
