<?php
/**
 * Tests for the creators module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators;

use MerchBoard\Core\Container;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorsModule;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use wpdb;

#[CoversClass( CreatorsModule::class )]
final class CreatorsModuleTest extends TestCase {

	public function test_registers_the_repository_lazily(): void {
		$container = new Container();
		$container->set( wpdb::class, static fn(): wpdb => new RecordingWpdb() );

		( new CreatorsModule() )->register( $container );

		self::assertSame( 'creators', ( new CreatorsModule() )->id() );
		self::assertTrue( $container->has( CreatorRepository::class ) );
		self::assertInstanceOf( CreatorRepository::class, $container->get( CreatorRepository::class ) );
	}
}
