<?php
/**
 * Why a giveaway cannot be created or changed.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Giveaways;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A refused giveaway action; the message is a stable code the REST API
 * returns (never a text with data in it).
 */
final class GiveawayException extends RuntimeException {

	/**
	 * Giveaway links are switched off for the shop (ADR-108 pattern).
	 */
	public const DISABLED = 'disabled';

	/**
	 * The creator has the most open giveaways allowed.
	 */
	public const LIMIT = 'limit';

	/**
	 * The product is not the creator's, or cannot be bought.
	 */
	public const PRODUCT = 'product';

	/**
	 * The available balance does not cover the giveaway.
	 */
	public const BALANCE = 'balance';

	/**
	 * No such giveaway of the creator.
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * The giveaway cannot be cancelled now (its gift is drawn, claimed or paid in the shop).
	 */
	public const NOT_CANCELLABLE = 'not_cancellable';

	/**
	 * The giveaway cannot be paid in the shop (it is not awaiting payment).
	 */
	public const NOT_PAYABLE = 'not_payable';
}
