<?php
/**
 * Mockups of a submitted design from Prodigi.
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
 * ADR-084: when a design of a Prodigi base product is submitted while Prodigi
 * is on (`merchboard_designer_submitted`), one task is queued for the
 * version. The Action Scheduler job `merchboard_designer_prodigi` (a lock per
 * version) works through its phases, kept in the task:
 *
 * 1. **assets:** each used side's print file goes to Prodigi as a signed URL
 *    (SignedPrintFiles), then the job waits until the assets are `ready`.
 * 2. **product:** a saved product with a variant per chosen colour, its
 *    assets bound to their print areas, and one mockup per side and colour.
 * 3. **mockups:** polled every 30 seconds (at most 40 times), downloaded
 *    (validated like uploads) and put in place of the creator's images.
 *
 * Prodigi's sandbox shares artwork and saved products with the live account,
 * so what the job saved there is deleted again, also after a failure.
 *
 * Meanwhile the version's `mockup_state` is `running`, and it cannot be
 * approved. `ready` when every mockup is stored; `failed` (the browser's
 * images stay, the administrator is told) on a failure or a timeout. A 429
 * waits for `Retry-After`. Never throws.
 *
 * **Preview (ADR-102):** before a design is sent, "See the finished product"
 * makes the photos of a draft's first colour the same way, moved on by the
 * dashboard's polls instead of a job and kept in a transient; when they are
 * done, or after a failure, everything is deleted at Prodigi, and a job
 * deletes what an abandoned preview left after half an hour.
 *
 * Written from Prodigi's OpenAPI file (2026-10-31) and not yet run against
 * Prodigi.
 */
class ProdigiMockups {

	public const HOOK = 'merchboard_designer_prodigi';

	/**
	 * The side of the version's one task.
	 */
	public const TASK_SIDE = 'all';

	private const LOCK = 'merchboard_designer_prodigi_';

	private const POLL_DELAY = 30;

	private const MAX_POLLS = 40;

	/**
	 * A preview's state (transient prefix, by draft version).
	 */
	public const PREVIEW = 'merchboard_designer_prodigi_preview_';

	/**
	 * The job that deletes what an abandoned preview left at Prodigi.
	 */
	public const PREVIEW_HOOK = 'merchboard_designer_prodigi_preview';

	private const PREVIEW_TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Seconds between two looks at Prodigi for one preview.
	 */
	private const PREVIEW_GAP = 5;

	/**
	 * Seconds between two starts of a draft's preview.
	 */
	private const PREVIEW_START_GAP = 60;

	/**
	 * Seconds after its start that an abandoned preview is deleted.
	 */
	private const PREVIEW_CLEANUP = 30 * MINUTE_IN_SECONDS;

	/**
	 * What a refused delete left at Prodigi (transient prefix, by draft version).
	 */
	public const PREVIEW_LEFT = 'merchboard_designer_prodigi_left_';

	/**
	 * Seconds a finished preview keeps its images.
	 */
	private const PREVIEW_DONE_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Seconds between two tries of a refused delete, and the most tries.
	 */
	private const PREVIEW_RETRY = 10 * MINUTE_IN_SECONDS;

	private const PREVIEW_CLEAN_TRIES = 5;

	/**
	 * The largest images (as data URLs) a finished preview keeps.
	 */
	private const PREVIEW_KEEP_BYTES = 4 * 1024 * 1024;

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb     Database (the named lock).
	 * @param DesignerProductRepository $products Designer products.
	 * @param BaseProductRepository     $bases    Base products.
	 * @param ProdigiApi                $api      Prodigi.
	 * @param ProviderTaskRepository    $tasks    Mockup tasks.
	 * @param DesignerFiles             $files    Files (stores downloads by moving them).
	 * @param JobQueue                  $queue    Job queue.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct(
		private wpdb $wpdb,
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private ProdigiApi $api,
		private ProviderTaskRepository $tasks,
		private DesignerFiles $files,
		private JobQueue $queue,
		private Logger $logger
	) {
	}

