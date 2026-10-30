<?php
/**
 * Background jobs run without wp-admin's includes (found on the devpage 2026-10-30).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class BackgroundJobFunctionsTest extends TestCase {

	public function test_only_designer_files_calls_the_admin_function_for_temporary_files(): void {
		$root  = dirname( __DIR__, 4 ) . '/src/Modules/ProductDesigner';
		$calls = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( str_contains( (string) file_get_contents( (string) $file ), 'wp_tempnam(' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A source file.
				$calls[] = basename( (string) $file );
			}
		}

		self::assertSame( array( 'DesignerFiles.php' ), $calls, 'wp_tempnam() lives in wp-admin: DesignerFiles::temporary_path() loads it.' );
	}
}
