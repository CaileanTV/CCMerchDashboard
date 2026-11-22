<?php
/**
 * Mockups of a submitted design from a Gelato template product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\Designs\ColorDesigns;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\WooCommerce\JobQueue;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-082 pkt. 3–4 (step 30.13): when a design of a Gelato base product is
 * submitted while Gelato is on (`merchboard_designer_submitted`), one task
 * is queued for the version. The Action Scheduler job
 * `merchboard_designer_gelato` (a lock per version) creates a product from
 * the base product's template in the API-only store, with one template
 * variant per chosen colour (the first chosen size it has) and each used
 * side's print file as a signed URL (SignedPrintFiles) in its placeholder.
 * The Gelato product's id is stored on the version. The job then polls the
 * product every 30 seconds (at most 40 times) until Gelato has made its
 * images, downloads them (validated like uploads) and puts them in place of
 * the creator's mockups: for a base product from the catalog import (review
 * 30.15) each used side's image of each colour (its position among the
 * variant's images was measured on import), else on the first used side an
 * image tied to variants for their colour, and the product's preview for
 * the first colour without one.
 * Gelato's image fields are taken from its API reference and not yet
 * verified against a real answer.
 *
 * The product's images are taken only once its status is `active`.
 * Meanwhile the version's `mockup_state` is `running`, and it cannot be
 * approved. `ready` when the images are stored; `failed` (the browser's
 * mockups stay, the administrator is told) on `publishing_error`, a
 * timeout or when Gelato refuses. A 429 waits for `Retry-After`. The
 * product stays in the store (Gelato has no call to delete it). Never throws.
 *
 * Gelato's images of an embroidered side are flat, so the browser's stitched
 * images stay (ADR-085): a design with only such sides makes no product.
 *
 * A used format side of a poster (ADR-115) gets a task of its own (ADR-117):
 * a product of one variant, its group's largest size in the side's
 * orientation, with the side's print file. Its images at the positions the
 * import found besides the side's own (`scenes`, e.g. the poster on a wall)
 * are added as environment photos (`_scene-<n>` per colour); the browser's
 * mockups stay. At most CREATES_PER_RUN products are made per round, and the
 * version is `running` until every task is done; one that failed makes it
 * `failed` and keeps what the others gave.
 */
class GelatoMockups {

	public const HOOK = 'merchboard_designer_gelato';

	/**
	 * The side of the version's one task (the product holds every side).
	 */
	public const TASK_SIDE = 'all';

	private const LOCK = 'merchboard_designer_gelato_';

	private const POLL_DELAY = 30;

	private const MAX_POLLS = 40;

	/**
	 * Products made per round (ADR-117: up to 16 format sides).
	 */
	private const CREATES_PER_RUN = 4;

	/**
	 * The transient of a draft's preview (ADR-119), by version.
	 */
	private const PREVIEW = 'merchboard_gelato_preview_';

	/**
	 * Products a preview makes at most, and images it shows at most.
	 */
	private const PREVIEW_PRODUCTS = 4;

	private const PREVIEW_IMAGES = 6;

	/**
	 * Seconds a preview is waited for before it is given up.
	 */
	private const PREVIEW_WAIT = 600;

	/**
	 * Whether a base product's designs can be previewed with Gelato's own
	 * images (ADR-119, "Se det færdige produkt"). Each preview leaves hidden
	 * products in the store: Gelato has no call to delete them.
	 *
	 * @param BaseProduct $base Base product.
	 */
	public function can_preview( BaseProduct $base ): bool {
		return 'gelato' === $base->source && $this->api->available();
	}

