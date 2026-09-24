<?php
/**
 * Tests for the PSR-4 autoloader.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core;

use MerchBoard\Core\Autoloader;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( Autoloader::class )]
final class AutoloaderTest extends TestCase {

	public function test_resolves_class_to_psr4_path(): void {
		self::assertSame(
			'/plugin/src/Modules/Creators/CreatorService.php',
			Autoloader::resolve( 'MerchBoard\Modules\Creators\CreatorService', 'MerchBoard\\', '/plugin/src/' )
		);
	}

	public function test_base_dir_without_trailing_slash_is_normalised(): void {
		self::assertSame(
			'/plugin/src/Plugin.php',
			Autoloader::resolve( 'MerchBoard\Plugin', 'MerchBoard\\', '/plugin/src' )
		);
	}

	public function test_ignores_classes_outside_the_prefix(): void {
		self::assertNull( Autoloader::resolve( 'Other\Plugin', 'MerchBoard\\', '/plugin/src/' ) );
		self::assertNull( Autoloader::resolve( 'MerchBoardOther\Plugin', 'MerchBoard\\', '/plugin/src/' ) );
	}

	/**
	 * Class names can come from untrusted input via class_exists().
	 *
	 * @return array<string, array{string}>
	 */
	public static function unsafe_class_names(): array {
		return array(
			'parent directory'   => array( 'MerchBoard\..\secret' ),
			'forward slash'      => array( 'MerchBoard\Foo/../../Bar' ),
			'dot segment'        => array( 'MerchBoard\Foo\.\Bar' ),
			'null byte'          => array( "MerchBoard\\Foo\0" ),
			'empty relative'     => array( 'MerchBoard\\' ),
			'double separator'   => array( 'MerchBoard\Foo\\\\Bar' ),
			'leading digit'      => array( 'MerchBoard\1Foo' ),
			'windows drive path' => array( 'MerchBoard\C:\Windows' ),
		);
	}

	#[DataProvider( 'unsafe_class_names' )]
	public function test_rejects_unsafe_class_names( string $class_name ): void {
		self::assertNull( Autoloader::resolve( $class_name, 'MerchBoard\\', '/plugin/src/' ) );
	}

	public function test_registered_autoloader_loads_class_from_base_dir(): void {
		$class_name = 'MerchBoard\Tests\AutoloadFixture\Sub\Loaded';

		self::assertFalse( class_exists( $class_name, false ) );

		Autoloader::register( 'MerchBoard\Tests\AutoloadFixture\\', dirname( __DIR__, 2 ) . '/Fixtures/Autoload' );

		self::assertTrue( class_exists( $class_name ) );
	}
}
