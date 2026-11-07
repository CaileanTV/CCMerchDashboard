<?php
/**
 * Private executable dashboard fixtures for integration tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration;

trait PrivateDashboardBuild {

	/**
	 * Owned fixture directories, including partially created builds.
	 *
	 * @var list<string>
	 */
	private array $dashboard_builds = array();

	/**
	 * Creates a new runner-owned directory before writing executable PHP.
	 *
	 * @param string[] $dependencies Script dependencies.
	 * @param string   $version      Fixture version.
	 * @return string
	 */
	private function create_dashboard_build( array $dependencies, string $version ): string {
		$dir = sys_get_temp_dir() . '/merchboard-build-' . bin2hex( random_bytes( 16 ) ) . '/';
		// Never reuse an existing directory, even if it is readable.
		self::assertTrue( mkdir( $dir, 0700 ), 'Could not create a private dashboard fixture.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Private test fixture.
		$this->dashboard_builds[] = $dir;
		foreach ( array( 'build', 'languages' ) as $folder ) {
			self::assertTrue( mkdir( $dir . $folder, 0700 ), 'Could not create a fixture subdirectory.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Private test fixture.
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- PHP fixture source.
		$manifest = '<?php return ' . var_export(
			array(
				'dependencies' => $dependencies,
				'version'      => $version,
			),
			true
		) . ';';
		foreach ( array(
			'dashboard.asset.php' => $manifest,
			'dashboard.js'        => '/* bundle */',
		) as $file => $contents ) {
			self::assertSame( strlen( $contents ), file_put_contents( $dir . 'build/' . $file, $contents ), 'Could not write the complete dashboard fixture.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private test fixture.
		}

		return $dir;
	}

	private function remove_dashboard_builds(): void {
		foreach ( $this->dashboard_builds as $dir ) {
			foreach ( array( 'build', 'languages' ) as $folder ) {
				if ( ! is_dir( $dir . $folder ) ) {
					continue;
				}
				foreach ( new \DirectoryIterator( $dir . $folder ) as $file ) {
					if ( ! $file->isDot() ) {
						unlink( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Private test fixture cleanup.
					}
				}
				rmdir( $dir . $folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Private test fixture cleanup.
			}
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Private test fixture cleanup.
		}
		$this->dashboard_builds = array();
	}
}