	/**
	 * Starts a preview of a draft (ADR-119): in its first colour, a hidden
	 * product per used format side of it (its group's largest size), or one
	 * product with every used side for other products (the colour's first
	 * chosen size), at most PREVIEW_PRODUCTS. The draft's print files reach
	 * Gelato as signed URLs.
	 *
	 * @param int $id Draft version.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function start_preview( int $id ): void {
		$version  = $this->products->version( $id );
		$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );
		$base     = null === $version ? null : $this->bases->find( (int) $version['base_id'] );

		if ( null === $version || null === $provider || null === $base ) {
			self::fail( GelatoException::INVALID );
		}

		$colors = json_decode( (string) $version['colors'], true );
		$color  = is_array( $colors ) && is_string( $colors[0] ?? null ) ? $colors[0] : '';
		$part   = ColorDesigns::part_of( self::design_of( $version ), $color );
		$used   = self::sides( $version, $provider['placements'], $part );
		$items  = array();

		foreach ( $used as $side ) {
			$view = self::view_of( $base, $side );

			if ( null === $view || array() === $view->formats || ! in_array( $color, $view->colors, true ) || count( $items ) >= self::PREVIEW_PRODUCTS ) {
				continue;
			}

			$variant = (string) ( $provider['variants'][ $color ][ self::largest( $view ) ] ?? '' );

			if ( '' !== $variant ) {
				$items[] = array(
					'variant' => $variant,
					'images'  => array(
						array(
							'name'      => (string) $provider['placements'][ $side ],
							'fileUrl'   => SignedPrintFiles::url( $id, $side, null, $part ),
							'fitMethod' => 'meet',
						),
					),
				);
			}
		}

		$others = self::photographed( $used, $base );
		$first  = array_search( $color, $this->variants( (string) $provider['ref'], $version, $base, $provider['variants'] ), true );

		if ( array() !== $others && false !== $first && count( $items ) < self::PREVIEW_PRODUCTS ) {
			$items[] = array(
				'variant' => (string) $first,
				'images'  => array_map(
					static fn( string $side ): array => array(
						'name'      => (string) $provider['placements'][ $side ],
						'fileUrl'   => SignedPrintFiles::url( $id, $side, null, $part ),
						'fitMethod' => 'meet',
					),
					$others
				),
			);
		}

		if ( array() === $items ) {
			self::fail( GelatoException::INVALID );
		}

		$made = array();

		foreach ( $items as $item ) {
			$answer  = $this->api->create_from_template(
				array(
					'templateId'                => (string) $provider['ref'],
					'title'                     => (string) $version['title'],
					'description'               => '',
					'isVisibleInTheOnlineStore' => false,
					'variants'                  => array(
						array(
							'templateVariantId' => $item['variant'],
							'imagePlaceholders' => $item['images'],
						),
					),
				)
			);
			$product = is_scalar( $answer['id'] ?? null ) ? (string) $answer['id'] : '';

			if ( 1 !== preg_match( '/^[A-Za-z0-9-]{1,100}$/', $product ) ) {
				self::fail( GelatoException::INVALID );
			}

			$made[] = array(
				'product' => $product,
				'variant' => $item['variant'],
			);
		}

		set_transient(
			self::PREVIEW . $id,
			array(
				'items'   => $made,
				'color'   => $color,
				'started' => time(),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * A preview's state and, when Gelato has made every product's images,
	 * those the administrator chose (all without a choice), at most
	 * PREVIEW_IMAGES, as data URLs: the dashboard's CSP takes no other host.
	 *
	 * @param int $id Draft version.
	 * @return array{status: string, images: list<array{side: string, color: string, url: string}>}
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function preview( int $id ): array {
		$map      = get_transient( self::PREVIEW . $id );
		$version  = $this->products->version( $id );
		$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );

		if ( ! is_array( $map ) || ! is_array( $map['items'] ?? null ) || null === $provider ) {
			return array(
				'status' => 'none',
				'images' => array(),
			);
		}

		$color = is_string( $map['color'] ?? null ) ? $map['color'] : '';
		$late  = time() - (int) ( $map['started'] ?? 0 ) > self::PREVIEW_WAIT;
		$urls  = array();

		foreach ( $map['items'] as $item ) {
			$answer = $this->api->product( (string) ( $item['product'] ?? '' ) );
			$status = is_string( $answer['status'] ?? null ) ? $answer['status'] : '';
			$list   = 'active' === $status ? ( GelatoCatalog::variant_images( $answer, array( $color => (string) ( $item['variant'] ?? '' ) ) )[ $color ] ?? array() ) : array();

			if ( 'publishing_error' === $status ) {
				return array(
					'status' => 'failed',
					'images' => array(),
				);
			}

			if ( array() === $list ) {
				return array(
					'status' => $late ? 'failed' : 'pending',
					'images' => array(),
				);
			}

			foreach ( $list as $position => $url ) {
				if ( array() === $provider['gelato_thumbs'] || self::picked( $provider, (int) $position ) ) {
					$urls[] = $url;
				}
			}
		}

		$images = array();

		foreach ( array_slice( $urls, 0, self::PREVIEW_IMAGES ) as $url ) {
			$bytes = $this->api->download( $url );
			$info  = getimagesizefromstring( $bytes );

			if ( is_array( $info ) && in_array( $info['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
				$images[] = array(
					'side'  => self::TASK_SIDE,
					'color' => $color,
					'url'   => 'data:' . $info['mime'] . ';base64,' . base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- An image for the dashboard's CSP.
				);
			}
		}

		return array(
			'status' => array() === $images ? 'failed' : 'done',
			'images' => $images,
		);
	}

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb     Database (the named lock).
	 * @param DesignerProductRepository $products Designer products.
	 * @param BaseProductRepository     $bases    Base products.
	 * @param GelatoApi                 $api      Gelato.
	 * @param ProviderTaskRepository    $tasks    Mockup tasks.
	 * @param DesignerFiles             $files    Files (stores downloads by moving them).
	 * @param JobQueue                  $queue    Job queue.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct(
		private wpdb $wpdb,
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private GelatoApi $api,
		private ProviderTaskRepository $tasks,
		private DesignerFiles $files,
		private JobQueue $queue,
		private Logger $logger
	) {
	}

	/**
	 * Queues the task of a submitted version of a Gelato base product;
	 * never throws.
	 *
	 * @param mixed $version_id Version.
	 */
	public function submitted( mixed $version_id ): void {
		$id = is_numeric( $version_id ) ? (int) $version_id : 0;

		try {
			$version  = $id > 0 && $this->api->available() ? $this->products->version( $id ) : null;
			$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );
			$base     = null === $version ? null : $this->bases->find( (int) $version['base_id'] );

			if ( null === $version || null === $provider || null === $base || 'gelato' !== $provider['source'] ) {
				return;
			}

			$photographed = self::photographed( self::sides( $version, $provider['placements'] ), $base );
			$scenes       = self::scene_sides( $version, $provider, $base );

			if ( array() === $photographed && array() === $scenes ) {
				return;
			}

			if ( array() !== $photographed ) {
				$this->tasks->create( $id, 'gelato', self::TASK_SIDE, '{}' );
			}

			foreach ( $scenes as $side ) {
				$this->tasks->create( $id, 'gelato', $side, '{}' );
			}

			$this->products->set_mockup_state( $id, PrintfulMockups::RUNNING );

			if ( ! $this->schedule( $id, 0 ) ) {
				$this->failed( $id, 'queue' );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The Gelato mockups of a design could not be started.',
				array(
					'version_id' => $id,
					'exception'  => $e::class,
				)
			);
		}
	}

	/**
	 * Creates, polls and collects the version's task (the job); never throws.
	 *
	 * @param mixed $version_id Version.
	 */
	public function run( mixed $version_id ): void {
		$id   = is_numeric( $version_id ) ? (int) $version_id : 0;
		$wpdb = $this->wpdb;

		if ( $id < 1 ) {
			return;
		}

		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK . $id, 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		if ( ! is_numeric( $acquired ) || 1 !== (int) $acquired ) {
			// Another run holds the version: look again a little later (review 30.15).
			$this->schedule( $id, self::POLL_DELAY );

			return;
		}

		try {
			$this->step( $id );
		} catch ( GelatoException $e ) {
			if ( GelatoException::RATE_LIMITED === $e->reason() ) {
				$this->schedule( $id, $e->retry_after );
			} else {
				$this->failed( $id, $e->reason() );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The Gelato mockups of a design failed.',
				array(
					'version_id' => $id,
					'exception'  => $e::class,
				)
			);
			$this->failed( $id, 'error' );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
		}
	}

	/**
	 * One round: create the product, or poll it.
	 *
	 * @param int $id Version.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DatabaseException When the designer's data cannot be read or stored.
	 * @throws DesignerException When a mockup is not a valid image.
	 */
	private function step( int $id ): void {
		$version  = $this->products->version( $id );
		$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );
		$base     = null === $version ? null : $this->bases->find( (int) $version['base_id'] );

		if ( null === $version || DesignerProductRepository::PENDING !== $version['status'] ) {
			// Withdrawn or decided meanwhile: nothing more to do.
			return;
		}

		if ( null === $provider || null === $base ) {
			$this->failed( $id, 'error' );

			return;
		}

		$created = 0;
		$tasks   = array_values( array_filter( $this->tasks->for_version( $id ), static fn( array $task ): bool => 'gelato' === $task['provider'] ) );

		if ( array() === $tasks ) {
			return;
		}

		foreach ( $tasks as $task ) {
			$scene = self::TASK_SIDE !== (string) $task['side'];

			if ( ProviderTaskRepository::QUEUED === $task['status'] ) {
				if ( $created >= self::CREATES_PER_RUN ) {
					continue;
				}

				++$created;

				if ( $scene ) {
					$this->scene( $id, $task, fn() => $this->create_scene( $id, $provider, $version, $base, $task ) );
				} else {
					$this->create( $id, $provider['ref'], $provider['placements'], $version, $base, $task, $provider['variants'] );
				}
			} elseif ( ProviderTaskRepository::PENDING === $task['status'] ) {
				if ( $scene ) {
					$this->scene( $id, $task, fn() => $this->poll_scene( $id, $provider, $task ) );
				} else {
					$this->poll( $id, $provider['placements'], $version, $base, $task, $provider['slots'], $provider );
				}
			}
		}

		$statuses = array_column( array_filter( $this->tasks->for_version( $id ), static fn( array $task ): bool => 'gelato' === $task['provider'] ), 'status' );

		if ( array() !== array_intersect( $statuses, array( ProviderTaskRepository::QUEUED, ProviderTaskRepository::PENDING ) ) ) {
			$this->schedule( $id, self::POLL_DELAY );
		} elseif ( in_array( ProviderTaskRepository::FAILED, $statuses, true ) ) {
			$this->failed( $id, 'task' );
		} else {
			$this->products->set_mockup_state( $id, PrintfulMockups::READY );
		}
	}

	/**
	 * Runs a step of a format side's task (ADR-117): Gelato refusing it fails
	 * that task only; a 429 waits for the whole version.
	 *
	 * @param int                  $id   Version.
	 * @param array<string, mixed> $task Task row.
	 * @param callable(): void     $work The step.
	 * @throws GelatoException When Gelato asks to wait.
	 */
	private function scene( int $id, array $task, callable $work ): void {
		try {
			$work();
		} catch ( GelatoException $e ) {
			if ( GelatoException::RATE_LIMITED === $e->reason() ) {
				throw $e;
			}

			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'error' => $e->reason() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		} catch ( DesignerException $e ) {
			$this->logger->warning(
				'An environment photo of a design was refused.',
				array(
					'version_id' => $id,
					'reason'     => $e->reason(),
				)
			);
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'error' => 'image' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
	}

	/**
	 * Creates a format side's product (ADR-117): one variant, the group's
	 * largest size in the side's orientation, with the side's print file.
	 *
	 * @param int                  $id       Version.
	 * @param array<string, mixed> $provider The base product's provider data.
	 * @param array<string, mixed> $version  Version.
	 * @param BaseProduct          $base     Base product.
	 * @param array<string, mixed> $task     Task row.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function create_scene( int $id, array $provider, array $version, BaseProduct $base, array $task ): void {
		$side    = (string) $task['side'];
		$view    = self::view_of( $base, $side );
		$chosen  = json_decode( (string) $version['colors'], true );
		$colors  = null === $view ? array() : array_values( array_intersect( $view->colors, is_array( $chosen ) ? array_map( 'strval', $chosen ) : array() ) );
		$color   = (string) ( $colors[0] ?? '' );
		$variant = null === $view ? '' : (string) ( $provider['variants'][ $color ][ self::largest( $view ) ] ?? '' );
		$part    = ColorDesigns::part_of( self::design_of( $version ), $color );
		$name    = (string) ( $provider['placements'][ $side ] ?? '' );

		if ( '' === $variant || '' === $name || ! in_array( $side, self::sides( $version, $provider['placements'], $part ), true ) ) {
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'error' => 'no_variant' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		$answer  = $this->api->create_from_template(
			array(
				'templateId'                => (string) $provider['ref'],
				'title'                     => (string) $version['title'],
				'description'               => '',
				'isVisibleInTheOnlineStore' => false,
				'variants'                  => array(
					array(
						'templateVariantId' => $variant,
						'imagePlaceholders' => array(
							array(
								'name'      => $name,
								'fileUrl'   => SignedPrintFiles::url( $id, $side, null, $part ),
								'fitMethod' => 'meet',
							),
						),
					),
				),
			)
		);
		$product = is_scalar( $answer['id'] ?? null ) ? (string) $answer['id'] : '';

		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{1,100}$/', $product ) ) {
			self::fail( GelatoException::INVALID );
		}

		$this->tasks->update(
			(int) $task['id'],
			array(
				'status'   => ProviderTaskRepository::PENDING,
				'task_key' => $product,
				'result'   => (string) wp_json_encode(
					array(
						'color'   => $color,
						'variant' => $variant,
					)
				),
			)
		);
	}

	/**
	 * Polls a format side's product and adds its environment photos once
	 * Gelato has made them all; after MAX_POLLS it keeps those it has.
	 *
	 * @param int                  $id       Version.
	 * @param array<string, mixed> $provider The base product's provider data.
	 * @param array<string, mixed> $task     Task row.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When a photo is not a valid image.
	 */
	private function poll_scene( int $id, array $provider, array $task ): void {
		$attempts = (int) $task['attempts'] + 1;
		$answer   = $this->api->product( (string) $task['task_key'] );
		$status   = is_string( $answer['status'] ?? null ) ? $answer['status'] : '';
		$map      = json_decode( (string) $task['result'], true );
		$color    = is_array( $map ) && is_string( $map['color'] ?? null ) ? $map['color'] : '';
		$variant  = is_array( $map ) && is_string( $map['variant'] ?? null ) ? $map['variant'] : '';
		$side     = (string) $task['side'];
		// ADR-119: the rooms and the flat image the administrator chose.
		$wanted = array_values( array_filter( $provider['scenes'][ $side ] ?? array(), static fn( mixed $position ): bool => is_int( $position ) && self::picked( $provider, $position ) ) );
		$flat   = self::flat_of( $provider, $side );
		$needed = null === $flat ? $wanted : array_merge( $wanted, array( $flat ) );

		if ( 'publishing_error' === $status ) {
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'attempts' => $attempts, 'error' => 'publishing_error' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		$urls  = 'active' === $status && '' !== $color ? ( GelatoCatalog::variant_images( $answer, array( $color => $variant ) )[ $color ] ?? array() ) : array();
		$found = array_values( array_intersect_key( $urls, array_flip( $wanted ) ) );
		$have  = count( array_intersect_key( $urls, array_flip( $needed ) ) );

		if ( $have < count( $needed ) && $attempts < self::MAX_POLLS ) {
			$this->tasks->update( (int) $task['id'], array( 'attempts' => $attempts ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		if ( 0 === $have ) {
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'attempts' => $attempts, 'error' => 'timeout' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		// ADR-119: Gelato's flat poster in place of the browser's.
		if ( null !== $flat && isset( $urls[ $flat ] ) ) {
			$this->store( $id, $side, array( $color => $urls[ $flat ] ) );
		}

		if ( array() !== $found ) {
			$this->store_scenes( $id, $color, $found );
		}
		$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::DONE, 'attempts' => $attempts ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Downloads environment photos and adds them to the version's mockups as
	 * `_scene-<n>` of a colour, numbered from the colour's next free one.
	 *
	 * @phpstan-param list<string> $urls
	 *
	 * @param int      $id    Version.
	 * @param string   $color Colour key (a poster's orientation).
	 * @param string[] $urls  Gelato's image URLs.
	 * @throws GelatoException When an image cannot be downloaded.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When an image is not valid.
	 */
	private function store_scenes( int $id, string $color, array $urls ): void {
		$stored = array();
		$done   = false;

		try {
			foreach ( $urls as $url ) {
				$stored[] = $this->download( $url );
			}
			$done = true;
		} finally {
			// Files stored before a failure are never referenced: delete them.
			if ( ! $done ) {
				foreach ( array_filter( array_column( $stored, 'file' ), 'is_string' ) as $name ) {
					$this->files->delete( $name );
				}
			}
		}

		$version = $this->products->version( $id );
		$files   = SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );
		$next    = 0;

		foreach ( $stored as $file ) {
			while ( isset( $files['mockups'][ SubmissionFiles::SCENE . $next ][ $color ] ) ) {
				++$next;
			}

			$files['mockups'][ SubmissionFiles::SCENE . $next ][ $color ] = $file;
		}

		if ( ! $this->products->set_pending_files( $id, (string) wp_json_encode( $files ) ) ) {
			foreach ( array_filter( array_column( $stored, 'file' ), 'is_string' ) as $name ) {
				$this->files->delete( $name );
			}
		}
	}

	/**
	 * Whether the administrator chose an image of the template for mockups
	 * (ADR-119): every one when no choice was made.
	 *
	 * @param array<string, mixed> $provider The base product's provider data.
	 * @param int                  $position The image's place in Gelato's list.
	 */
	private static function picked( array $provider, int $position ): bool {
		$photos = $provider['gelato_photos'] ?? null;

		return ! is_array( $photos ) || in_array( $position, $photos, true );
	}

	/**
	 * The place of a format side's own image (the flat poster) when the
	 * administrator chose it, else null (ADR-119). Only a base product
	 * imported with thumbnails has the choice; one before keeps the
	 * browser's flat images.
	 *
	 * @param array<string, mixed> $provider The base product's provider data.
	 * @param string               $side     Side key.
	 */
	private static function flat_of( array $provider, string $side ): ?int {
		$slot = $provider['slots'][ $side ] ?? null;

		return is_numeric( $slot ) && array() !== ( $provider['gelato_thumbs'] ?? array() ) && self::picked( $provider, (int) $slot ) ? (int) $slot : null;
	}

	/**
	 * The used format sides of a version that get Gelato's environment photos
	 * (ADR-117) or its flat image (ADR-119): on a chosen colour, with images
	 * found on import and chosen by the administrator.
	 *
	 * @param array<string, mixed> $version  Version.
	 * @param array<string, mixed> $provider The base product's provider data.
	 * @param BaseProduct          $base     Base product.
	 * @return list<string>
	 */
	private static function scene_sides( array $version, array $provider, BaseProduct $base ): array {
		$chosen = json_decode( (string) $version['colors'], true );
		$chosen = is_array( $chosen ) ? array_map( 'strval', $chosen ) : array();
		$sides  = array();

		foreach ( self::sides( $version, is_array( $provider['placements'] ?? null ) ? $provider['placements'] : array() ) as $side ) {
			$view = self::view_of( $base, $side );

			$rooms = array_filter( $provider['scenes'][ $side ] ?? array(), static fn( mixed $position ): bool => is_int( $position ) && self::picked( $provider, $position ) );

			if ( null !== $view && array() !== $view->formats && array() !== array_intersect( $view->colors, $chosen ) && ( array() !== $rooms || null !== self::flat_of( $provider, $side ) ) ) {
				$sides[] = $side;
			}
		}

		return $sides;
	}

	/**
	 * A side of the base product by its key.
	 *
	 * @param BaseProduct $base Base product.
	 * @param string      $key  Side key.
	 */
	private static function view_of( BaseProduct $base, string $key ): ?BaseView {
		foreach ( $base->views as $view ) {
			if ( $view->key === $key ) {
				return $view;
			}
		}

		return null;
	}

	/**
	 * A format side's largest size: its print file's (ADR-115).
	 *
	 * @param BaseView $view Side.
	 */
	private static function largest( BaseView $view ): string {
		$largest = '';
		$area    = -1.0;

		foreach ( $view->formats as $size => $format ) {
			if ( $format['width_mm'] * $format['height_mm'] > $area ) {
				$largest = (string) $size;
				$area    = $format['width_mm'] * $format['height_mm'];
			}
		}

		return $largest;
	}

	/**
	 * Creates the product from the template at Gelato.
	 *
	 * @param int                                  $id           Version.
	 * @param string                               $template     Gelato template.
	 * @param array<string, string>                $placeholders Placeholder by side.
	 * @param array<string, mixed>                 $version      Version.
	 * @param BaseProduct                          $base         Base product.
	 * @param array<string, mixed>                 $task         Task row.
	 * @param array<string, array<string, string>> $known        Template variant by colour key and size (the catalog import).
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function create( int $id, string $template, array $placeholders, array $version, BaseProduct $base, array $task, array $known = array() ): void {
		$variants = $this->variants( $template, $version, $base, $known );
		$sides    = self::sides( $version, $placeholders );

		if ( array() === $variants || array() === $sides ) {
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'error' => 'no_variant' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		// Each variant shows the print files of its colour's design (ADR-096).
		$design = self::design_of( $version );
		$images = array();

		foreach ( array_unique( array_map( static fn( string $color ): string => ColorDesigns::part_of( $design, $color ), $variants ) ) as $part ) {
			foreach ( self::sides( $version, $placeholders, $part ) as $side ) {
				$images[ $part ][] = array(
					'name'      => $placeholders[ $side ],
					'fileUrl'   => SignedPrintFiles::url( $id, $side, null, $part ),
					'fitMethod' => 'meet',
				);
			}
		}

		// A variant whose design has no image Gelato can place is left out (review 34.12).
		$variants = array_filter( $variants, static fn( string $color ): bool => array() !== ( $images[ ColorDesigns::part_of( $design, $color ) ] ?? array() ) );

		if ( array() === $variants ) {
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'error' => 'no_variant' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		$answer  = $this->api->create_from_template(
			array(
				'templateId'                => $template,
				'title'                     => (string) $version['title'],
				'description'               => '',
				'isVisibleInTheOnlineStore' => false,
				'variants'                  => array_map(
					static fn( string $variant ): array => array(
						'templateVariantId' => $variant,
						'imagePlaceholders' => $images[ ColorDesigns::part_of( $design, $variants[ $variant ] ) ] ?? array(),
					),
					array_map( 'strval', array_keys( $variants ) )
				),
			)
		);
		$product = is_scalar( $answer['id'] ?? null ) ? (string) $answer['id'] : '';

		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{1,100}$/', $product ) ) {
			self::fail( GelatoException::INVALID );
		}

		$this->products->set_provider_ref( $id, $product );
		$this->tasks->update(
			(int) $task['id'],
			array(
				'status'   => ProviderTaskRepository::PENDING,
				'task_key' => $product,
				'result'   => (string) wp_json_encode(
					array(
						'colors'   => array_values( $variants ),
						'variants' => array_flip( $variants ),
					)
				),
			)
		);
	}

	/**
	 * Polls the product and stores its images when Gelato has made them.
	 *
	 * @param int                   $id           Version.
	 * @param array<string, string> $placeholders Placeholder by side.
	 * @param array<string, mixed>  $version      Version.
	 * @param BaseProduct           $base         Base product.
	 * @param array<string, mixed>  $task         Task row.
	 * @param array<string, string> $slots        The image of each side among a variant's images (the catalog import).
	 * @param array<string, mixed>  $provider     The base product's provider data (the administrator's choice of images, ADR-119).
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When a mockup is not a valid image.
	 */
	private function poll( int $id, array $placeholders, array $version, BaseProduct $base, array $task, array $slots = array(), array $provider = array() ): void {
		$attempts = (int) $task['attempts'] + 1;
		$answer   = $this->api->product( (string) $task['task_key'] );
		$status   = is_string( $answer['status'] ?? null ) ? $answer['status'] : '';
		$map      = json_decode( (string) $task['result'], true );
		$colors   = array_values( array_filter( is_array( $map['colors'] ?? null ) ? $map['colors'] : array(), 'is_string' ) );
		$variants = array();

		foreach ( $colors as $color ) {
			$variants[ $color ] = is_array( $map['variants'] ?? null ) && is_scalar( $map['variants'][ $color ] ?? null ) ? (string) $map['variants'][ $color ] : '';
		}

		// Only an active product has its images; any other status is waited for (review 30.15). ADR-119: a side's image only when chosen.
		$slots  = array_filter( $slots, static fn( string $slot ): bool => ! is_numeric( $slot ) || self::picked( $provider, (int) $slot ) );
		$images = 'active' === $status ? self::own_sides( $this->images( $answer, $variants, self::photographed( self::sides( $version, $placeholders ), $base ), $slots ), $version, $placeholders ) : array();

		if ( 'publishing_error' === $status ) {
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'attempts' => $attempts, 'error' => 'publishing_error' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		if ( array() === $images ) {
			$this->tasks->update( (int) $task['id'], self::MAX_POLLS <= $attempts ? array( 'status' => ProviderTaskRepository::FAILED, 'attempts' => $attempts, 'error' => 'timeout' ) : array( 'attempts' => $attempts ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		foreach ( $images as $side => $by_color ) {
			$this->store( $id, $side, $by_color );
		}

		// ADR-119: the other images the administrator chose (rooms), per colour, beside the sides'.
		$extra = array_diff( array_map( 'intval', array_keys( $provider['gelato_thumbs'] ?? array() ) ), array_map( 'intval', array_values( $provider['slots'] ?? array() ) ) );

		foreach ( GelatoCatalog::variant_images( $answer, $variants ) as $color => $urls ) {
			$rooms = array_values( array_intersect_key( $urls, array_flip( array_filter( $extra, static fn( int $position ): bool => self::picked( $provider, $position ) ) ) ) );

			if ( array() !== $rooms ) {
				$this->store_scenes( $id, (string) $color, $rooms );
			}
		}

		$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::DONE, 'attempts' => $attempts ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * The product's image URL by side and colour key (GelatoCatalog::variant_images()).
	 * With the catalog import's image per side (review 30.15), each used
	 * side takes its image of each colour; before it, the first used side
	 * takes each colour's first image, and the product's `previewUrl` the
	 * first colour without one.
	 *
	 * @phpstan-param list<string> $sides
	 *
	 * @param array<string, mixed>  $answer   Product answer.
	 * @param array<string, string> $variants Template variant by colour key ('' when unknown), in the order sent.
	 * @param string[]              $sides    The used sides with a placeholder.
	 * @param array<string, string> $slots    The image of each side among a variant's images.
	 * @return array<string, array<string, string>>
	 */
	private function images( array $answer, array $variants, array $sides, array $slots ): array {
		$lists  = GelatoCatalog::variant_images( $answer, $variants );
		$images = array();

		if ( array() !== $slots ) {
			foreach ( $sides as $side ) {
				foreach ( isset( $slots[ $side ] ) ? $lists : array() as $color => $urls ) {
					if ( isset( $urls[ (int) $slots[ $side ] ] ) ) {
						$images[ $side ][ $color ] = $urls[ (int) $slots[ $side ] ];
					}
				}
			}

			return $images;
		}

		$first = $sides[0] ?? null;
		$color = array_key_first( $variants );

		foreach ( null === $first ? array() : $lists as $key => $urls ) {
			$images[ $first ][ $key ] = $urls[0];
		}

		if ( null !== $first && null !== $color && ! isset( $images[ $first ][ $color ] ) && is_string( $answer['previewUrl'] ?? null ) && '' !== $answer['previewUrl'] ) {
			$images[ $first ][ $color ] = $answer['previewUrl'];
		}

		return $images;
	}

	/**
	 * Downloads the images and puts them in place of the browser's mockups of a side.
	 *
	 * @param int                   $id     Version.
	 * @param string                $side   Side.
	 * @param array<string, string> $images Image URL by colour key.
	 * @throws GelatoException When an image cannot be downloaded.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When an image is not valid.
	 */
	private function store( int $id, string $side, array $images ): void {
		$stored = array();
		$done   = false;

		try {
			foreach ( $images as $color => $url ) {
				$stored[ $color ] = $this->download( $url );
			}
			$done = true;
		} finally {
			// Files stored before a failure are never referenced: delete them (review 30.15).
			if ( ! $done ) {
				foreach ( array_filter( array_column( $stored, 'file' ), 'is_string' ) as $name ) {
					$this->files->delete( $name );
				}
			}
		}

		$replaced = array();
		$version  = $this->products->version( $id );
		$files    = SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );

		foreach ( $stored as $color => $file ) {
			$replaced[]                          = $files['mockups'][ $side ][ $color ]['file'] ?? null;
			$files['mockups'][ $side ][ $color ] = $file;
		}

		if ( ! $this->products->set_pending_files( $id, (string) wp_json_encode( $files ) ) ) {
			$replaced = array_column( $stored, 'file' );
		}

		foreach ( array_filter( $replaced, 'is_string' ) as $name ) {
			$this->files->delete( $name );
		}
	}

	/**
	 * An image as a stored designer file.
	 *
	 * @param string $url Gelato's URL.
	 * @return array<string, mixed>
	 * @throws GelatoException When it cannot be downloaded.
	 * @throws DesignerException When it is not a valid image.
	 */
	private function download( string $url ): array {
		$body = $this->api->download( $url );
		$path = DesignerFiles::temporary_path( 'merchboard-mockup' );

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file of the download.
			self::fail( GelatoException::INVALID );
		}

		$type = wp_getimagesize( $path );
		$name = is_array( $type ) && IMAGETYPE_PNG === $type[2] ? 'mockup.png' : 'mockup.jpg';

		try {
			return $this->files->store(
				array(
					'name'     => $name,
					'tmp_name' => $path,
					'error'    => UPLOAD_ERR_OK,
					'size'     => strlen( $body ),
				)
			);
		} finally {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * One template variant per chosen colour (the first chosen size it
	 * has): colour key by template variant id. From the catalog import's
	 * variant map (review 30.15), else from the template by the colours' names.
	 *
	 * @param string                               $template Gelato template.
	 * @param array<string, mixed>                 $version  Version.
	 * @param BaseProduct                          $base     Base product.
	 * @param array<string, array<string, string>> $known    Template variant by colour key and size.
	 * @return array<string, string>
	 * @throws GelatoException When Gelato refuses or fails.
	 */
	private function variants( string $template, array $version, BaseProduct $base, array $known = array() ): array {
		$chosen = json_decode( (string) $version['colors'], true );
		$sizes  = json_decode( (string) $version['sizes'], true );

		if ( array() !== $known ) {
			$map = array();

			foreach ( is_array( $chosen ) ? $chosen : array() as $key ) {
				foreach ( is_array( $sizes ) ? $sizes : array() as $size ) {
					$variant = $known[ (string) $key ][ (string) $size ] ?? null;

					if ( null !== $variant && ! in_array( (string) $key, $map, true ) ) {
						$map[ $variant ] = (string) $key;
					}
				}
			}

			return $map;
		}

		$names    = array_column( $base->colors, 'name', 'key' );
		$variants = GelatoCatalog::variants( $this->api->template( $template ) );
		$map      = array();

		foreach ( is_array( $chosen ) ? $chosen : array() as $key ) {
			$name = $names[ $key ] ?? null;

			foreach ( is_array( $sizes ) ? $sizes : array() as $size ) {
				foreach ( $variants as $variant ) {
					$variant_id = is_scalar( $variant['id'] ?? null ) ? (string) $variant['id'] : '';

					if ( '' !== $variant_id && GelatoCatalog::color_of( $variant ) === $name && GelatoCatalog::size_of( $variant ) === $size && ! in_array( $key, $map, true ) ) {
						$map[ $variant_id ] = (string) $key;
					}
				}
			}
		}

		return $map;
	}

	/**
	 * The version's used sides that have a placeholder, in the base product's
	 * order: of one design (ADR-096), or of every design.
	 *
	 * @param array<string, mixed>  $version      Version.
	 * @param array<string, string> $placeholders Placeholder by side.
	 * @param string|null           $part         A design's id (SHARED for the shared one); null for every design.
	 * @return list<string>
	 */
	private static function sides( array $version, array $placeholders, ?string $part = null ): array {
		$prints = SubmissionFiles::prints( SubmissionFiles::decode( is_string( $version['files'] ) ? $version['files'] : null ) );
		$used   = null === $part ? array_merge( array(), ...array_values( $prints ) ) : ( $prints[ $part ] ?? array() );

		return array_values( array_filter( array_map( 'strval', array_keys( $placeholders ) ), static fn( string $side ): bool => isset( $used[ $side ] ) ) );
	}

	/**
	 * The images of each colour on the sides of its own design only (ADR-096).
	 *
	 * @param array<string, array<string, string>> $images       Image URL by side and colour key.
	 * @param array<string, mixed>                 $version      Version.
	 * @param array<string, string>                $placeholders Placeholder by side.
	 * @return array<string, array<string, string>>
	 */
	private static function own_sides( array $images, array $version, array $placeholders ): array {
		$design = self::design_of( $version );

		foreach ( $images as $side => $by_color ) {
			foreach ( array_keys( $by_color ) as $color ) {
				if ( ! in_array( (string) $side, self::sides( $version, $placeholders, ColorDesigns::part_of( $design, (string) $color ) ), true ) ) {
					unset( $images[ $side ][ $color ] );
				}
			}
		}

		return array_filter( $images );
	}

	/**
	 * A version's design, decoded.
	 *
	 * @param array<string, mixed> $version Version.
	 * @return array<mixed>
	 */
	private static function design_of( array $version ): array {
		$design = json_decode( (string) ( $version['design'] ?? '' ), true );

		return is_array( $design ) ? $design : array();
	}

	/**
	 * The sides Gelato's images are used for: those the browser does not
	 * stitch and that are no format group's (ADR-115). A format group's side
	 * shares its placement with the other groups and has its own template
	 * variant; the browser's mockups on Gelato's own photo of the group stay.
	 *
	 * @phpstan-param list<string> $sides
	 *
	 * @param string[]    $sides Sides.
	 * @param BaseProduct $base  Base product.
	 * @return list<string>
	 */
	private static function photographed( array $sides, BaseProduct $base ): array {
		$stitched = array();

		foreach ( $base->views as $view ) {
			if ( $base->stitched( $view ) || array() !== $view->formats || array() !== $view->colors ) {
				$stitched[] = $view->key;
			}
		}

		return array_values( array_diff( $sides, $stitched ) );
	}

	/**
	 * Marks the mockups failed and tells the administrator once.
	 *
	 * @param int    $id     Version.
	 * @param string $reason Reason.
	 */
	private function failed( int $id, string $reason ): void {
		try {
			$this->products->set_mockup_state( $id, PrintfulMockups::FAILED );
			$version = $this->products->version( $id );
		} catch ( Throwable ) {
			return;
		}

		$this->logger->warning(
			'Gelato could not make the mockups of a design; the creator\'s mockups stay.',
			array(
				'version_id' => $id,
				'reason'     => $reason,
			)
		);

		if ( null !== $version ) {
			do_action(
				'merchboard_notify',
				DesignerNotifications::MOCKUPS_FAILED,
				(int) $version['creator_id'],
				array(
					'title'    => (string) $version['title'],
					'reason'   => 'task' === $reason ? 'gelato_task' : $reason,
					'provider' => 'Gelato',
				),
				'designer_mockups:' . $id
			);
		}
	}

	/**
	 * Queues the next round; false when the queue refused it.
	 *
	 * @param int $id    Version.
	 * @param int $delay Seconds.
	 */
	private function schedule( int $id, int $delay ): bool {
		if ( $this->queue->enqueue( self::HOOK, array( $id ), $delay ) ) {
			return true;
		}

		$this->logger->warning( 'The Gelato mockups of a design could not be queued.', array( 'version_id' => $id ) );

		return false;
	}

	/**
	 * Fails with a reason.
	 *
	 * @param string $reason GelatoException reason.
	 * @throws GelatoException Always.
	 */
	private static function fail( string $reason ): never {
		throw new GelatoException( $reason );
	}
}
