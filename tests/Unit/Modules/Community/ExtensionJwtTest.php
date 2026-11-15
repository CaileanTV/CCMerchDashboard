<?php
/**
 * The Twitch Extension's JWT (ADR-112).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use MerchBoard\Modules\Community\TwitchExtension\ExtensionJwt;
use PHPUnit\Framework\TestCase;

/**
 * Only a token signed with the extension's key, not expired and with a
 * numeric channel gives the channel.
 */
final class ExtensionJwtTest extends TestCase {

	private const KEY = 'a-raw-key-of-more-than-16-bytes';

	private const NOW = 1_800_000_000;

	public function test_a_valid_token_gives_its_channel(): void {
		self::assertSame( '4711', ExtensionJwt::channel( self::token( array( 'channel_id' => '4711' ) ), self::KEY, self::NOW ) );
	}

	public function test_anything_else_gives_nothing(): void {
		$cases = array(
			'another key'        => array( self::token( array( 'channel_id' => '4711' ), 'another-raw-key-of-16-bytes!' ), self::KEY ),
			'expired'            => array( self::token( array( 'channel_id' => '4711', 'exp' => self::NOW ) ), self::KEY ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
			'no channel'         => array( self::token( array() ), self::KEY ),
			'channel not digits' => array( self::token( array( 'channel_id' => '47a1' ) ), self::KEY ),
			'channel as number'  => array( self::token( array( 'channel_id' => 4711 ) ), self::KEY ),
			'alg none'           => array( self::token( array( 'channel_id' => '4711' ), self::KEY, 'none' ), self::KEY ),
			'two parts'          => array( 'abc.def', self::KEY ),
			'not base64url'      => array( 'a+b.c/d.e=f', self::KEY ),
			'empty key'          => array( self::token( array( 'channel_id' => '4711' ) ), '' ),
			'too long'           => array( str_repeat( 'a', 5000 ), self::KEY ),
		);

		foreach ( $cases as $name => [ $token, $key ] ) {
			self::assertNull( ExtensionJwt::channel( $token, $key, self::NOW ), $name );
		}
	}

	public function test_a_changed_body_breaks_the_signature(): void {
		[ $head, , $signature ] = explode( '.', self::token( array( 'channel_id' => '4711' ) ) );
		$other                  = explode( '.', self::token( array( 'channel_id' => '9999' ) ) )[1];

		self::assertNull( ExtensionJwt::channel( $head . '.' . $other . '.' . $signature, self::KEY, self::NOW ) );
	}

	/**
	 * A signed token.
	 *
	 * @param array<string, mixed> $claims Claims (exp defaults to in an hour).
	 * @param string               $key    Key.
	 * @param string               $alg    Algorithm in the header.
	 */
	private static function token( array $claims, string $key = self::KEY, string $alg = 'HS256' ): string {
		$encode = static fn( string $value ): string => rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A JWT for the test.
		$head   = $encode( (string) json_encode( array( 'alg' => $alg, 'typ' => 'JWT' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Unit test without WordPress.
		$body   = $encode( (string) json_encode( $claims + array( 'exp' => self::NOW + 3600 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.

		return $head . '.' . $body . '.' . $encode( hash_hmac( 'sha256', $head . '.' . $body, $key, true ) );
	}
}
