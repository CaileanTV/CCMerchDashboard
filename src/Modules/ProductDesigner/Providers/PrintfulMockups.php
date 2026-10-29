<?php
/**
 * Mockups of a submitted design from Printful's mockup generator.
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
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\WooCommerce\JobQueue;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-081 pkt. 5 (step 30.12): when a design of a Printful base product is
 * submitted while Printful is on (`merchboard_designer_submitted`), one task
 * per used side is created at Printful's mockup generator with a variant
 * per chosen colour and the side's print file as a signed URL
 * (SignedPrintFiles). The Action Scheduler job `merchboard_designer_printful`
 * (a lock per version) creates the tasks, polls them every 15 seconds (at
 * most 40 times), downloads the mockups (validated like uploads) and puts
 * them in place of the mockups the creator's browser rendered.
 *
 * Meanwhile the version's `mockup_state` is `running`, and it cannot be
 * approved. `ready` when every side is done; `failed` (the browser's
 * mockups stay, the administrator is told) when a task failed, timed out or
 * Printful refused. A 429 waits for `Retry-After`. Never throws.
 */
class PrintfulMockups {

	public const HOOK = 'merchboard_designer_printful';

	public const RUNNING = 'running';

	public const READY = 'ready';

	public const FAILED = 'failed';

	private const LOCK = 'merchboard_designer_mockups_';

	private const POLL_DELAY = 15;

	private const MAX_POLLS = 40;

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb     Database (the named lock).
	 * @param DesignerProductRepository $products Designer products.
	 * @param BaseProductRepository     $bases    Base products.
	 * @param PrintfulApi               $api      Printful.
	 * @param ProviderTaskRepository    $tasks    Mockup tasks.
	 * @param DesignerFiles             $files    Files (stores downloads by moving them).
	 * @param JobQueue                  $queue    Job queue.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct(
		private wpdb $wpdb,
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private PrintfulApi $api,
		private ProviderTaskRepository $tasks,
		private DesignerFiles $files,
		private JobQueue $queue,
		private Logger $logger
	) {
	}

	/**
	 * Starts the tasks of a submitted version of a Printful base product;
	 * never throws.
	 *
	 * @param mixed $version_id Version.
	 */
	public function submitted( mixed $version_id ): void {
		$id = is_numeric( $version_id ) ? (int) $version_id : 0;

		try {
			$version  = $id > 0 && $this->api->available() ? $this->products->version( $id ) : null;
			$provider = null === $version ? null : $this->bases->provider( (int) $version['base_id'] );

			if ( null === $version || null === $provider || 'printful' !== $provider['source'] ) {
				return;
			}

			$sides = array_keys( SubmissionFiles::decode( is_string( $version['files'] ) ? $version['files'] : null )['print'] );

			foreach ( $sides as $side ) {
				if ( isset( $provider['placements'][ $side ] ) ) {
					$this->tasks->create( $id, 'printful', (string) $side, '{}' );
				}
			}

			if ( array() !== $this->tasks->for_version( $id ) ) {
				$this->products->set_mockup_state( $id, self::RUNNING );

				if ( ! $this->schedule( $id, 0 ) ) {
					$this->failed( $id, 'queue' );
				}
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The Printful mockups of a design could not be started.',
				array(
					'version_id' => $id,
					'exception'  => $e::class,
				)
			);
		}
	}