	/**
	 * Queues the task of a submitted version of a Prodigi base product;
	 * never throws.
	 *
	 * @param mixed $version_id Version.
	 */
	public function submitted( mixed $version_id ): void {
		$id = is_numeric( $version_id ) ? (int) $version_id : 0;

		try {
			$version  = $id > 0 && $this->api->available() ? $this->products->version( $id ) : null;
			$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );

			if ( null === $version || null === $provider || ProdigiCatalog::SOURCE !== $provider['source'] ) {
				return;
			}

			// Any design's print files count, also when every colour has a design of its own (review 34.12).
			$sides = array();

			foreach ( array_keys( SubmissionFiles::prints( SubmissionFiles::decode( is_string( $version['files'] ) ? $version['files'] : null ) ) ) as $part ) {
				$sides = array_merge( $sides, self::sides( $version, $provider['placements'], (string) $part ) );
			}

			if ( array() === $sides ) {
				return;
			}

			$this->tasks->create( $id, ProdigiCatalog::SOURCE, self::TASK_SIDE, '{}' );
			$this->products->set_mockup_state( $id, PrintfulMockups::RUNNING );

			if ( ! $this->schedule( $id, 0 ) ) {
				$this->failed( $id, 'queue' );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The Prodigi mockups of a design could not be started.',
				array(
					'version_id' => $id,
					'exception'  => $e::class,
				)
			);
		}
	}

	/**
	 * Whether a base product's designs can be previewed before they are sent
	 * (ADR-102): Prodigi only, and only while it is on.
	 *
	 * @param BaseProduct $base Base product.
	 */
	public function can_preview( BaseProduct $base ): bool {
		return ProdigiCatalog::SOURCE === $base->source && $this->api->available();
	}

	/**
	 * Starts the finished product's photos of a draft (ADR-102): the used
	 * sides of its first colour's design go to Prodigi as signed URLs. A
	 * former preview of the draft is deleted at Prodigi first; one start a
	 * minute while one is under way, and one request at a time (a lock per
	 * draft). Nothing waits here: the dashboard's polls move it on
	 * (preview()), and a job deletes what is left after half an hour.
	 *
	 * @param int $id Draft version.
	 * @throws ProdigiException When Prodigi refuses or fails, or too soon after the last start or busy (RATE_LIMITED); a DatabaseException when the draft cannot be read.
	 */
	public function start_preview( int $id ): void {
		if ( ! $this->lock_preview( $id ) ) {
			throw new ProdigiException( ProdigiException::RATE_LIMITED, self::PREVIEW_GAP );
		}

		try {
			$former = get_transient( self::PREVIEW . $id );

			if ( is_array( $former ) && in_array( $former['phase'] ?? '', array( 'assets', 'mockups' ), true ) && time() - (int) ( $former['started'] ?? 0 ) < self::PREVIEW_START_GAP ) {
				throw new ProdigiException( ProdigiException::RATE_LIMITED, self::PREVIEW_START_GAP );
			}

			$this->drop_preview( $id, $former );

			$version  = $this->products->version( $id );
			$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );
			$base     = null === $version ? null : $this->bases->find( (int) $version['base_id'] );

			if ( null === $version || null === $provider || null === $base || ProdigiCatalog::SOURCE !== $provider['source'] ) {
				self::fail( ProdigiException::INVALID );
			}

			$colors = $this->chosen( $version, $base, $provider['variants'] );
			$color  = array_key_first( $colors );
			$design = json_decode( (string) $version['design'], true );
			$part   = null === $color ? ColorDesigns::SHARED : ColorDesigns::part_of( is_array( $design ) ? $design : array(), (string) $color );
			$sides  = self::sides( $version, $provider['placements'], $part );

			if ( null === $color || array() === $sides ) {
				self::fail( ProdigiException::INVALID );
			}

			$state = array(
				'started' => time(),
				'checked' => 0,
				'phase'   => 'assets',
				'color'   => (string) $color,
				'assets'  => array(),
			);

			try {
				foreach ( $sides as $side ) {
					$state['assets'][ $side ] = $this->api->ingest_asset( SignedPrintFiles::url( $id, $side, null, $part ), (string) $version['title'] . ' ' . $side );
				}
			} catch ( ProdigiException $e ) {
				$this->release_saved( $id, $state );
				$this->logger->warning( 'Prodigi could not make the photos of a draft.', self::refusal( $id, $e ) );

				throw $e;
			}

			set_transient( self::PREVIEW . $id, $state, self::PREVIEW_TTL );

			if ( ! $this->queue->enqueue( self::PREVIEW_HOOK, array( $id, $state['started'] ), self::PREVIEW_CLEANUP ) ) {
				$this->logger->warning( 'The clean-up of a Prodigi preview could not be queued.', array( 'version_id' => $id ) );
			}
		} finally {
			$this->unlock_preview( $id );
		}
	}

	/**
	 * A preview's state, moved on one step at most every few seconds and by
	 * one request at a time: the assets ready → a saved product with the
	 * colour, the assets bound and a mockup per side → the mockups as data
	 * URLs (the dashboard's CSP takes no other host), after which everything
	 * is deleted at Prodigi. The finished images are kept for ten minutes, so
	 * a lost answer can be asked for again.
	 *
	 * @param int $id Draft version.
	 * @return array{status: string, images: list<array{side: string, color: string, url: string}>}
	 * @throws ProdigiException When Prodigi refuses or fails, or a mockup failed (what was saved is deleted); a DatabaseException when the draft cannot be read.
	 */
	public function preview( int $id ): array {
		$state   = get_transient( self::PREVIEW . $id );
		$pending = array(
			'status' => 'pending',
			'images' => array(),
		);

		if ( ! is_array( $state ) ) {
			return array(
				'status' => 'none',
				'images' => array(),
			);
		}

		if ( 'done' === ( $state['phase'] ?? '' ) ) {
			return array(
				'status' => 'done',
				'images' => self::stored_images( $state ),
			);
		}

		if ( time() - (int) ( $state['checked'] ?? 0 ) < self::PREVIEW_GAP || ! $this->lock_preview( $id ) ) {
			return $pending;
		}

		try {
			// Read again under the lock: another request may have moved it on.
			$state = get_transient( self::PREVIEW . $id );

			if ( ! is_array( $state ) || ! in_array( $state['phase'] ?? '', array( 'assets', 'mockups' ), true ) ) {
				return $pending;
			}

			$state['checked'] = time();
			set_transient( self::PREVIEW . $id, $state, self::PREVIEW_TTL );

			try {
				if ( 'assets' === $state['phase'] && $this->assets_ready( $state ) ) {
					$state = $this->preview_mockups( $id, $state );
				} elseif ( 'mockups' === $state['phase'] ) {
					$images = $this->preview_images( $state );

					if ( null !== $images ) {
						$this->finish_preview( $id, $state, $images );

						return array(
							'status' => 'done',
							'images' => $images,
						);
					}
				}
			} catch ( ProdigiException $e ) {
				// The stored state knows what was saved meanwhile (preview_mockups() keeps it at once).
				$stored = get_transient( self::PREVIEW . $id );

				if ( ProdigiException::RATE_LIMITED === $e->reason() && is_array( $stored ) ) {
					$stored['checked'] = time() + $e->retry_after;
					set_transient( self::PREVIEW . $id, $stored, self::PREVIEW_TTL );

					return $pending;
				}

				$this->drop_preview( $id, $stored );
				$this->logger->warning( 'Prodigi could not make the photos of a draft.', self::refusal( $id, $e ) );

				throw $e;
			}

			set_transient( self::PREVIEW . $id, $state, self::PREVIEW_TTL );

			return $pending;
		} finally {
			$this->unlock_preview( $id );
		}
	}

	/**
	 * The job: half an hour after a start (`started` > 0), deletes at Prodigi
	 * what that preview left behind, leaving a newer preview of the draft
	 * alone; with `started` 0, tries again what a delete left behind (at
	 * most five times, ten minutes apart). Never throws.
	 *
	 * @param mixed $version_id Draft version.
	 * @param mixed $started    When the preview started; 0 for what was left behind.
	 * @param mixed $tries      Tries so far of what was left behind.
	 */
	public function clean_preview( mixed $version_id, mixed $started, mixed $tries = 0 ): void {
		$id      = is_numeric( $version_id ) ? (int) $version_id : 0;
		$started = is_numeric( $started ) ? (int) $started : 0;
		$tries   = is_numeric( $tries ) ? (int) $tries : 0;

		if ( $id < 1 ) {
			return;
		}

		if ( $started > 0 ) {
			$state = get_transient( self::PREVIEW . $id );

			if ( is_array( $state ) && (int) ( $state['started'] ?? 0 ) === $started && 'done' !== ( $state['phase'] ?? '' ) ) {
				$this->drop_preview( $id, $state );
			}

			return;
		}

		$left = get_transient( self::PREVIEW_LEFT . $id );
		$keep = array();

		foreach ( is_array( $left ) ? $left : array() as $saved ) {
			$rest = is_array( $saved ) ? $this->remove_saved( $saved ) : array();

			if ( array() !== $rest ) {
				$keep[] = $rest;
			}
		}

		if ( array() === $keep ) {
			delete_transient( self::PREVIEW_LEFT . $id );

			return;
		}

		if ( $tries >= self::PREVIEW_CLEAN_TRIES ) {
			delete_transient( self::PREVIEW_LEFT . $id );
			$this->logger->warning( 'What a Prodigi preview saved could not be deleted; delete it in Prodigi\'s dashboard.', array( 'version_id' => $id ) );

			return;
		}

		set_transient( self::PREVIEW_LEFT . $id, $keep, DAY_IN_SECONDS );
		$this->queue->enqueue( self::PREVIEW_HOOK, array( $id, 0, $tries + 1 ), self::PREVIEW_RETRY );
	}

	/**
	 * Saves the product with the preview's colour, binds the assets and
	 * starts a mockup per side. The product is kept in the state at once: a
	 * failure later must still delete it, and a round after a 429 deletes
	 * the product of the round before and starts over.
	 *
	 * @param int                  $id    Draft version.
	 * @param array<string, mixed> $state The preview's state.
	 * @return array<string, mixed> The new state.
	 * @throws ProdigiException When Prodigi refuses or fails.
	 * @throws DatabaseException When the draft cannot be read.
	 */
	private function preview_mockups( int $id, array $state ): array {
		$version  = $this->products->version( $id );
		$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );
		$base     = null === $version ? null : $this->bases->find( (int) $version['base_id'] );
		$color    = null === $version || null === $provider || null === $base ? null : ( $this->chosen( $version, $base, $provider['variants'] )[ (string) ( $state['color'] ?? '' ) ] ?? null );

		if ( null === $version || null === $provider || null === $color ) {
			self::fail( ProdigiException::INVALID );
		}

		if ( is_string( $state['product'] ?? null ) ) {
			$this->release_saved( $id, array( 'product' => $state['product'] ) );
			unset( $state['product'] );
			set_transient( self::PREVIEW . $id, $state, self::PREVIEW_TTL );
		}

		$product = $this->api->create_product(
			array(
				'title'                => (string) $version['title'],
				'catalogueProductSlug' => $provider['ref'],
				'catalogueProductId'   => $provider['ref'],
				'status'               => 'draft',
				'variants'             => array(
					array(
						'catalogueVariantId' => $color['sku'],
						'attributes'         => $color['attributes'],
					),
				),
			)
		);

		$state['product'] = $product;
		set_transient( self::PREVIEW . $id, $state, self::PREVIEW_TTL );

		$variant = self::saved_variant( $this->saved_variants( $product, array( $color ) ), $color );

		if ( null === $variant ) {
			self::fail( ProdigiException::INVALID );
		}

		$mockups = array();

		foreach ( is_array( $state['assets'] ?? null ) ? $state['assets'] : array() as $side => $asset ) {
			$this->api->bind_asset( $product, (string) $asset, $provider['placements'][ (string) $side ] ?? '' );
			$mockups[] = array(
				'side'   => (string) $side,
				'asset'  => (string) $asset,
				'mockup' => $this->api->create_mockup( (string) $asset, $product, $variant ),
			);
		}

		$state['phase']   = 'mockups';
		$state['mockups'] = $mockups;

		return $state;
	}

	/**
	 * The preview's mockups as data URLs when all are done, else null.
	 *
	 * @param array<string, mixed> $state The preview's state.
	 * @return list<array{side: string, color: string, url: string}>|null
	 * @throws ProdigiException When Prodigi refuses or fails, or a mockup failed or is not an image.
	 */
	private function preview_images( array $state ): ?array {
		$urls = array();

		foreach ( is_array( $state['mockups'] ?? null ) ? $state['mockups'] : array() as $entry ) {
			$mockup = is_array( $entry ) ? $this->api->mockup( (string) ( $entry['asset'] ?? '' ), (string) ( $entry['mockup'] ?? '' ) ) : array();
			$status = $mockup['status'] ?? '';
			$url    = is_array( $mockup['result'] ?? null ) && is_string( $mockup['result']['url'] ?? null ) ? $mockup['result']['url'] : '';

			if ( 'failed' === $status ) {
				self::fail( ProdigiException::FAILED );
			}

			if ( 'completed' !== $status || '' === $url ) {
				return null;
			}

			$urls[ (string) ( $entry['side'] ?? '' ) ] = $url;
		}

		$images = array();

		foreach ( $urls as $side => $url ) {
			$bytes = $this->api->download( $url );
			$info  = getimagesizefromstring( $bytes );

			if ( ! is_array( $info ) || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
				self::fail( ProdigiException::INVALID );
			}

			$images[] = array(
				'side'  => (string) $side,
				'color' => (string) ( $state['color'] ?? '' ),
				'url'   => 'data:' . $info['mime'] . ';base64,' . base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- An image for the dashboard's CSP.
			);
		}

		return $images;
	}

	/**
	 * Deletes what the finished preview saved at Prodigi and keeps its images
	 * for a while (not when they are too large to keep).
	 *
	 * @param int                                                   $id     Draft version.
	 * @param array<string, mixed>                                  $state  The preview's state.
	 * @param list<array{side: string, color: string, url: string}> $images The images.
	 */
	private function finish_preview( int $id, array $state, array $images ): void {
		$this->release_saved( $id, $state );

		if ( array_sum( array_map( static fn( array $image ): int => strlen( $image['url'] ), $images ) ) > self::PREVIEW_KEEP_BYTES ) {
			delete_transient( self::PREVIEW . $id );

			return;
		}

		set_transient(
			self::PREVIEW . $id,
			array(
				'started' => (int) ( $state['started'] ?? 0 ),
				'phase'   => 'done',
				'images'  => $images,
			),
			self::PREVIEW_DONE_TTL
		);
	}

	/**
	 * The images a finished preview kept.
	 *
	 * @param array<string, mixed> $state The preview's state.
	 * @return list<array{side: string, color: string, url: string}>
	 */
	private static function stored_images( array $state ): array {
		$images = array();

		foreach ( is_array( $state['images'] ?? null ) ? $state['images'] : array() as $image ) {
			if ( is_array( $image ) && is_string( $image['side'] ?? null ) && is_string( $image['color'] ?? null ) && is_string( $image['url'] ?? null ) ) {
				$images[] = array(
					'side'  => $image['side'],
					'color' => $image['color'],
					'url'   => $image['url'],
				);
			}
		}

		return $images;
	}

	/**
	 * Deletes what a preview saved at Prodigi and forgets it.
	 *
	 * @param int   $id    Draft version.
	 * @param mixed $state The preview's state, or anything else for none.
	 */
	private function drop_preview( int $id, mixed $state ): void {
		if ( is_array( $state ) ) {
			$this->release_saved( $id, $state );
		}

		delete_transient( self::PREVIEW . $id );
	}

	/**
	 * Deletes what was saved at Prodigi; what could not be deleted is kept
	 * and tried again later by the job.
	 *
	 * @param int                  $id    Draft version.
	 * @param array<string, mixed> $saved `product`, `products` and `assets`.
	 */
	private function release_saved( int $id, array $saved ): void {
		$rest = $this->remove_saved( $saved );

		if ( array() === $rest ) {
			return;
		}

		$left   = get_transient( self::PREVIEW_LEFT . $id );
		$left   = is_array( $left ) ? $left : array();
		$left[] = $rest;

		set_transient( self::PREVIEW_LEFT . $id, $left, DAY_IN_SECONDS );

		if ( ! $this->queue->has_pending( self::PREVIEW_HOOK, array( $id, 0, 1 ) ) ) {
			$this->queue->enqueue( self::PREVIEW_HOOK, array( $id, 0, 1 ), self::PREVIEW_RETRY );
		}
	}

	/**
	 * Deletes the saved products and assets one by one, so one refused
	 * delete does not keep the others; never throws.
	 *
	 * @param array<string, mixed> $saved `product`, `products` and `assets`.
	 * @return array{products?: list<string>, assets?: list<string>} What could not be deleted.
	 */
	private function remove_saved( array $saved ): array {
		$products = array_merge( is_string( $saved['product'] ?? null ) ? array( $saved['product'] ) : array(), is_array( $saved['products'] ?? null ) ? array_values( $saved['products'] ) : array() );
		$rest     = array();

		foreach ( array_unique( array_filter( $products, 'is_string' ) ) as $product ) {
			try {
				$this->api->delete_product( $product );
			} catch ( ProdigiException $e ) {
				$rest['products'][] = $product;
				$this->left_behind( $e );
			}
		}

		foreach ( is_array( $saved['assets'] ?? null ) ? $saved['assets'] : array() as $asset ) {
			try {
				$this->api->delete_asset( (string) $asset );
			} catch ( ProdigiException $e ) {
				$rest['assets'][] = (string) $asset;
				$this->left_behind( $e );
			}
		}

		return $rest;
	}

	/**
	 * What the log keeps of a refusal: the call and Prodigi's own message
	 * (never the key).
	 *
	 * @param int              $id Version.
	 * @param ProdigiException $e  The refusal.
	 * @return array<string, int|string>
	 */
	private static function refusal( int $id, ProdigiException $e ): array {
		return array(
			'version_id' => $id,
			'reason'     => $e->reason(),
			'call'       => $e->call,
			'detail'     => $e->detail,
		);
	}

	/**
	 * Logs a delete Prodigi refused.
	 *
	 * @param ProdigiException $e The refusal.
	 */
	private function left_behind( ProdigiException $e ): void {
		$this->logger->warning(
			'What was saved at Prodigi for a mockup could not be deleted.',
			array(
				'reason' => $e->reason(),
				'call'   => $e->call,
			)
		);
	}

	/**
	 * Takes the draft's preview lock; false when another request holds it.
	 *
	 * @param int $id Draft version.
	 */
	private function lock_preview( int $id ): bool {
		$wpdb     = $this->wpdb;
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::PREVIEW . $id, 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		return is_numeric( $acquired ) && 1 === (int) $acquired;
	}

	/**
	 * Releases the draft's preview lock.
	 *
	 * @param int $id Draft version.
	 */
	private function unlock_preview( int $id ): void {
		$wpdb = $this->wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::PREVIEW . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
	}

	/**
	 * One round of the version's task (the job); never throws.
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
		} catch ( ProdigiException $e ) {
			if ( ProdigiException::RATE_LIMITED === $e->reason() ) {
				$this->schedule( $id, $e->retry_after );
			} else {
				$this->logger->warning( 'Prodigi refused a call for the mockups of a design.', self::refusal( $id, $e ) );
				$this->failed( $id, $e->reason() );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The Prodigi mockups of a design failed.',
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
	 * One round: moves the task on through its phases.
	 *
	 * @param int $id Version.
	 * @throws ProdigiException When Prodigi refuses or fails.
	 * @throws DatabaseException When the designer's data cannot be read or stored.
	 * @throws DesignerException When a mockup is not a valid image.
	 */
	private function step( int $id ): void {
		$version  = $this->products->version( $id );
		$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );
		$base     = null === $version ? null : $this->bases->find( (int) $version['base_id'] );
		$task     = $this->task( $id );

		if ( null === $version || null === $task || DesignerProductRepository::PENDING !== $version['status'] ) {
			// Withdrawn or decided meanwhile: nothing more to do, and nothing to leave at Prodigi.
			if ( null !== $task ) {
				$this->clean_up( $task );
			}

			return;
		}

		if ( null === $provider || null === $base ) {
			$this->failed( $id, 'error' );

			return;
		}

		$map = json_decode( (string) $task['result'], true );
		$map = is_array( $map ) ? $map : array();

		if ( ProviderTaskRepository::QUEUED === $task['status'] ) {
			$map = $this->send_assets( $id, $version, $provider['placements'] );
		}

		$phase = (string) ( $map['phase'] ?? '' );

		if ( 'assets' === $phase && $this->assets_ready( $map ) ) {
			$map = $this->make_mockups( $version, $provider, $base, $map, (int) $task['id'] );
		} elseif ( 'mockups' === $phase ) {
			$done = $this->collect( $id, $map );

			if ( $done ) {
				$this->clean_up( $task );
				$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::DONE ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				$this->products->set_mockup_state( $id, PrintfulMockups::READY );

				return;
			}
		}

		$attempts = (int) $task['attempts'] + 1;

		if ( self::MAX_POLLS <= $attempts ) {
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'attempts' => $attempts, 'error' => 'timeout', 'result' => (string) wp_json_encode( $map ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->failed( $id, 'task' );

			return;
		}

		$this->tasks->update(
			(int) $task['id'],
			array(
				'status'   => ProviderTaskRepository::PENDING,
				'attempts' => $attempts,
				'result'   => (string) wp_json_encode( $map ),
			)
		);
		$this->schedule( $id, self::POLL_DELAY );
	}

	/**
	 * The version's task, or null.
	 *
	 * @param int $id Version.
	 * @return array<string, mixed>|null
	 * @throws DatabaseException When it cannot be read.
	 */
	private function task( int $id ): ?array {
		foreach ( $this->tasks->for_version( $id ) as $task ) {
			if ( ProdigiCatalog::SOURCE === $task['provider'] ) {
				return $task;
			}
		}

		return null;
	}

	/**
	 * Sends each used side's print file to Prodigi.
	 *
	 * @param int                   $id         Version.
	 * @param array<string, mixed>  $version    Version.
	 * @param array<string, string> $placements Print area by side.
	 * @return array<string, mixed> What the task keeps.
	 * @throws ProdigiException When Prodigi refuses or fails.
	 */
	private function send_assets( int $id, array $version, array $placements ): array {
		$assets = array();

		// Every design's print files; a colour design's asset is keyed "side@id" (ADR-096).
		foreach ( array_keys( SubmissionFiles::prints( SubmissionFiles::decode( is_string( $version['files'] ) ? $version['files'] : null ) ) ) as $part ) {
			foreach ( self::sides( $version, $placements, (string) $part ) as $side ) {
				$assets[ self::asset_key( $side, (string) $part ) ] = $this->api->ingest_asset( SignedPrintFiles::url( $id, $side, null, (string) $part ), (string) $version['title'] . ' ' . $side );
			}
		}

		return array(
			'phase'  => 'assets',
			'assets' => $assets,
		);
	}

	/**
	 * The key of a design's asset: the side, with "@id" for a colour design (ADR-096).
	 *
	 * @param string $side Side.
	 * @param string $part A design's id, or SHARED.
	 */
	private static function asset_key( string $side, string $part ): string {
		return ColorDesigns::SHARED === $part ? $side : $side . '@' . $part;
	}

	/**
	 * An asset key's side and design.
	 *
	 * @param string $key Asset key.
	 * @return array{0: string, 1: string}
	 */
	private static function split_asset( string $key ): array {
		$parts = explode( '@', $key, 2 );

		return array( $parts[0], $parts[1] ?? ColorDesigns::SHARED );
	}

	/**
	 * Whether every asset is ready.
	 *
	 * @param array<string, mixed> $map What the task keeps.
	 * @throws ProdigiException When Prodigi refuses or fails, or an asset failed.
	 */
	private function assets_ready( array $map ): bool {
		foreach ( is_array( $map['assets'] ?? null ) ? $map['assets'] : array() as $asset ) {
			$status = $this->api->asset_status( (string) $asset );

			if ( 'failed' === $status ) {
				self::fail( ProdigiException::FAILED );
			}

			if ( 'ready' !== $status ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Saves the product, binds the assets and starts a mockup per side and
	 * colour. The product is stored in the task at once: a failure later
	 * must still delete it.
	 *
	 * @phpstan-param array{ref: string, placements: array<string, string>, variants: array<string, array<string, string>>} $provider
	 *
	 * @param array<string, mixed> $version  Version.
	 * @param array                $provider The base product's provider data.
	 * @param BaseProduct          $base     Base product.
	 * @param array<string, mixed> $map      What the task keeps.
	 * @param int                  $task     Task.
	 * @return array<string, mixed> What the task keeps.
	 * @throws ProdigiException When Prodigi refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function make_mockups( array $version, array $provider, BaseProduct $base, array $map, int $task ): array {
		$colors = $this->chosen( $version, $base, $provider['variants'] );

		if ( array() === $colors ) {
			self::fail( ProdigiException::INVALID );
		}

		// Artwork is bound to a saved product, so each design gets one (ADR-096).
		$design  = json_decode( (string) $version['design'], true );
		$design  = is_array( $design ) ? $design : array();
		$parts   = ColorDesigns::parts( $design, array_map( 'strval', array_keys( $colors ) ) );
		$assets  = is_array( $map['assets'] ?? null ) ? $map['assets'] : array();
		$mockups = array();

		foreach ( $parts as $part => $keys ) {
			$part    = (string) $part;
			$own     = array_intersect_key( $colors, array_flip( $keys ) );
			$product = $this->api->create_product(
				array(
					'title'                => (string) $version['title'],
					'catalogueProductSlug' => $provider['ref'],
					'catalogueProductId'   => $provider['ref'],
					'status'               => 'draft',
					'variants'             => array_values(
						array_map(
							static fn( array $color ): array => array(
								'catalogueVariantId' => $color['sku'],
								'attributes'         => $color['attributes'],
							),
							$own
						)
					),
				)
			);

			$map['products'][ $part ] = $product;

			if ( ColorDesigns::SHARED === $part ) {
				$map['product'] = $product;
			}

			$this->tasks->update( $task, array( 'result' => (string) wp_json_encode( $map ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			$saved = $this->saved_variants( $product, array_values( $own ) );
			$bound = array();

			foreach ( $assets as $key => $asset ) {
				[ $side, $of ] = self::split_asset( (string) $key );

				if ( $of === $part ) {
					$bound[ $side ] = (string) $asset;
					$this->api->bind_asset( $product, (string) $asset, $provider['placements'][ $side ] ?? '' );
				}
			}

			foreach ( $own as $key => $color ) {
				$variant = self::saved_variant( $saved, $color );

				if ( null === $variant ) {
					continue;
				}

				foreach ( $bound as $side => $asset ) {
					$mockups[] = array(
						'side'   => $side,
						'color'  => (string) $key,
						'asset'  => $asset,
						'mockup' => $this->api->create_mockup( $asset, $product, $variant ),
					);
				}
			}
		}

		$map['phase']   = 'mockups';
		$map['mockups'] = $mockups;

		return $map;
	}

	/**
	 * A saved product's variants. Prodigi does not keep the `variants` of
	 * `POST /products` (tried 2026-11-08, ADR-102), so the colours are added
	 * one by one when it has none.
	 *
	 * @param string                                                      $product Saved product.
	 * @param list<array{sku: string, attributes: array<string, string>}> $colors  The colours it should have.
	 * @return list<array<mixed>>
	 * @throws ProdigiException When Prodigi refuses or fails.
	 */
	private function saved_variants( string $product, array $colors ): array {
		$read  = function () use ( $product ): array {
			$saved = array();

			foreach ( $this->api->product_variants( $product ) as $variant ) {
				if ( is_array( $variant ) && is_scalar( $variant['id'] ?? null ) ) {
					$saved[] = $variant;
				}
			}

			return $saved;
		};
		$saved = $read();

		if ( array() !== $saved ) {
			return $saved;
		}

		foreach ( $colors as $color ) {
			$this->api->add_product_variant( $product, $color['sku'], $color['attributes'] );
		}

		return $read();
	}

	/**
	 * The saved variant of a colour: the one with its attributes on its SKU.
	 *
	 * @phpstan-param list<array<mixed>> $saved
	 * @phpstan-param array{sku: string, attributes: array<string, string>} $color
	 *
	 * @param array<array<mixed>> $saved Saved variants.
	 * @param array               $color The colour.
	 */
	private static function saved_variant( array $saved, array $color ): ?string {
		foreach ( $saved as $variant ) {
			$attributes = is_array( $variant['attributes'] ?? null ) ? $variant['attributes'] : array();

			if ( ( $variant['catalogueVariantId'] ?? $color['sku'] ) === $color['sku'] && array() === array_diff_assoc( $color['attributes'], $attributes ) && is_scalar( $variant['id'] ?? null ) ) {
				return (string) $variant['id'];
			}
		}

		return null;
	}

	/**
	 * Polls the mockups; when all are done, stores them.
	 *
	 * @param int                  $id  Version.
	 * @param array<string, mixed> $map What the task keeps.
	 * @return bool Whether all are stored.
	 * @throws ProdigiException When Prodigi refuses or fails, or a mockup failed.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When a mockup is not a valid image.
	 */
	private function collect( int $id, array $map ): bool {
		$urls = array();

		foreach ( is_array( $map['mockups'] ?? null ) ? $map['mockups'] : array() as $entry ) {
			$mockup = is_array( $entry ) ? $this->api->mockup( (string) ( $entry['asset'] ?? '' ), (string) ( $entry['mockup'] ?? '' ) ) : array();
			$status = $mockup['status'] ?? '';
			$url    = is_array( $mockup['result'] ?? null ) && is_string( $mockup['result']['url'] ?? null ) ? $mockup['result']['url'] : '';

			if ( 'failed' === $status ) {
				self::fail( ProdigiException::FAILED );
			}

			if ( 'completed' !== $status || '' === $url ) {
				return false;
			}

			$urls[ (string) ( $entry['side'] ?? '' ) ][ (string) ( $entry['color'] ?? '' ) ] = $url;
		}

		$this->store( $id, $urls );

		return true;
	}

	/**
	 * Downloads the mockups and puts them in place of the browser's.
	 *
	 * @param int                                  $id   Version.
	 * @param array<string, array<string, string>> $urls URL by side and colour.
	 * @throws ProdigiException When a mockup cannot be downloaded.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When a mockup is not a valid image.
	 */
	private function store( int $id, array $urls ): void {
		$stored = array();
		$names  = array();
		$done   = false;

		try {
			foreach ( $urls as $side => $colors ) {
				foreach ( $colors as $color => $url ) {
					$stored[ $side ][ $color ] = $this->download( $url );
					$names[]                   = $stored[ $side ][ $color ]['file'] ?? null;
				}
			}
			$done = true;
		} finally {
			// Files stored before a failure are never referenced: delete them.
			if ( ! $done ) {
				foreach ( array_filter( $names, 'is_string' ) as $name ) {
					$this->files->delete( $name );
				}
			}
		}

		$replaced = array();
		$version  = $this->products->version( $id );
		$files    = SubmissionFiles::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );

		foreach ( $stored as $side => $colors ) {
			foreach ( $colors as $color => $file ) {
				$replaced[]                          = $files['mockups'][ $side ][ $color ]['file'] ?? null;
				$files['mockups'][ $side ][ $color ] = $file;
			}
		}

		if ( ! $this->products->set_pending_files( $id, (string) wp_json_encode( $files ) ) ) {
			$replaced = $names;
		}

		foreach ( array_filter( $replaced, 'is_string' ) as $name ) {
			$this->files->delete( $name );
		}
	}

	/**
	 * A mockup as a stored designer file.
	 *
	 * @param string $url Prodigi's URL.
	 * @return array<string, mixed>
	 * @throws ProdigiException When it cannot be downloaded.
	 * @throws DesignerException When it is not a valid image.
	 */
	private function download( string $url ): array {
		$body = $this->api->download( $url );
		$path = DesignerFiles::temporary_path( 'merchboard-mockup' );

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file of the download.
			self::fail( ProdigiException::INVALID );
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
	 * Deletes what the task saved at Prodigi. A file left behind must not
	 * cost the design its mockups, so a refused delete is only logged.
	 *
	 * @param array<string, mixed> $task Task row.
	 */
	private function clean_up( array $task ): void {
		$map = json_decode( (string) $task['result'], true );

		// Each delete on its own: one refused delete must not keep the others (review 40.7).
		$this->remove_saved( is_array( $map ) ? $map : array() );
	}

	/**
	 * What to save per chosen colour (the first chosen size it has): the SKU
	 * and the colour as an attribute, unless the colour is "Standard".
	 *
	 * @param array<string, mixed>                 $version Version.
	 * @param BaseProduct                          $base    Base product.
	 * @param array<string, array<string, string>> $known   SKU by colour key and size.
	 * @return array<string, array{sku: string, attributes: array<string, string>}>
	 */
	private function chosen( array $version, BaseProduct $base, array $known ): array {
		$chosen = json_decode( (string) $version['colors'], true );
		$sizes  = json_decode( (string) $version['sizes'], true );
		$names  = array_column( $base->colors, 'name', 'key' );
		$map    = array();

		foreach ( is_array( $chosen ) ? $chosen : array() as $key ) {
			foreach ( is_array( $sizes ) ? $sizes : array() as $size ) {
				$sku  = $known[ (string) $key ][ (string) $size ] ?? null;
				$name = (string) ( $names[ (string) $key ] ?? '' );

				if ( null !== $sku && ! isset( $map[ (string) $key ] ) ) {
					$map[ (string) $key ] = array(
						'sku'        => $sku,
						'attributes' => GelatoCatalog::STANDARD_COLOR === $name || '' === $name ? array() : array( 'color' => $name ),
					);
				}
			}
		}

		return $map;
	}

	/**
	 * The version's used sides of a design with a print area, in the base
	 * product's order.
	 *
	 * @param array<string, mixed>  $version    Version.
	 * @param array<string, string> $placements Print area by side.
	 * @param string                $part       A design's id (ADR-096); SHARED for the shared design.
	 * @return list<string>
	 */
	private static function sides( array $version, array $placements, string $part = ColorDesigns::SHARED ): array {
		$used = SubmissionFiles::prints( SubmissionFiles::decode( is_string( $version['files'] ) ? $version['files'] : null ) )[ $part ] ?? array();

		return array_values( array_filter( array_map( 'strval', array_keys( $placements ) ), static fn( string $side ): bool => isset( $used[ $side ] ) ) );
	}

	/**
	 * Marks the mockups failed, deletes what was saved and tells the
	 * administrator once.
	 *
	 * @param int    $id     Version.
	 * @param string $reason Reason.
	 */
	private function failed( int $id, string $reason ): void {
		try {
			$task = $this->task( $id );

			if ( null !== $task ) {
				$this->clean_up( $task );
				$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}

			$this->products->set_mockup_state( $id, PrintfulMockups::FAILED );
			$version = $this->products->version( $id );
		} catch ( Throwable ) {
			return;
		}

		$this->logger->warning(
			'Prodigi could not make the mockups of a design; the creator\'s images stay.',
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
					'reason'   => 'task' === $reason ? 'prodigi_task' : $reason,
					'provider' => 'Prodigi',
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

		$this->logger->warning( 'The Prodigi mockups of a design could not be queued.', array( 'version_id' => $id ) );

		return false;
	}

	/**
	 * Fails with a reason.
	 *
	 * @param string $reason ProdigiException reason.
	 * @throws ProdigiException Always.
	 */
	private static function fail( string $reason ): never {
		throw new ProdigiException( $reason );
	}
}
