<?php
/**
 * Tests for the pagination value object.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Http;

use MerchBoard\Core\Http\Pagination;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( Pagination::class )]
final class PaginationTest extends TestCase {

	public function test_the_page_and_page_size_are_kept_within_bounds(): void {
		$low  = new Pagination( 0, 0 );
		$high = new Pagination( 7, 500 );

		self::assertSame( array( 1, 1 ), array( $low->page, $low->per_page ) );
		self::assertSame( array( 7, Pagination::MAX_PER_PAGE ), array( $high->page, $high->per_page ) );
		self::assertSame( array( 20, 100 ), array( Pagination::DEFAULT_PER_PAGE, Pagination::MAX_PER_PAGE ) );
	}

	public function test_offset_skips_the_previous_pages(): void {
		self::assertSame( 0, ( new Pagination( 1, 20 ) )->offset() );
		self::assertSame( 40, ( new Pagination( 3, 20 ) )->offset() );
		self::assertSame( 900, ( new Pagination( 10, 100 ) )->offset() );
	}

	/**
	 * Totals and their page counts at 20 per page.
	 *
	 * @return array<string, array{int, int}>
	 */
	public static function totals(): array {
		return array(
			'nothing'           => array( 0, 0 ),
			'negative'          => array( -3, 0 ),
			'one item'          => array( 1, 1 ),
			'one full page'     => array( 20, 1 ),
			'several full'      => array( 60, 3 ),
			'last partial page' => array( 45, 3 ),
		);
	}

	/**
	 * Number of pages for a total.
	 *
	 * @param int $total Total.
	 * @param int $pages Expected number of pages.
	 */
	#[DataProvider( 'totals' )]
	public function test_total_pages( int $total, int $pages ): void {
		self::assertSame( $pages, ( new Pagination( 1, 20 ) )->total_pages( $total ) );
	}
}
