<?php
/**
 * Mockups of a submitted design from a temporary Printify product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
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
 * ADR-122: when a design of a Printify base product is submitted while a
 * shop for photos is chosen (`merchboard_designer_submitted`), one task is
 * queued for the version. The job `merchboard_designer_printify` (a lock per
 * version):
 *
 * 1. uploads each used side's print file as a signed URL (SignedPrintFiles)
 *    and creates a temporary product in the shop with one variant per
 *    chosen colour (the first chosen size it has), each colour with its own
 *    design's files (ADR-096); it is never published;
 * 2. looks at the product until Printify lists its photos, then downloads,
 *    a few colours per round, the views the administrator ticked on the base
 *    product (`printify_photos`; every view before the administrator chose,
 *    and no product at all when every view was unticked): a side's
 *    own view (`slots`, its camera name) replaces the browser's mockup of
 *    that side and colour, and the other views are added as environment
 *    photos (`_scene-<n>`);
 * 3. deletes the product and archives the uploads, also after a failure;
 *    what Printify would not delete is tried again later
 *    (PrintifyCatalog::forget()).
 *
 * Meanwhile the version's `mockup_state` is `running`, and it cannot be
 * approved. `ready` when the photos are stored; `failed` (the browser's
 * mockups stay, the administrator is told) when Printify refuses or takes
 * too long. A 429 waits for `Retry-After`. Stitched sides keep the browser's
 * images (ADR-085). Never throws.
 */
class PrintifyMockups {

	public const HOOK = 'merchboard_designer_printify';

	/**
	 * The version's one task (the product holds every side).
	 */
	public const TASK_SIDE = 'all';

	private const PROVIDER = 'printify';

	private const LOCK = 'merchboard_designer_printify_';

	private const POLL_DELAY = 30;

	private const MAX_POLLS = 20;

	/**
	 * Colours whose photos are downloaded per round (6 views took 12 s on 2026-11-23).
	 */
	private const COLORS_PER_RUN = 2;

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb     Database (the named lock).
	 * @param DesignerProductRepository $products Designer products.
	 * @param BaseProductRepository     $bases    Base products.
	 * @param PrintifyApi               $api      Printify.
	 * @param PrintifyAccount           $account  The shop.
	 * @param PrintifyCatalog           $catalog  Deletes temporary products.
	 * @param ProviderTaskRepository    $tasks    Mockup tasks.
	 * @param DesignerFiles             $files    Files (stores downloads by moving them).
	 * @param JobQueue                  $queue    Job queue.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct(
		private wpdb $wpdb,
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private PrintifyApi $api,
		private PrintifyAccount $account,
		private PrintifyCatalog $catalog,
		private ProviderTaskRepository $tasks,
		private DesignerFiles $files,
		private JobQueue $queue,
		private Logger $logger
	) {
	}

