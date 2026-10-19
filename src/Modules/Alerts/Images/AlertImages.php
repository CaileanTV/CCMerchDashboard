<?php
/**
 * The creator's alert images.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Images;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;

defined( 'ABSPATH' ) || exit;

/**
 * Images that fill a creator's alert text (phase 25, step 6.10): the
 * creator's own uploads and the administrator's library.
 *
 * - **Own files:** at most MAX_OWN uploads (validated by ImageFiles),
 *   attachments marked with their owner (OWNER_META). Only the owner's
 *   attachments are ever shown, listed or deleted, so a settings value can
 *   never reach another creator's file.
 * - **Library:** images of AlertImageLibrary; never deleted by creators.
 * - **Settings:** a text fill (OverlaySettings `text_fills`) may only name
 *   the creator's own image or a library image; clean() clears any other.
 *   Deleting an own image clears the fills that used it.
 *
 * Changes are not audited (like the other look-and-feel settings).
 */
final class AlertImages {

	/**
	 * Attachment meta: the creator who uploaded the image.
	 */
	public const OWNER_META = '_merchboard_alert_image_creator';

	/**
	 * Most own images per creator.
	 */
	public const MAX_OWN = 10;

	/**
	 * Constructor.
	 *
	 * @param AlertOverlayRepository $overlays Overlays.
	 * @param ImageFiles             $files    Image files.
	 * @param AlertImageLibrary|null $library  The administrator's library; null for none.
	 */
	public function __construct( private AlertOverlayRepository $overlays, private ImageFiles $files, private ?AlertImageLibrary $library = null ) {
	}

	/**
	 * The creator's own images, newest first.
	 *
	 * @param int $creator_id Creator.
	 * @return list<AlertImage>
	 */
	public function own( int $creator_id ): array {
		$images = array();

		foreach ( $this->own_ids( $creator_id ) as $id ) {
			$image = $this->files->image( $id );

			if ( null !== $image ) {
				$images[] = $image;
			}
		}

		return $images;
	}

	/**
	 * An image the creator may use: their own or a library image; else null.
	 *
	 * @param int $creator_id Creator.
	 * @param int $attachment Attachment id.
	 */
	public function find( int $creator_id, int $attachment ): ?AlertImage {
		if ( $attachment <= 0 ) {
			return null;
		}

		return $this->owns( $attachment, $creator_id ) ? $this->files->image( $attachment ) : $this->library?->find( $attachment );
	}

	/**
	 * The images for the dashboard.
	 *
	 * @param int $creator_id Creator.
	 * @return array{own: list<array{id: int, url: string, name: string, width: int, height: int}>, library: list<array{id: int, url: string, name: string, width: int, height: int}>, max_bytes: int, max_pixels: int, max_own: int}
	 */
	public function describe( int $creator_id ): array {
		return array(
			'own'        => array_map( static fn( AlertImage $image ): array => $image->to_array(), $this->own( $creator_id ) ),
			'library'    => array_map( static fn( AlertImage $image ): array => $image->to_array(), $this->library?->all() ?? array() ),
			'max_bytes'  => ImageFiles::max_bytes(),
			'max_pixels' => ImageFiles::MAX_PIXELS,
			'max_own'    => self::MAX_OWN,
		);
	}

	/**
	 * Stores an uploaded image of the creator.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $file       The upload (one entry of $_FILES).
	 * @throws AlertImageException When the creator has MAX_OWN images or the file is refused (nothing is kept).
	 */
	public function upload( int $creator_id, array $file ): AlertImage {
		if ( count( $this->own_ids( $creator_id ) ) >= self::MAX_OWN ) {
			throw new AlertImageException( AlertImageException::OWN_FULL );
		}

		$id = $this->files->store( $file, self::OWNER_META, $creator_id );

		return $this->files->image( $id ) ?? new AlertImage( $id, '', '', 1, 1 );
	}

