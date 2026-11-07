<?php
/**
 * Tests for the front-page group (ADR-097).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Creators\Signup\FrontGroup;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( FrontGroup::class )]
final class FrontGroupTest extends TestCase {

	public function test_only_the_banner_plugins_two_values_are_valid(): void {
		self::assertSame( array( 'creator', 'community' ), FrontGroup::VALUES );
		self::assertSame( 'ccm_front_group', FrontGroup::TERM_META );
		self::assertTrue( FrontGroup::is_valid( 'creator' ) );
		self::assertTrue( FrontGroup::is_valid( 'community' ) );
		self::assertFalse( FrontGroup::is_valid( 'Creator' ) );
		self::assertFalse( FrontGroup::is_valid( '' ) );
		self::assertFalse( FrontGroup::is_valid( null ) );
		self::assertFalse( FrontGroup::is_valid( array( 'creator' ) ) );
	}

	public function test_anything_else_stored_means_content_creator(): void {
		self::assertSame( 'community', FrontGroup::or_default( 'community' ) );
		self::assertSame( 'creator', FrontGroup::or_default( null ) );
		self::assertSame( 'creator', FrontGroup::or_default( 'partner' ) );
	}

	public function test_the_labels(): void {
		Functions\when( '__' )->returnArg();

		self::assertSame( 'Content creator', FrontGroup::label( 'creator' ) );
		self::assertSame( 'Association & community', FrontGroup::label( 'community' ) );
	}
}
