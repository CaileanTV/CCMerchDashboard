<?php
/**
 * Tests for the lazily resolved logger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Logging;

use MerchBoard\Core\Logging\LazyLogger;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( LazyLogger::class )]
final class LazyLoggerTest extends TestCase {

	public function test_resolves_the_shared_logger_once_on_first_use_and_forwards_every_level(): void {
		$inner    = new RecordingLogger();
		$resolved = 0;
		$logger   = new LazyLogger(
			static function () use ( $inner, &$resolved ): Logger {
				++$resolved;
				return $inner;
			}
		);

		self::assertSame( 0, $resolved, 'Nothing is resolved on construction.' );

		$logger->debug( 'd', array( 'a' => 1 ) );
		$logger->info( 'i' );
		$logger->warning( 'w' );
		$logger->error( 'e' );

		self::assertSame( 1, $resolved );
		self::assertSame( array( 'debug', 'info', 'warning', 'error' ), array_column( $inner->entries, 'level' ) );
		self::assertSame( array( 'a' => 1 ), $inner->entries[0]['context'] );
	}
}