	/**
	 * Queues the task of a submitted version of a Printify base product;
	 * never throws.
	 *
	 * @param mixed $version_id Version.
	 */
	public function submitted( mixed $version_id ): void {
		$id = is_numeric( $version_id ) ? (int) $version_id : 0;

		try {
			$version  = $id > 0 && $this->api->available() && '' !== $this->account->shop() ? $this->products->version( $id ) : null;
			$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );
			$base     = null === $version ? null : $this->bases->find( (int) $version['base_id'] );

			if ( null === $version || null === $provider || null === $base || PrintifyCatalog::SOURCE !== $provider['source'] || array() === $provider['printify_photos'] || array() === self::printed( $version, $provider['placements'], $base ) ) {
				return;
			}

			$this->tasks->create( $id, self::PROVIDER, self::TASK_SIDE, '{}' );
			$this->products->set_mockup_state( $id, PrintfulMockups::RUNNING );

			if ( ! $this->schedule( $id, 0 ) ) {
				$this->failed( $id, 'queue' );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The Printify mockups of a design could not be started.',
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
			$this->schedule( $id, self::POLL_DELAY );

			return;
		}

		try {
			$this->step( $id );
		} catch ( PrintifyException $e ) {
			if ( PrintifyException::RATE_LIMITED === $e->reason() ) {
				$this->schedule( $id, $e->retry_after );
			} else {
				$this->failed( $id, $e->reason() );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The Printify mockups of a design failed.',
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
	 * One round: create the product, or poll it and store photos.
	 *
	 * @param int $id Version.
	 * @throws PrintifyException When Printify refuses or fails.
	 * @throws DatabaseException When the designer's data cannot be read or stored.
	 * @throws DesignerException When a photo is not a valid image.
	 */
	private function step( int $id ): void {
		$task = $this->task( $id );

		if ( null === $task ) {
			return;
		}

		$version  = $this->products->version( $id );
		$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );
		$base     = null === $version ? null : $this->bases->find( (int) $version['base_id'] );

		if ( null === $version || DesignerProductRepository::PENDING !== $version['status'] ) {
			// Withdrawn or decided meanwhile: nothing more to make, but nothing may stay at Printify.
			$this->forget( $task );

			return;
		}

		if ( null === $provider || null === $base ) {
			$this->failed( $id, 'error' );

			return;
		}

		if ( ProviderTaskRepository::QUEUED === $task['status'] ) {
			$this->create( $id, $provider, $version, $base, $task );
		} elseif ( ProviderTaskRepository::PENDING === $task['status'] ) {
			$this->poll( $id, $provider, $version, $base, $task );
		}

		$task = $this->task( $id );

		if ( null !== $task && in_array( $task['status'], array( ProviderTaskRepository::QUEUED, ProviderTaskRepository::PENDING ), true ) ) {
			// Colours left to download go on at once; a product without photos yet is looked at later.
			$result = json_decode( (string) ( $task['result'] ?? '' ), true );
			$this->schedule( $id, is_array( $result ) && array() !== ( $result['done'] ?? array() ) ? 1 : self::POLL_DELAY );
		} elseif ( null !== $task && ProviderTaskRepository::FAILED === $task['status'] ) {
			$this->failed( $id, 'task' );
		} else {
			$this->products->set_mockup_state( $id, PrintfulMockups::READY );
		}
	}

	/**
	 * Uploads the print files and creates the temporary product.
	 *
	 * @param int                  $id       Version.
	 * @param array<string, mixed> $provider The base product's provider data.
	 * @param array<string, mixed> $version  Version.
	 * @param BaseProduct          $base     Base product.
	 * @param array<string, mixed> $task     Task row.
	 * @throws PrintifyException When Printify refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function create( int $id, array $provider, array $version, BaseProduct $base, array $task ): void {
		$shop     = $this->account->shop();
		$variants = self::variants( $version, $provider['variants'] );
		$design   = self::design_of( $version );
		$areas    = array();
		$earlier  = json_decode( (string) ( $task['result'] ?? '' ), true );
		// Uploads of an earlier attempt (a 429 midway) are kept, so every one is archived.
		$uploads = array_values( array_filter( is_array( $earlier ) && is_array( $earlier['uploads'] ?? null ) ? $earlier['uploads'] : array(), 'is_string' ) );

		[ $blueprint, $producer ] = self::ref_of( (string) $provider['ref'] );

		if ( '' === $shop ) {
			self::fail( PrintifyException::NOT_CONFIGURED );
		}

		// Each colour's design has its own print files (ADR-096), so its own print area.
		foreach ( $variants as $variant => $color ) {
			$part  = ColorDesigns::part_of( $design, $color );
			$sides = self::printed( $version, $provider['placements'], $base, $part );

			if ( array() === $sides ) {
				continue;
			}

			if ( ! isset( $areas[ $part ] ) ) {
				$places = array();

				foreach ( $sides as $side ) {
					$upload    = $this->api->upload_url( 'print-' . $id . '-' . substr( (string) preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $side ) ), 0, 40 ) . '.png', SignedPrintFiles::url( $id, $side, null, $part ) );
					$uploads[] = $upload;
					// Stored at once: every upload is archived, even if the product is never made.
					$this->tasks->update( (int) $task['id'], array( 'result' => (string) wp_json_encode( array( 'uploads' => $uploads ) ) ) );
					$places[] = array(
						'position' => $provider['placements'][ $side ],
						'images'   => array(
							array(
								'id'    => $upload,
								'x'     => 0.5,
								'y'     => 0.5,
								'scale' => 1,
								'angle' => 0,
							),
						),
					);
				}

				$areas[ $part ] = array(
					'variant_ids'  => array(),
					'placeholders' => $places,
				);

			}

			$areas[ $part ]['variant_ids'][] = (int) $variant;
		}

		$variants = array_filter( $variants, static fn( string $color ): bool => isset( $areas[ ColorDesigns::part_of( $design, $color ) ] ) );

		if ( array() === $variants ) {
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'error' => 'no_variant' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->forget( $this->task( $id ) ?? $task );

			return;
		}

		$product = $this->api->create_product(
			$shop,
			array(
				'title'             => 'Merch Dashboard mockups (temporary)',
				'description'       => 'Made for the mockups of a design and deleted when they are stored. Never published.',
				'blueprint_id'      => $blueprint,
				'print_provider_id' => $producer,
				'variants'          => array_map(
					static fn( int|string $variant ): array => array(
						'id'         => (int) $variant,
						'price'      => 10000,
						'is_enabled' => true,
					),
					array_keys( $variants )
				),
				'print_areas'       => array_values( $areas ),
			)
		);
		$key     = $shop . ':' . (string) $product['id'];

		// The product's key first: whatever fails after it, forget() can delete the product.
		$this->tasks->update( (int) $task['id'], array( 'task_key' => $key ) );
		$this->products->set_provider_ref( $id, $key );
		$this->tasks->update(
			(int) $task['id'],
			array(
				'status'   => ProviderTaskRepository::PENDING,
				'task_key' => $key,
				'result'   => (string) wp_json_encode(
					array(
						'uploads' => $uploads,
						'colors'  => array_map( 'strval', $variants ),
						'done'    => array(),
					)
				),
			)
		);
	}

	/**
	 * Looks at the product and stores the chosen views of a few colours.
	 *
	 * @param int                  $id       Version.
	 * @param array<string, mixed> $provider The base product's provider data.
	 * @param array<string, mixed> $version  Version.
	 * @param BaseProduct          $base     Base product.
	 * @param array<string, mixed> $task     Task row.
	 * @throws PrintifyException When Printify refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When a photo is not a valid image.
	 */
	private function poll( int $id, array $provider, array $version, BaseProduct $base, array $task ): void {
		$result = json_decode( (string) ( $task['result'] ?? '' ), true );
		$result = is_array( $result ) ? $result : array();
		$colors = array_map( 'strval', is_array( $result['colors'] ?? null ) ? $result['colors'] : array() );
		$done   = array_map( 'strval', is_array( $result['done'] ?? null ) ? $result['done'] : array() );

		[ $shop, $product ] = explode( ':', (string) $task['task_key'], 2 ) + array( '', '' );

		$photos   = PrintifyCatalog::photos( $this->api->product( $shop, $product ), $colors );
		$attempts = (int) $task['attempts'] + 1;

		if ( array() === $photos ) {
			if ( $attempts >= self::MAX_POLLS ) {
				$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'error' => 'no_photos', 'attempts' => $attempts ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				$this->forget( $this->task( $id ) ?? $task );

				return;
			}

			$this->tasks->update( (int) $task['id'], array( 'attempts' => $attempts ) );

			return;
		}

		$chosen = is_array( $provider['printify_photos'] ?? null ) ? $provider['printify_photos'] : array_map( 'strval', array_keys( $photos ) );
		$design = self::design_of( $version );
		$round  = 0;

		foreach ( array_values( array_unique( $colors ) ) as $color ) {
			if ( in_array( $color, $done, true ) ) {
				continue;
			}

			if ( $round >= self::COLORS_PER_RUN ) {
				break;
			}

			// Only the sides of the colour's own design (ADR-096).
			$own   = self::printed( $version, $provider['placements'], $base, ColorDesigns::part_of( $design, $color ) );
			$views = array();

			foreach ( $own as $side ) {
				$name = (string) ( $provider['slots'][ $side ] ?? '' );

				if ( in_array( $name, $chosen, true ) && is_string( $photos[ $name ][ $color ] ?? null ) ) {
					$views[ $side ] = $photos[ $name ][ $color ];
				}
			}

			// Every side's own view is a photo of that side, never an environment (as Gelato's slots).
			$own_names = array_map( 'strval', array_values( $provider['slots'] ) );
			$scenes    = array();

			foreach ( $photos as $name => $by_color ) {
				if ( in_array( (string) $name, $chosen, true ) && ! in_array( (string) $name, $own_names, true ) && is_string( $by_color[ $color ] ?? null ) ) {
					$scenes[] = $by_color[ $color ];
				}
			}

			$this->store( $id, $color, $views, $scenes );

			$done[] = $color;
			++$round;
			$this->tasks->update( (int) $task['id'], array( 'result' => (string) wp_json_encode( array_merge( $result, array( 'done' => $done ) ) ) ) );
		}

		if ( array() !== array_diff( array_unique( $colors ), $done ) ) {
			return;
		}

		$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::DONE ) );
		$this->forget( $this->task( $id ) ?? $task );
	}

