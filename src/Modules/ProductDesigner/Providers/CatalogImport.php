<?php
/**
 * A base product from Printful's or Gelato's catalog, imported in the background.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\ThreadPacks;
use MerchBoard\Modules\WooCommerce\JobQueue;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The catalog import (review 30.15, the owner's choice 2026-10-29): the
 * administrator picks a product in Printful's or Gelato's catalog and ticks
 * its colours, sizes and sides. The draft base product is created at once
 * (name, description, base price, colours, sizes; `import_state` `running`)
 * and the Action Scheduler job `merchboard_designer_import` (a lock per base
 * product) fetches the photos in rounds:
 *
 * 1. **render:** the provider renders the product with the transparent file
 *    in each placement (Printful every colour in one task, Gelato one
 *    product per colour), and the first colour with a marker colour per
 *    side. Polled until done (at most 40 times); the provider's own state
 *    is stored per render and shown live on the base product.
 * 2. **measure:** per photo of the first colour, ImageProbe compares the
 *    two renders; each side's print area is where its marker shows most,
 *    and its print size follows what the photo shows of the layer.
 * 3. **download:** the blank photo of each colour and side, as a PNG of the
 *    side's size (Gelato's colour codes are measured on them).
 * 4. **save:** the sides are stored through BaseProductService as the
 *    administrator who started the import (the first colour's photo as the
 *    background, the others as the colours' own backgrounds), the provider's
 *    placement and photo per side are stored, and the state is `ready`.
 *
 * A flat product (Printful's all-over products) has no photos: the round
 * `flat` fetches each side's sewing pattern and makes a white background
 * of its size, and the sides are stored with the print area the provider
 * names. Nothing is rendered: the provider sews the creator's design into
 * mockups after the submission.
 *
 * A failure (the provider refuses, a render fails or takes too long, no
 * marker is found) sets `failed` with the reason, deletes the photos
 * downloaded so far and tells the administrator; "Try again" starts over.
 * A 429 waits for `Retry-After`. The base product stays a draft throughout.
 */
class CatalogImport {

	public const HOOK = 'merchboard_designer_import';

	public const NONE = 'none';

	public const RUNNING = 'running';

	/**
	 * Seconds without a round before a running import may be started over:
	 * a round that died (a fatal error, a timeout) queues no next round
	 * (30.15 review).
	 */
	public const STALE = 30 * MINUTE_IN_SECONDS;

	public const READY = 'ready';

	public const FAILED = 'failed';

	private const LOCK = 'merchboard_designer_import_';

	public const MAX_POLLS = 40;

	/**
	 * Renders started per round (a provider with one image per product has one per colour).
	 */
	private const RENDERS_PER_RUN = 5;

	/**
	 * Seconds before the next renders are started.
	 */
	private const NEXT_RENDERS_DELAY = 5;

	/**
	 * Most rounds of one import before it gives up.
	 */
	private const MAX_RUNS = 300;

	/**
	 * Photos compared per round (each needs two downloads).
	 */
	private const MEASURES_PER_RUN = 3;

	/**
	 * Photos downloaded per round.
	 */
	private const DOWNLOADS_PER_RUN = 8;

	/**
	 * The longest side of a thumbnail of a provider's image (ADR-119).
	 */
	private const THUMB = 320;

	/**
	 * Seconds before a round looks again while another holds the lock.
	 */
	private const BUSY_DELAY = 15;

	/**
	 * The smallest share of a photo a side's marker must cover.
	 */
	private const MIN_SHARE = 0.002;

	/**
	 * The share of a Printify side's print area a photo must show, or the
	 * print goes around the product and is designed on a sheet (ADR-122).
	 */
	private const WRAPPED = 0.75;

	/**
	 * Printify's camera names of the product's own views around it (ADR-122):
	 * its `position` names the print, not the view, so a room's photo is told by name.
	 */
	private const GUIDE_VIEWS = '/^(front|back|left|right)(-|$)/D';

	/**
	 * The width of a flat side without a pattern.
	 */
	private const FLAT_WIDTH = 1000;

	/**
	 * The widest pattern kept.
	 */
	private const FLAT_MAX = 1500;

	/**
	 * The most photos made of a design, each per colour.
	 */
	public const MAX_PHOTOS = 8;

	/**
	 * The import data of the current round (for a failure inside it).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $current = null;

	/**
	 * Constructor.
	 *
	 * @param wpdb                  $wpdb       Database (the named lock).
	 * @param BaseProductRepository $bases      Base products.
	 * @param BaseProductService    $service    Base products (stores the photos by moving them).
	 * @param DesignerFiles         $files      Files (our own downloads).
	 * @param JobQueue              $queue      Job queue.
	 * @param Logger                $logger     Technical log.
	 * @param int                   $decimals   Store price decimals.
	 * @param string                $assets_url URL of `assets/designer/` with a trailing slash.
	 * @param PrintfulCatalog|null  $printful   Printful.
	 * @param GelatoCatalog|null    $gelato     Gelato.
	 * @param ProdigiCatalog|null   $prodigi    Prodigi.
	 * @param PrintifyCatalog|null  $printify   Printify's catalog.
	 */
	public function __construct(
		private wpdb $wpdb,
		private BaseProductRepository $bases,
		private BaseProductService $service,
		private DesignerFiles $files,
		private JobQueue $queue,
		private Logger $logger,
		private int $decimals,
		private string $assets_url,
		private ?PrintfulCatalog $printful = null,
		private ?GelatoCatalog $gelato = null,
		private ?ProdigiCatalog $prodigi = null,
		private ?PrintifyCatalog $printify = null
	) {
	}

	/**
	 * The provider of a source, or null.
	 *
	 * @param string $source `printful` or `gelato`.
	 */
	public function renderer( string $source ): ?CatalogRenderer {
		return match ( $source ) {
			PrintfulCatalog::SOURCE => $this->printful,
			GelatoCatalog::SOURCE   => $this->gelato,
			ProdigiCatalog::SOURCE  => $this->prodigi,
			PrintifyCatalog::SOURCE => $this->printify,
			default                 => null,
		};
	}

