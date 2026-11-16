<?php
/**
 * The product images without background of approved designer products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerAssetDownload;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerVersionFiles;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Providers\ImageProbe;
use MerchBoard\Modules\ProductDesigner\Rest\MeDesignerController;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerMedia;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-114 pkt. 4: the products in the shop from before get images without
 * background through the administrator.
 *
 * - A provider's photos (e.g. Printful's) get their plain background
 *   removed from the photo itself (the owner's wish 2026-11-16).
 * - An image can be made for a side and colour whose mockup the browser drew
 *   (a JPEG) on a side with an outline: its
 *   silhouette, else its background in that colour when that is a PNG
 *   without background (the template's own photo), else its colour mask
 *   when the colour has no photo of its own. A PSD scene (e.g. a mug) can
 *   always be made: the browser keeps it a JPEG when the PSD has a
 *   background, and it is not sent.
 * - The administrator's browser draws them from the stored print files and
 *   the template's layers (job()) and sends each PNG (store()). They wait in
 *   the shop product's meta PENDING until approved or discarded.
 * - Approve: each becomes an attachment in the media library and takes the
 *   old image's place in the product image, the gallery and the variations;
 *   the version's mockup is the PNG from then on (re-applies and a new
 *   product image use it). The old attachment stays, moved to the slot
 *   `replaced:<side>:<colour>`; SWAP remembers both for "Undo".
 * - Undo: the old images come back, the PNGs wait again.
 *
 * Every change runs under the product's lock (the apply, the repricing and
 * the retirement write the same product). No transaction around shop
 * saves: WooCommerce commits it (ADR-114, the HPOS lesson of 2026-10-26).
 */
final class DesignerCutouts {

	/**
	 * Shop product meta: the images waiting, `{version, files: {"side:colour": file}}`.
	 */
	public const PENDING = '_merchboard_cutouts';

	/**
	 * Shop product meta: what approve swapped, `{version, slots: {"side:colour": {old, new}}}`.
	 */
	public const SWAP = '_merchboard_cutouts_swap';

	/**
	 * The slot of a replaced image in the media library.
	 */
	public const REPLACED = 'replaced';

	/**
	 * Whether each layer asset read so far is a PNG without background.
	 *
	 * @var array<int, bool>
	 */
	private array $clear = array();

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products Designer products.
	 * @param BaseProductRepository     $bases    Base products.
	 * @param DesignerFiles             $files    The private files.
	 * @param DesignerMedia             $media    The media library.
	 * @param DesignerProductGuard      $guard    The legacy guard.
	 * @param WooCommerceDesignerWriter $writer   The shop products.
	 * @param AuditTrail                $audit    Audit trail.
	 * @param DesignerAssetRepository   $assets   The template layers' files.
	 */
	public function __construct(
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private DesignerFiles $files,
		private DesignerMedia $media,
		private DesignerProductGuard $guard,
		private WooCommerceDesignerWriter $writer,
		private AuditTrail $audit,
		private DesignerAssetRepository $assets
	) {
	}

	/**
	 * Deletes the waiting images of a shop product that is deleted
	 * (`before_delete_post`).
	 *
	 * @param mixed $post_id Post.
	 */
	public function forget( mixed $post_id ): void {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( $post_id > 0 ) {
			foreach ( self::pending( $post_id )['files'] as $file ) {
				$this->files->delete( (string) $file['file'] );
			}
		}
	}