	/**
	 * Downloads a colour's photos: each side's own view in place of the
	 * browser's mockup, the other views as environment photos.
	 *
	 * @phpstan-param list<string> $scenes
	 *
	 * @param int                   $id     Version.
	 * @param string                $color  Colour key.
	 * @param array<string, string> $views  Photo URL by side.
	 * @param string[]              $scenes Photo URLs of the other views.
	 * @throws PrintifyException When a photo cannot be downloaded.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When a photo is not valid.
	 */
	private function store( int $id, string $color, array $views, array $scenes ): void {
		$stored = array();
		$extra  = array();
		$done   = false;

		try {
			foreach ( $views as $side => $url ) {
				$stored[ $side ] = $this->download( $url );
			}

			foreach ( $scenes as $url ) {
				$extra[] = $this->download( $url );
			}

			$done = true;
		} finally {
			// Files stored before a failure are never referenced: delete them.
			if ( ! $done ) {
				foreach ( array_filter( array_merge( array_column( $stored, 'file' ), array_column( $extra, 'file' ) ), 'is_string' ) as $name ) {
					$this->files->delete( $name );
				}
			}
		}

		$version  = $this->products->version( $id );
		$files    = SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );
		$replaced = array();
		$next     = 0;

		foreach ( $stored as $side => $file ) {
			$replaced[]                                   = $files['mockups'][ (string) $side ][ $color ]['file'] ?? null;
			$files['mockups'][ (string) $side ][ $color ] = $file;
		}

