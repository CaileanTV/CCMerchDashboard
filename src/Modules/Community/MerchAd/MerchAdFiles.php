<?php
/**
 * What is left of the merch ad's uploaded images.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchAd;

defined( 'ABSPATH' ) || exit;

/**
 * The folder `uploads/merchboard-merch-ad/<creator>/` of the background-free
 * images creators could upload for the merch ad during the 2.2.0 tests
 * (ADR-113 pkt. 4). The product images are without background now (ADR-114),
 * so nothing is uploaded any more; what is left is removed with the creator
 * and at uninstall.
 */
final class MerchAdFiles {

	/**
	 * The folder under uploads.
	 */
	public const FOLDER = 'merchboard-merch-ad';

	/**
	 * Constructor.
	 *
	 * @param string|null $base_dir Uploads directory (tests); null for WordPress' own.
	 */
	public function __construct( private ?string $base_dir = null ) {
	}

	/**
	 * Deletes a creator's folder (the creator is deleted).
	 *
	 * @param int $creator_id Creator.
	 */
	public function delete_all( int $creator_id ): void {
		$dir = $this->dir( $creator_id );

		$paths = glob( $dir . '*' );

		foreach ( false === $paths ? array() : $paths as $path ) {
			wp_delete_file( $path );
		}

		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The plugin's own, now empty folder.
		}
	}

	/**
	 * Deletes every creator's images (opt-in uninstall).
	 */
	public static function remove_all(): void {
		$files   = new self();
		$root    = $files->base_dir() . '/' . self::FOLDER;
		$folders = glob( $root . '/*', GLOB_ONLYDIR );

		foreach ( false === $folders ? array() : $folders as $folder ) {
			$files->delete_all( (int) basename( $folder ) );
		}

		if ( is_dir( $root ) ) {
			rmdir( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The plugin's own, now empty folder.
		}
	}

	/**
	 * A creator's folder, with a trailing slash.
	 *
	 * @param int $creator_id Creator.
	 */
	private function dir( int $creator_id ): string {
		return $this->base_dir() . '/' . self::FOLDER . '/' . $creator_id . '/';
	}

	/**
	 * The uploads directory.
	 */
	private function base_dir(): string {
		return rtrim( $this->base_dir ?? (string) wp_upload_dir( null, false )['basedir'], '/\\' );
	}
}