	/**
	 * The approved designer products in the shop with their images, newest
	 * first, for the admin page.
	 *
	 * State: `none` (no side with an outline), `done` (nothing left to
	 * make), `todo` or `pending` (made,
	 * waiting for approval); `undo` when approved images can be put back.
	 * Each image: the shop's current attachment (`old`), and the new one as a
	 * waiting file (`waiting`) or an approved attachment (`approved`).
	 *
	 * @return list<array{product: int, creator_id: int, base_id: int, wc_product_id: int, version_id: int, title: string, state: string, undo: bool, slots: list<array{key: string, label: string, old: int|null, waiting: bool, approved: int|null}>}>
	 * @throws DatabaseException When the products cannot be read.
	 */
	public function overview(): array {
		$rows = array();

		foreach ( $this->products->live_in_shop() as $row ) {
			$version = $this->products->version( $row['live_version_id'] );
			$base    = $this->bases->find( $row['base_id'] );

			if ( null === $version || null === $base ) {
				continue;
			}

			$pending = self::pending( $row['wc_product_id'] );
			$pending = $pending['version'] === $row['live_version_id'] ? $pending['files'] : array();
			$swap    = self::swap( $row['wc_product_id'] );
			$swap    = $swap['version'] === $row['live_version_id'] ? $swap['slots'] : array();
			$open    = $this->slots( $version, $base );
			$labels  = self::labels( $base );
			$slots   = array();

			foreach ( array_keys( $open + $swap ) as $key ) {
				$slots[] = array(
					'key'      => $key,
					'label'    => $labels[ $key ] ?? $key,
					'old'      => isset( $swap[ $key ] ) ? $swap[ $key ]['old'] : $this->media->find( $row['live_version_id'], SubmissionFiles::MOCKUP . ':' . $key ),
					'waiting'  => isset( $pending[ $key ] ),
					'approved' => isset( $swap[ $key ] ) ? $swap[ $key ]['new'] : null,
				);
			}

			$rows[] = array(
				'product'       => $row['id'],
				'creator_id'    => $row['creator_id'],
				'base_id'       => $row['base_id'],
				'wc_product_id' => $row['wc_product_id'],
				'version_id'    => $row['live_version_id'],
				'title'         => (string) ( $version['title'] ?? '' ),
				'state'         => match ( true ) {
					array() !== $pending => 'pending',
					array() !== $open    => 'todo',
					array() !== $swap || self::provider( $version ) || $this->outlined( $base ) => 'done',
					default              => 'none',
				},
				'undo'          => array() !== $swap,
				'slots'         => $slots,
			);
		}

		return $rows;
	}

	/**
	 * How many products have images waiting for approval.
	 *
	 * @throws DatabaseException When the products cannot be read.
	 */
	public function waiting(): int {
		$count = 0;

		foreach ( $this->products->live_in_shop() as $row ) {
			$pending = self::pending( $row['wc_product_id'] );
			$count  += $pending['version'] === $row['live_version_id'] && array() !== $pending['files'] ? 1 : 0;
		}

		return $count;
	}

