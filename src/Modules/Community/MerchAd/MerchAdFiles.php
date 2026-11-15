<?php
/**
 * The background-free product images of the merch ad.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchAd;

use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;

defined( 'ABSPATH' ) || exit;

/**
 * PNG files in `uploads/merchboard-merch-ad/<creator>/` (ADR-113 pkt. 4).
 * They show published products, so they are public like the shop's own
 * product images. Only a real PNG of at most 4 MB and 2000 px is kept; the
 * server names the file itself.
 */
final class MerchAdFiles {

	/**
	 * The folder under uploads.
	 */
	public const FOLDER = 'merchboard-merch-ad';

	/**
	 * The largest file.
	 */
	public const MAX_BYTES = 4194304;

	/**
	 * The largest width or height.
	 */
	public const MAX_PIXELS = 2000;

	/**
	 * The smallest width or height.
	 */
	public const MIN_PIXELS = 16;

	/**
	 * Constructor.
	 *
	 * @param string|null $base_dir Uploads directory (tests); null for WordPress' own.
	 * @param string|null $base_url Uploads URL (tests); null for WordPress' own.
	 */
	public function __construct( private ?string $base_dir = null, private ?string $base_url = null ) {
	}

	/**
	 * Stores a product's image and returns its file name.
	 *
	 * @param int    $creator_id Creator.
	 * @param int    $product_id Parent product.
	 * @param string $bytes      The PNG.
	 * @throws TwitchSubDiscountInvalid With `png` => `too_large`, `not_png`, `size` or `not_saved`.
	 */
	public function store( int $creator_id, int $product_id, string $bytes ): string {
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			throw new TwitchSubDiscountInvalid( array( 'png' => 'too_large' ) );
		}

		$size = '' === $bytes ? false : getimagesizefromstring( $bytes );

		if ( false === $size || IMAGETYPE_PNG !== $size[2] ) {
			throw new TwitchSubDiscountInvalid( array( 'png' => 'not_png' ) );
		}

		if ( $size[0] < self::MIN_PIXELS || $size[1] < self::MIN_PIXELS || $size[0] > self::MAX_PIXELS || $size[1] > self::MAX_PIXELS ) {
			throw new TwitchSubDiscountInvalid( array( 'png' => 'size' ) );
		}

		$dir  = $this->dir( $creator_id );
		$file = 'p' . $product_id . '-' . bin2hex( random_bytes( 6 ) ) . '.png';

		if ( ! wp_mkdir_p( $dir ) || false === file_put_contents( $dir . $file, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A file in the plugin's own uploads folder.
			throw new TwitchSubDiscountInvalid( array( 'png' => 'not_saved' ) );
		}

		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- No directory listing.
		}

		return $file;
	}

	/**
	 * A file's public URL.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $file       File name.
	 */
	public function url( int $creator_id, string $file ): string {
		return $this->base_url() . '/' . self::FOLDER . '/' . $creator_id . '/' . rawurlencode( $file );
	}

	/**
	 * Deletes a file (a name of store() only).
	 *
	 * @param int    $creator_id Creator.
	 * @param string $file       File name.
	 */
	public function delete( int $creator_id, string $file ): void {
		if ( 1 === preg_match( '/^p\d+-[0-9a-f]{12}\.png$/', $file ) && is_file( $this->dir( $creator_id ) . $file ) ) {
			wp_delete_file( $this->dir( $creator_id ) . $file );
		}
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

	/**
	 * The uploads URL.
	 */
	private function base_url(): string {
		return rtrim( $this->base_url ?? (string) wp_upload_dir( null, false )['baseurl'], '/' );
	}
}