		foreach ( $extra as $file ) {
			while ( isset( $files['mockups'][ SubmissionFiles::SCENE . $next ][ $color ] ) ) {
				++$next;
			}

			$files['mockups'][ SubmissionFiles::SCENE . $next ][ $color ] = $file;
		}

		if ( ! $this->products->set_pending_files( $id, (string) wp_json_encode( $files ) ) ) {
			$replaced = array_merge( array_column( $stored, 'file' ), array_column( $extra, 'file' ) );
		}

		foreach ( array_filter( $replaced, 'is_string' ) as $name ) {
			$this->files->delete( $name );
		}
	}

	/**
	 * A photo as a stored designer file.
	 *
	 * @param string $url Printify's URL.
	 * @return array<string, mixed>
	 * @throws PrintifyException When it cannot be downloaded.
	 * @throws DesignerException When it is not a valid image.
	 */
	private function download( string $url ): array {
		$body = $this->api->image( $url );
		$path = DesignerFiles::temporary_path( 'merchboard-mockup' );

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file of the download.
			self::fail( PrintifyException::INVALID );
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
	 * Deletes the task's product and archives its uploads; never throws.
	 *
	 * @param array<string, mixed>|null $task Task row.
	 */
	private function forget( ?array $task ): void {
		if ( null === $task ) {
			return;
		}

		$key    = is_string( $task['task_key'] ?? null ) ? $task['task_key'] : '';
		$result = json_decode( (string) ( $task['result'] ?? '' ), true );

		// Once only: a timeout or a missing variant ends the task, and failed() would come again.
		if ( is_array( $result ) && true === ( $result['forgotten'] ?? false ) ) {
			return;
		}

		try {
			$this->tasks->update( (int) $task['id'], array( 'result' => (string) wp_json_encode( array_merge( is_array( $result ) ? $result : array(), array( 'forgotten' => true ) ) ) ) );
		} catch ( Throwable ) {
			// Deleting twice does no harm.
			$this->logger->warning( 'A Printify task could not be marked as cleaned up.', array( 'task_id' => (int) $task['id'] ) );
		}

		if ( '' !== $key ) {
			$this->catalog->forget( array( 'renders' => array( 'mockups' => array( 'key' => $key ) ) ) );
		}

		foreach ( is_array( $result ) && is_array( $result['uploads'] ?? null ) ? $result['uploads'] : array() as $upload ) {
			try {
				$this->api->archive_upload( (string) $upload );
			} catch ( PrintifyException ) {
				// The file stays in the account's library; it was never published.
				$this->logger->warning( 'A print file could not be archived at Printify.', array( 'upload' => (string) $upload ) );
			}
		}
	}

	/**
	 * The version's Printify task, or null.
	 *
	 * @param int $id Version.
	 * @return array<string, mixed>|null
	 * @throws DatabaseException When it cannot be read.
	 */
	private function task( int $id ): ?array {
		foreach ( $this->tasks->for_version( $id ) as $task ) {
			if ( self::PROVIDER === $task['provider'] ) {
				return $task;
			}
		}

		return null;
	}

	/**
	 * One variant per chosen colour (the first chosen size it has): colour key by variant id.
	 *
	 * @param array<string, mixed>                 $version Version.
	 * @param array<string, array<string, string>> $known   Variant by colour key and size (the import).
	 * @return array<string, string>
	 */
	private static function variants( array $version, array $known ): array {
		$chosen = json_decode( (string) $version['colors'], true );
		$sizes  = json_decode( (string) $version['sizes'], true );
		$map    = array();

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

	/**
	 * The version's used sides with a placement that are not stitched, in the
	 * base product's order: of one design (ADR-096), or of every design.
	 *
	 * @param array<string, mixed>  $version    Version.
	 * @param array<string, string> $placements Placement by side.
	 * @param BaseProduct|null      $base       Base product (to leave stitched sides out).
	 * @param string|null           $part       A design's id; null for every design.
	 * @return list<string>
	 */
	private static function printed( array $version, array $placements, ?BaseProduct $base = null, ?string $part = null ): array {
		$prints   = SubmissionFiles::prints( SubmissionFiles::decode( is_string( $version['files'] ) ? $version['files'] : null ) );
		$used     = null === $part ? array_merge( array(), ...array_values( $prints ) ) : ( $prints[ $part ] ?? array() );
		$stitched = array();

		if ( null !== $base ) {
			foreach ( $base->views as $view ) {
				if ( $base->stitched( $view ) ) {
					$stitched[] = $view->key;
				}
			}
		}

		return array_values( array_filter( array_map( 'strval', array_keys( $placements ) ), static fn( string $side ): bool => isset( $used[ $side ] ) && ! in_array( $side, $stitched, true ) ) );
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
	 * The catalog product and producer of a reference.
	 *
	 * @param string $ref `<product>-<producer>`.
	 * @return array{0: int, 1: int}
	 * @throws PrintifyException When it is not one.
	 */
	private static function ref_of( string $ref ): array {
		if ( 1 !== preg_match( '/^(\d{1,9})-(\d{1,9})$/D', $ref, $match ) ) {
			self::fail( PrintifyException::INVALID );
		}

		return array( (int) $match[1], (int) $match[2] );
	}

	/**
	 * Marks the mockups failed, removes what is at Printify and tells the administrator once.
	 *
	 * @param int    $id     Version.
	 * @param string $reason Reason.
	 */
	private function failed( int $id, string $reason ): void {
		try {
			$this->products->set_mockup_state( $id, PrintfulMockups::FAILED );
			$version = $this->products->version( $id );
			$task    = $this->task( $id );

			if ( null !== $task && ProviderTaskRepository::FAILED !== $task['status'] ) {
				$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'error' => mb_substr( $reason, 0, 60 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}

			$this->forget( $task );
		} catch ( Throwable ) {
			return;
		}

		$this->logger->warning(
			'Printify could not make (all) the mockups of a design; the creator\'s mockups stay where Printify\'s were not stored.',
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
					'reason'   => 'task' === $reason ? 'printify_task' : $reason,
					'provider' => 'Printify',
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

		$this->logger->warning( 'The Printify mockups of a design could not be queued.', array( 'version_id' => $id ) );

		return false;
	}

	/**
	 * Fails with a reason.
	 *
	 * @param string $reason PrintifyException reason.
	 * @throws PrintifyException Always.
	 */
	private static function fail( string $reason ): never {
		throw new PrintifyException( $reason );
	}
}
