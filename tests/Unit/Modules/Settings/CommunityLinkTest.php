<?php
/**
 * The community links' parameter name.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Settings;

use Brain\Monkey\Functions;
use MerchBoard\Core\Settings\CommunityLink;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CommunityLink::class )]
final class CommunityLinkTest extends TestCase {

	private InMemoryOptions $options;

	protected function setUp(): void {
		parent::setUp();
		$this->options = InMemoryOptions::install();
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'sanitize_key' )->alias( static fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
	}

	public function test_only_safe_names_are_valid(): void {
		foreach ( array( 'gave', 'gift', 'gave-link', 'cc_gave', 'g2' ) as $name ) {
			self::assertTrue( CommunityLink::valid( $name ), $name );
		}

		foreach ( array( '', 'g', 'Gave', '2gave', 'gave link', 'gave/x', 'p', 's', 'page', 'add-to-cart', 'merchboard_gift_redeem', str_repeat( 'a', 41 ) ) as $name ) {
			self::assertFalse( CommunityLink::valid( $name ), $name );
		}
	}

	public function test_nothing_changes_until_a_name_is_chosen(): void {
		self::assertSame( 'merchboard_gift_claim', CommunityLink::name() );
		self::assertSame( 'https://shop.test/?merchboard_gift_claim=abc', CommunityLink::url( 'abc' ) );
		self::assertSame( 'abc', CommunityLink::token( array( 'merchboard_gift_claim' => 'abc' ) ) );
	}

	public function test_links_use_the_chosen_name_and_old_links_keep_working(): void {
		$this->options->options[ Settings::OPTION ] = array(
			Settings::COMMUNITY_LINK_PARAM    => 'gave',
			Settings::COMMUNITY_LINK_PREVIOUS => array( 'gift' ),
		);

		self::assertSame( 'https://shop.test/?gave=abc&merchboard_gift_redeem=1', CommunityLink::url( 'abc', array( 'merchboard_gift_redeem' => '1' ) ) );
		self::assertSame( 'abc', CommunityLink::token( array( 'gave' => 'abc' ) ) );
		self::assertSame( 'def', CommunityLink::token( array( 'gift' => 'def' ) ), 'An earlier name.' );
		self::assertSame( 'ghi', CommunityLink::token( array( 'merchboard_gift_claim' => 'ghi' ) ), 'The original name.' );
		self::assertSame( '', CommunityLink::token( array( 'other' => 'abc' ) ) );
	}

	public function test_the_earlier_names_keep_the_old_one_first_and_are_limited(): void {
		self::assertSame( array( 'gave' ), CommunityLink::previous_after( array(), 'gave', 'gift' ) );
		self::assertSame( array(), CommunityLink::previous_after( array(), 'merchboard_gift_claim', 'gave' ), 'The original name is always read.' );
		self::assertSame( array( 'gift' ), CommunityLink::previous_after( array( 'gave' ), 'gift', 'gave' ), 'Back to an earlier name: no longer an earlier one.' );

		$many = array_map( static fn( int $i ): string => 'name' . $i, range( 1, 12 ) );
		self::assertCount( CommunityLink::MAX_PREVIOUS, CommunityLink::previous_after( $many, 'old', 'new' ) );
		self::assertSame( 'old', CommunityLink::previous_after( $many, 'old', 'new' )[0] );
	}
}
