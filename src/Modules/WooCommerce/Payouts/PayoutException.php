<?php
/**
 * A payout operation that was refused.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * A refused payout operation with a stable reason code. Nothing was changed.
 * The message never contains payment details.
 */
final class PayoutException extends DomainException {

	public const NOT_AUTHORIZED = 'not_authorized';

	public const NOT_FOUND = 'not_found';

	public const INVALID_AMOUNT = 'invalid_amount';

	public const INVALID_CURRENCY = 'invalid_currency';

	public const INVALID_DETAILS = 'invalid_details';

	public const INVALID_KEY = 'invalid_idempotency_key';

	public const INVALID_REASON = 'invalid_reason';

	public const INVALID_REFERENCE = 'invalid_reference';

	/**
	 * The reference or reason contains payment details (an account number,
	 * IBAN or e-mail address; PayoutFreeText). The text is never echoed.
	 */
	public const PAYMENT_DETAILS_IN_TEXT = 'payment_details_in_text';

	public const INVALID_START = 'invalid_start';

	/**
	 * The settlement start cannot change: the creator has a settlement
	 * document (D3, ADR-060).
	 */
	public const START_LOCKED = 'start_locked';

	/**
	 * A settlement document cannot be corrected: it does not exist, or it is
	 * not the latest in its chain (D11, ADR-060).
	 */
	public const DOCUMENT_NOT_CORRECTABLE = 'document_not_correctable';

	public const INVALID_HOLDING_DAYS = 'invalid_holding_days';

	public const METHOD_UNAVAILABLE = 'method_unavailable';

	public const BALANCE_UNKNOWN = 'balance_unknown';

	public const INSUFFICIENT_BALANCE = 'insufficient_balance';

	public const TOO_MANY_PENDING = 'too_many_pending';

	public const TOO_MANY_REQUESTS = 'too_many_requests';

	public const NOT_PENDING = 'not_pending';

	public const DETAILS_UNREADABLE = 'details_unreadable';

	public const BUSY = 'busy';

	/**
	 * The operation does not fit the payout's method (a gift card on a bank
	 * payout, a manual transfer on a gift card payout).
	 */
	public const WRONG_METHOD = 'wrong_method';

	/**
	 * No gift card provider is selected, the selected one is unknown, or it is
	 * not available (ADR-057).
	 */
	public const GIFT_CARDS_UNAVAILABLE = 'gift_cards_unavailable';

	/**
	 * The chosen gift card provider is no registered adapter.
	 */
	public const INVALID_GIFT_CARD_PROVIDER = 'invalid_gift_card_provider';

	public const INVALID_GIFT_CARD_CODE = 'invalid_gift_card_code';

	public const GIFT_CARD_NOT_FOUND = 'gift_card_not_found';

	public const GIFT_CARD_AMBIGUOUS = 'gift_card_ambiguous';

	/**
	 * The provider's data of the card cannot be read exactly (never read as 0).
	 */
	public const GIFT_CARD_INVALID_DATA = 'gift_card_invalid_data';

	/**
	 * The card could not be read; nothing is known about it.
	 */
	public const GIFT_CARD_READ_FAILED = 'gift_card_read_failed';

	public const GIFT_CARD_NOT_ACTIVE = 'gift_card_not_active';

	public const GIFT_CARD_EXPIRED = 'gift_card_expired';

	public const GIFT_CARD_CURRENCY = 'gift_card_currency';

	/**
	 * The card's original amount is not the payout amount.
	 */
	public const GIFT_CARD_AMOUNT = 'gift_card_amount';

	/**
	 * The card's balance is not its original amount: it has been used.
	 */
	public const GIFT_CARD_USED = 'gift_card_used';

	/**
	 * The card is already registered on another payout.
	 */
	public const GIFT_CARD_LINKED = 'gift_card_linked';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Payout operation refused: ' . $reason );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
