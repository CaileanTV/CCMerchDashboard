<?php
/**
 * Tests for the PSR-4 autoloader.
 *
 * @package CCMerch
 */

declare( strict_types=1 );

namespace CCMerch\Tests\Unit\Core;

use CCMerch\Core\Autoloader;
use CCMerch\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( Autoloader::class )]
final class AutoloaderTest extends TestCase {

	public function test_resolves_class_to_psr4_path(): void {
		self::assertSame(
			'/plugin/src/Modules/Creators/CreatorService.php',
			Autoloader::resolve( 'CCMerch\Modules\Creators\CreatorService', 'CCMerch\\', '/plugin/src/' )
		);
	}

	public function test_base_dir_without_trailing_slash_is_normalised(): void {
		self::assertSame(
			'/plugin/src/Plugin.php',
			Autoloader::resolve( 'CCMerch\Plugin', 'CCMerch\\', '/plugin/src' )
		);
	}

	public function test_ignores_classes_outside_the_prefix(): void {
		self::assertNull( Autoloader::resolve( 'Other\Plugin', 'CCMerch\\', '/plugin/src/' ) );
		self::assertNull( Autoloader::resolve( 'CCMerchOther\Plugin', 'CCMerch\\', '/plugin/src/' ) );
	}

	/**
	 * Class names can come from untrusted input via class_exists().
	 *
	 * @return array<string, array{string}>
	 */
	public static function unsafe_class_names(): array {
		return array(
			'parent directory'   => array( 'CCMerch\..\secret' ),
			'forward slash'      => array( 'CCMerch\Foo/../../Bar' ),
			'dot segment'        => array( 'CCMerch\Foo\.\Bar' ),
			'null byte'          => array( "CCMerch\\Foo\0" ),
			'empty relative'     => array( 'CCMerch\\' ),
			'double separator'   => array( 'CCMerch\Foo\\\\Bar' ),
			'leading digit'      => array( 'CCMerch\1Foo' ),
			'windows drive path' => array( 'CCMerch\C:\Windows' ),
		);
	}

	#[DataProvider( 'unsafe_class_names' )]
	public function test_rejects_unsafe_class_names( string $class_name ): void {
		self::assertNull( Autoloader::resolve( $class_name, 'CCMerch\\', '/plugin/src/' ) );
	}

	public function test_registered_autoloader_loads_class_from_base_dir(): void {
		$class_name = 'CCMerch\Tests\AutoloadFixture\Sub\Loaded';

		self::assertFalse( class_exists( $class_name, false ) );

		Autoloader::register( 'CCMerch\Tests\AutoloadFixture\\', dirname( __DIR__, 2 ) . '/Fixtures/Autoload' );

		self::assertTrue( class_exists( $class_name ) );
	}
}
