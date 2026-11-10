<?php
/**
 * Invalid settings of the Twitch subscriber discount.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A code per invalid field (`required`, `invalid`, `too_many`,
 * `not_connected`).
 */
final class TwitchSubDiscountInvalid extends InvalidArgumentException {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $fields Field => code.
	 */
	public function __construct( private array $fields ) {
		parent::__construct( 'Invalid Twitch subscriber discount.' );
	}

	/**
	 * Field => code.
	 *
	 * @return array<string, string>
	 */
	public function fields(): array {
		return $this->fields;
	}
}
