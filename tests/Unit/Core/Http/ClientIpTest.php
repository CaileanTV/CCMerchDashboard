<?php
/**
 * Tests for the visitor's address behind Cloudflare.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Http;

use Brain\Monkey\Filters;
use MerchBoard\Core\Http\ClientIp;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Security review 2026-11-21 (L5): every rate limit counts the visitor, not
 * Cloudflare, and a request straight to the origin cannot pretend to be
 * someone else.
 */
#[CoversClass( ClientIp::class )]
final class ClientIpTest extends TestCase {

	public function test_cloudflare_s_header_counts_only_from_cloudflare(): void {
		self::assertSame( '203.0.113.7', ClientIp::address( array( 'REMOTE_ADDR' => '104.16.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7' ) ), 'From Cloudflare (IPv4).' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( '2001:db8::5', ClientIp::address( array( 'REMOTE_ADDR' => '2606:4700:10::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::5' ) ), 'From Cloudflare (IPv6).' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( '198.51.100.9', ClientIp::address( array( 'REMOTE_ADDR' => '198.51.100.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7' ) ), 'Straight to the origin: the header is forged.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( '104.16.1.2', ClientIp::address( array( 'REMOTE_ADDR' => '104.16.1.2', 'HTTP_CF_CONNECTING_IP' => 'not an address' ) ), 'An invalid header is not used.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( '198.51.100.9', ClientIp::address( array( 'REMOTE_ADDR' => '198.51.100.9' ) ), 'nginx real_ip already gave the visitor.' );
		self::assertSame( 'unknown', ClientIp::address( array( 'REMOTE_ADDR' => '<script>' ) ) );
		self::assertSame( 'unknown', ClientIp::address( array() ) );
	}

	public function test_ranges_match_by_their_prefix(): void {
		self::assertTrue( ClientIp::in_range( '104.23.255.255', '104.16.0.0/13' ) );
		self::assertFalse( ClientIp::in_range( '104.24.0.0', '104.16.0.0/13' ) );
		self::assertTrue( ClientIp::in_range( '2a06:98c7:ffff::1', '2a06:98c0::/29' ) );
		self::assertFalse( ClientIp::in_range( '2a06:98c8::1', '2a06:98c0::/29' ) );
		self::assertFalse( ClientIp::in_range( '104.16.0.1', '2606:4700::/32' ), 'IPv4 never matches an IPv6 range.' );
		self::assertFalse( ClientIp::in_range( '104.16.0.1', 'nonsense' ) );
		self::assertFalse( ClientIp::in_range( '104.16.0.1', '104.16.0.0/33' ) );
	}

	public function test_the_trusted_ranges_can_be_changed_by_a_filter(): void {
		Filters\expectApplied( ClientIp::FILTER )->andReturn( array( '10.0.0.0/8' ) );

		self::assertSame( '203.0.113.7', ClientIp::address( array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
	}
}
