<?php
/**
 * Tests for the daily previews per creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\ProductDesigner\Providers\PreviewQuota;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Security review 2026-11-21 (M3): a creator starts at most 100 previews a
 * day (each a task at a provider); the next Copenhagen day starts again.
 */
#[CoversClass( PreviewQuota::class )]
final class PreviewQuotaTest extends TestCase {

	private string $now = '2026-11-21 12:00:00';

	protected function setUp(): void {
		parent::setUp();
		InMemoryOptions::install();
	}

	public function test_at_most_the_day_s_previews_per_creator(): void {
		$quota = new PreviewQuota( null, fn(): DateTimeImmutable => new DateTimeImmutable( $this->now, new DateTimeZone( 'UTC' ) ) );

		for ( $i = 0; $i < PreviewQuota::PER_DAY; $i++ ) {
			self::assertTrue( $quota->take( 7 ) );
		}

		self::assertFalse( $quota->take( 7 ) );
		self::assertTrue( $quota->take( 8 ), 'Each creator has their own.' );

		// 23:30 UTC is the next day in Copenhagen.
		$this->now = '2026-11-21 23:30:00';
		self::assertTrue( $quota->take( 7 ) );
	}
}
