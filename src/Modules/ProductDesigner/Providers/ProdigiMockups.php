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

			if ( null === $version || null === $provider || ProdigiCatalog::SOURCE !== $provider['source'] || array() === self::sides( $version, $provider['placements'] ) ) {
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

		foreach ( self::sides( $version, $placements ) as $side ) {
			$assets[ $side ] = $this->api->ingest_asset( SignedPrintFiles::url( $id, $side ), (string) $version['title'] . ' ' . $side );
		}

		return array(
			'phase'  => 'assets',
			'assets' => $assets,
		);
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

		$map['product'] = $this->api->create_product(
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
						$colors
					)
				),
			)
		);
		$this->tasks->update( $task, array( 'result' => (string) wp_json_encode( $map ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$saved = array();

		foreach ( $this->api->product_variants( (string) $map['product'] ) as $variant ) {
			if ( is_array( $variant ) && is_scalar( $variant['id'] ?? null ) ) {
				$saved[] = $variant;
			}
		}

		$assets  = is_array( $map['assets'] ?? null ) ? $map['assets'] : array();
		$mockups = array();

		foreach ( $assets as $side => $asset ) {
			$this->api->bind_asset( (string) $map['product'], (string) $asset, $provider['placements'][ (string) $side ] ?? '' );
		}

		foreach ( $colors as $key => $color ) {
			$variant = self::saved_variant( $saved, $color );

			if ( null === $variant ) {
				continue;
			}

			foreach ( $assets as $side => $asset ) {
				$mockups[] = array(
					'side'   => (string) $side,
					'color'  => (string) $key,
					'asset'  => (string) $asset,
					'mockup' => $this->api->create_mockup( (string) $asset, (string) $map['product'], $variant ),
				);
			}
		}

		$map['phase']   = 'mockups';
		$map['mockups'] = $mockups;

		return $map;
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
		$map = is_array( $map ) ? $map : array();

		try {
			if ( is_string( $map['product'] ?? null ) ) {
				$this->api->delete_product( $map['product'] );
			}

			foreach ( is_array( $map['assets'] ?? null ) ? $map['assets'] : array() as $asset ) {
				$this->api->delete_asset( (string) $asset );
			}
		} catch ( ProdigiException $e ) {
			$this->logger->warning(
				'What was saved at Prodigi for a mockup could not be deleted.',
				array(
					'reason' => $e->reason(),
					'call'   => $e->call,
				)
			);
		}
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
	 * The version's used sides with a print area, in the base product's order.
	 *
	 * @param array<string, mixed>  $version    Version.
	 * @param array<string, string> $placements Print area by side.
	 * @return list<string>
	 */
	private static function sides( array $version, array $placements ): array {
		$used = SubmissionFiles::decode( is_string( $version['files'] ) ? $version['files'] : null )['print'];

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