	/**
	 * Creates a draft base product from a catalog product and starts its
	 * import. Picked: `colors` (names), `sizes`, `sides` (placements),
	 * `packs` (embroidery pack by placement), `photos` (mockup styles),
	 * `base_price`, `extra_side_price` and `group` (the sizes with the same
	 * print sizes).
	 *
	 * @param string               $source `printful` or `gelato`.
	 * @param string               $ref    The provider's product (Printful) or template (Gelato).
	 * @param array<string, mixed> $picked What the administrator ticked.
	 * @return int The base product's id.
	 * @throws DesignerException When the user may not, nothing was ticked or a value is refused.
	 * @throws PrintfulException When Printful refuses or fails.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws ProdigiException When Prodigi refuses or fails.
	 * @throws PrintifyException When Printify refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( string $source, string $ref, array $picked ): int {
		self::authorize();

		$renderer = $this->renderer( $source );

		if ( null === $renderer || ! $renderer->available() ) {
			self::unavailable( $source );
		}

		$choice = $renderer->choice( $ref, is_numeric( $picked['group'] ?? null ) ? (int) $picked['group'] : 0 );

		// Sizes in inches become centimetres unless inches were asked for, as on the form.
		if ( 'in' !== ( $picked['units'] ?? '' ) && in_array( $source, array( PrintfulCatalog::SOURCE, PrintifyCatalog::SOURCE ), true ) ) {
			$choice = SizeUnits::choice( $choice );
		}
		$flat = true === ( $choice['flat'] ?? false );
		// White sheets for the company's own photos: no pattern, and designs print as on any side.
		$sheets = true === ( $choice['printed'] ?? false );
		$list   = static fn( string $key ): array => array_map( 'strval', array_filter( is_array( $picked[ $key ] ?? null ) ? $picked[ $key ] : array(), 'is_scalar' ) );
		$photos = self::chosen_photos( $choice['photos'] ?? array(), $list( 'photos' ) );
		// Names are compared as stored (ADR-115): runs of spaces as one.
		$sizes  = array_values( array_intersect( $choice['sizes'], array_map( BaseProduct::size_name( ... ), $list( 'sizes' ) ) ) );
		$colors = array();
		$render = array();

		foreach ( $choice['colors'] as $color ) {
			foreach ( in_array( $color['name'], $list( 'colors' ), true ) ? $sizes : array() as $size ) {
				if ( isset( $choice['variants'][ $color['name'] ][ $size ] ) ) {
					$colors[]                 = $color['name'] . ' ' . $color['hex'];
					$render[ $color['name'] ] = $choice['variants'][ $color['name'] ][ $size ];
					break;
				}
			}
		}

		$sides   = array();
		$packs   = is_array( $picked['packs'] ?? null ) ? $picked['packs'] : array();
		$grouped = true === ( $choice['grouped'] ?? false );
		$ticked  = array_keys( $render );
		$groups  = array();

		foreach ( $choice['sides'] as $side ) {
			$key  = BaseView::key_of( $side['name'] );
			$pack = $packs[ $side['placement'] ] ?? '';
			// A side of some sizes only comes with the ticked ones of them, and not at all without any.
			$own  = $side['sizes'] ?? array();
			$only = array_values( array_intersect( $own, $sizes ) );
			// A format group's side (ADR-115) belongs to its value of the first axis; its markers are counted per group.
			$values = array_values( array_intersect( $side['colors'] ?? array(), $ticked ) );
			$room   = $grouped ? count( $sides ) < BaseProduct::MAX_VIEWS : count( $sides ) < count( ImageProbe::MARKERS );

			if ( '' !== $key && ! isset( $sides[ $key ] ) && in_array( $side['placement'], $list( 'sides' ), true ) && $room && ( array() === $own || array() !== $only ) && ( ! $grouped || array() !== $values ) ) {
				$sides[ $key ] = array_merge(
					array(
						'key'         => $key,
						'thread_pack' => is_string( $pack ) && null !== ThreadPacks::find( $pack ) ? $pack : '',
					),
					$side,
					array( 'sizes' => $only )
				);

				if ( $grouped ) {
					$sides[ $key ]['colors']           = $values;
					$sides[ $key ]['formats']          = array_intersect_key( is_array( $side['formats'] ?? null ) ? $side['formats'] : array(), array_flip( $only ) );
					$groups[ (string) $side['group'] ] = (string) $side['variant'];
				}
			}
		}

		if ( array() === $colors || array() === $sizes || array() === $sides ) {
			self::stop( DesignerException::BASE_PICK );
		}

		$id   = $this->service->save(
			null,
			array(
				'name'             => $choice['name'],
				'description'      => $choice['description'],
				'base_price'       => is_string( $picked['base_price'] ?? null ) ? $picked['base_price'] : '',
				'extra_side_price' => is_string( $picked['extra_side_price'] ?? null ) ? $picked['extra_side_price'] : '',
				'category_id'      => '0',
				'colors'           => implode( "\n", $colors ),
				'sizes'            => implode( ', ', $sizes ),
			),
			array()
		);
		$base = $this->bases->find( $id );
		$keys = array_column( $base->colors ?? array(), 'key', 'name' );
		$map  = array();
		$data = array();
		$uids = array();

		foreach ( $render as $name => $variant ) {
			$key          = (string) ( $keys[ $name ] ?? '' );
			$data[ $key ] = $variant;

			foreach ( $sizes as $size ) {
				if ( isset( $choice['variants'][ $name ][ $size ] ) ) {
					$map[ $key ][ $size ] = $choice['variants'][ $name ][ $size ];
				}

				if ( is_string( $choice['uids'][ $name ][ $size ] ?? null ) && '' !== $choice['uids'][ $name ][ $size ] ) {
					$uids[ $key ][ $size ] = $choice['uids'][ $name ][ $size ];
				}
			}
		}

		unset( $data[''], $map[''], $uids[''] );

		// A side's values are stored by colour key.
		foreach ( $sides as $side_key => $side ) {
			if ( isset( $side['colors'] ) ) {
				$sides[ $side_key ]['colors'] = array_values( array_filter( array_map( static fn( string $name ): string => (string) ( $keys[ $name ] ?? '' ), $side['colors'] ) ) );
			}
		}

		$this->bases->set_provider(
			$id,
			$source,
			$ref,
			self::json(
				array(
					'placements' => array_column( $sides, 'placement', 'key' ),
					'variants'   => $map,
					'slots'      => array(),
					'flat'       => $flat && ! $sheets,
					'photos'     => $photos,
					'stitches'   => $choice['stitches'] ?? array(),
					'techniques' => array_column( $sides, 'technique', 'key' ),
					'options'    => $choice['options'] ?? array(),
					'axes'       => $choice['axes'] ?? array(),
					'uids'       => $uids,
				)
			)
		);
		$this->start(
			$id,
			array(
				'source'      => $source,
				'ref'         => $ref,
				'sides'       => array_values( $sides ),
				'colors'      => $data,
				'groups'      => $groups,
				'uids'        => $uids,
				// The values of a grouped first axis (Vertical …) are no colours to measure.
				// Printify (ADR-122) names no colour codes either; its sheets have no photo to measure.
				'measure_hex' => ( GelatoCatalog::SOURCE === $source || ( PrintifyCatalog::SOURCE === $source && ! $sheets ) ) && ! $grouped,
				'flat'        => $flat,
				'printed'     => $sheets,
			)
		);

		return $id;
	}

	/**
	 * The photos to make of the product's designs: the ticked ones it offers
	 * or, with none ticked, its default ones, and never more than MAX_PHOTOS:
	 * each is made per colour.
	 *
	 * @param array<array{id: int, category: string, view: string, default: bool}> $offered What the product offers.
	 * @param array<string>                                                        $ticked  The ticked ids.
	 * @return list<string>
	 */
	private static function chosen_photos( array $offered, array $ticked ): array {
		$ids    = array_map( 'strval', array_column( $offered, 'id' ) );
		$chosen = array_values( array_intersect( $ids, $ticked ) );

		if ( array() === $chosen ) {
			$chosen = array_map( 'strval', array_column( array_filter( $offered, static fn( array $photo ): bool => $photo['default'] ), 'id' ) );
		}

		return array_slice( $chosen, 0, self::MAX_PHOTOS );
	}

