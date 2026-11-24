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
	 * Seconds a round cuts photos out (step 57.21): about one a second; the
	 * first of a round is always cut.
	 */
	private const CUT_SECONDS = 15;

	/**
	 * A colour this close to white (RGB distance) is light: its photos are
	 * only cut against the same view in a colour at least CONTRAST away
	 * (ImageProbe::cutout()), never alone, as a white garment on a white
	 * backdrop would lose its body. An unknown colour counts as light.
	 */
	private const LIGHT = 80;

	private const CONTRAST = 100;

	/**
	 * A draft's preview (step 57.17): the transient by version, and the job
	 * that removes the preview's product.
	 */
	private const PREVIEW = 'merchboard_printify_preview_';

	public const PREVIEW_HOOK = 'merchboard_designer_printify_preview';

	/**
	 * Seconds a preview's product is kept for the creator to look at.
	 */
	private const PREVIEW_KEEP = 900;

	/**
	 * Seconds a preview's state is kept: long after its clean-up job, so a
	 * late job still finds what to remove.
	 */
	private const PREVIEW_TTL = DAY_IN_SECONDS;

	/**
	 * Seconds a start without a product yet blocks another start.
	 */
	private const PREVIEW_STARTING = 60;

	/**
	 * Seconds a preview is waited for before it is given up.
	 */
	private const PREVIEW_WAIT = 600;

	/**
	 * Images a preview shows at most.
	 */
	private const PREVIEW_IMAGES = 6;

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
	 * Whether a base product's drafts can be previewed with Printify's
	 * mockups ("See the finished product", step 57.17): from Printify, with
	 * a shop for photos.
	 *
	 * @param BaseProduct $base Base product.
	 */
	public function can_preview( BaseProduct $base ): bool {
		return PrintifyCatalog::SOURCE === $base->source && $this->api->available() && '' !== $this->account->shop();
	}

	/**
	 * Starts a preview of a draft: a temporary product in one of its colours
	 * (the first, or the one asked for, step 57.22) with that colour's print
	 * files as signed URLs. An earlier preview of the draft is removed first;
	 * this one is removed by the job PREVIEW_HOOK after PREVIEW_KEEP seconds.
	 *
	 * @param int         $id    Draft version.
	 * @param string|null $color A chosen colour; null or another for the first.
	 * @throws PrintifyException When Printify refuses or fails.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function start_preview( int $id, ?string $color = null ): void {
		$version  = $this->products->version( $id );
		$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );
		$base     = null === $version ? null : $this->bases->find( (int) $version['base_id'] );
		$shop     = $this->account->shop();

		if ( null === $version || null === $provider || null === $base || '' === $shop ) {
			self::fail( PrintifyException::INVALID );
		}

		// A start a moment ago that has no product yet (a double click, a second tab) is still running.
		$running = get_transient( self::PREVIEW . $id );

		if ( is_array( $running ) && ! isset( $running['key'] ) && time() - (int) ( $running['started'] ?? 0 ) < self::PREVIEW_STARTING ) {
			self::fail( PrintifyException::RATE_LIMITED );
		}

		$this->forget_preview( $id );

		$colors  = json_decode( (string) $version['colors'], true );
		$colors  = is_array( $colors ) ? array_values( array_filter( $colors, 'is_string' ) ) : array();
		$color   = null !== $color && in_array( $color, $colors, true ) ? $color : ( $colors[0] ?? '' );
		$variant = (string) ( array_search( $color, self::variants( $version, $provider['variants'] ), true ) ?: '' ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- false becomes ''.
		$part    = ColorDesigns::part_of( self::design_of( $version ), $color );
		$sides   = self::printed( $version, $provider['placements'], $base, $part );

		if ( '' === $variant || array() === $sides ) {
			self::fail( PrintifyException::INVALID );
		}

		[ $blueprint, $producer ] = self::ref_of( (string) $provider['ref'] );

		$uploads = array();
		$places  = array();
		$state   = array(
			'color'   => $color,
			'variant' => $variant,
			'started' => time(),
			'uploads' => array(),
		);

		// The clean-up first: whatever this start leaves at Printify is removed in any case.
		set_transient( self::PREVIEW . $id, $state, self::PREVIEW_TTL );
		$this->queue->enqueue( self::PREVIEW_HOOK, array( $id, $state['started'] ), self::PREVIEW_KEEP );

		foreach ( $sides as $side ) {
			$uploads[] = $this->api->upload_url( 'preview-' . $id . '-' . substr( (string) preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $side ) ), 0, 40 ) . '.png', SignedPrintFiles::url( $id, $side, null, $part ) );
			// Stored at once: every upload is archived, even if the product is never made.
			set_transient( self::PREVIEW . $id, array_merge( $state, array( 'uploads' => $uploads ) ), self::PREVIEW_TTL );
			$places[] = array(
				'position' => $provider['placements'][ $side ],
				'images'   => array(
					array(
						'id'    => (string) end( $uploads ),
						'x'     => 0.5,
						'y'     => 0.5,
						'scale' => 1,
						'angle' => 0,
					),
				),
			);
		}

		$product = $this->api->create_product(
			$shop,
			array(
				'title'             => 'Merch Dashboard preview (temporary)',
				'description'       => 'Made for a preview of a draft and deleted soon after. Never published.',
				'blueprint_id'      => $blueprint,
				'print_provider_id' => $producer,
				'variants'          => array(
					array(
						'id'         => (int) $variant,
						'price'      => 10000,
						'is_enabled' => true,
					),
				),
				'print_areas'       => array(
					array(
						'variant_ids'  => array( (int) $variant ),
						'placeholders' => $places,
					),
				),
			)
		);

		set_transient( self::PREVIEW . $id, array_merge( $state, array( 'uploads' => $uploads, 'key' => $shop . ':' . (string) $product['id'] ) ), self::PREVIEW_TTL ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * A preview's state and, once Printify lists them, the views the shop
	 * will get in the preview's colour, at most PREVIEW_IMAGES, as data URLs:
	 * the dashboard's CSP takes no other host, and creators never see
	 * Printify's address.
	 *
	 * The views are the ticked ones (all before the administrator chose,
	 * none when every one was unticked, as poll()), without a side's own view
	 * when the design leaves that side empty. Each has its camera (`name`)
	 * and the main image it is (`choice`, `_view-<camera>`, step 57.22).
	 *
	 * @param int $id Draft version.
	 * @return array{status: string, images: list<array{side: string, color: string, url: string, choice: string, name: string}>}
	 * @throws PrintifyException When Printify refuses or fails.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function preview( int $id ): array {
		$state    = get_transient( self::PREVIEW . $id );
		$version  = $this->products->version( $id );
		$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );

		if ( ! is_array( $state ) || ! is_string( $state['key'] ?? null ) || null === $provider ) {
			return array(
				'status' => is_array( $state ) ? 'pending' : 'none',
				'images' => array(),
			);
		}

		[ $shop, $product ] = explode( ':', $state['key'], 2 ) + array( '', '' );

		$color  = (string) ( $state['color'] ?? '' );
		$photos = PrintifyCatalog::photos( $this->api->product( $shop, $product ), array( (string) ( $state['variant'] ?? '' ) => $color ) );
		$chosen = is_array( $provider['printify_photos'] ?? null ) ? array_map( 'strval', $provider['printify_photos'] ) : array_map( 'strval', array_keys( $photos ) );
		$base   = $this->bases->find( (int) $version['base_id'] );
		$slots  = $provider['slots'];
		$used   = self::printed( $version, $provider['placements'], $base, ColorDesigns::part_of( self::design_of( $version ), $color ) );
		// A side's own view comes only with a design on that side (poll()).
		$empty = array_values( array_diff_key( $slots, array_flip( $used ) ) );

		if ( array() === $photos ) {
			return array(
				'status' => time() - (int) ( $state['started'] ?? 0 ) > self::PREVIEW_WAIT ? 'failed' : 'pending',
				'images' => array(),
			);
		}

		$images = array();

		foreach ( $photos as $name => $by_color ) {
			$name = (string) $name;

			if ( count( $images ) >= self::PREVIEW_IMAGES || ! in_array( $name, $chosen, true ) || in_array( $name, $empty, true ) || ! is_string( $by_color[ $color ] ?? null ) ) {
				continue;
			}

			$bytes = $this->api->image( $by_color[ $color ] );
			$info  = getimagesizefromstring( $bytes );

			if ( is_array( $info ) && in_array( $info['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
				$images[] = array(
					'side'   => self::TASK_SIDE,
					'color'  => $color,
					'url'    => 'data:' . $info['mime'] . ';base64,' . base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- An image for the dashboard's CSP.
					'choice' => SubmissionFiles::VIEW . substr( $name, 0, 24 ),
					'name'   => $name,
				);
			}
		}

		return array(
			'status' => array() === $images ? 'failed' : 'done',
			'images' => $images,
		);
	}

	/**
	 * The job: PREVIEW_KEEP seconds after a start, deletes that preview's
	 * product and archives its uploads, leaving a newer preview of the draft
	 * alone. Never throws.
	 *
	 * @param mixed $version_id Draft version.
	 * @param mixed $started    When the preview started.
	 */
	public function clean_preview( mixed $version_id, mixed $started = 0 ): void {
		$id    = is_numeric( $version_id ) ? (int) $version_id : 0;
		$state = $id > 0 ? get_transient( self::PREVIEW . $id ) : false;

		if ( is_array( $state ) && (int) ( $state['started'] ?? 0 ) === ( is_numeric( $started ) ? (int) $started : -1 ) ) {
			$this->forget_preview( $id );
		}
	}

	/**
	 * Removes a draft's preview at Printify; never throws.
	 *
	 * @param int $id Draft version.
	 */
	private function forget_preview( int $id ): void {
		$state = get_transient( self::PREVIEW . $id );

		if ( ! is_array( $state ) ) {
			return;
		}

		delete_transient( self::PREVIEW . $id );
		$this->forget(
			array(
				'id'       => 0,
				'task_key' => is_string( $state['key'] ?? null ) ? $state['key'] : '',
				'result'   => (string) wp_json_encode( array( 'uploads' => is_array( $state['uploads'] ?? null ) ? $state['uploads'] : array() ) ),
			)
		);
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

		// A colour to cut a light one's photos against (step 57.21), with the first design.
		$refs = self::references( $variants, $base, is_array( $provider['variants'] ?? null ) ? $provider['variants'] : array() );

		foreach ( array_keys( $refs ) as $variant ) {
			$areas[ (string) array_key_first( $areas ) ]['variant_ids'][] = (int) $variant;
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
					array_merge( array_keys( $variants ), array_keys( $refs ) )
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
						'refs'    => $refs,
						'done'    => array(),
					)
				),
			)
		);
	}

	/**
	 * Looks at the product and stores the chosen views of a few colours.
	 * Once every colour is stored, its rounds cut the photos out (step 57.21).
	 * A DatabaseException (it cannot be stored) or a DesignerException (a
	 * photo is no valid image) goes on to run().
	 *
	 * @param int                  $id       Version.
	 * @param array<string, mixed> $provider The base product's provider data.
	 * @param array<string, mixed> $version  Version.
	 * @param BaseProduct          $base     Base product.
	 * @param array<string, mixed> $task     Task row.
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	private function poll( int $id, array $provider, array $version, BaseProduct $base, array $task ): void {
		$result = json_decode( (string) ( $task['result'] ?? '' ), true );
		$result = is_array( $result ) ? $result : array();
		$colors = array_map( 'strval', is_array( $result['colors'] ?? null ) ? $result['colors'] : array() );
		$refs   = array_map( 'strval', is_array( $result['refs'] ?? null ) ? $result['refs'] : array() );
		$done   = array_map( 'strval', is_array( $result['done'] ?? null ) ? $result['done'] : array() );

		[ $shop, $product ] = explode( ':', (string) $task['task_key'], 2 ) + array( '', '' );

		$stored = array() === array_diff( array_unique( $colors ), $done );

		try {
			// A reference colour's photos are only cut against (step 57.21), never stored.
			$photos = PrintifyCatalog::photos( $this->api->product( $shop, $product ), $colors + $refs );
		} catch ( PrintifyException $e ) {
			// Every photo is stored and only being cut: an error now only leaves the references out.
			if ( ! $stored || PrintifyException::RATE_LIMITED === $e->reason() ) {
				throw $e;
			}

			$photos = array();
		}

		if ( $stored ) {
			if ( $this->cut( $id, $base, $photos, $task, $result ) ) {
				$this->resolve_view( $id, $result );
				$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::DONE ) );
				$this->forget( $this->task( $id ) ?? $task );
			}

			return;
		}

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
		$names  = array();

		foreach ( $photos as $name => $by_color ) {
			foreach ( $by_color as $url ) {
				$names[ $url ] = (string) $name;
			}
		}

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

			$shots = $this->store( $id, $color, $views, $scenes, $names );

			$done[] = $color;
			++$round;
			$result = array_merge(
				$result,
				array(
					'done'  => $done,
					'shots' => array_merge( is_array( $result['shots'] ?? null ) ? $result['shots'] : array(), $shots ),
				)
			);
			$this->tasks->update( (int) $task['id'], array( 'result' => (string) wp_json_encode( $result ) ) );
		}

		// Once every colour is stored, the next rounds cut the studio photos out (step 57.21).
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
	 * @param array<string, string> $names  Camera name by photo URL.
	 * @return list<array{color: string, key: string, name: string}> The photos
	 *         stored, each with its key in the mockups and its camera name,
	 *         to cut out later (step 57.21).
	 * @throws PrintifyException When a photo cannot be downloaded.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When a photo is not valid.
	 */
	private function store( int $id, string $color, array $views, array $scenes, array $names = array() ): array {
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
		$shots    = array();
		$next     = 0;

		foreach ( $stored as $side => $file ) {
			$replaced[]                                   = $files['mockups'][ (string) $side ][ $color ]['file'] ?? null;
			$files['mockups'][ (string) $side ][ $color ] = $file;
			$shots[]                                      = array(
				'color' => $color,
				'key'   => (string) $side,
				'name'  => $names[ $views[ $side ] ] ?? '',
			);
		}

		foreach ( $extra as $index => $file ) {
			while ( isset( $files['mockups'][ SubmissionFiles::SCENE . $next ][ $color ] ) ) {
				++$next;
			}

			$files['mockups'][ SubmissionFiles::SCENE . $next ][ $color ] = $file;
			$shots[] = array(
				'color' => $color,
				'key'   => SubmissionFiles::SCENE . $next,
				'name'  => $names[ $scenes[ $index ] ?? '' ] ?? '',
			);
		}

		if ( ! $this->products->set_pending_files( $id, (string) wp_json_encode( $files ) ) ) {
			$replaced = array_merge( array_column( $stored, 'file' ), array_column( $extra, 'file' ) );
			$shots    = array();
		}

		foreach ( array_filter( $replaced, 'is_string' ) as $name ) {
			$this->files->delete( $name );
		}

		return $shots;
	}

	/**
	 * Cuts the stored studio photos out, one after another, for at most
	 * CUT_SECONDS a round (step 57.21); what is left goes on next round.
	 *
	 * @phpstan-param array<string, array<string, string>> $photos
	 * @phpstan-param array<string, mixed> $result
	 *
	 * @param int                  $id     Version.
	 * @param BaseProduct          $base   Base product (the colours' codes).
	 * @param array                $photos Printify's photo URLs by camera name and colour.
	 * @param array<string, mixed> $task   Task row.
	 * @param array                $result The task's result (`shots`, `cut`, `refs`).
	 * @return bool Whether every photo is done (a DatabaseException when it
	 *              cannot be stored goes on to run()).
	 * @throws PrintifyException When Printify asks to wait (429).
	 */
	private function cut( int $id, BaseProduct $base, array $photos, array $task, array &$result ): bool {
		$shots    = array_values( array_filter( is_array( $result['shots'] ?? null ) ? $result['shots'] : array(), 'is_array' ) );
		$at       = max( 0, (int) ( $result['cut'] ?? 0 ) );
		$deadline = microtime( true ) + self::CUT_SECONDS;
		$fetched  = array();
		$hex      = self::hexes( $base );
		$refs     = array_map( 'strval', is_array( $result['refs'] ?? null ) ? $result['refs'] : array() );
		$started  = $at;
		$total    = count( $shots );

		while ( $at < $total ) {
			if ( $at > $started && microtime( true ) > $deadline ) {
				return false;
			}

			$failed = null;
			$shot   = $shots[ $at ];

			// Counted first: a photo that stops PHP (memory, time) is never tried again.
			++$at;
			$result['cut'] = $at;
			$this->tasks->update( (int) $task['id'], array( 'result' => (string) wp_json_encode( $result ) ) );

			try {
				$this->cut_one( $id, $shot, $shots, $photos, $refs, $hex, $fetched );
			} catch ( PrintifyException $e ) {
				if ( PrintifyException::RATE_LIMITED === $e->reason() ) {
					// The same photo again after Retry-After (run()).
					$result['cut'] = $at - 1;
					$this->tasks->update( (int) $task['id'], array( 'result' => (string) wp_json_encode( $result ) ) );

					throw $e;
				}

				$failed = $e::class;
			} catch ( DesignerException $e ) {
				$failed = $e::class;
			}

			if ( null !== $failed ) {
				// The photo stays as Printify made it.
				$this->logger->warning(
					'A Printify photo could not be cut out.',
					array(
						'version_id' => $id,
						'exception'  => $failed,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Cuts one stored photo out when it is a studio photo (ImageProbe::cutout()),
	 * against the same view in the colour farthest from it: a stored photo,
	 * or one of a colour added only for this (`refs`). A light colour without
	 * a reference CONTRAST away stays as it is.
	 *
	 * @phpstan-param array<mixed> $shot
	 * @phpstan-param list<mixed> $shots
	 * @phpstan-param array<string, array<string, string>> $photos
	 * @phpstan-param array<string, string> $refs
	 * @phpstan-param array<string, string|null> $hex
	 * @phpstan-param array<string, string> $fetched
	 *
	 * @param int   $id      Version.
	 * @param array $shot    The photo: colour, key in the mockups, camera name.
	 * @param array $shots   Every stored photo.
	 * @param array $photos  Printify's photo URLs by camera name and colour.
	 * @param array $refs    Reference colours by variant.
	 * @param array $hex     `#rrggbb` by colour key.
	 * @param array $fetched Downloads of this round by URL.
	 * @throws PrintifyException When a reference cannot be downloaded.
	 * @throws DesignerException When the cut-out cannot be stored.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function cut_one( int $id, array $shot, array $shots, array $photos, array $refs, array $hex, array &$fetched ): void {
		$color = (string) ( $shot['color'] ?? '' );
		$key   = (string) ( $shot['key'] ?? '' );
		$name  = (string) ( $shot['name'] ?? '' );
		$file  = $this->stored_file( $id, $key, $color );
		$bytes = null === $file ? null : $this->bytes_of( $file );

		if ( null === $file || null === $bytes ) {
			return;
		}

		// The farthest colour with the same view whose photo is there: a stored one, else a reference colour's.
		$reference = null;
		$far       = self::CONTRAST - 1;

		foreach ( $shots as $other ) {
			$other_color = is_array( $other ) ? (string) ( $other['color'] ?? '' ) : '';

			if ( '' === $name || ! is_array( $other ) || $other_color === $color || ( $other['name'] ?? '' ) !== $name ) {
				continue;
			}

			$distance = self::distance( $hex[ $color ] ?? null, $hex[ $other_color ] ?? null );
			$found    = $distance > $far ? $this->stored_file( $id, (string) ( $other['key'] ?? '' ), $other_color ) : null;

			if ( null !== $found ) {
				$far       = $distance;
				$reference = array( 'file' => $found );
			}
		}

		foreach ( $refs as $ref_color ) {
			$url      = '' === $name ? null : ( $photos[ $name ][ $ref_color ] ?? null );
			$distance = self::distance( $hex[ $color ] ?? null, $hex[ $ref_color ] ?? null );

			if ( is_string( $url ) && $distance > $far ) {
				$far       = $distance;
				$reference = array( 'url' => $url );
			}
		}

		$other = null;

		if ( is_string( $reference['file'] ?? null ) ) {
			$other = $this->bytes_of( $reference['file'] );
		} elseif ( is_string( $reference['url'] ?? null ) ) {
			$fetched[ $reference['url'] ] ??= $this->api->image( $reference['url'] );
			$other                          = $fetched[ $reference['url'] ];
		}

		// A light colour is only cut against a reference cutout() can use.
		$light = self::light( $hex[ $color ] ?? null, ImageProbe::backdrop_of( $bytes ) );

		if ( $light && null === $other ) {
			return;
		}

		$png = ImageProbe::cutout( $bytes, $other, $light );

		if ( null === $png ) {
			return;
		}

		$cut     = $this->keep( $png );
		$version = $this->products->version( $id );
		$files   = SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );
		$kept    = false;

		// Only while the photo is still the one cut (a new submission replaces them).
		if ( ( $files['mockups'][ $key ][ $color ]['file'] ?? null ) === $file ) {
			$files['mockups'][ $key ][ $color ] = $cut;
			$kept                               = $this->products->set_pending_files( $id, (string) wp_json_encode( $files ) );
		}

		$this->files->delete( $kept ? $file : (string) $cut['file'] );
	}

	/**
	 * Turns a main image chosen among the views (`_view-<camera>:<colour>`,
	 * step 57.22) into the stored photo's key, now that every photo is
	 * stored: the side it replaced, or its environment photo. A view that
	 * was not stored stays as it is, and the shop then shows the colour's
	 * first image (DesignerImages::main()).
	 *
	 * @phpstan-param array<string, mixed> $result
	 *
	 * @param int   $id     Version.
	 * @param array $result The task's result (`shots`).
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	private function resolve_view( int $id, array $result ): void {
		$version = $this->products->version( $id );
		$primary = is_string( $version['primary_image'] ?? null ) ? $version['primary_image'] : '';

		if ( 1 !== preg_match( '/^' . preg_quote( SubmissionFiles::VIEW, '/' ) . '([a-z0-9_-]{1,24}):([a-z0-9%_-]{1,32})$/', $primary, $match ) ) {
			return;
		}

		foreach ( is_array( $result['shots'] ?? null ) ? $result['shots'] : array() as $shot ) {
			if ( is_array( $shot ) && ( $shot['color'] ?? null ) === $match[2] && substr( (string) ( $shot['name'] ?? '' ), 0, 24 ) === $match[1] && is_string( $shot['key'] ?? null ) ) {
				$this->products->set_primary_image( $id, $shot['key'] . ':' . $match[2] );

				return;
			}
		}
	}

	/**
	 * The stored file of a version's mockup, or null.
	 *
	 * @param int    $id    Version.
	 * @param string $key   Side or environment key.
	 * @param string $color Colour key.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function stored_file( int $id, string $key, string $color ): ?string {
		$version = $this->products->version( $id );
		$files   = SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );
		$file    = $files['mockups'][ $key ][ $color ]['file'] ?? null;

		return is_string( $file ) && '' !== $file ? $file : null;
	}

	/**
	 * A stored file's bytes, or null.
	 *
	 * @param string $file Stored name.
	 */
	private function bytes_of( string $file ): ?string {
		$path  = $this->files->path( $file );
		$bytes = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A stored designer file.

		return is_string( $bytes ) && '' !== $bytes ? $bytes : null;
	}

	/**
	 * The base product's colour codes by key.
	 *
	 * @param BaseProduct $base Base product.
	 * @return array<string, string|null>
	 */
	private static function hexes( BaseProduct $base ): array {
		$hex = array();

		foreach ( $base->colors as $color ) {
			$hex[ $color['key'] ] = $color['hex'];
		}

		return $hex;
	}

	/**
	 * The RGB distance between two `#rrggbb` codes; 0 when one is unknown.
	 *
	 * @param string|null $a Code.
	 * @param string|null $b Code.
	 */
	private static function distance( ?string $a, ?string $b ): float {
		if ( null === $a || null === $b || 1 !== preg_match( '/^#[0-9a-f]{6}$/i', $a ) || 1 !== preg_match( '/^#[0-9a-f]{6}$/i', $b ) ) {
			return 0.0;
		}

		$sum = 0;

		foreach ( array( 1, 3, 5 ) as $at ) {
			$sum += ( hexdec( substr( $a, $at, 2 ) ) - hexdec( substr( $b, $at, 2 ) ) ) ** 2;
		}

		return sqrt( $sum );
	}

	/**
	 * Whether a colour is light: LIGHT from white or from the photo's
	 * backdrop (any light grey); an unknown one is.
	 *
	 * @param string|null $hex      `#rrggbb`.
	 * @param string|null $backdrop The photo's backdrop `#rrggbb`, when known.
	 */
	private static function light( ?string $hex, ?string $backdrop = null ): bool {
		return null === $hex
			|| 1 !== preg_match( '/^#[0-9a-f]{6}$/i', $hex )
			|| self::distance( $hex, '#ffffff' ) <= self::LIGHT
			|| ( null !== $backdrop && self::distance( $hex, $backdrop ) <= self::LIGHT );
	}

	/**
	 * A colour added to the temporary product only to cut a light colour's
	 * photos against (step 57.21): when a chosen light colour has no chosen
	 * colour CONTRAST away, the base product's colour farthest from it.
	 * Its photos are never stored.
	 *
	 * @param array<string, string>                $variants Chosen colour by variant.
	 * @param BaseProduct                          $base     Base product.
	 * @param array<string, array<string, string>> $known    Variant by colour key and size (the import).
	 * @return array<string, string> Reference colour by variant; at most one.
	 */
	private static function references( array $variants, BaseProduct $base, array $known ): array {
		$hex    = self::hexes( $base );
		$chosen = array_values( array_map( 'strval', $variants ) );
		$needs  = null;

		foreach ( $chosen as $color ) {
			if ( ! self::light( $hex[ $color ] ?? null ) ) {
				continue;
			}

			$best = 0.0;

			foreach ( $chosen as $other ) {
				$best = max( $best, self::distance( $hex[ $color ] ?? null, $hex[ $other ] ?? null ) );
			}

			if ( $best < self::CONTRAST ) {
				$needs = $color;
				break;
			}
		}

		if ( null === $needs ) {
			return array();
		}

		$pick = null;
		$far  = self::CONTRAST - 1;

		foreach ( $base->colors as $color ) {
			$key      = (string) $color['key'];
			$variant  = is_array( $known[ $key ] ?? null ) ? reset( $known[ $key ] ) : false;
			$distance = self::distance( $hex[ $needs ] ?? null, $hex[ $key ] ?? null );

			if ( ! in_array( $key, $chosen, true ) && false !== $variant && '' !== $variant && $distance > $far ) {
				$far  = $distance;
				$pick = array( (string) $variant => $key );
			}
		}

		return $pick ?? array();
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
		return $this->keep( $this->api->image( $url ) );
	}

	/**
	 * Image bytes as a stored designer file (a PNG keeps its type).
	 *
	 * @param string $body Bytes.
	 * @return array<string, mixed>
	 * @throws PrintifyException When it cannot be written.
	 * @throws DesignerException When it is not a valid image.
	 */
	private function keep( string $body ): array {
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
			// A preview has no task row.
			if ( (int) ( $task['id'] ?? 0 ) > 0 ) {
				$this->tasks->update( (int) $task['id'], array( 'result' => (string) wp_json_encode( array_merge( is_array( $result ) ? $result : array(), array( 'forgotten' => true ) ) ) ) );
			}
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