	/**
	 * What the administrator's browser draws for a product: per side and
	 * colour the side as the designer draws it, the colour, the print file
	 * and the layers in that colour (a colour's own photo replaces the
	 * background and the colour mask), all through admin URLs.
	 *
	 * @param int $product Designer product.
	 * A provider's photo (e.g. Printful's) is sent as `photo`: the browser
	 * removes its plain background (the owner's wish 2026-11-16).
	 *
	 * @return array{product: int, version: int, slots: list<array{key: string, color: string, photo?: string, view?: array<string, mixed>, print?: string, layers?: array<string, string>}>}
	 * @throws DesignerException When it is not live.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function job( int $product ): array {
		[ $row, $version, $base ] = $this->live( $product );
		$design                   = json_decode( (string) $version['design'], true );
		$design                   = is_array( $design ) ? $design : array();
		$files                    = SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );
		$slots                    = array();

		foreach ( $this->slots( $version, $base ) as $key => $slot ) {
			if ( null === $slot['view'] || $slot['photo'] ) {
				$slots[] = array(
					'key'   => $key,
					'color' => $slot['hex'],
					'photo' => DesignerVersionFiles::url( (int) $version['id'], SubmissionFiles::MOCKUP . ':' . $key ),
				);
				continue;
			}

			$part  = ColorDesigns::part_of( $design, $slot['color'] );
			$print = ColorDesigns::SHARED === $part ? SubmissionFiles::PRINT . ':' . $slot['view']->key : SubmissionFiles::PRINT . ':' . $slot['view']->key . ':' . $part;

			if ( ! isset( $files['print'][ $slot['view']->key ] ) && ! isset( $files['designs'][ $part ][ $slot['view']->key ] ) ) {
				continue;
			}

			$slots[] = array(
				'key'    => $key,
				'color'  => $slot['hex'],
				'view'   => MeDesignerController::view_data( $base, $slot['view'] ),
				'print'  => DesignerVersionFiles::url( (int) $version['id'], $print ),
				'layers' => array_map( array( DesignerAssetDownload::class, 'url' ), null === $slot['view']->scene ? self::layers( $slot['view'], $slot['color'] ) : array_intersect_key( $slot['view']->layers, array( BaseView::SCENE => true ) ) ),
			);
		}

		return array(
			'product' => (int) $row['id'],
			'version' => (int) $version['id'],
			'slots'   => $slots,
		);
	}

	/**
	 * The sides of a base product without an outline, with the photo a
	 * silhouette can be made from (its background, else its first colour's
	 * own photo): the administrator's browser removes a plain background and
	 * sends the silhouette (ADR-114, the owner's wish 2026-11-15: the old
	 * images on white become images without background).
	 *
	 * @param int $base_id Base product.
	 * @return list<array{key: string, name: string, photo: string}>
	 * @throws DesignerException When the base product is unknown.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function outline_job( int $base_id ): array {
		$base  = $this->bases->find( $base_id ) ?? self::refuse( DesignerException::BASE_UNKNOWN );
		$sides = array();

		foreach ( $base->views as $view ) {
			$photo = $view->layers['background'] ?? array_values( $view->color_backgrounds() )[0] ?? null;

			if ( null !== $view->scene || null === $photo ) {
				continue;
			}

			foreach ( $base->colors as $color ) {
				if ( null === $this->outline( $view, $color['key'] ) ) {
					$sides[] = array(
						'key'   => $view->key,
						'name'  => $view->name,
						'photo' => DesignerAssetDownload::url( $photo ),
					);
					break;
				}
			}
		}

		return $sides;
	}

	/**
	 * Keeps an image without background the administrator's browser drew.
	 *
	 * @param int          $product Designer product.
	 * @param string       $key     `<side>:<colour>`.
	 * @param array<mixed> $upload  The PNG (one entry of $_FILES).
	 * @throws DesignerException When it is not live, busy, no open image or no PNG of the side's mockup size.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function store( int $product, string $key, array $upload ): void {
		$this->locked(
			$product,
			function () use ( $product, $key, $upload ): void {
				[ $row, $version, $base ] = $this->live( $product );
				$slot                     = $this->slots( $version, $base )[ $key ] ?? self::refuse( DesignerException::DESIGN_INVALID );
				$file                     = $this->files->store( $upload, true );

				// A PSD scene draws its mockup in its own size (e.g. mugs); a provider's photo keeps its own.
				if ( array( $file['width'], $file['height'] ) !== $slot['size'] ) {
					$this->files->delete( $file['file'] );
					self::refuse( DesignerException::BASE_LAYER_SIZE );
				}

				$wc_id   = (int) $row['wc_product_id'];
				$pending = self::pending( $wc_id );

				if ( $pending['version'] !== (int) $version['id'] ) {
					$this->drop( $pending['files'] );
					$pending = array(
						'version' => (int) $version['id'],
						'files'   => array(),
					);
				}

				if ( isset( $pending['files'][ $key ] ) ) {
					$this->files->delete( (string) $pending['files'][ $key ]['file'] );
				}

				$pending['files'][ $key ] = $file;
				update_post_meta( $wc_id, self::PENDING, (string) wp_json_encode( $pending ) );
			}
		);
	}

	/**
	 * Approves a product's waiting images: they replace the old ones in the
	 * shop, in their places.
	 *
	 * @param int $product Designer product.
	 * @return int How many images were replaced.
	 * @throws DesignerException When it is not live, busy or has nothing waiting for its live version.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function approve( int $product ): int {
		return $this->locked(
			$product,
			function () use ( $product ): int {
				[ $row, $version, $base ] = $this->live( $product );
				$version_id               = (int) $version['id'];
				$wc_id                    = (int) $row['wc_product_id'];
				$pending                  = self::pending( $wc_id );
				$open                     = $this->slots( $version, $base );

				if ( $pending['version'] !== $version_id || array() === $pending['files'] ) {
					self::refuse( DesignerException::DESIGN_STALE );
				}

				$proof   = $this->guard->for_row( $product ) ?? self::refuse( DesignerException::NOT_LIVE );
				$files   = SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );
				$mockups = $files['mockups'];
				$title   = (string) $version['title'];
				$added   = array();
				$swapped = array();
				$left    = array();

				try {
					foreach ( $pending['files'] as $key => $file ) {
						$old = $this->media->find( $version_id, SubmissionFiles::MOCKUP . ':' . $key );

						// Only an image in place of one in the shop; one without stays waiting.
						if ( ! isset( $open[ $key ] ) || null === $old ) {
							$left[ $key ] = $file;
							continue;
						}

						$added[ $key ]   = $this->media->add( $version_id, 'cutout:' . $key, $file, $wc_id, $title );
						$swapped[ $key ] = array(
							'old'  => $old,
							'new'  => $added[ $key ],
							'file' => $mockups[ $open[ $key ]['side'] ][ $open[ $key ]['color'] ] ?? null,
						);
						$mockups[ $open[ $key ]['side'] ][ $open[ $key ]['color'] ] = $file;
					}

					if ( array() === $swapped ) {
						self::refuse( DesignerException::DESIGN_STALE );
					}

					$this->products->set_live_mockups( $version_id, $mockups );
				} catch ( DesignerException | DatabaseException $e ) {
					foreach ( $added as $attachment ) {
						wp_delete_attachment( $attachment, true );
					}

					throw $e;
				}

				foreach ( $swapped as $key => $swap ) {
					$this->media->relabel( $swap['old'], $version_id, self::REPLACED . ':' . $key );
					$this->media->relabel( $swap['new'], $version_id, SubmissionFiles::MOCKUP . ':' . $key );
				}

				$this->writer->swap_images( $proof, array_combine( array_column( $swapped, 'old' ), array_column( $swapped, 'new' ) ) );

				$earlier = self::swap( $wc_id );
				$slots   = $earlier['version'] === $version_id ? $earlier['slots'] : array();

				foreach ( $swapped as $key => $swap ) {
					$slots[ $key ] = array(
						'old' => $swap['old'],
						'new' => $swap['new'],
					);
				}

				update_post_meta(
					$wc_id,
					self::SWAP,
					(string) wp_json_encode(
						array(
							'version' => $version_id,
							'slots'   => $slots,
						)
					)
				);
				$this->keep_pending( $wc_id, $version_id, $left );

				// The browser's old files: no version uses them any more; the media library keeps the images.
				$used = $this->products->files_of( $product );

				foreach ( $swapped as $swap ) {
					$name = is_array( $swap['file'] ) && is_string( $swap['file']['file'] ?? null ) ? $swap['file']['file'] : null;

					if ( null !== $name && ! in_array( $name, $used, true ) ) {
						$this->files->delete( $name );
					}
				}

				$this->audit->record(
					AuditAction::DesignerCutoutsApproved,
					(int) $row['creator_id'],
					array(
						'design_product_id' => $product,
						'version_id'        => $version_id,
						'images'            => count( $swapped ),
					)
				);

				return count( $swapped );
			}
		);
	}

	/**
	 * Puts the old images back in the shop; the images without background
	 * wait for approval again.
	 *
	 * @param int $product Designer product.
	 * @return int How many images were put back.
	 * @throws DesignerException When it is not live, busy or nothing was approved for its live version.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function revert( int $product ): int {
		return $this->locked(
			$product,
			function () use ( $product ): int {
				[ $row, $version ] = $this->live( $product );
				$version_id        = (int) $version['id'];
				$wc_id             = (int) $row['wc_product_id'];
				$swap              = self::swap( $wc_id );

				if ( $swap['version'] !== $version_id || array() === $swap['slots'] ) {
					self::refuse( DesignerException::DESIGN_STALE );
				}

				$proof    = $this->guard->for_row( $product ) ?? self::refuse( DesignerException::NOT_LIVE );
				$files    = SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );
				$mockups  = $files['mockups'];
				$waiting  = self::pending( $wc_id );
				$waiting  = $waiting['version'] === $version_id ? $waiting['files'] : array();
				$restored = array();
				$written  = array();

				try {
					foreach ( $swap['slots'] as $key => $slot ) {
						[ $side, $color ] = explode( ':', $key, 2 ) + array( '', '' );
						$png              = $mockups[ $side ][ $color ] ?? null;
						$old              = $this->restore( $slot['old'] );

						if ( null === $png || null === $old ) {
							continue;
						}

						$written[]                  = $old['file'];
						$mockups[ $side ][ $color ] = $old;
						$waiting[ $key ]            = $png;
						$restored[ $key ]           = $slot;
					}

					if ( array() === $restored ) {
						self::refuse( DesignerException::DESIGN_STALE );
					}

					$this->products->set_live_mockups( $version_id, $mockups );
				} catch ( DesignerException | DatabaseException $e ) {
					foreach ( $written as $name ) {
						$this->files->delete( $name );
					}

					throw $e;
				}

				foreach ( $restored as $key => $slot ) {
					$this->media->relabel( $slot['old'], $version_id, SubmissionFiles::MOCKUP . ':' . $key );
				}

				$this->writer->swap_images( $proof, array_combine( array_column( $restored, 'new' ), array_column( $restored, 'old' ) ) );

				foreach ( $restored as $slot ) {
					wp_delete_attachment( $slot['new'], true );
				}

				$left = array_diff_key( $swap['slots'], $restored );

				if ( array() === $left ) {
					delete_post_meta( $wc_id, self::SWAP );
				} else {
					update_post_meta(
						$wc_id,
						self::SWAP,
						(string) wp_json_encode(
							array(
								'version' => $version_id,
								'slots'   => $left,
							)
						)
					);
				}

				$this->keep_pending( $wc_id, $version_id, $waiting );
				$this->audit->record(
					AuditAction::DesignerCutoutsReverted,
					(int) $row['creator_id'],
					array(
						'design_product_id' => $product,
						'version_id'        => $version_id,
						'images'            => count( $restored ),
					)
				);

				return count( $restored );
			}
		);
	}

	/**
	 * Discards a product's waiting images.
	 *
	 * @param int $product Designer product.
	 * @throws DesignerException When it is unknown or busy.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function discard( int $product ): void {
		$this->locked(
			$product,
			function () use ( $product ): void {
				$row = $this->products->product( $product ) ?? self::refuse( DesignerException::DESIGN_UNKNOWN );

				if ( null !== $row['wc_product_id'] ) {
					$this->drop( self::pending( (int) $row['wc_product_id'] )['files'] );
					delete_post_meta( (int) $row['wc_product_id'], self::PENDING );
				}
			}
		);
	}

	/**
	 * The private file of a waiting image, or null.
	 *
	 * @param int    $product Designer product.
	 * @param string $key     `<side>:<colour>`.
	 * @return array{path: string, mime: string}|null
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database and the files.
	 */
	public function waiting_file( int $product, string $key ): ?array {
		$row  = $this->products->product( $product );
		$file = null === $row || null === $row['wc_product_id'] ? null : ( self::pending( (int) $row['wc_product_id'] )['files'][ $key ] ?? null );
		$path = null === $file ? null : $this->files->path( (string) $file['file'] );

		return null === $path ? null : array(
			'path' => $path,
			'mime' => (string) $file['mime'],
		);
	}

