<?php
/**
 * Integration tests for side keys (30.15 review).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A side's key is always a valid key, with WordPress's own sanitize_title():
 * a size group in inches gave a key with "%" that every save refused.
 */
#[CoversClass( BaseView::class )]
final class BaseViewKeyTest extends IntegrationTestCase {

	public function test_keys_are_valid_also_for_inch_sizes(): void {
		$keys = array(
			'Front 16″×16″, 18″×18″' => 'front-16inx16in-18inx18in',
			'Print file 12"x18"'     => 'print-file-12inx18in',
			'Front'                  => 'front',
			'Bagside – grøn'         => 'bagside-gron',
		);

		foreach ( $keys as $name => $key ) {
			self::assertSame( $key, BaseView::key_of( $name ), $name );
			self::assertMatchesRegularExpression( '/^[a-z0-9][a-z0-9-]{0,31}$/', BaseView::key_of( $name ) );
		}

		self::assertSame( 32, strlen( BaseView::key_of( 'Print file 16″×32″, 12″×18″, 12″×22″' ) ) );
	}
}
