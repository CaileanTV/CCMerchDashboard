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

			if ( null === $version || null === $provider || 'gelato' !== $provider['source'] || array() === self::sides( $version, $provider['placements'] ) ) {
				return;
			}

			$this->tasks->create( $id, 'gelato', self::TASK_SIDE, '{}' );
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

		foreach ( $this->tasks->for_version( $id ) as $task ) {
			if ( 'gelato' !== $task['provider'] ) {
				continue;
			}

			if ( ProviderTaskRepository::QUEUED === $task['status'] ) {
				$this->create( $id, $provider['ref'], $provider['placements'], $version, $base, $task, $provider['variants'] );
			} elseif ( ProviderTaskRepository::PENDING === $task['status'] ) {
				$this->poll( $id, $provider['placements'], $version, $task, $provider['slots'] );
			}

			$status = '';

			foreach ( $this->tasks->for_version( $id ) as $current ) {
				$status = (int) $current['id'] === (int) $task['id'] ? (string) $current['status'] : $status;
			}

			if ( ProviderTaskRepository::FAILED === $status ) {
				$this->failed( $id, 'task' );
			} elseif ( ProviderTaskRepository::DONE === $status ) {
				$this->products->set_mockup_state( $id, PrintfulMockups::READY );
			} else {
				$this->schedule( $id, self::POLL_DELAY );
			}

			return;
		}
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

		$images = array();

		foreach ( $sides as $side ) {
			$images[] = array(
				'name'      => $placeholders[ $side ],
				'fileUrl'   => SignedPrintFiles::url( $id, $side ),
				'fitMethod' => 'meet',
			);
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
						'imagePlaceholders' => $images,
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
	 * @param array<string, mixed>  $task         Task row.
	 * @param array<string, string> $slots        The image of each side among a variant's images (the catalog import).
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DatabaseException When it cannot be stored.
	 * @throws DesignerException When a mockup is not a valid image.
	 */
	private function poll( int $id, array $placeholders, array $version, array $task, array $slots = array() ): void {
		$attempts = (int) $task['attempts'] + 1;
		$answer   = $this->api->product( (string) $task['task_key'] );
		$status   = is_string( $answer['status'] ?? null ) ? $answer['status'] : '';
		$map      = json_decode( (string) $task['result'], true );
		$colors   = array_values( array_filter( is_array( $map['colors'] ?? null ) ? $map['colors'] : array(), 'is_string' ) );
		$variants = array();

		foreach ( $colors as $color ) {
			$variants[ $color ] = is_array( $map['variants'] ?? null ) && is_scalar( $map['variants'][ $color ] ?? null ) ? (string) $map['variants'][ $color ] : '';
		}

		// Only an active product has its images; any other status is waited for (review 30.15).
		$images = 'active' === $status ? $this->images( $answer, $variants, self::sides( $version, $placeholders ), $slots ) : array();

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
	 * The version's used sides that have a placeholder, in the base product's order.
	 *
	 * @param array<string, mixed>  $version      Version.
	 * @param array<string, string> $placeholders Placeholder by side.
	 * @return list<string>
	 */
	private static function sides( array $version, array $placeholders ): array {
		$used = SubmissionFiles::decode( is_string( $version['files'] ) ? $version['files'] : null )['print'];

		return array_values( array_filter( array_map( 'strval', array_keys( $placeholders ) ), static fn( string $side ): bool => isset( $used[ $side ] ) ) );
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