	/**
	 * The images of a version that can be made without background, by
	 * `<side>:<colour>`, while they still have a background (a JPEG, or a PNG
	 * without transparent pixels):
	 * - the browser's mockups on a side with a PSD scene or an outline in that
	 *   colour: drawn again (`photo` false, the side's mockup size);
	 * - a provider's photos (e.g. Printful's; the owner's wish 2026-11-16):
	 *   the plain background is removed from the photo itself (`photo` true,
	 *   the photo's own size). Embroidery close-ups stay.
	 *
	 * @param array<string, mixed> $version Live version.
	 * @param BaseProduct          $base    Its base product.
	 * @return array<string, array{view: BaseView|null, side: string, color: string, hex: string, photo: bool, size: array{0: int, 1: int}}>
	 * @throws DatabaseException When a layer cannot be read.
	 */
	public function slots( array $version, BaseProduct $base ): array {
		$files    = SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );
		$hexes    = array_column( $base->colors, 'hex', 'key' );
		$views    = array();
		$provider = self::provider( $version );
		$slots    = array();

		foreach ( $base->views as $view ) {
			$views[ $view->key ] = $view;
		}

		foreach ( $files['mockups'] as $side => $colors ) {
			$side = (string) $side;
			$view = $views[ $side ] ?? null;

			if ( SubmissionFiles::is_close( $side ) ) {
				continue;
			}

			foreach ( $colors as $color => $file ) {
				$color = (string) $color;

				if ( ! isset( $hexes[ $color ] ) || ! $this->has_background( $file ) ) {
					continue;
				}

				if ( $provider ) {
					$slots[ $side . ':' . $color ] = array(
						'view'  => $view,
						'side'  => $side,
						'color' => $color,
						'hex'   => $hexes[ $color ],
						'photo' => true,
						'size'  => array( (int) ( $file['width'] ?? 0 ), (int) ( $file['height'] ?? 0 ) ),
					);
				} elseif ( null !== $view && ( null !== $view->scene || null !== $this->outline( $view, $color ) ) ) {
					$slots[ $side . ':' . $color ] = array(
						'view'  => $view,
						'side'  => $side,
						'color' => $color,
						'hex'   => $hexes[ $color ],
						'photo' => false,
						'size'  => $view->mockup_size(),
					);
				}
			}
		}