	/**
	 * Creates, polls and collects the version's tasks (the job); never throws.
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
		} catch ( PrintfulException $e ) {
			if ( PrintfulException::RATE_LIMITED === $e->reason() ) {
				$this->schedule( $id, $e->retry_after );
			} else {
				$this->failed( $id, $e->reason() );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The Printful mockups of a design failed.',
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
	 * One round: create the queued tasks, poll the pending ones.
	 *
	 * @param int $id Version.
	 * @throws PrintfulException When Printful refuses or fails.
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

		$product = (int) $provider['ref'];

		foreach ( $this->tasks->for_version( $id ) as $task ) {
			if ( ProviderTaskRepository::QUEUED === $task['status'] ) {
				$this->create( $id, $product, $version, $base, $provider['placements'], $task );
			} elseif ( ProviderTaskRepository::PENDING === $task['status'] ) {
				$this->poll( $id, $task );
			}
		}

		$states = array_column( $this->tasks->for_version( $id ), 'status' );

		if ( in_array( ProviderTaskRepository::QUEUED, $states, true ) || in_array( ProviderTaskRepository::PENDING, $states, true ) ) {
			$this->schedule( $id, self::POLL_DELAY );

			return;
		}

		if ( in_array( ProviderTaskRepository::FAILED, $states, true ) ) {
			$this->failed( $id, 'task' );

			return;
		}

		$this->products->set_mockup_state( $id, self::READY );
	}

	/**
	 * Creates the task of a side at Printful.
	 *
	 * @param int                   $id         Version.
	 * @param int                   $product    Printful product.
	 * @param array<string, mixed>  $version    Version.
	 * @param BaseProduct           $base       Base product.
	 * @param array<string, string> $placements Printful placement by side.
	 * @param array<string, mixed>  $task       Task row.
	 * @throws PrintfulException When Printful refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function create( int $id, int $product, array $version, BaseProduct $base, array $placements, array $task ): void {
		$side     = (string) $task['side'];
		$variants = $this->variants( $product, $version, $base );
		$view     = null;

		foreach ( $base->views as $candidate ) {
			if ( $candidate->key === $side ) {
				$view = $candidate;
			}
		}

		if ( array() === $variants || null === $view || ! isset( $placements[ $side ] ) ) {
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'error' => 'no_variant' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		[ $width, $height ] = $view->print_pixels();
		$answer             = $this->api->create_task(
			$product,
			array(
				'variant_ids' => array_map( 'intval', array_keys( $variants ) ),
				'format'      => 'jpg',
				'files'       => array(
					array(
						'placement' => $placements[ $side ],
						'image_url' => SignedPrintFiles::url( $id, $side ),
						'position'  => array(
							'area_width'  => $width,
							'area_height' => $height,
							'width'       => $width,
							'height'      => $height,
							'top'         => 0,
							'left'        => 0,
						),
					),
				),
			)
		);
		$key                = is_string( $answer['task_key'] ?? null ) ? $answer['task_key'] : '';

		if ( '' === $key ) {
			self::fail( PrintfulException::INVALID );
		}

		$this->tasks->update(
			(int) $task['id'],
			array(
				'status'   => ProviderTaskRepository::PENDING,
				'task_key' => $key,
				'result'   => (string) wp_json_encode( array( 'variants' => $variants ) ),
			)
		);
	}

	/**
	 * Polls a task and stores its mockups when it is done.
	 *
	 * @param int                  $id   Version.
	 * @param array<string, mixed> $task Task row.
	 * @throws PrintfulException When Printful refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When a mockup is not a valid image.
	 */
	private function poll( int $id, array $task ): void {
		$attempts = (int) $task['attempts'] + 1;
		$answer   = $this->api->task( (string) $task['task_key'] );
		$status   = is_string( $answer['status'] ?? null ) ? $answer['status'] : '';

		if ( 'failed' === $status || ( is_string( $answer['error'] ?? null ) && '' !== $answer['error'] ) ) {
			$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::FAILED, 'attempts' => $attempts, 'error' => is_string( $answer['error'] ?? null ) ? $answer['error'] : 'failed' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		if ( 'completed' !== $status ) {
			$this->tasks->update( (int) $task['id'], self::MAX_POLLS <= $attempts ? array( 'status' => ProviderTaskRepository::FAILED, 'attempts' => $attempts, 'error' => 'timeout' ) : array( 'attempts' => $attempts ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			return;
		}

		$map    = json_decode( (string) $task['result'], true );
		$colors = is_array( $map['variants'] ?? null ) ? $map['variants'] : array();

		$this->store( $id, (string) $task['side'], is_array( $answer['mockups'] ?? null ) ? $answer['mockups'] : array(), $colors );
		$this->tasks->update( (int) $task['id'], array( 'status' => ProviderTaskRepository::DONE, 'attempts' => $attempts ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Downloads the mockups of a side and puts them in place of the browser's.
	 *
	 * @param int                  $id      Version.
	 * @param string               $side    Side.
	 * @param array<mixed>         $mockups Printful's mockups.
	 * @param array<string, mixed> $colors  Colour key by variant id.
	 * @throws PrintfulException When a mockup cannot be downloaded.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When a mockup is not a valid image.
	 */
	private function store( int $id, string $side, array $mockups, array $colors ): void {
		$stored = array();
		$done   = false;

		try {
			foreach ( $mockups as $mockup ) {
				if ( ! is_array( $mockup ) ) {
					continue;
				}

				$url = is_string( $mockup['mockup_url'] ?? null ) ? $mockup['mockup_url'] : ( is_array( $mockup['extra'][0] ?? null ) && is_string( $mockup['extra'][0]['url'] ?? null ) ? $mockup['extra'][0]['url'] : '' );

				foreach ( is_array( $mockup['variant_ids'] ?? null ) ? $mockup['variant_ids'] : array() as $variant ) {
					$color = $colors[ (string) $variant ] ?? null;

					if ( is_string( $color ) && '' !== $url && ! isset( $stored[ $color ] ) ) {
						$stored[ $color ] = $this->download( $url );
					}
				}
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
	 * A mockup as a stored designer file.
	 *
	 * @param string $url Printful's URL.
	 * @return array<string, mixed>
	 * @throws PrintfulException When it cannot be downloaded.
	 * @throws DesignerException When it is not a valid image.
	 */
	private function download( string $url ): array {
		$body = $this->api->download( $url );
		$path = wp_tempnam( 'merchboard-mockup' );

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file of the download.
			self::fail( PrintfulException::INVALID );
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
	 * One Printful variant per chosen colour (the first chosen size it has):
	 * colour key by variant id.
	 *
	 * @param int                  $product Printful product.
	 * @param array<string, mixed> $version Version.
	 * @param BaseProduct          $base    Base product.
	 * @return array<int, string>
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	private function variants( int $product, array $version, BaseProduct $base ): array {
		$chosen   = json_decode( (string) $version['colors'], true );
		$sizes    = json_decode( (string) $version['sizes'], true );
		$names    = array_column( $base->colors, 'name', 'key' );
		$catalog  = $this->api->product( $product );
		$variants = is_array( $catalog['variants'] ?? null ) ? $catalog['variants'] : array();
		$map      = array();

		foreach ( is_array( $chosen ) ? $chosen : array() as $key ) {
			$name = $names[ $key ] ?? null;

			foreach ( is_array( $sizes ) ? $sizes : array() as $size ) {
				foreach ( $variants as $variant ) {
					if ( is_array( $variant ) && ( $variant['color'] ?? null ) === $name && ( $variant['size'] ?? null ) === $size && ! in_array( $key, $map, true ) ) {
						$map[ (int) ( $variant['id'] ?? 0 ) ] = (string) $key;
					}
				}
			}
		}

		unset( $map[0] );

		return $map;
	}

	/**
	 * Marks the mockups failed and tells the administrator once.
	 *
	 * @param int    $id     Version.
	 * @param string $reason Reason.
	 */
	private function failed( int $id, string $reason ): void {
		try {
			$this->products->set_mockup_state( $id, self::FAILED );
			$version = $this->products->version( $id );
		} catch ( Throwable ) {
			return;
		}

		$this->logger->warning(
			'Printful could not make the mockups of a design; the creator\'s mockups stay.',
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
					'title'  => (string) $version['title'],
					'reason' => $reason,
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

		$this->logger->warning( 'The Printful mockups of a design could not be queued.', array( 'version_id' => $id ) );

		return false;
	}
	/**
	 * Fails with a reason.
	 *
	 * @param string $reason PrintfulException reason.
	 * @throws PrintfulException Always.
	 */
	private static function fail( string $reason ): never {
		throw new PrintfulException( $reason );
	}
}
