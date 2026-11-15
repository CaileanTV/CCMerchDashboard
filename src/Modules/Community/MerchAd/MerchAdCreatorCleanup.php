<?php
/**
 * Removes a deleted creator's merch ad images.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchAd;

use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's background-free images go with the creator (ADR-113); the
 * creator meta is removed with the creator's other meta.
 */
final class MerchAdCreatorCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param MerchAdFiles $files The images.
	 */
	public function __construct( private MerchAdFiles $files ) {
	}

	/**
	 * The cleaner's key.
	 */
	public function key(): string {
		return 'merch_ad';
	}

	/**
	 * Nothing blocks the deletion.
	 *
	 * @param Creator $creator Creator.
	 */
	public function check( Creator $creator ): void {
	}

	/**
	 * Deletes the creator's images.
	 *
	 * @param Creator $creator Creator.
	 * @return array<string, int>
	 */
	public function remove( Creator $creator ): array {
		$this->files->delete_all( $creator->id );

		return array( 'merch_ad_images' => 1 );
	}
}
