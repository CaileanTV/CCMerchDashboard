<?php
/**
 * Text fill images of the global alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\GlobalOverlay;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Images\AlertImage;
use MerchBoard\Modules\Alerts\Images\AlertImageException;
use MerchBoard\Modules\Alerts\Images\AlertImageLibrary;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Images\ImageFiles;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;

defined( 'ABSPATH' ) || exit;

/**
 * The images the global overlay's texts can be filled with (step 25.9.4):
 * its own uploads (attachment meta OWNER_META, never a creator's; at most
 * MAX_OWN) and the administrator's image library. A creator's image can
 * neither be used nor deleted.
 */
final class GlobalOverlayImages {

	/**
	 * Attachment meta of the global overlay's own images.
	 */
	public const OWNER_META = '_merchboard_alert_image_global';

	/**
	 * Most own images (as a creator's).
	 */
	public const MAX_OWN = AlertImages::MAX_OWN;

	/**
	 * Constructor.
	 *
	 * @param GlobalOverlayStore     $store   The global overlay.
	 * @param ImageFiles             $files   Image files.
	 * @param AlertImageLibrary|null $library The administrator's library; null for none.
	 */
	public function __construct( private GlobalOverlayStore $store, private ImageFiles $files, private ?AlertImageLibrary $library = null ) {
	}

	/**
	 * An image the global overlay may use (its own or a library image), or null.
	 *
	 * @param int $attachment Attachment id.
	 */
	public function find( int $attachment ): ?AlertImage {
		if ( $attachment <= 0 ) {
			return null;
		}

		return self::owns( $attachment ) ? $this->files->image( $attachment ) : $this->library?->find( $attachment );
	}

	/**
	 * The images as the admin page shows them (the same shape as a creator's).
	 *
	 * @return array{own: list<array{id: int, url: string, name: string, width: int, height: int}>, library: list<array{id: int, url: string, name: string, width: int, height: int}>, max_bytes: int, max_pixels: int, max_own: int}
	 */
	public function describe(): array {
		$own = array();

		foreach ( $this->own_ids() as $id ) {
			$image = $this->files->image( $id );

			if ( null !== $image ) {
				$own[] = $image->to_array();
			}
		}

		return array(
			'own'        => $own,
			'library'    => array_map( static fn( AlertImage $image ): array => $image->to_array(), $this->library?->all() ?? array() ),
			'max_bytes'  => ImageFiles::max_bytes(),
			'max_pixels' => ImageFiles::MAX_PIXELS,
			'max_own'    => self::MAX_OWN,
		);
	}

	/**
	 * Uploads an own image.
	 *
	 * @param array<mixed> $file The upload (one entry of $_FILES).
	 * @throws AlertImageException When the file is refused or MAX_OWN is reached (nothing is kept).
	 */
	public function upload( array $file ): AlertImage {
		if ( count( $this->own_ids() ) >= self::MAX_OWN ) {
			throw new AlertImageException( AlertImageException::OWN_FULL );
		}

		$id = $this->files->store( $file, self::OWNER_META, 1 );

		return $this->files->image( $id ) ?? new AlertImage( $id, '', '', 1, 1 );
	}

	/**
	 * Deletes an own image; texts filled with it show their colour again.
	 *
	 * @param int $attachment Attachment id.
	 * @throws AlertImageException When it is not one of its own images.
	 * @throws DatabaseException When the settings cannot be stored.
	 */
	public function remove( int $attachment ): void {
		self::require_own( $attachment );

		$overlay = $this->store->find();

		if ( null !== $overlay ) {
			$values = $overlay->settings->to_array();
			$fills  = $values['text_fills'];

			foreach ( $fills as $text => $fill ) {
				if ( 'image' === $fill['type'] && $attachment === $fill['image'] ) {
					$fills[ $text ] = array_merge(
						$fill,
						array(
							'type'  => 'none',
							'image' => 0,
						)
					);
				}
			}

			if ( $fills !== $values['text_fills'] ) {
				$values['text_fills'] = $fills;
				$this->store->update_settings( $overlay, OverlaySettings::from_array( $values ) );
			}
		}

		wp_delete_attachment( $attachment, true );
	}

	/**
	 * Fills without images the global overlay may not use.
	 *
	 * @param array{main_text: array{type: string, preset: string, image: int, motion: string}, secondary_text: array{type: string, preset: string, image: int, motion: string}} $fills Fills.
	 * @return array{main_text: array{type: string, preset: string, image: int, motion: string}, secondary_text: array{type: string, preset: string, image: int, motion: string}}
	 */
	public function clean( array $fills ): array {
		foreach ( $fills as $text => $fill ) {
			if ( 0 !== $fill['image'] && null === $this->find( $fill['image'] ) ) {
				$fills[ $text ] = array(
					'type'   => 'image' === $fill['type'] ? 'none' : $fill['type'],
					'preset' => $fill['preset'],
					'image'  => 0,
					'motion' => $fill['motion'],
				);
			}
		}

		return $fills;
	}

	/**
	 * How the overlay page fills a text (AlertImages::look() for the global
	 * overlay's images).
	 *
	 * @param array{type: string, preset: string, image: int, motion: string} $fill Normalized fill.
	 * @return array{kind: string, preset?: string, url?: string, width?: int, height?: int, motion: string}|null
	 */
	public function look( array $fill ): ?array {
		if ( 'preset' === $fill['type'] ) {
			return AlertImages::look( null, 0, $fill );
		}

		$image = 'image' === $fill['type'] ? $this->find( $fill['image'] ) : null;

		return null === $image ? null : array(
			'kind'   => 'image',
			'url'    => $image->url,
			'width'  => $image->width,
			'height' => $image->height,
			'motion' => $fill['motion'],
		);
	}

	/**
	 * Requires one of the global overlay's own images.
	 *
	 * @param int $attachment Attachment id.
	 * @throws AlertImageException When it is not one.
	 */
	private static function require_own( int $attachment ): void {
		if ( ! self::owns( $attachment ) ) {
			throw new AlertImageException( AlertImageException::UNKNOWN_IMAGE );
		}
	}

	/**
	 * Whether an attachment is one of the global overlay's own images.
	 *
	 * @param int $attachment Attachment id.
	 */
	public static function owns( int $attachment ): bool {
		return $attachment > 0 && 'attachment' === get_post_type( $attachment ) && '1' === (string) get_post_meta( $attachment, self::OWNER_META, true );
	}

	/**
	 * The own images, newest first.
	 *
	 * @return list<int>
	 */
	private function own_ids(): array {
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => self::MAX_OWN,
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'DESC',
				'meta_key'    => self::OWNER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- At most MAX_OWN rows, admin only.
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}
}
