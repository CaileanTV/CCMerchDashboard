<?php
/**
 * Tests for the service container.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core;

use ArrayObject;
use MerchBoard\Core\Container;
use MerchBoard\Tests\Unit\TestCase;
use Countable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

#[CoversClass( Container::class )]
final class ContainerTest extends TestCase {

	public function test_service_is_created_lazily_and_only_once(): void {
		$container = new Container();
		$calls     = 0;

		$container->set(
			stdClass::class,
			static function () use ( &$calls ): stdClass {
				++$calls;
				return new stdClass();
			}
		);

		self::assertSame( 0, $calls );

		$first  = $container->get( stdClass::class );
		$second = $container->get( stdClass::class );

		self::assertSame( 1, $calls );
		self::assertSame( $first, $second );
	}

	public function test_factory_receives_the_container(): void {
		$container = new Container();
		$received  = null;

		$container->set(
			stdClass::class,
			static function ( Container $c ) use ( &$received ): stdClass {
				$received = $c;
				return new stdClass();
			}
		);
		$container->get( stdClass::class );

		self::assertSame( $container, $received );
	}

	public function test_interface_can_be_used_as_service_id(): void {
		$container = new Container();
		$container->set( Countable::class, static fn(): ArrayObject => new ArrayObject() );

		self::assertInstanceOf( ArrayObject::class, $container->get( Countable::class ) );
	}

	public function test_has_reports_registered_services(): void {
		$container = new Container();
		$container->set( stdClass::class, static fn(): stdClass => new stdClass() );

		self::assertTrue( $container->has( stdClass::class ) );
		self::assertFalse( $container->has( ArrayObject::class ) );
	}

	public function test_unknown_service_throws(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'is not registered' );

		( new Container() )->get( stdClass::class );
	}

	public function test_factory_returning_wrong_type_throws(): void {
		$container = new Container();
		$container->set( ArrayObject::class, static fn(): stdClass => new stdClass() );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'returned an instance of "stdClass"' );

		$container->get( ArrayObject::class );
	}

	public function test_circular_dependency_throws(): void {
		$container = new Container();
		$container->set( stdClass::class, static fn( Container $c ): stdClass => $c->get( stdClass::class ) );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'Circular dependency' );

		$container->get( stdClass::class );
	}

	public function test_replacing_a_created_service_throws(): void {
		$container = new Container();
		$container->set( stdClass::class, static fn(): stdClass => new stdClass() );
		$container->get( stdClass::class );

		$this->expectException( LogicException::class );

		$container->set( stdClass::class, static fn(): stdClass => new stdClass() );
	}

	public function test_replacing_a_service_before_creation_is_allowed(): void {
		$container   = new Container();
		$replacement = new stdClass();
		$container->set( stdClass::class, static fn(): stdClass => new stdClass() );
		$container->set( stdClass::class, static fn(): stdClass => $replacement );

		self::assertSame( $replacement, $container->get( stdClass::class ) );
	}
}
