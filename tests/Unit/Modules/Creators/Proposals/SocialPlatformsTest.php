<?php
/**
 * Tests for the social platforms of a creator's category.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators\Proposals;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Creators\Proposals\ProposalField;
use MerchBoard\Modules\Creators\Proposals\SocialPlatforms;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The seven platforms of the banner plugin, each limited to its domains; a
 * user name becomes the profile URL (ADR-077 pkt. 6).
 */
#[CoversClass( SocialPlatforms::class )]
#[CoversClass( ProposalField::class )]
final class SocialPlatformsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.
	}

	public function test_the_platforms_are_the_banner_plugins_seven_without_website(): void {
		self::assertSame( array( 'instagram', 'tiktok', 'youtube', 'x', 'facebook', 'kick', 'twitch' ), SocialPlatforms::PLATFORMS );
		self::assertSame( SocialPlatforms::PLATFORMS, array_keys( SocialPlatforms::DOMAINS ) );

		foreach ( SocialPlatforms::PLATFORMS as $platform ) {
			self::assertSame( $platform, ProposalField::social( $platform )?->platform() );
		}

		self::assertNull( ProposalField::social( 'website' ) );
	}

	/**
	 * Accepted input and the stored link.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function accepted(): array {
		return array(
			'empty'             => array( 'instagram', '  ', '' ),
			'full URL'          => array( 'instagram', 'https://www.instagram.com/nova', 'https://www.instagram.com/nova' ),
			'handle with @'     => array( 'tiktok', '@nova.live', 'https://www.tiktok.com/@nova.live' ),
			'handle with a dot' => array( 'instagram', 'nova.doe', 'https://www.instagram.com/nova.doe' ),
			'address no scheme' => array( 'twitch', 'twitch.tv/nova', 'https://twitch.tv/nova' ),
			'subdomain'         => array( 'tiktok', 'https://vm.tiktok.com/ZM123/', 'https://vm.tiktok.com/ZM123/' ),
			'second domain'     => array( 'youtube', 'https://youtu.be/abc', 'https://youtu.be/abc' ),
			'twitter.com for X' => array( 'x', 'https://twitter.com/nova', 'https://twitter.com/nova' ),
			'mobile Facebook'   => array( 'facebook', 'https://m.facebook.com/nova', 'https://m.facebook.com/nova' ),
			'kick handle'       => array( 'kick', 'nova', 'https://kick.com/nova' ),
			'upper-case host'   => array( 'youtube', 'https://WWW.YouTube.com/@nova', 'https://WWW.YouTube.com/@nova' ),
		);
	}

	#[DataProvider( 'accepted' )]
	public function test_accepted_input_becomes_an_https_link_on_the_platform( string $platform, string $input, string $expected ): void {
		self::assertSame( $expected, SocialPlatforms::normalize( $platform, $input ) );
	}

	/**
	 * Refused input.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function refused(): array {
		return array(
			'website platform'  => array( 'website', 'https://nova.example' ),
			'other domain'      => array( 'instagram', 'https://nova.example/instagram.com' ),
			'look-alike domain' => array( 'instagram', 'https://instagram.com.evil.example/nova' ),
			'suffix look-alike' => array( 'twitch', 'https://nottwitch.tv/nova' ),
			'another platform'  => array( 'kick', 'https://www.twitch.tv/nova' ),
			'http'              => array( 'instagram', 'http://www.instagram.com/nova' ),
			'javascript'        => array( 'x', 'javascript:alert(1)' ),
			'credentials'       => array( 'x', 'https://user:pass@x.com/nova' ),
			'port'              => array( 'x', 'https://x.com:8443/nova' ),
			'markup'            => array( 'x', 'https://x.com/<script>' ),
			'spaces in handle'  => array( 'kick', 'no va' ),
			'too long'          => array( 'kick', 'https://kick.com/' . str_repeat( 'a', 200 ) ),
		);
	}

	#[DataProvider( 'refused' )]
	public function test_other_domains_and_unsafe_links_are_refused( string $platform, string $input ): void {
		self::assertNull( SocialPlatforms::normalize( $platform, $input ) );
	}
}
