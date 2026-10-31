<?php
/**
 * Creators never see which providers the company uses.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Dashboard;

use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The owner's rule of 2026-10-31: no provider is ever named outwardly. The
 * dashboard is what creators see, so its sources may not name one, in a text
 * or anywhere else. The administrator's pages may.
 */
#[CoversNothing]
final class NoProviderNamesTest extends TestCase {

	private const SOURCES = __DIR__ . '/../../../../assets/src/dashboard';

	public function test_the_dashboard_names_no_provider(): void {
		$found = array();
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::SOURCES, RecursiveDirectoryIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			$path = str_replace( '\\', '/', (string) $file );

			if ( ! str_contains( $path, '/test/' ) && 1 === preg_match( '/printful|gelato|prodigi/i', (string) file_get_contents( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A source file.
				$found[] = $path;
			}
		}

		self::assertSame( array(), $found );
	}
}