	/**
	 * Deletes an own image; the texts it filled lose their fill.
	 *
	 * @param int $creator_id Creator.
	 * @param int $attachment Attachment id.
	 * @throws AlertImageException When it is not the creator's image.
	 * @throws DatabaseException When the settings cannot be stored (the file is kept).
	 */
	public function remove( int $creator_id, int $attachment ): void {
		$this->require_own( $attachment, $creator_id );

		$overlay = $this->overlays->find_for_creator( $creator_id );

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
				$this->overlays->update_settings( $overlay->id, OverlaySettings::from_array( $values ) );
			}
		}

		wp_delete_attachment( $attachment, true );
	}

	/**
	 * Text fills with only images the creator may use; any other image is
	 * cleared (and its fill switched off).
	 *
	 * @phpstan-param array<string, array{type: string, preset: string, image: int, motion: string}> $fills
	 * @phpstan-return array<string, array{type: string, preset: string, image: int, motion: string}>
	 *
	 * @param int                  $creator_id Creator.
	 * @param array<string, mixed> $fills      Normalized text fills (OverlaySettings).
	 * @return array<string, mixed>
	 */
	public function clean( int $creator_id, array $fills ): array {
		foreach ( $fills as $text => $fill ) {
			if ( 0 !== $fill['image'] && null === $this->find( $creator_id, $fill['image'] ) ) {
				$fills[ $text ] = self::without_image( $fill );
			}
		}

		return $fills;
	}

	/**
	 * Text fills without any image (when no images can be checked).
	 *
	 * @phpstan-param array<string, array{type: string, preset: string, image: int, motion: string}> $fills
	 * @phpstan-return array<string, array{type: string, preset: string, image: int, motion: string}>
	 *
	 * @param array<string, mixed> $fills Normalized text fills.
	 * @return array<string, mixed>
	 */
	public static function without_images( array $fills ): array {
		return array_map( static fn( array $fill ): array => 0 === $fill['image'] ? $fill : self::without_image( $fill ), $fills );
	}

	/**
	 * What the overlay shows for a text fill: a preset, an image the creator
	 * may use, or null for none.
	 *
	 * @param AlertImages|null                                                $images     Images; null shows only presets.
	 * @param int                                                             $creator_id Creator.
	 * @param array{type: string, preset: string, image: int, motion: string} $fill       Normalized fill.
	 * @return array{kind: string, preset?: string, url?: string, width?: int, height?: int, motion: string}|null
	 */
	public static function look( ?self $images, int $creator_id, array $fill ): ?array {
		if ( 'preset' === $fill['type'] ) {
			return array(
				'kind'   => 'preset',
				'preset' => $fill['preset'],
				'motion' => $fill['motion'],
			);
		}

		$image = 'image' === $fill['type'] ? $images?->find( $creator_id, $fill['image'] ) : null;

		return null === $image ? null : array(
			'kind'   => 'image',
			'url'    => $image->url,
			'width'  => $image->width,
			'height' => $image->height,
			'motion' => $fill['motion'],
		);
	}

	/**
	 * A fill without its image.
	 *
	 * @param array{type: string, preset: string, image: int, motion: string} $fill Fill.
	 * @return array{type: string, preset: string, image: int, motion: string}
	 */
	private static function without_image( array $fill ): array {
		return array(
			'type'   => 'image' === $fill['type'] ? 'none' : $fill['type'],
			'preset' => $fill['preset'],
			'image'  => 0,
			'motion' => $fill['motion'],
		);
	}

	/**
	 * The ids of the creator's images, newest first.
	 *
	 * @param int $creator_id Creator.
	 * @return list<int>
	 */
	private function own_ids( int $creator_id ): array {
		if ( $creator_id <= 0 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => self::MAX_OWN,
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'DESC',
				'meta_key'    => self::OWNER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- At most MAX_OWN rows, dashboard only.
				'meta_value'  => (string) $creator_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Requires an image the creator uploaded.
	 *
	 * @param int $attachment Attachment id.
	 * @param int $creator_id Creator.
	 * @throws AlertImageException When it is not the creator's image.
	 */
	private function require_own( int $attachment, int $creator_id ): void {
		if ( ! $this->owns( $attachment, $creator_id ) ) {
			throw new AlertImageException( AlertImageException::UNKNOWN_IMAGE );
		}
	}

	/**
	 * Whether the creator uploaded an attachment.
	 *
	 * @param int $attachment Attachment id.
	 * @param int $creator_id Creator.
	 */
	private function owns( int $attachment, int $creator_id ): bool {
		return $creator_id > 0 && $attachment > 0 && 'attachment' === get_post_type( $attachment ) && (string) get_post_meta( $attachment, self::OWNER_META, true ) === (string) $creator_id;
	}
}