	/**
	 * Starts a failed import over, or one that has not moved for STALE
	 * seconds.
	 *
	 * @param int $id Base product.
	 * @throws DesignerException When the user may not or the base product is unknown.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function retry( int $id ): void {
		self::authorize();

		$import = $this->bases->import( $id );

		if ( null === $import ) {
			self::stop( DesignerException::BASE_UNKNOWN );
		}

		$updated = is_numeric( $import['data']['updated'] ?? null ) ? (int) $import['data']['updated'] : 0;
		$stuck   = self::RUNNING === $import['state'] && time() - $updated > self::STALE;

		if ( self::FAILED !== $import['state'] && ! $stuck ) {
			return;
		}

		// What the last attempt left at the provider (ADR-122) goes first.
		$this->cleanup( $id, $import['data'] );

		// What create() started with; the format groups and their products too (ADR-115),
		// or a poster would render all its sides at once and fail.
		$this->start( $id, array_intersect_key( $import['data'], array_flip( array( 'source', 'ref', 'sides', 'colors', 'groups', 'uids', 'measure_hex', 'flat', 'printed' ) ) ) );
	}

	/**
	 * Whether a base product is being imported.
	 *
	 * @param int $id Base product.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function running( int $id ): bool {
		return self::RUNNING === ( $this->bases->import( $id )['state'] ?? self::NONE );
	}

	/**
	 * The import's progress and the provider's answers about the product,
	 * for the administrator's diagnosis (never credentials).
	 *
	 * @param int $id Base product.
	 * @return array<string, mixed>
	 * @throws DesignerException When the user may not.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function diagnose( int $id ): array {
		self::authorize();

		$provider = $this->bases->provider( $id );
		$renderer = null === $provider ? null : $this->renderer( $provider['source'] );
		$answer   = array(
			'provider' => $provider,
			'import'   => $this->bases->import( $id ),
		);

		if ( null === $provider || null === $renderer || ! $renderer->available() ) {
			return $answer;
		}

		$renders = $answer['import']['data']['renders'] ?? array();

		// Each answer on its own: one refused call must not hide the others.
		foreach ( is_array( $renders ) ? $renders : array() as $kind => $render ) {
			try {
				$answer[ 'render ' . $kind ] = is_array( $render ) ? $renderer->render_answer( (string) ( $render['key'] ?? '' ) ) : array();
			} catch ( PrintfulException | GelatoException | ProdigiException | PrintifyException $e ) {
				$answer[ 'render ' . $kind ] = self::refusal( $e );
			}
		}

		try {
			return array_merge( $answer, $renderer->diagnose( $provider['ref'] ) );
		} catch ( PrintfulException | GelatoException | ProdigiException | PrintifyException $e ) {
			$answer['error'] = self::refusal( $e );

			return $answer;
		}
	}

	/**
	 * One round of an import (the job); never throws.
	 *
	 * @param mixed $base_id Base product.
	 */
	public function run( mixed $base_id ): void {
		$id   = is_numeric( $base_id ) ? (int) $base_id : 0;
		$wpdb = $this->wpdb;

		if ( $id < 1 ) {
			return;
		}

		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK . $id, 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		if ( ! is_numeric( $acquired ) || 1 !== (int) $acquired ) {
			$this->schedule( $id, self::BUSY_DELAY );

			return;
		}

		$this->current = null;

		try {
			$this->step( $id );
		} catch ( PrintfulException | GelatoException | ProdigiException | PrintifyException $e ) {
			if ( in_array( $e->reason(), array( PrintfulException::RATE_LIMITED, GelatoException::RATE_LIMITED, ProdigiException::RATE_LIMITED, PrintifyException::RATE_LIMITED ), true ) ) {
				$this->schedule( $id, $e->retry_after );
			} else {
				$this->fail( $id, $e->reason(), $e->call, $e->detail );
			}
		} catch ( DesignerException $e ) {
			$this->fail( $id, $e->reason() );
		} catch ( InvalidArgumentException ) {
			$this->fail( $id, DesignerException::IMPORT_IMAGE );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The catalog import of a base product failed.',
				array(
					'base_id'   => $id,
					'exception' => $e::class,
					'message'   => $e->getMessage(),
				)
			);
			$this->fail( $id, 'error' );
		} finally {
			$this->current = null;
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
		}
	}

	/**
	 * Stores a new import and queues its first round.
	 *
	 * @param int                  $id   Base product.
	 * @param array<string, mixed> $data What to import.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function start( int $id, array $data ): void {
		$data = array_merge(
			$data,
			array(
				'user'    => get_current_user_id(),
				'blank'   => $this->assets_url . 'blank.png',
				'markers' => array_map( fn( int $n ): string => $this->assets_url . 'marker-' . $n . '.png', range( 1, count( ImageProbe::MARKERS ) ) ),
				'phase'   => true === ( $data['flat'] ?? false ) ? 'flat' : 'render',
				'started' => time(),
			)
		);

		$this->bases->set_import( $id, self::RUNNING, self::json( $data ) );

		if ( ! $this->schedule( $id, 0 ) ) {
			$this->fail( $id, DesignerException::IMPORT_QUEUE );
		}
	}

	/**
	 * One round.
	 *
	 * @param int $id Base product.
	 * @throws PrintfulException When Printful refuses or fails.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws ProdigiException When Prodigi refuses or fails.
	 * @throws PrintifyException When Printify refuses or fails.
	 * @throws DesignerException When the import cannot go on.
	 * @throws DatabaseException When the designer's data cannot be read or stored.
	 * @throws InvalidArgumentException When a photo cannot be read.
	 */
	private function step( int $id ): void {
		$import = $this->bases->import( $id );

		if ( null === $import || self::RUNNING !== $import['state'] ) {
			return;
		}

		$data          = $import['data'];
		$data['runs']  = (int) ( $data['runs'] ?? 0 ) + 1;
		$this->current = $data;
		$renderer      = $this->renderer( (string) ( $data['source'] ?? '' ) );

		if ( null === $renderer || ! $renderer->available() ) {
			self::unavailable( (string) ( $data['source'] ?? '' ) );
		}

		if ( ! ImageProbe::available() ) {
			self::stop( DesignerException::IMPORT_GD );
		}

		if ( $data['runs'] > self::MAX_RUNS ) {
			self::stop( DesignerException::IMPORT_TIMEOUT );
		}

		if ( 'save' === ( $data['phase'] ?? '' ) ) {
			$this->finish( $id, $data );
			$this->cleanup( $id, $data );

			return;
		}

		$delay = match ( $data['phase'] ?? 'render' ) {
			'flat'     => $this->flatten( $id, $renderer, $data ),
			'measure'  => $this->measure( $renderer, $data ),
			'download' => $this->collect( $id, $renderer, $data ),
			'guides'   => $this->guides( $renderer, $data ),
			'bleed'    => $this->trims( $renderer, $data ),
			default    => $this->render( $id, $renderer, $data ),
		};

		$this->persist( $id, $data );
		$this->schedule( $id, $delay );
	}

	/**
	 * Fetches the sewing pattern of each side and makes its white background.
	 * A side without a pattern (a provider that has none) is a white sheet
	 * in the shape of its print area.
	 *
	 * @param int                  $id       Base product.
	 * @param CatalogRenderer      $renderer Provider.
	 * @param array<string, mixed> $data     Import data.
	 * @return int Seconds to the next round.
	 * @throws PrintfulException When Printful refuses or fails.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DesignerException When a pattern is refused.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws InvalidArgumentException When a pattern cannot be read.
	 */
	private function flatten( int $id, CatalogRenderer $renderer, array &$data ): int {
		foreach ( is_array( $data['sides'] ?? null ) ? array_values( $data['sides'] ) : array() as $side ) {
			$key = is_array( $side ) ? (string) ( $side['key'] ?? '' ) : '';

			if ( '' === $key || isset( $data['files'][ $key ] ) ) {
				continue;
			}

			$url    = is_string( $side['template'] ?? null ) ? $side['template'] : '';
			$width  = self::FLAT_WIDTH;
			$height = (int) max( 1, round( self::FLAT_WIDTH * (int) ( $side['height_mm'] ?? 1 ) / max( 1, (int) ( $side['width_mm'] ?? 1 ) ) ) );
			$files  = array();

			// A sheet of the size of the company's own photos.
			if ( true === ( $data['printed'] ?? false ) ) {
				$width  = (int) ( $side['template_width'] ?? $width );
				$height = (int) ( $side['template_height'] ?? $height );
			}

			if ( '' !== $url ) {
				$bytes = $renderer->download( $url );

				// The labelled patterns are up to 3000 px: the editor needs far less.
				$info   = getimagesizefromstring( $bytes );
				$scale  = is_array( $info ) && $info[0] > self::FLAT_MAX ? self::FLAT_MAX / $info[0] : 1;
				$scaled = is_array( $info ) && $scale < 1 ? array( self::FLAT_MAX, (int) round( $info[1] * $scale ) ) : array( null, null );

				[ $png, $width, $height ] = ImageProbe::png( $bytes, $scaled[0], $scaled[1] );

				$files['shading'] = $this->store( $png );
			}

			$files['background']   = $this->store( ImageProbe::blank( $width, $height ) );
			$data['files'][ $key ] = $files;
			$data['sizes'][ $key ] = array( $width, $height );
			// Stored at once: a failure later deletes the files.
			$this->persist( $id, $data );
		}

		$data['phase'] = 'save';

		return 0;
	}

	/**
	 * Starts both renders, then polls them until they are done.
	 *
	 * @param int                  $id       Base product.
	 * @param CatalogRenderer      $renderer Provider.
	 * @param array<string, mixed> $data     Import data.
	 * @return int Seconds to the next round.
	 * @throws PrintfulException When Printful refuses or fails.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DesignerException When a render fails or takes too long.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function render( int $id, CatalogRenderer $renderer, array &$data ): int {
		$renders = is_array( $data['renders'] ?? null ) ? $data['renders'] : array();
		$started = 0;

		foreach ( $renderer->render_kinds( $data ) as $kind ) {
			if ( ! isset( $renders[ $kind ] ) ) {
				if ( $started >= self::RENDERS_PER_RUN ) {
					return self::NEXT_RENDERS_DELAY;
				}

				$renders[ $kind ] = array(
					'key'     => $renderer->render( $kind, $data ),
					'status'  => 'pending',
					'polls'   => 0,
					'started' => time(),
				);
				$data['renders']  = $renders;
				++$started;
				// Stored at once: a render is never started twice.
				$this->persist( $id, $data );
			}
		}

		if ( $started > 0 ) {
			return $renderer->poll_delay();
		}

		foreach ( $renders as $kind => $render ) {
			if ( ! is_array( $render ) || 'pending' !== ( $render['status'] ?? '' ) ) {
				continue;
			}

			$state = $renderer->check( (string) $render['key'], $data );
			$polls = (int) ( $render['polls'] ?? 0 ) + 1;

			$renders[ $kind ]['polls']   = $polls;
			$renders[ $kind ]['note']    = $state['note'] ?? '';
			$renders[ $kind ]['checked'] = time();

			if ( 'failed' === $state['status'] ) {
				$data['renders'] = $renders;
				$data['detail']  = $kind . ': ' . ( $state['error'] ?? '' );
				$this->current   = $data;
				self::stop( DesignerException::IMPORT_RENDER );
			}

			if ( 'done' === $state['status'] ) {
				$group = str_starts_with( (string) $kind, 'marker' ) ? 'marker' : ( 'stripes' === $kind ? 'stripes' : 'blank' );

				$renders[ $kind ]['status'] = 'done';

				foreach ( $state['images'] ?? array() as $slot => $urls ) {
					$data['images'][ $group ][ $slot ] = array_merge( $data['images'][ $group ][ $slot ] ?? array(), $urls );
					$renders[ $kind ]['image']       ??= reset( $urls );
				}
			} elseif ( $polls >= self::MAX_POLLS ) {
				$data['renders'] = $renders;
				$data['detail']  = $kind . ': ' . ( $state['note'] ?? '' );
				$this->current   = $data;
				self::stop( DesignerException::IMPORT_TIMEOUT );
			}
		}

		$data['renders'] = $renders;

		if ( array( 'done' ) !== array_values( array_unique( array_column( $renders, 'status' ) ) ) ) {
			return $renderer->poll_delay();
		}

		// A render can lead to more renders.
		if ( array() !== array_diff( $renderer->render_kinds( $data ), array_keys( $renders ) ) ) {
			return 0;
		}

		$data['phase'] = 'measure';

		return 0;
	}

	/**
	 * Compares the renders photo by photo, then picks each side's photo
	 * and print area.
	 *
	 * @param CatalogRenderer      $renderer Provider.
	 * @param array<string, mixed> $data     Import data.
	 * @return int Seconds to the next round.
	 * @throws PrintfulException When Printful refuses or fails.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DesignerException When no side's marker shows.
	 * @throws InvalidArgumentException When a photo cannot be read.
	 */
	private function measure( CatalogRenderer $renderer, array &$data ): int {
		$first     = (string) array_key_first( is_array( $data['colors'] ?? null ) ? $data['colors'] : array() );
		$blank     = is_array( $data['images']['blank'] ?? null ) ? $data['images']['blank'] : array();
		$marker    = is_array( $data['images']['marker'] ?? null ) ? $data['images']['marker'] : array();
		$sides     = is_array( $data['sides'] ?? null ) ? array_values( $data['sides'] ) : array();
		$measured  = is_array( $data['measured'] ?? null ) ? $data['measured'] : array();
		$positions = self::positions( $sides );
		$done      = 0;
		// Each format group (ADR-115) has its own renders; measured as `<group>@<slot>`.
		$groups = array_map( 'strval', array_keys( is_array( $data['groups'] ?? null ) ? $data['groups'] : array() ) );

		foreach ( $marker as $slot => $urls ) {
			foreach ( array() === $groups ? array( $first ) : $groups as $group ) {
				$at = array() === $groups ? (string) $slot : $group . '@' . $slot;

				if ( isset( $measured[ $at ] ) || ! is_string( $urls[ $group ] ?? null ) || ! is_string( $blank[ $slot ][ $group ] ?? null ) ) {
					continue;
				}

				if ( $done >= self::MEASURES_PER_RUN ) {
					$data['measured'] = $measured;

					return 0;
				}

				$count = array() === $groups ? count( $sides ) : count( array_filter( $positions, static fn( array $position ): bool => $group === $position[0] ) );

				$measured[ $at ] = ImageProbe::markers( $renderer->download( $blank[ $slot ][ $group ] ), $renderer->download( $urls[ $group ] ), $count );
				++$done;
			}
		}

		if ( array() === $measured ) {
			self::stop( DesignerException::IMPORT_NO_PHOTOS );
		}

		$data['measured'] = $measured;
		$chosen           = array();

		foreach ( array_keys( $sides ) as $index ) {
			$best = null;

			[ $group, $position ] = $positions[ $index ];

			foreach ( $measured as $at => $found ) {
				$parts = explode( '@', (string) $at, 2 );
				$slot  = $parts[1] ?? $parts[0];

				if ( ( isset( $parts[1] ) ? $parts[0] : '' ) !== $group ) {
					continue;
				}

				$hit    = is_array( $found ) && is_array( $found['found'] ?? null ) ? ( $found['found'][ $position ] ?? null ) : null;
				$width  = is_array( $found ) ? (int) ( $found['width'] ?? 0 ) : 0;
				$height = is_array( $found ) ? (int) ( $found['height'] ?? 0 ) : 0;
				$count  = is_array( $hit ) ? (int) ( $hit['count'] ?? 0 ) : 0;

				if ( is_array( $hit ) && $count >= self::MIN_SHARE * $width * $height && $count > ( $best['count'] ?? 0 ) ) {
					// A format group's photo shows the whole print (a poster): the marker's exact edges.
					$box  = '' !== $group && is_array( $hit['edge'] ?? null ) ? $hit['edge'] : $hit;
					$best = array(
						'slot'   => (string) $slot,
						'count'  => $count,
						'box'    => array(
							'x'      => (int) ( $box['x'] ?? 0 ),
							'y'      => (int) ( $box['y'] ?? 0 ),
							'width'  => max( 1, (int) ( $box['width'] ?? 0 ) ),
							'height' => max( 1, (int) ( $box['height'] ?? 0 ) ),
						),
						'width'  => $width,
						'height' => $height,
					);
				}
			}

			if ( null !== $best ) {
				$chosen[ $index ] = $best;
			}
		}

		if ( array() === $chosen ) {
			// What was measured stays for the diagnosis.
			$this->current = $data;
			self::stop( DesignerException::IMPORT_NO_AREA );
		}

		$data['chosen'] = $chosen;
		$data['phase']  = 'download';

		return 0;
	}

	/**
	 * Each side's format group (empty without groups) and its place among the
	 * group's sides, which is its marker (ADR-115).
	 *
	 * @param array<int, mixed> $sides Import sides.
	 * @return array<int, array{0: string, 1: int}>
	 */
	private static function positions( array $sides ): array {
		$positions = array();
		$counts    = array();

		foreach ( $sides as $index => $side ) {
			$group               = is_array( $side ) && is_string( $side['group'] ?? null ) ? $side['group'] : '';
			$counts[ $group ]    = ( $counts[ $group ] ?? -1 ) + 1;
			$positions[ $index ] = array( $group, $counts[ $group ] );
		}

		return $positions;
	}

	/**
	 * Downloads the blank photo of each colour and side as a PNG of the
	 * side's size.
	 *
	 * @param int                  $id       Base product.
	 * @param CatalogRenderer      $renderer Provider.
	 * @param array<string, mixed> $data     Import data.
	 * @return int Seconds to the next round.
	 * @throws PrintfulException When Printful refuses or fails.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DesignerException When a photo is refused.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws InvalidArgumentException When a photo cannot be read.
	 */
	private function collect( int $id, CatalogRenderer $renderer, array &$data ): int {
		$sides  = is_array( $data['sides'] ?? null ) ? array_values( $data['sides'] ) : array();
		$blank  = is_array( $data['images']['blank'] ?? null ) ? $data['images']['blank'] : array();
		$colors = is_array( $data['colors'] ?? null ) ? array_keys( $data['colors'] ) : array();
		$count  = 0;

		foreach ( is_array( $data['chosen'] ?? null ) ? $data['chosen'] : array() as $index => $chosen ) {
			$key   = (string) ( $sides[ $index ]['key'] ?? '' );
			$group = is_string( $sides[ $index ]['group'] ?? null ) ? $sides[ $index ]['group'] : '';
			// A format group's side is designed on a sheet (ADR-119): no photo of it is needed.
			$own = '' === $group ? $colors : array();

			foreach ( $own as $color ) {
				$color = (string) $color;
				$url   = $blank[ $chosen['slot'] ][ $color ] ?? null;

				if ( isset( $data['files'][ $key ][ $color ] ) ) {
					continue;
				}

				if ( ! is_string( $url ) ) {
					// The provider has no photo of this colour: it shows the first colour's.
					$data['files'][ $key ][ $color ] = '';
					continue;
				}

				if ( $count >= self::DOWNLOADS_PER_RUN ) {
					return 0;
				}

				[ $png ] = ImageProbe::png( $renderer->download( $url ), (int) $chosen['width'], (int) $chosen['height'] );
				++$count;

				if ( true === ( $data['measure_hex'] ?? false ) && ! isset( $data['hex'][ $color ] ) ) {
					$data['hex'][ $color ] = ImageProbe::color( $png, ImageProbe::fit( $chosen['box'], (int) ( $sides[ $index ]['width_mm'] ?? 1 ), (int) ( $sides[ $index ]['height_mm'] ?? 1 ) ) );
				}

				$data['files'][ $key ][ $color ] = $this->store( $png );
				// Stored at once: a failure later deletes the file.
				$this->persist( $id, $data );
			}
		}

		// ADR-119: a thumbnail of each of Gelato's images (a mockup or a room), of the first group or colour;
		// ADR-122: of each of Printify's cameras by name, of the first colour.
		$gelato = GelatoCatalog::SOURCE === ( $data['source'] ?? '' );

		if ( $gelato || PrintifyCatalog::SOURCE === ( $data['source'] ?? '' ) ) {
			$groups = array_map( 'strval', array_keys( is_array( $data['groups'] ?? null ) ? $data['groups'] : array() ) );
			$first  = $groups[0] ?? (string) ( $colors[0] ?? '' );

			foreach ( $blank as $slot => $urls ) {
				$url = is_array( $urls ) ? ( $urls[ $first ] ?? null ) : null;

				if ( ( $gelato && ! is_numeric( $slot ) ) || ! is_string( $url ) || isset( $data['thumbs'][ (string) $slot ] ) ) {
					continue;
				}

				if ( $count >= self::DOWNLOADS_PER_RUN ) {
					return 0;
				}

				$bytes = $renderer->download( $url );
				$size  = getimagesizefromstring( $bytes );
				$wide  = is_array( $size ) && $size[0] > 0 ? (int) $size[0] : self::THUMB;
				$high  = is_array( $size ) && $size[1] > 0 ? (int) $size[1] : self::THUMB;
				$scale = min( 1, self::THUMB / max( $wide, $high ) );

				[ $png ] = ImageProbe::png( $bytes, max( 1, (int) round( $wide * $scale ) ), max( 1, (int) round( $high * $scale ) ) );
				++$count;

				$data['thumbs'][ (string) $slot ] = $this->stored( $png );
				$this->persist( $id, $data );
			}
		}

		// ADR-122: where the views of a print around the product face, when the striped sheet was rendered.
		$data['phase'] = array() !== ( $data['images']['stripes'] ?? array() ) ? 'guides' : 'bleed';

		return 0;
	}

	/**
	 * Measures where each product view of a print around the product faces
	 * on its sheet (ADR-122): per view named in GUIDE_VIEWS, the blank and
	 * the striped photo of the first colour (ImageProbe::stripe_centre()),
	 * a few per round. A view whose stripes do not show is left out.
	 *
	 * @param CatalogRenderer      $renderer Provider.
	 * @param array<string, mixed> $data     Import data.
	 * @return int Seconds to the next round.
	 * @throws PrintifyException When Printify asks to wait.
	 */
	private function guides( CatalogRenderer $renderer, array &$data ): int {
		$stripes = is_array( $data['images']['stripes'] ?? null ) ? $data['images']['stripes'] : array();
		$blank   = is_array( $data['images']['blank'] ?? null ) ? $data['images']['blank'] : array();
		$first   = PrintifyCatalog::stripes_color( is_array( $data['colors'] ?? null ) ? $data['colors'] : array() );
		$sides   = is_array( $data['sides'] ?? null ) ? array_values( $data['sides'] ) : array();
		$done    = 0;
		$around  = array();

		foreach ( is_array( $data['chosen'] ?? null ) ? $data['chosen'] : array() as $index => $chosen ) {
			$side = $sides[ $index ] ?? null;

			if ( is_array( $side ) && is_array( $chosen ) && self::wrapped( $data, $side, $chosen ) ) {
				$around[] = $side;
			}
		}

		// Every side has stripes in the one render: with two sides around the product, a photo can show both.
		foreach ( 1 === count( $around ) ? $around : array() as $side ) {
			$key = (string) ( $side['key'] ?? '' );

			foreach ( $stripes as $slot => $urls ) {
				$slot = (string) $slot;

				if ( 1 !== preg_match( self::GUIDE_VIEWS, $slot ) || isset( $data['centres'][ $key ][ $slot ] ) || ! is_array( $urls ) || ! is_string( $urls[ $first ] ?? null ) || ! is_string( $blank[ $slot ][ $first ] ?? null ) ) {
					continue;
				}

				if ( $done >= self::MEASURES_PER_RUN ) {
					return 0;
				}

				// The guides only help: a photo that cannot be read leaves its view out, but a 429 waits.
				try {
					$centre = ImageProbe::stripe_centre( $renderer->download( $blank[ $slot ][ $first ] ), $renderer->download( $urls[ $first ] ) );
				} catch ( PrintifyException $e ) {
					if ( PrintifyException::RATE_LIMITED === $e->reason() ) {
						throw $e;
					}

					$centre = null;
				} catch ( PrintfulException | GelatoException | ProdigiException | InvalidArgumentException ) {
					$centre = null;
				}

				$data['centres'][ $key ][ $slot ] = $centre ?? -1;
				++$done;
			}
		}

		$data['phase'] = 'bleed';

		return 0;
	}

	/**
	 * Whether a Printify side's print goes around the product (ADR-122): its
	 * marker shows less than WRAPPED of its print area on the chosen photo.
	 *
	 * @param array<string, mixed> $data   Import data.
	 * @param array<mixed>         $side   Import side.
	 * @param array<mixed>         $chosen The side's chosen photo and box.
	 */
	private static function wrapped( array $data, array $side, array $chosen ): bool {
		$box = $chosen['box'] ?? null;

		if ( PrintifyCatalog::SOURCE !== ( $data['source'] ?? '' ) || ! is_array( $box ) ) {
			return false;
		}

		$width  = (int) ( $side['width_mm'] ?? 0 );
		$height = (int) ( $side['height_mm'] ?? 0 );
		$shown  = ImageProbe::print_size(
			array(
				'x'      => (int) ( $box['x'] ?? 0 ),
				'y'      => (int) ( $box['y'] ?? 0 ),
				'width'  => max( 1, (int) ( $box['width'] ?? 1 ) ),
				'height' => max( 1, (int) ( $box['height'] ?? 1 ) ),
			),
			$width,
			$height
		);

		return $shown[0] * $shown[1] < self::WRAPPED * $width * $height;
	}

	/**
	 * The guides of a side from its views' measured centres, in order along
	 * the sheet (ADR-122); a view not found is left out. Their photos are
	 * the provider's thumbnails of the same camera names, found when the
	 * side is sent to the designer.
	 *
	 * @param array<mixed> $centres Share of the sheet by camera name; -1 when not found.
	 * @return list<array{x: float, label: string, figure: int}>
	 */
	private static function guide_list( array $centres ): array {
		$guides = array();

		foreach ( $centres as $name => $centre ) {
			if ( is_numeric( $centre ) && (float) $centre >= 0 && (float) $centre <= 1 ) {
				$guides[] = array(
					'x'      => round( (float) $centre, 4 ),
					'label'  => (string) $name,
					'figure' => 0,
				);
			}
		}

		usort( $guides, static fn( array $a, array $b ): int => $a['x'] <=> $b['x'] );

		return array_slice( $guides, 0, BaseView::MAX_GUIDES );
	}

	/**
	 * Asks Gelato for the finished size of each format's product (ADR-115
	 * pkt. 8), a few per round; a failure leaves that size without a bleed.
	 *
	 * @param CatalogRenderer      $renderer Provider.
	 * @param array<string, mixed> $data     Import data.
	 * @return int Seconds to the next round.
	 * @throws GelatoException When Gelato asks to wait.
	 */
	private function trims( CatalogRenderer $renderer, array &$data ): int {
		$asked = 0;

		if ( ! $renderer instanceof GelatoCatalog ) {
			$data['phase'] = 'save';

			return 0;
		}

		foreach ( self::format_uids( $data ) as $uid ) {
			if ( isset( $data['trims'][ $uid ] ) ) {
				continue;
			}

			if ( $asked >= self::DOWNLOADS_PER_RUN ) {
				return 0;
			}

			try {
				$data['trims'][ $uid ] = $renderer->dimensions( $uid ) ?? array();
			} catch ( GelatoException $e ) {
				if ( GelatoException::RATE_LIMITED === $e->reason() ) {
					throw $e;
				}

				$data['trims'][ $uid ] = array();
			}

			++$asked;
		}

		$data['phase'] = 'save';

		return 0;
	}

	/**
	 * The `productUid` of every size of the sides with formats.
	 *
	 * @param array<string, mixed> $data Import data.
	 * @return list<string>
	 */
	private static function format_uids( array $data ): array {
		$uids = array();

		foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $side ) {
			$color = is_array( $side ) && is_array( $side['colors'] ?? null ) ? (string) reset( $side['colors'] ) : '';

			foreach ( is_array( $side ) && is_array( $side['formats'] ?? null ) ? array_keys( $side['formats'] ) : array() as $size ) {
				$uid = $data['uids'][ $color ][ (string) $size ] ?? null;

				if ( is_string( $uid ) && '' !== $uid && ! in_array( $uid, $uids, true ) ) {
					$uids[] = $uid;
				}
			}
		}

		return $uids;
	}

	/**
	 * Stores the sides and finishes the import.
	 *
	 * @param int                  $id   Base product.
	 * @param array<string, mixed> $data Import data.
	 * @throws DesignerException When the sides are refused or the user may no longer.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function finish( int $id, array $data ): void {
		$base = $this->bases->find( $id );
		$user = (int) ( $data['user'] ?? 0 );

		if ( null === $base ) {
			self::stop( DesignerException::BASE_UNKNOWN );
		}

		if ( $user < 1 || ! user_can( $user, Capabilities::MANAGE_DESIGNER ) ) {
			self::stop( DesignerException::NOT_AUTHORIZED );
		}

		$sides    = is_array( $data['sides'] ?? null ) ? array_values( $data['sides'] ) : array();
		$views    = array_map(
			static fn( BaseView $view ): array => array(
				'key'    => $view->key,
				'name'   => $view->name,
				'remove' => true,
			),
			$base->views
		);
		$slots    = array();
		$kept     = array();
		$scenes   = array();
		$provider = $this->bases->provider( $id );
		$printify = PrintifyCatalog::SOURCE === ( $data['source'] ?? '' );
		$photos   = $provider['gelato_photos'] ?? null;
		$names    = $provider['printify_photos'] ?? null;

		if ( true === ( $data['flat'] ?? false ) ) {
			foreach ( $sides as $side ) {
				$view = is_array( $side ) ? $this->flat_view( $side, $data ) : null;

				if ( null !== $view ) {
					$views[]                       = $view;
					$kept[ (string) $side['key'] ] = (string) $side['placement'];
				}
			}
		}

		foreach ( is_array( $data['chosen'] ?? null ) ? $data['chosen'] : array() as $index => $chosen ) {
			$side = $sides[ $index ] ?? null;

			if ( ! is_array( $side ) ) {
				continue;
			}

			// ADR-122: a print around the product (a bottle, a mug) shows only in part on each of Printify's photos.
			$wrapped = self::wrapped( $data, $side, $chosen );

			// ADR-119: a poster (a format group's side) is designed on a flat sheet of its whole print area, as in Gelato's editor;
			// so is a print around the product: Printify's mockups show it after the submission.
			if ( isset( $side['formats'] ) || $wrapped ) {
				[ $width_mm, $height_mm ] = array( (int) $side['width_mm'], (int) $side['height_mm'] );
				[ $sheet, $area ]         = ImageProbe::sheet( (float) $side['width_mm'], (float) $side['height_mm'] );
				$uploads                  = array( 'background' => $this->sheet_upload( $sheet ) );

				// The colour photos were only measured for the colour codes.
				foreach ( is_array( $data['files'][ $side['key'] ] ?? null ) ? $data['files'][ $side['key'] ] : array() as $name ) {
					if ( is_string( $name ) && '' !== $name ) {
						$this->files->delete( $name );
					}
				}
			} else {
				$uploads = $this->uploads( $base, $data['files'][ $side['key'] ] ?? array() );

				if ( array() === $uploads ) {
					continue;
				}

				[ $width_mm, $height_mm ] = ImageProbe::print_size( $chosen['box'], (int) $side['width_mm'], (int) $side['height_mm'] );
				$area                     = ImageProbe::fit( $chosen['box'], $width_mm, $height_mm );
			}

			$slots[ $side['key'] ] = (string) $chosen['slot'];
			$kept[ $side['key'] ]  = (string) $side['placement'];
			$view                  = array(
				'key'         => '',
				'name'        => (string) $side['name'],
				'x'           => (string) $area['x'],
				'y'           => (string) $area['y'],
				'width'       => (string) $area['width'],
				'height'      => (string) $area['height'],
				'width_mm'    => (string) $width_mm,
				'height_mm'   => (string) $height_mm,
				'dpi'         => (string) $side['dpi'],
				'thread_pack' => (string) ( $side['thread_pack'] ?? '' ),
				'remove'      => false,
				'clear'       => array(),
				'uploads'     => $uploads,
			);

			// ADR-122: where the product's views face on a print around it; their photos are the thumbnails of the same names.
			if ( $wrapped ) {
				$view['guides'] = self::guide_list( is_array( $data['centres'][ $side['key'] ] ?? null ) ? $data['centres'][ $side['key'] ] : array() );
			}

			// A format group's side (ADR-115): its sizes and values, and each size's print size and bleed.
			if ( is_array( $side['formats'] ?? null ) && array() !== $side['formats'] ) {
				$view['sizes']                   = array_map( 'strval', is_array( $side['sizes'] ?? null ) ? $side['sizes'] : array() );
				$view['colors']                  = array_map( 'strval', is_array( $side['colors'] ?? null ) ? $side['colors'] : array() );
				$view['formats']                 = self::formats( $side, $data );
				$scenes[ (string) $side['key'] ] = self::scenes( $data, (string) ( $side['group'] ?? '' ), (string) $chosen['slot'] );
			}

			$views[] = $view;
		}

		if ( array() === $kept ) {
			self::stop( DesignerException::IMPORT_NO_AREA );
		}

		$hex      = is_array( $data['hex'] ?? null ) ? $data['hex'] : array();
		$colors   = array_map( static fn( array $color ): string => $color['name'] . ' ' . ( is_string( $hex[ $color['key'] ] ?? null ) ? $hex[ $color['key'] ] : $color['hex'] ), $base->colors );
		$previous = get_current_user_id();

		// Stored as the administrator who started the import (audited as theirs).
		wp_set_current_user( $user );

		try {
			$this->service->save(
				$id,
				array(
					'name'             => $base->name,
					'description'      => $base->description,
					'base_price'       => PricingMath::price_string( $base->base_units, $this->decimals ),
					'extra_side_price' => PricingMath::price_string( $base->extra_side_units, $this->decimals ),
					'category_id'      => (string) ( $base->category_id ?? 0 ),
					'colors'           => implode( "\n", $colors ),
					'sizes'            => implode( ', ', $base->sizes ),
				),
				$views
			);
		} finally {
			wp_set_current_user( $previous );
		}

		// ADR-119: the provider's images, one thumbnail each, for the administrator's choice of mockups
		// (ADR-122: Printify's by camera name). Made once the sides are stored: the earlier ones go with them.
		$thumbs = $this->service->thumbnails( $id, is_array( $data['thumbs'] ?? null ) ? $data['thumbs'] : array(), $provider[ $printify ? 'printify_thumbs' : 'gelato_thumbs' ] ?? array(), $printify ? 'view' : 'Gelato' );

		$this->bases->set_provider(
			$id,
			(string) ( $data['source'] ?? '' ),
			(string) ( $data['ref'] ?? '' ),
			self::json(
				array(
					'placements'      => $kept,
					'variants'        => $provider['variants'] ?? array(),
					'slots'           => $slots,
					'flat'            => $provider['flat'] ?? false,
					'photos'          => $provider['photos'] ?? array(),
					'stitches'        => $provider['stitches'] ?? array(),
					'techniques'      => $provider['techniques'] ?? array(),
					'options'         => $provider['options'] ?? array(),
					'axes'            => $provider['axes'] ?? array(),
					'uids'            => $provider['uids'] ?? array(),
					'scenes'          => array_filter( $scenes ),
					'share_formats'   => $provider['share_formats'] ?? true,
					'gelato_thumbs'   => $printify ? array() : $thumbs,
					// A choice made before stays for the images there still are; none made is every image.
					'gelato_photos'   => $printify || null === $photos ? null : array_values( array_intersect( $photos, array_map( 'intval', array_keys( $thumbs ) ) ) ),
					'printify_thumbs' => $printify ? $thumbs : array(),
					'printify_photos' => ! $printify || null === $names ? null : array_values( array_intersect( $names, array_map( 'strval', array_keys( $thumbs ) ) ) ),
				)
			)
		);
		$this->bases->set_import(
			$id,
			self::READY,
			self::json(
				array(
					'source'   => $data['source'] ?? '',
					'ref'      => $data['ref'] ?? '',
					'renders'  => $data['renders'] ?? array(),
					'slots'    => $slots,
					'flat'     => true === ( $data['flat'] ?? false ),
					'printed'  => true === ( $data['printed'] ?? false ),
					'skipped'  => array_column( array_values( array_filter( $sides, static fn( mixed $side ): bool => is_array( $side ) && ! isset( $kept[ (string) ( $side['key'] ?? '' ) ] ) ) ), 'name' ),
					'started'  => $data['started'] ?? 0,
					'finished' => time(),
				)
			)
		);
	}

	/**
	 * The positions of a format group's environment photos among its
	 * render's images (ADR-117): every image of the group but the one its
	 * side is designed on (e.g. a poster on a wall beside the flat poster).
	 *
	 * @param array<string, mixed> $data  Import data.
	 * @param string               $group The format group.
	 * @param string               $slot  The side's own image.
	 * @return list<int>
	 */
	private static function scenes( array $data, string $group, string $slot ): array {
		$positions = array();

		foreach ( is_array( $data['images']['blank'] ?? null ) ? $data['images']['blank'] : array() as $at => $urls ) {
			if ( (string) $at !== $slot && is_numeric( $at ) && is_array( $urls ) && is_string( $urls[ $group ] ?? null ) ) {
				$positions[] = (int) $at;
			}
		}

		sort( $positions );

		return $positions;
	}

	/**
	 * A sheet (ImageProbe::sheet()) as an upload the service stores.
	 *
	 * @param string $png PNG bytes.
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 * @throws DesignerException When it cannot be written.
	 */
	private function sheet_upload( string $png ): array {
		$path = DesignerFiles::temporary_path( 'merchboard-sheet' );

		if ( false === file_put_contents( $path, $png ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file of the import.
			self::stop( DesignerException::FILE_MISSING );
		}

		return array(
			'name'     => 'background.png',
			'type'     => 'image/png',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $png ),
		);
	}

	/**
	 * A format group side's print size and bleed per size: the bleed from
	 * the finished size Gelato named (GelatoCatalog::bleed()).
	 *
	 * @param array<string, mixed> $side Import side.
	 * @param array<string, mixed> $data Import data.
	 * @return array<string, array{width_mm: float, height_mm: float, bleed?: array{0: float, 1: float}}>
	 */
	private static function formats( array $side, array $data ): array {
		$formats = array();
		$color   = is_array( $side['colors'] ?? null ) ? (string) reset( $side['colors'] ) : '';

		foreach ( is_array( $side['formats'] ?? null ) ? $side['formats'] : array() as $size => $format ) {
			$print = array( (float) ( $format['width_mm'] ?? 0 ), (float) ( $format['height_mm'] ?? 0 ) );
			$uid   = $data['uids'][ $color ][ (string) $size ] ?? '';
			$trim  = is_string( $uid ) ? ( $data['trims'][ $uid ] ?? array() ) : array();
			$bleed = GelatoCatalog::bleed( $print, is_array( $trim ) && 2 === count( $trim ) ? array( (float) $trim[0], (float) $trim[1] ) : null );

			$formats[ (string) $size ] = array(
				'width_mm'  => $print[0],
				'height_mm' => $print[1],
			) + ( null === $bleed ? array() : array( 'bleed' => $bleed ) );
		}

		return $formats;
	}

	/**
	 * A flat side as the service takes it, or null when its files are missing.
	 * The print area keeps the print file's shape; without a pattern it is
	 * the whole sheet.
	 *
	 * @param array<mixed>         $side Side.
	 * @param array<string, mixed> $data Import data.
	 * @return array<string, mixed>|null
	 */
	private function flat_view( array $side, array $data ): ?array {
		$key     = (string) ( $side['key'] ?? '' );
		$size    = is_array( $data['sizes'][ $key ] ?? null ) ? array_values( $data['sizes'][ $key ] ) : array( 0, 0 );
		$uploads = array();

		foreach ( is_array( $data['files'][ $key ] ?? null ) ? $data['files'][ $key ] : array() as $layer => $name ) {
			$path = is_string( $name ) && '' !== $name ? $this->files->path( $name ) : null;

			if ( null !== $path ) {
				$uploads[ (string) $layer ] = array(
					'name'     => 'background.png',
					'type'     => 'image/png',
					'tmp_name' => $path,
					'error'    => UPLOAD_ERR_OK,
					'size'     => (int) filesize( $path ),
				);
			}
		}

		if ( ! isset( $uploads['background'] ) || (int) $size[0] < 1 ) {
			return null;
		}

		$scale = (int) $size[0] / max( 1, (int) ( $side['template_width'] ?? $size[0] ) );
		$area  = is_array( $side['area'] ?? null ) ? $side['area'] : array();
		$box   = array(
			'x'      => (int) round( (int) ( $area['x'] ?? 0 ) * $scale ),
			'y'      => (int) round( (int) ( $area['y'] ?? 0 ) * $scale ),
			'width'  => (int) max( 1, round( (int) ( $area['width'] ?? $size[0] ) * $scale ) ),
			'height' => (int) max( 1, round( (int) ( $area['height'] ?? $size[1] ) * $scale ) ),
		);
		$box   = ImageProbe::fit( $box, (int) $side['width_mm'], (int) $side['height_mm'] );

		return array(
			'key'         => '',
			'name'        => (string) $side['name'],
			'x'           => (string) $box['x'],
			'y'           => (string) $box['y'],
			'width'       => (string) $box['width'],
			'height'      => (string) $box['height'],
			'width_mm'    => (string) $side['width_mm'],
			'height_mm'   => (string) $side['height_mm'],
			'dpi'         => (string) $side['dpi'],
			'thread_pack' => '',
			'flat'        => true !== ( $data['printed'] ?? false ),
			'remove'      => false,
			'clear'       => array(),
			'uploads'     => $uploads,
		) + ( is_array( $side['sizes'] ?? null ) && array() !== $side['sizes'] ? array( 'sizes' => array_values( array_filter( $side['sizes'], 'is_string' ) ) ) : array() );
	}

	/**
	 * A side's photos as uploads: the first colour with a photo as the
	 * background, the others as their own backgrounds.
	 *
	 * @param BaseProduct           $base  Base product.
	 * @param array<string, string> $files Stored photo by colour key ('' for none).
	 * @return array<string, array<string, mixed>>
	 */
	private function uploads( BaseProduct $base, array $files ): array {
		$uploads = array();

		foreach ( $base->colors as $color ) {
			$path = '' === ( $files[ $color['key'] ] ?? '' ) ? null : $this->files->path( $files[ $color['key'] ] );

			if ( null === $path ) {
				continue;
			}

			$uploads[ array() === $uploads ? 'background' : BaseView::color_layer( $color['key'] ) ] = array(
				'name'     => 'background.png',
				'type'     => 'image/png',
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => (int) filesize( $path ),
			);
		}

		return $uploads;
	}

	/**
	 * Stores a PNG among the designer's files.
	 *
	 * @param string $png Bytes.
	 * @return string The stored name.
	 * @throws DesignerException When it is refused.
	 */
	private function store( string $png ): string {
		return $this->stored( $png )['file'];
	}

	/**
	 * A PNG as a stored designer file, with its size.
	 *
	 * @param string $png PNG bytes.
	 * @return array<string, mixed>
	 * @throws DesignerException When it cannot be stored.
	 */
	private function stored( string $png ): array {
		$path = DesignerFiles::temporary_path( 'merchboard-import' );

		try {
			if ( false === file_put_contents( $path, $png ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file of the import.
				self::stop( DesignerException::FILE_MISSING );
			}

			return $this->files->store(
				array(
					'name'     => 'background.png',
					'tmp_name' => $path,
					'error'    => UPLOAD_ERR_OK,
					'size'     => strlen( $png ),
				),
				true
			);
		} finally {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Marks the import failed, deletes its photos and tells the administrator once.
	 *
	 * @param int    $id     Base product.
	 * @param string $reason Reason.
	 * @param string $call   The provider's refused call.
	 * @param string $detail The provider's message.
	 */
	private function fail( int $id, string $reason, string $call = '', string $detail = '' ): void {
		try {
			$import = $this->bases->import( $id );

			if ( null === $import || self::RUNNING !== $import['state'] ) {
				return;
			}

			$data = $this->current ?? $import['data'];

			foreach ( is_array( $data['files'] ?? null ) ? $data['files'] : array() as $side ) {
				foreach ( is_array( $side ) ? $side : array() as $name ) {
					if ( is_string( $name ) && '' !== $name ) {
						$this->files->delete( $name );
					}
				}
			}

			foreach ( is_array( $data['thumbs'] ?? null ) ? $data['thumbs'] : array() as $thumb ) {
				if ( is_array( $thumb ) && is_string( $thumb['file'] ?? null ) ) {
					$this->files->delete( $thumb['file'] );
				}
			}

			// The renders stored in this round too: the round's own copy can be older.
			$stored = is_array( $import['data']['renders'] ?? null ) ? $import['data']['renders'] : array();
			$this->cleanup( $id, array_merge( $data, array( 'renders' => array_merge( $stored, is_array( $data['renders'] ?? null ) ? $data['renders'] : array() ) ) ) );

			// The renders' images and measurements stay for the diagnosis; "Try again" starts without them.
			unset( $data['files'], $data['chosen'], $data['hex'], $data['thumbs'] );

			$data['error']  = $reason;
			$data['call']   = $call;
			$data['detail'] = '' !== $detail ? $detail : ( $data['detail'] ?? '' );
			$data['failed'] = time();

			$this->bases->set_import( $id, self::FAILED, self::json( $data ) );
			$base = $this->bases->find( $id );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'A failed catalog import could not be stored.',
				array(
					'base_id'   => $id,
					'exception' => $e::class,
				)
			);

			return;
		}

		$this->logger->warning(
			'The catalog import of a base product failed.',
			array(
				'base_id' => $id,
				'reason'  => $reason,
				'call'    => $call,
			)
		);

		do_action(
			'merchboard_notify',
			DesignerNotifications::IMPORT_FAILED,
			null,
			array(
				'title'    => $base->name ?? '',
				'reason'   => $reason,
				'provider' => self::provider_name( (string) ( $data['source'] ?? '' ) ),
			),
			'designer_import:' . $id . ':' . (int) ( $data['started'] ?? 0 )
		);
	}

	/**
	 * Removes what the renders left at the provider (ADR-122: Printify's
	 * temporary products); what cannot be removed is logged. Never throws.
	 *
	 * @param int                  $id   Base product.
	 * @param array<string, mixed> $data Import data.
	 */
	private function cleanup( int $id, array $data ): void {
		$renderer = $this->renderer( is_string( $data['source'] ?? null ) ? $data['source'] : '' );

		if ( ! $renderer instanceof CatalogCleanup || ! is_array( $data['renders'] ?? null ) || array() === $data['renders'] ) {
			return;
		}

		try {
			$left = $renderer->forget( $data );
		} catch ( Throwable $e ) {
			$left = array( $e::class );
		}

		if ( array() !== $left ) {
			$this->logger->warning(
				'A catalog import could not remove its temporary products at the provider.',
				array(
					'base_id' => $id,
					'source'  => $data['source'] ?? '',
					'left'    => $left,
				)
			);
		}
	}

	/**
	 * Stores the import's progress.
	 *
	 * @param int                  $id   Base product.
	 * @param array<string, mixed> $data Import data.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function persist( int $id, array $data ): void {
		$data['updated'] = time();
		$this->current   = $data;
		$this->bases->set_import( $id, self::RUNNING, self::json( $data ) );
	}

	/**
	 * Queues the next round; false when the queue refused it.
	 *
	 * @param int $id    Base product.
	 * @param int $delay Seconds.
	 */
	private function schedule( int $id, int $delay ): bool {
		if ( $this->queue->enqueue( self::HOOK, array( $id ), $delay ) ) {
			return true;
		}

		$this->logger->warning( 'The catalog import of a base product could not be queued.', array( 'base_id' => $id ) );

		return false;
	}

	/**
	 * JSON of a value.
	 *
	 * @param array<mixed> $value Value.
	 * @throws DatabaseException When it cannot be encoded.
	 */
	private static function json( array $value ): string {
		$json = wp_json_encode( $value );

		if ( false === $json ) {
			throw new DatabaseException( 'Could not encode the import of the base product.' );
		}

		return $json;
	}

	/**
	 * A provider's refusal for the diagnosis.
	 *
	 * @param PrintfulException|GelatoException|ProdigiException|PrintifyException $e Refusal.
	 * @return array{reason: string, call: string, detail: string}
	 */
	private static function refusal( PrintfulException|GelatoException|ProdigiException|PrintifyException $e ): array {
		return array(
			'reason' => $e->reason(),
			'call'   => $e->call,
			'detail' => $e->detail,
		);
	}

	/**
	 * The name of a source for the administrator.
	 *
	 * @param string $source Source.
	 */
	public static function provider_name( string $source ): string {
		return match ( $source ) {
			GelatoCatalog::SOURCE   => 'Gelato',
			ProdigiCatalog::SOURCE  => 'Prodigi',
			PrintifyCatalog::SOURCE => 'Printify',
			default                 => 'Printful',
		};
	}

	/**
	 * Refuses users without `merchboard_manage_designer`.
	 *
	 * @throws DesignerException When the user may not.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			self::stop( DesignerException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Stops with a reason.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function stop( string $reason ): never {
		throw new DesignerException( $reason );
	}

	/**
	 * Stops because the provider is switched off or has no credentials.
	 *
	 * @param string $source `printful`, `gelato` or `prodigi`.
	 * @throws GelatoException For Gelato.
	 * @throws ProdigiException For Prodigi.
	 * @throws PrintifyException For Printify.
	 * @throws PrintfulException For Printful.
	 */
	private static function unavailable( string $source ): never {
		if ( GelatoCatalog::SOURCE === $source ) {
			throw new GelatoException( GelatoException::NOT_CONFIGURED );
		}

		if ( ProdigiCatalog::SOURCE === $source ) {
			throw new ProdigiException( ProdigiException::NOT_CONFIGURED );
		}

		if ( PrintifyCatalog::SOURCE === $source ) {
			throw new PrintifyException( PrintifyException::NOT_CONFIGURED );
		}

		throw new PrintfulException( PrintfulException::NOT_CONFIGURED );
	}
}
