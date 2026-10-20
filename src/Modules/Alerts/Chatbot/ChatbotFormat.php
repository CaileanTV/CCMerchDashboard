<?php
/**
 * How chat messages are composed.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator's choice for every creator (ADR-066, status note
 * 2026-10-20): one chat message per order line (as MerchAlert) or one per
 * order. For one per order, `$Products` in the creator's template is the
 * order's lines, each shown with ITEM_FORMAT (alert variables of the line)
 * and joined with the separator. Stored in an option without autoload;
 * changes are audited.
 */
final class ChatbotFormat {

	/**
	 * Option (no autoload).
	 */
	public const OPTION = 'merchboard_alert_chatbot_format';

	public const MODES = array( 'line', 'order' );

	/**
	 * Default of how one line shows in `$Products`.
	 */
	public const DEFAULT_ITEM = '$Quantity× $Product';

	/**
	 * Default separator of `$Products`.
	 */
	public const DEFAULT_SEPARATOR = ', ';

	/**
	 * Longest item format and separator in characters.
	 */
	public const ITEM_MAX      = 100;
	public const SEPARATOR_MAX = 10;

	/**
	 * Constructor.
	 *
	 * @param AuditTrail  $audit       Audit trail.
	 * @param Transaction $transaction Database transaction.
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * The format.
	 *
	 * @return array{mode: string, item: string, separator: string}
	 */
	public function get(): array {
		$raw = get_option( self::OPTION, array() );

		return self::normalize( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Saves the format.
	 *
	 * @param string $mode      'line' or 'order'.
	 * @param string $item      How one line shows in `$Products`.
	 * @param string $separator Between the lines.
	 * @return bool Whether it changed.
	 * @throws TwitchException When not authorized.
	 * @throws DatabaseException When the audit record cannot be written (nothing is saved).
	 */
	public function save( string $mode, string $item, string $separator ): bool {
		self::authorize();

		$new = self::normalize(
			array(
				'mode'      => $mode,
				'item'      => $item,
				'separator' => $separator,
			)
		);

		if ( $new === $this->get() ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $new ): void {
				$this->audit->record( AuditAction::AlertChatbotFormatChanged, null, array( 'mode' => $new['mode'] ) );
			}
		);
		update_option( self::OPTION, $new, false );

		return true;
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws TwitchException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new TwitchException( TwitchException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Normalized values: an unknown mode is 'line', an empty item format the
	 * default; plain text, cut to the limits.
	 *
	 * @param array<mixed> $raw Raw values.
	 * @return array{mode: string, item: string, separator: string}
	 */
	private static function normalize( array $raw ): array {
		// The separator's tags are removed without wp_strip_all_tags(), which would trim its spaces.
		$mode      = $raw['mode'] ?? null;
		$item      = is_string( $raw['item'] ?? null ) ? trim( mb_substr( sanitize_text_field( $raw['item'] ), 0, self::ITEM_MAX ) ) : '';
		$separator = is_string( $raw['separator'] ?? null ) ? mb_substr( str_replace( array( "\r", "\n", "\t" ), ' ', (string) preg_replace( '/<[^>]*>?/', '', $raw['separator'] ) ), 0, self::SEPARATOR_MAX ) : self::DEFAULT_SEPARATOR;

		return array(
			'mode'      => in_array( $mode, self::MODES, true ) ? $mode : 'line',
			'item'      => '' === $item ? self::DEFAULT_ITEM : $item,
			'separator' => $separator,
		);
	}
}
