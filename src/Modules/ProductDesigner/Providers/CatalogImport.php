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
 * 1. **render:** the provider renders the product twice: every colour with
 *    the transparent file in each placement, and the first colour with a
 *    marker colour per side. Polled until done (at most 40 times).
 * 2. **measure:** per photo of the first colour, ImageProbe compares the
 *    two renders; each side's print area is where its marker shows most.
 * 3. **download:** the blank photo of each colour and side, as a PNG of the
 *    side's size (Gelato's colour codes are measured on them).
 * 4. **save:** the sides are stored through BaseProductService as the
 *    administrator who started the import (the first colour's photo as the
 *    background, the others as the colours' own backgrounds), the provider's
 *    placement and photo per side are stored, and the state is `ready`.
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

	public const READY = 'ready';

	public const FAILED = 'failed';

	private const LOCK = 'merchboard_designer_import_';

	private const MAX_POLLS = 40;

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
	 * Seconds before a round looks again while another holds the lock.
	 */
	private const BUSY_DELAY = 15;

	/**
	 * The smallest share of a photo a side's marker must cover.
	 */
	private const MIN_SHARE = 0.002;

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
		private ?GelatoCatalog $gelato = null
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
			default                 => null,
		};
	}

	/**
	 * Creates a draft base product from a catalog product and starts its
	 * import. Picked: `colors` (names), `sizes`, `sides` (placements) and
	 * `base_price`.
	 *
	 * @param string               $source `printful` or `gelato`.
	 * @param string               $ref    The provider's product (Printful) or template (Gelato).
	 * @param array<string, mixed> $picked What the administrator ticked.
	 * @return int The base product's id.
	 * @throws DesignerException When the user may not, nothing was ticked or a value is refused.
	 * @throws PrintfulException When Printful refuses or fails.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( string $source, string $ref, array $picked ): int {
		self::authorize();

		$renderer = $this->renderer( $source );

		if ( null === $renderer || ! $renderer->available() ) {
			self::unavailable( $source );
		}

		$choice = $renderer->choice( $ref );
		$list   = static fn( string $key ): array => array_map( 'strval', array_filter( is_array( $picked[ $key ] ?? null ) ? $picked[ $key ] : array(), 'is_scalar' ) );
		$sizes  = array_values( array_intersect( $choice['sizes'], $list( 'sizes' ) ) );
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

		$sides = array();

		foreach ( $choice['sides'] as $side ) {
			$key = substr( sanitize_title( $side['name'] ), 0, 32 );

			if ( '' !== $key && ! isset( $sides[ $key ] ) && in_array( $side['placement'], $list( 'sides' ), true ) && count( $sides ) < count( ImageProbe::MARKERS ) ) {
				$sides[ $key ] = array_merge( array( 'key' => $key ), $side );
			}
		}

		if ( array() === $colors || array() === $sizes || array() === $sides ) {
			self::stop( DesignerException::BASE_PICK );
		}

		$id   = $this->service->save(
			null,
			array(
				'name'        => $choice['name'],
				'description' => $choice['description'],
				'base_price'  => is_string( $picked['base_price'] ?? null ) ? $picked['base_price'] : '',
				'category_id' => '0',
				'colors'      => implode( "\n", $colors ),
				'sizes'       => implode( ', ', $sizes ),
			),
			array()
		);
		$base = $this->bases->find( $id );
		$keys = array_column( $base->colors ?? array(), 'key', 'name' );
		$map  = array();
		$data = array();

		foreach ( $render as $name => $variant ) {
			$key          = (string) ( $keys[ $name ] ?? '' );
			$data[ $key ] = $variant;

			foreach ( $sizes as $size ) {
				if ( isset( $choice['variants'][ $name ][ $size ] ) ) {
					$map[ $key ][ $size ] = $choice['variants'][ $name ][ $size ];
				}
			}
		}

		unset( $data[''], $map[''] );

		$this->bases->set_provider( $id, $source, $ref, self::json( array( 'placements' => array_column( $sides, 'placement', 'key' ), 'variants' => $map, 'slots' => array() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->start(
			$id,
			array(
				'source'      => $source,
				'ref'         => $ref,
				'sides'       => array_values( $sides ),
				'colors'      => $data,
				'measure_hex' => GelatoCatalog::SOURCE === $source,
			)
		);

		return $id;
	}

	/**
	 * Starts a failed import over.
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

		if ( self::FAILED !== $import['state'] ) {
			return;
		}

		$this->start( $id, array_intersect_key( $import['data'], array_flip( array( 'source', 'ref', 'sides', 'colors', 'measure_hex' ) ) ) );
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
			} catch ( PrintfulException | GelatoException $e ) {
				$answer[ 'render ' . $kind ] = self::refusal( $e );
			}
		}

		try {
			return array_merge( $answer, $renderer->diagnose( $provider['ref'] ) );
		} catch ( PrintfulException | GelatoException $e ) {
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
		} catch ( PrintfulException | GelatoException $e ) {
			if ( PrintfulException::RATE_LIMITED === $e->reason() || GelatoException::RATE_LIMITED === $e->reason() ) {
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
				'phase'   => 'render',
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

			return;
		}

		$delay = match ( $data['phase'] ?? 'render' ) {
			'measure'  => $this->measure( $renderer, $data ),
			'download' => $this->collect( $id, $renderer, $data ),
			default    => $this->render( $id, $renderer, $data ),
		};

		$this->persist( $id, $data );
		$this->schedule( $id, $delay );
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
		$started = false;

		foreach ( array( 'blank', 'marker' ) as $kind ) {
			if ( ! isset( $renders[ $kind ] ) ) {
				$renders[ $kind ] = array(
					'key'    => $renderer->render( $kind, $data ),
					'status' => 'pending',
					'polls'  => 0,
				);
				$data['renders']  = $renders;
				$started          = true;
				// Stored at once: a render is never started twice.
				$this->persist( $id, $data );
			}
		}

		if ( $started ) {
			return $renderer->poll_delay();
		}

		foreach ( $renders as $kind => $render ) {
			if ( ! is_array( $render ) || 'pending' !== ( $render['status'] ?? '' ) ) {
				continue;
			}

			$state = $renderer->check( (string) $render['key'], $data );
			$polls = (int) ( $render['polls'] ?? 0 ) + 1;

			$renders[ $kind ]['polls'] = $polls;

			if ( 'failed' === $state['status'] ) {
				$data['renders'] = $renders;
				$data['detail']  = $kind . ': ' . ( $state['error'] ?? '' );
				$this->current   = $data;
				self::stop( DesignerException::IMPORT_RENDER );
			}

			if ( 'done' === $state['status'] ) {
				$renders[ $kind ]['status'] = 'done';
				$data['images'][ $kind ]    = $state['images'] ?? array();
			} elseif ( $polls >= self::MAX_POLLS ) {
				self::stop( DesignerException::IMPORT_TIMEOUT );
			}
		}

		$data['renders'] = $renders;

		if ( array( 'done' ) !== array_values( array_unique( array_column( $renders, 'status' ) ) ) ) {
			return $renderer->poll_delay();
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
		$first    = (string) array_key_first( is_array( $data['colors'] ?? null ) ? $data['colors'] : array() );
		$blank    = is_array( $data['images']['blank'] ?? null ) ? $data['images']['blank'] : array();
		$marker   = is_array( $data['images']['marker'] ?? null ) ? $data['images']['marker'] : array();
		$sides    = is_array( $data['sides'] ?? null ) ? array_values( $data['sides'] ) : array();
		$measured = is_array( $data['measured'] ?? null ) ? $data['measured'] : array();
		$done     = 0;

		foreach ( $marker as $slot => $urls ) {
			$slot = (string) $slot;

			if ( isset( $measured[ $slot ] ) || ! is_string( $urls[ $first ] ?? null ) || ! is_string( $blank[ $slot ][ $first ] ?? null ) ) {
				continue;
			}

			if ( $done >= self::MEASURES_PER_RUN ) {
				$data['measured'] = $measured;

				return 0;
			}

			$found = ImageProbe::markers( $renderer->download( $blank[ $slot ][ $first ] ), $renderer->download( $urls[ $first ] ), count( $sides ) );

			$measured[ $slot ] = $found;
			++$done;
		}

		if ( array() === $measured ) {
			self::stop( DesignerException::IMPORT_NO_PHOTOS );
		}

		$data['measured'] = $measured;
		$chosen           = array();

		foreach ( array_keys( $sides ) as $index ) {
			$best = null;

			foreach ( $measured as $slot => $found ) {
				$hit    = is_array( $found ) && is_array( $found['found'] ?? null ) ? ( $found['found'][ $index ] ?? null ) : null;
				$width  = is_array( $found ) ? (int) ( $found['width'] ?? 0 ) : 0;
				$height = is_array( $found ) ? (int) ( $found['height'] ?? 0 ) : 0;
				$count  = is_array( $hit ) ? (int) ( $hit['count'] ?? 0 ) : 0;

				if ( is_array( $hit ) && $count >= self::MIN_SHARE * $width * $height && $count > ( $best['count'] ?? 0 ) ) {
					$best = array(
						'slot'   => (string) $slot,
						'count'  => $count,
						'box'    => array(
							'x'      => (int) ( $hit['x'] ?? 0 ),
							'y'      => (int) ( $hit['y'] ?? 0 ),
							'width'  => max( 1, (int) ( $hit['width'] ?? 0 ) ),
							'height' => max( 1, (int) ( $hit['height'] ?? 0 ) ),
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
			$key = (string) ( $sides[ $index ]['key'] ?? '' );

			foreach ( $colors as $color ) {
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

		$data['phase'] = 'save';

		return 0;
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

		$sides = is_array( $data['sides'] ?? null ) ? array_values( $data['sides'] ) : array();
		$views = array_map(
			static fn( BaseView $view ): array => array(
				'key'    => $view->key,
				'name'   => $view->name,
				'remove' => true,
			),
			$base->views
		);
		$slots = array();
		$kept  = array();

		foreach ( is_array( $data['chosen'] ?? null ) ? $data['chosen'] : array() as $index => $chosen ) {
			$side    = $sides[ $index ] ?? null;
			$uploads = $this->uploads( $base, is_array( $side ) ? ( $data['files'][ $side['key'] ] ?? array() ) : array() );

			if ( ! is_array( $side ) || array() === $uploads ) {
				continue;
			}

			$area                  = ImageProbe::fit( $chosen['box'], (int) $side['width_mm'], (int) $side['height_mm'] );
			$slots[ $side['key'] ] = (string) $chosen['slot'];
			$kept[ $side['key'] ]  = (string) $side['placement'];
			$views[]               = array(
				'key'       => '',
				'name'      => (string) $side['name'],
				'x'         => (string) $area['x'],
				'y'         => (string) $area['y'],
				'width'     => (string) $area['width'],
				'height'    => (string) $area['height'],
				'width_mm'  => (string) $side['width_mm'],
				'height_mm' => (string) $side['height_mm'],
				'dpi'       => (string) $side['dpi'],
				'remove'    => false,
				'clear'     => array(),
				'uploads'   => $uploads,
			);
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
					'name'        => $base->name,
					'description' => $base->description,
					'base_price'  => PricingMath::price_string( $base->base_units, $this->decimals ),
					'category_id' => (string) ( $base->category_id ?? 0 ),
					'colors'      => implode( "\n", $colors ),
					'sizes'       => implode( ', ', $base->sizes ),
				),
				$views
			);
		} finally {
			wp_set_current_user( $previous );
		}

		$provider = $this->bases->provider( $id );

		$this->bases->set_provider( $id, (string) ( $data['source'] ?? '' ), (string) ( $data['ref'] ?? '' ), self::json( array( 'placements' => $kept, 'variants' => $provider['variants'] ?? array(), 'slots' => $slots ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->bases->set_import(
			$id,
			self::READY,
			self::json(
				array(
					'source'   => $data['source'] ?? '',
					'ref'      => $data['ref'] ?? '',
					'renders'  => $data['renders'] ?? array(),
					'slots'    => $slots,
					'started'  => $data['started'] ?? 0,
					'finished' => time(),
				)
			)
		);
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
		$path = wp_tempnam( 'merchboard-import' );

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
			)['file'];
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

			// The renders' images and measurements stay for the diagnosis; "Try again" starts without them.
			unset( $data['files'], $data['chosen'], $data['hex'] );

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
				'provider' => GelatoCatalog::SOURCE === ( $data['source'] ?? '' ) ? 'Gelato' : 'Printful',
			),
			'designer_import:' . $id . ':' . (int) ( $data['started'] ?? 0 )
		);
	}

	/**
	 * Stores the import's progress.
	 *
	 * @param int                  $id   Base product.
	 * @param array<string, mixed> $data Import data.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function persist( int $id, array $data ): void {
		$this->current = $data;
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
	 * @param PrintfulException|GelatoException $e Refusal.
	 * @return array{reason: string, call: string, detail: string}
	 */
	private static function refusal( PrintfulException|GelatoException $e ): array {
		return array(
			'reason' => $e->reason(),
			'call'   => $e->call,
			'detail' => $e->detail,
		);
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
	 * @param string $source `printful` or `gelato`.
	 * @throws GelatoException For Gelato.
	 * @throws PrintfulException For Printful.
	 */
	private static function unavailable( string $source ): never {
		if ( GelatoCatalog::SOURCE === $source ) {
			throw new GelatoException( GelatoException::NOT_CONFIGURED );
		}

		throw new PrintfulException( PrintfulException::NOT_CONFIGURED );
	}
}
