<?php
/**
 * Tests for the marker of creators archived by the uninstall.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Lifecycle;

use MerchBoard\Core\Lifecycle\RestoredHistory;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RestoredHistory::class )]
final class RestoredHistoryTest extends TestCase {

	public function test_records_the_highest_archived_id_and_marks_exactly_those_creators(): void {
		$options = InMemoryOptions::install();

		self::assertTrue( RestoredHistory::record( 7, '2026-10-12 12:00:00' ) );

		self::assertSame(
			array(
				'archived_at'    => '2026-10-12 12:00:00',
				'max_creator_id' => 7,
			),
			$options->options[ RestoredHistory::OPTION ]
		);
		self::assertSame( 7, RestoredHistory::max_creator_id() );
		self::assertSame( '2026-10-12 12:00:00', RestoredHistory::archived_at() );
		self::assertTrue( RestoredHistory::is_archived( 1 ) );
		self::assertTrue( RestoredHistory::is_archived( 7 ) );
		self::assertFalse( RestoredHistory::is_archived( 8 ), 'Created after the reinstall.' );
		self::assertFalse( RestoredHistory::is_archived( 0 ) );
	}

	public function test_a_repeated_uninstall_writes_the_same_marker(): void {
		$options = InMemoryOptions::install();

		self::assertTrue( RestoredHistory::record( 7, '2026-10-12 12:00:00' ) );
		self::assertTrue( RestoredHistory::record( 7, '2026-10-12 12:00:00' ) );

		self::assertSame( array( RestoredHistory::OPTION ), $options->updates, 'An unchanged marker is not written again.' );
	}

	public function test_nothing_is_archived_without_a_valid_marker(): void {
		InMemoryOptions::install( array( RestoredHistory::OPTION => array( 'max_creator_id' => '7' ) ) );

		self::assertFalse( RestoredHistory::record( 0, '2026-10-12 12:00:00' ), 'No creators, no marker.' );
		self::assertNull( RestoredHistory::max_creator_id(), 'A forged value is ignored.' );
		self::assertFalse( RestoredHistory::is_archived( 1 ) );
		self::assertNull( RestoredHistory::archived_at() );
	}

	public function test_the_marker_is_forgotten_only_when_the_creators_table_is_missing(): void {
		$options = InMemoryOptions::install( array( RestoredHistory::OPTION => array( 'max_creator_id' => 7 ) ) );
		$wpdb    = new RecordingWpdb();

		$wpdb->tables_exist = true;
		self::assertFalse( RestoredHistory::forget_without_creators( $wpdb ) );
		self::assertArrayHasKey( RestoredHistory::OPTION, $options->options );

		$wpdb->last_error   = 'Lost connection';
		$wpdb->tables_exist = false;
		self::assertFalse( RestoredHistory::forget_without_creators( $wpdb ), 'A failed read keeps it.' );
		self::assertArrayHasKey( RestoredHistory::OPTION, $options->options );

		$wpdb->last_error = '';
		self::assertTrue( RestoredHistory::forget_without_creators( $wpdb ) );
		self::assertArrayNotHasKey( RestoredHistory::OPTION, $options->options );
		self::assertFalse( RestoredHistory::forget_without_creators( $wpdb ), 'Nothing to forget.' );
	}
}
