<?php
/**
 * Tests for the creator entity and status.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( Creator::class )]
#[CoversClass( CreatorStatus::class )]
final class CreatorTest extends TestCase {

	public function test_status_values_are_exactly_active_and_inactive(): void {
		self::assertSame(
			array( 'active', 'inactive' ),
			array_map( static fn( CreatorStatus $s ): string => $s->value, CreatorStatus::cases() )
		);
	}

	/**
	 * Values that must not be accepted as a status (no magic numbers, no casing variants).
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_statuses(): array {
		return array(
			'numeric'     => array( '1' ),
			'zero'        => array( '0' ),
			'capitalised' => array( 'Active' ),
			'empty'       => array( '' ),
			'unknown'     => array( 'archived' ),
		);
	}

	#[DataProvider( 'invalid_statuses' )]
	public function test_rejects_undefined_status_values( string $value ): void {
		self::assertNull( CreatorStatus::tryFrom( $value ) );
	}

	public function test_creator_without_linked_user_is_valid(): void {
		$creator = $this->creator( 5, null );

		self::assertSame( 5, $creator->id );
		self::assertNull( $creator->user_id );
		self::assertTrue( $creator->is_active() );
	}

	public function test_creator_id_is_independent_of_the_user_id(): void {
		$creator = $this->creator( 5, 42 );

		self::assertSame( 5, $creator->id );
		self::assertSame( 42, $creator->user_id );
	}

	public function test_inactive_creator_is_not_active(): void {
		self::assertFalse( $this->creator( 1, null, CreatorStatus::Inactive )->is_active() );
	}

	public function test_rejects_non_positive_creator_id(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->creator( 0, null );
	}

	public function test_rejects_non_positive_user_id(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->creator( 1, 0 );
	}

	/**
	 * Creates a creator.
	 *
	 * @param int           $id      Creator id.
	 * @param int|null      $user_id User id.
	 * @param CreatorStatus $status  Status.
	 */
	private function creator( int $id, ?int $user_id, CreatorStatus $status = CreatorStatus::Active ): Creator {
		$now = new DateTimeImmutable( '2026-09-24 12:00:00', new DateTimeZone( 'UTC' ) );

		return new Creator( $id, $user_id, 'Creator', $status, $now, $now );
	}
}
