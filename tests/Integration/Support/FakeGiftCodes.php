<?php
/**
 * Gift codes without WooCommerce.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\Community\Gifting\Gift;
use MerchBoard\Modules\Community\Gifting\GiftCodes;
use MerchBoard\Modules\Community\Gifting\GiftRepository;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * Gives a fake coupon per gift (WooCommerce is not loaded in the tests).
 */
final class FakeGiftCodes extends GiftCodes {

	/**
	 * Codes created.
	 *
	 * @var int
	 */
	public int $created = 0;

	/**
	 * Constructor.
	 *
	 * @param GiftRepository $gifts Gifts.
	 */
	public function __construct( GiftRepository $gifts ) {
		parent::__construct( $gifts, new RecordingLogger() );
	}

	/**
	 * A fake code.
	 *
	 * @param Gift $gift The gift.
	 * @return array{id: int, code: string}
	 */
	public function create( Gift $gift ): array {
		++$this->created;

		return array(
			'id'   => 70000 + $gift->id,
			'code' => 'gave-test' . $gift->id,
		);
	}
}
