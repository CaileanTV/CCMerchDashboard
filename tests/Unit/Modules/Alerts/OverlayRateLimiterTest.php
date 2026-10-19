<?php
/**
 * Tests for the overlay feed's request limit.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use MerchBoard\Modules\Alerts\Overlay\OverlayRateLimiter;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( OverlayRateLimiter::class )]
final class OverlayRateLimiterTest extends TestCase {

	private InMemoryOptions $options;

	private int $now = 1_000_000;

	protected function setUp(): void {
		parent::setUp();
		$this->options = InMemoryOptions::install();
	}

	public function test_at_most_the_limit_per_minute_and_a_new_window_after_it(): void {
		$limiter = new OverlayRateLimiter( fn(): int => $this->now );
		$id      = str_repeat( 'a', 32 );

		for ( $i = 0; $i < OverlayRateLimiter::LIMIT; $i++ ) {
			self::assertTrue( $limiter->allow( $id ) );
		}

		self::assertFalse( $limiter->allow( $id ) );
		self::assertTrue( $limiter->allow( str_repeat( 'b', 32 ) ), 'Each overlay has its own counter.' );

		$this->now += OverlayRateLimiter::WINDOW;
		self::assertTrue( $limiter->allow( $id ) );
	}

	public function test_the_secret_id_is_never_part_of_a_transient_name(): void {
		$id = str_repeat( 'c', 32 );
		( new OverlayRateLimiter( fn(): int => $this->now ) )->allow( $id );

		foreach ( array_keys( $this->options->transients ) as $name ) {
			self::assertStringNotContainsString( $id, (string) $name );
		}

		self::assertNotSame( array(), $this->options->transients );
	}
}
