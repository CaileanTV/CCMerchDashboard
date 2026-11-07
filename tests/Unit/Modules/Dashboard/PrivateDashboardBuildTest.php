<?php
/**
 * Executable dashboard fixture isolation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Dashboard;

use MerchBoard\Tests\Integration\PrivateDashboardBuild;
use PHPUnit\Framework\TestCase;

final class PrivateDashboardBuildTest extends TestCase {

	use PrivateDashboardBuild;

	protected function tearDown(): void {
		$this->remove_dashboard_builds();
		parent::tearDown();
	}

	public function test_executable_fixtures_are_private_unique_and_removed(): void {
		$first  = $this->create_dashboard_build( array( 'wp-i18n' ), 'first' );
		$second = $this->create_dashboard_build( array(), 'second' );
		self::assertNotSame( $first, $second );
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			self::assertSame( 0700, fileperms( $first ) & 0777 );
			self::assertSame( 0700, fileperms( $second ) & 0777 );
		}
		self::assertSame(
			array(
				'dependencies' => array( 'wp-i18n' ),
				'version'      => 'first',
			),
			require $first . 'build/dashboard.asset.php'
		);
		self::assertSame(
			array(
				'dependencies' => array(),
				'version'      => 'second',
			),
			require $second . 'build/dashboard.asset.php'
		);

		$this->remove_dashboard_builds();
		self::assertDirectoryDoesNotExist( $first );
		self::assertDirectoryDoesNotExist( $second );
	}
}
