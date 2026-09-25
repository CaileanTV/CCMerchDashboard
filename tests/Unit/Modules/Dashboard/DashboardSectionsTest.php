<?php
/**
 * Tests for the dashboard section registry.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Dashboard;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Dashboard\DashboardSections;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( DashboardSections::class )]
final class DashboardSectionsTest extends TestCase {

	private RecordingLogger $logger;

	/**
	 * Capabilities of the current user.
	 *
	 * @var array<int, string>
	 */
	private array $caps = array( 'read', Capabilities::ACCESS_DASHBOARD );

	protected function setUp(): void {
		parent::setUp();
		$this->logger = new RecordingLogger();

		Functions\stubTranslationFunctions();
		Functions\when( 'current_user_can' )->alias( fn( string $cap ): bool => in_array( $cap, $this->caps, true ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $text ): string => trim( strip_tags( $text ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
	}

	public function test_built_in_sections_for_a_creator(): void {
		self::assertSame(
			array(
				array(
					'id'    => 'overview',
					'label' => 'Overview',
					'path'  => '',
				),
				array(
					'id'    => 'profile',
					'label' => 'Profile',
					'path'  => 'profile',
				),
			),
			$this->registry()->for_current_user()
		);
		self::assertSame( array(), $this->logger->entries );
	}

	public function test_filter_adds_sections_and_the_capability_decides_visibility(): void {
		Filters\expectApplied( DashboardSections::FILTER )->once()->andReturnUsing(
			static function ( array $sections ): array {
				$sections[] = array(
					'id'         => 'sales',
					'label'      => 'Sales <b>x</b>',
					'path'       => '/Sales/',
					'capability' => 'merchboard_view_sales',
				);
				$sections[] = array(
					'id'         => 'news',
					'label'      => 'News',
					'path'       => 'news',
					'capability' => Capabilities::ACCESS_DASHBOARD,
				);

				return $sections;
			}
		);
		$this->caps[] = 'merchboard_view_sales';

		$sections = $this->registry()->for_current_user();

		self::assertSame( array( 'overview', 'profile', 'sales', 'news' ), array_column( $sections, 'id' ) );
		self::assertSame( 'Sales x', $sections[2]['label'], 'Labels are plain text.' );
		self::assertSame( 'sales', $sections[2]['path'], 'Paths are normalized like the route.' );
		self::assertArrayNotHasKey( 'capability', $sections[2], 'The capability is not sent to the browser.' );
	}

	public function test_sections_without_the_capability_are_not_sent(): void {
		Filters\expectApplied( DashboardSections::FILTER )->once()->andReturnUsing(
			static fn( array $sections ): array => array_merge(
				$sections,
				array(
					array(
						'id'         => 'payouts',
						'label'      => 'Payouts',
						'path'       => 'payouts',
						'capability' => 'merchboard_view_payouts',
					),
				)
			)
		);

		self::assertSame( array( 'overview', 'profile' ), array_column( $this->registry()->for_current_user(), 'id' ) );
	}

	/**
	 * Entries the registry must drop.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_entries(): array {
		$valid = array(
			'id'         => 'extra',
			'label'      => 'Extra',
			'path'       => 'extra',
			'capability' => Capabilities::ACCESS_DASHBOARD,
		);

		return array(
			'not an array'       => array( 'extra' ),
			'uppercase id'       => array( array_merge( $valid, array( 'id' => 'Extra' ) ) ),
			'id with spaces'     => array( array_merge( $valid, array( 'id' => 'my extra' ) ) ),
			'missing label'      => array( array_diff_key( $valid, array( 'label' => true ) ) ),
			'empty label'        => array( array_merge( $valid, array( 'label' => '  ' ) ) ),
			'missing capability' => array( array_diff_key( $valid, array( 'capability' => true ) ) ),
			'traversal path'     => array( array_merge( $valid, array( 'path' => '../wp-admin' ) ) ),
			'external path'      => array( array_merge( $valid, array( 'path' => 'https://evil.example' ) ) ),
			'duplicate id'       => array( array_merge( $valid, array( 'id' => 'profile' ) ) ),
			'duplicate path'     => array( array_merge( $valid, array( 'path' => 'profile' ) ) ),
		);
	}

	/**
	 * Invalid filtered entries are dropped and logged.
	 *
	 * @param mixed $entry Filtered entry.
	 */
	#[DataProvider( 'invalid_entries' )]
	public function test_invalid_entries_are_dropped_and_logged( mixed $entry ): void {
		Filters\expectApplied( DashboardSections::FILTER )->once()->andReturnUsing(
			static fn( array $sections ): array => array_merge( $sections, array( $entry ) )
		);

		self::assertSame( array( 'overview', 'profile' ), array_column( $this->registry()->for_current_user(), 'id' ) );
		self::assertSame( array( 'warning' ), array_column( $this->logger->entries, 'level' ) );
	}

	public function test_the_overview_start_page_always_exists(): void {
		Filters\expectApplied( DashboardSections::FILTER )->once()->andReturn( 'garbage' );

		self::assertSame( array( 'overview' ), array_column( $this->registry()->for_current_user(), 'id' ) );
	}

	public function test_non_creators_see_no_sections(): void {
		$this->caps = array( 'read' );

		self::assertSame( array(), $this->registry()->for_current_user() );
	}

	private function registry(): DashboardSections {
		return new DashboardSections( $this->logger );
	}
}