		return $slots;
	}

	/**
	 * Whether a stored mockup still has a background: a JPEG (or WebP), or a
	 * PNG without transparent pixels.
	 *
	 * @param array<string, mixed> $file Stored file.
	 */
	private function has_background( array $file ): bool {
		if ( 'image/png' !== ( $file['mime'] ?? '' ) ) {
			return true;
		}

		$path = is_string( $file['file'] ?? null ) ? $this->files->path( $file['file'] ) : null;
		$body = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A mockup in the plugin's own folder.

		return false !== $body && ! ImageProbe::transparent( $body );
	}

	/**
	 * The layer a side's image without background is cut to in a colour: its
	 * silhouette, else its background in that colour when it is a PNG without
	 * background, else its colour mask when the colour has no photo of its
	 * own; null without one. The browser picks the same (mockup.ts).
	 *
	 * @param BaseView $view  The side.
	 * @param string   $color Colour key.
	 * @throws DatabaseException When a layer cannot be read.
	 */
	public function outline( BaseView $view, string $color ): ?string {
		if ( isset( $view->layers[ BaseView::SILHOUETTE ] ) ) {
			return BaseView::SILHOUETTE;
		}

		$background = self::layers( $view, $color )['background'] ?? null;

		if ( null !== $background && $this->clear( $background ) ) {
			return 'background';
		}

		return isset( $view->layers['color'] ) && ! isset( $view->layers[ BaseView::COLOR_BACKGROUND . $color ] ) ? 'color' : null;
	}

	/**
	 * Whether a layer asset is a PNG without background.
	 *
	 * @param int $asset Asset.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function clear( int $asset ): bool {
		if ( ! isset( $this->clear[ $asset ] ) ) {
			$row  = $this->assets->find( $asset );
			$path = null === $row || 'image/png' !== $row['mime'] ? null : $this->files->path( $row['file'] );
			$body = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A template layer in the plugin's own folder.

			$this->clear[ $asset ] = false !== $body && ImageProbe::transparent( $body );
		}

		return $this->clear[ $asset ];
	}

	/**
	 * A side's layers in a colour (asset by kind): its own photo replaces the
	 * background and the colour mask, as in the designer.
	 *
	 * @param BaseView $view  The side.
	 * @param string   $color Colour key.
	 * @return array<string, int>
	 */
	public static function layers( BaseView $view, string $color ): array {
		$layers = array_intersect_key( $view->layers, array_flip( BaseView::LAYERS ) );
		$own    = $view->layers[ BaseView::COLOR_BACKGROUND . $color ] ?? null;

		if ( null !== $own ) {
			$layers['background'] = $own;
			unset( $layers['color'] );
		}

		return $layers;
	}

	/**
	 * The name of each side and colour, e.g. "Black – Front".
	 *
	 * @param BaseProduct $base Base product.
	 * @return array<string, string> Name by `<side>:<colour>`.
	 */
	private static function labels( BaseProduct $base ): array {
		$labels = array();

		foreach ( $base->views as $view ) {
			foreach ( $base->colors as $color ) {
				$labels[ $view->key . ':' . $color['key'] ] = $color['name'] . ' – ' . $view->name;
			}
		}

		return $labels;
	}

	/**
	 * Whether a provider's photos are a version's images (cut out from the photo, not drawn again).
	 *
	 * @param array<string, mixed> $version Version.
	 */
	private static function provider( array $version ): bool {
		return in_array( (string) ( $version['mockup_state'] ?? '' ), array( 'running', 'ready' ), true );
	}

	/**
	 * Whether any side of a base product has an outline in some colour.
	 *
	 * @param BaseProduct $base Base product.
	 * @throws DatabaseException When a layer cannot be read.
	 */
	private function outlined( BaseProduct $base ): bool {
		foreach ( $base->views as $view ) {
			foreach ( $base->colors as $color ) {
				if ( null !== $view->scene || null !== $this->outline( $view, $color['key'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * A live product with its live version and base product.
	 *
	 * @param int $product Designer product.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: BaseProduct}
	 * @throws DesignerException When it is not live in the shop.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function live( int $product ): array {
		$row = $this->products->product( $product );

		if ( null === $row || DesignerProductRepository::LIVE !== $row['status'] || null === $row['wc_product_id'] ) {
			self::refuse( DesignerException::NOT_LIVE );
		}

		$version = $this->products->live( $product ) ?? self::refuse( DesignerException::NOT_LIVE );
		$base    = $this->bases->find( (int) $row['base_id'] ) ?? self::refuse( DesignerException::BASE_UNKNOWN );

		return array( $row, $version, $base );
	}

	/**
	 * Runs work under the product's lock.
	 *
	 * @template T
	 * @param int           $product Designer product.
	 * @param callable(): T $work    The work.
	 * @return T
	 * @throws DesignerException When the product is busy, or the work refuses.
	 * @throws DatabaseException When the work cannot read or store.
	 */
	private function locked( int $product, callable $work ): mixed {
		if ( ! $this->products->lock_product( $product ) ) {
			self::refuse( DesignerException::DESIGN_BUSY );
		}

		try {
			return $work();
		} finally {
			$this->products->unlock_product( $product );
		}
	}

	/**
	 * A private copy of a replaced image from the media library, as a
	 * version's file; null when it is gone.
	 *
	 * @param int $attachment Attachment.
	 * @return array{file: string, mime: string, width: int, height: int, bytes: int}|null
	 * @throws DesignerException When it cannot be written.
	 */
	private function restore( int $attachment ): ?array {
		$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $attachment ) : false;
		$path = is_string( $path ) && is_file( $path ) ? $path : get_attached_file( $attachment );
		$body = is_string( $path ) && is_file( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local image of the media library.
		$size = false === $body ? false : wp_getimagesize( (string) $path );

		if ( false === $body || ! is_array( $size ) || ! in_array( $size[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG ), true ) ) {
			return null;
		}

		$jpeg = IMAGETYPE_JPEG === $size[2];
		$name = bin2hex( random_bytes( 16 ) ) . ( $jpeg ? '.jpg' : '.png' );
		$this->files->write( $name, $body );

		return array(
			'file'   => $name,
			'mime'   => $jpeg ? 'image/jpeg' : 'image/png',
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
			'bytes'  => strlen( $body ),
		);
	}

	/**
	 * Stores the images still waiting, or removes the meta when none are.
	 *
	 * @param int                                 $wc_id      Shop product.
	 * @param int                                 $version_id Live version.
	 * @param array<string, array<string, mixed>> $files      Waiting images.
	 */
	private function keep_pending( int $wc_id, int $version_id, array $files ): void {
		if ( array() === $files ) {
			delete_post_meta( $wc_id, self::PENDING );

			return;
		}

		update_post_meta(
			$wc_id,
			self::PENDING,
			(string) wp_json_encode(
				array(
					'version' => $version_id,
					'files'   => $files,
				)
			)
		);
	}

	/**
	 * Deletes waiting files.
	 *
	 * @param array<string, array<string, mixed>> $files Waiting images.
	 */
	private function drop( array $files ): void {
		foreach ( $files as $file ) {
			$this->files->delete( (string) $file['file'] );
		}
	}

	/**
	 * A shop product's waiting images.
	 *
	 * @param int $wc_id Shop product.
	 * @return array{version: int, files: array<string, array<string, mixed>>}
	 */
	private static function pending( int $wc_id ): array {
		$data  = json_decode( (string) get_post_meta( $wc_id, self::PENDING, true ), true );
		$files = array();

		foreach ( is_array( $data ) && is_array( $data['files'] ?? null ) ? $data['files'] : array() as $key => $file ) {
			if ( is_array( $file ) && is_string( $file['file'] ?? null ) && 'image/png' === ( $file['mime'] ?? null ) ) {
				$files[ (string) $key ] = $file;
			}
		}

		return array(
			'version' => is_array( $data ) && is_int( $data['version'] ?? null ) ? $data['version'] : 0,
			'files'   => $files,
		);
	}

	/**
	 * What approve swapped on a shop product.
	 *
	 * @param int $wc_id Shop product.
	 * @return array{version: int, slots: array<string, array{old: int, new: int}>}
	 */
	private static function swap( int $wc_id ): array {
		$data  = json_decode( (string) get_post_meta( $wc_id, self::SWAP, true ), true );
		$slots = array();

		foreach ( is_array( $data ) && is_array( $data['slots'] ?? null ) ? $data['slots'] : array() as $key => $slot ) {
			if ( is_array( $slot ) && is_int( $slot['old'] ?? null ) && is_int( $slot['new'] ?? null ) ) {
				$slots[ (string) $key ] = array(
					'old' => $slot['old'],
					'new' => $slot['new'],
				);
			}
		}

		return array(
			'version' => is_array( $data ) && is_int( $data['version'] ?? null ) ? $data['version'] : 0,
			'slots'   => $slots,
		);
	}

	/**
	 * Refuses with a reason.
	 *
	 * @param string $reason DesignerException code.
	 * @throws DesignerException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
