<?php
/**
 * In-memory Media Library for the logo tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use Brain\Monkey\Functions;

/**
 * Backs the attachment functions LogoAttachment uses with an array, on the
 * site https://shop.test. wp_check_filetype() maps extensions to MIME types
 * like WordPress (including SVG, which WordPress itself does not allow).
 */
final class FakeMediaLibrary {

	/**
	 * Attachments by id.
	 *
	 * Fields: type, status, mime, file, width (int|null), height (int|null), url.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $items = array();

	/**
	 * Installs the stubs and returns the library.
	 */
	public static function install(): self {
		$library = new self();

		Functions\when( 'get_post_type' )->alias( fn( int $id ): mixed => $library->items[ $id ]['type'] ?? false );
		Functions\when( 'get_post_status' )->alias( fn( int $id ): mixed => $library->items[ $id ]['status'] ?? false );
		Functions\when( 'get_post_mime_type' )->alias( fn( int $id ): mixed => $library->items[ $id ]['mime'] ?? false );
		Functions\when( 'get_attached_file' )->alias( fn( int $id ): string|false => isset( $library->items[ $id ] ) ? '/uploads/' . $library->items[ $id ]['file'] : false );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_check_filetype' )->alias(
			static function ( string $file ): array {
				$types = array(
					'png'  => 'image/png',
					'jpg'  => 'image/jpeg',
					'jpeg' => 'image/jpeg',
					'webp' => 'image/webp',
					'svg'  => 'image/svg+xml',
					'pdf'  => 'application/pdf',
				);
				$ext   = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

				return array(
					'ext'  => isset( $types[ $ext ] ) ? $ext : false,
					'type' => $types[ $ext ] ?? false,
				);
			}
		);
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			function ( int $id ) use ( $library ): mixed {
				$item = $library->items[ $id ] ?? null;

				if ( null === $item || null === $item['width'] || null === $item['height'] ) {
					return false;
				}

				return array(
					'width'  => $item['width'],
					'height' => $item['height'],
					'file'   => $item['file'],
				);
			}
		);
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			function ( int $id ) use ( $library ): mixed {
				$item = $library->items[ $id ] ?? null;

				return null === $item ? false : array( $item['url'], (int) $item['width'], (int) $item['height'], false );
			}
		);
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.

		return $library;
	}

	/**
	 * Adds an attachment; by default a valid 400×100 PNG on the site.
	 *
	 * @param int                  $id        Attachment id.
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	public function add( int $id, array $overrides = array() ): void {
		$this->items[ $id ] = array_merge(
			array(
				'type'   => 'attachment',
				'status' => 'publish',
				'mime'   => 'image/png',
				'file'   => 'logo-' . $id . '.png',
				'width'  => 400,
				'height' => 100,
				'url'    => 'https://shop.test/wp-content/uploads/logo-' . $id . '.png',
			),
			$overrides
		);
	}
}
