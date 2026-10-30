<?php
/**
 * The embroidery packs: the thread colours a side may be embroidered in.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner;

use GdImage;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A pack is a named set of threads and the most of them one embroidered side
 * may use. A side of a base product points at a pack; a side without one is
 * printed and has free colours (the owner's choice, 2026-10-30).
 *
 * Stored in its own option (not autoloaded). Without stored packs the Gelato
 * pack exists anyway. A changed pack counts from the next time a design is
 * saved: products in the shop and sent designs are not touched. A pack in
 * use cannot be deleted.
 *
 * @phpstan-type Thread array{code: string, name: string, hex: string}
 * @phpstan-type Pack array{id: string, name: string, max: int, threads: list<Thread>}
 */
class ThreadPacks {

	/**
	 * The option.
	 */
	public const OPTION = 'merchboard_thread_packs';

	/**
	 * The pack that exists without stored packs.
	 */
	public const DEFAULT_ID = 'gelato';

	/**
	 * Most threads in a pack.
	 */
	public const MAX_THREADS = 64;

	/**
	 * The most one channel of a print file may differ from a thread: some
	 * browsers add noise to a canvas.
	 */
	private const TOLERANCE = 2;

	/**
	 * The most pixels of a print file that are looked at.
	 */
	private const MAX_SAMPLES = 1000000;

	/**
	 * Gelato's threads.
	 */
	private const GELATO = array(
		'1801' => array( 'White', '#ffffff' ),
		'1895' => array( 'Aqua', '#349aff' ),
		'1611' => array( 'Grey', '#95a0a7' ),
		'1800' => array( 'Black', '#000000' ),
		'1567' => array( 'Maroon', '#670101' ),
		'1934' => array( 'Royal', '#015498' ),
		'1643' => array( 'Navy', '#343467' ),
		'1988' => array( 'Kelly Green', '#41894b' ),
		'1848' => array( 'Light Green', '#7ca45b' ),
		'1838' => array( 'Red', '#cb333b' ),
		'1910' => array( 'Pink', '#cb3265' ),
		'1922' => array( 'Purple', '#6c5395' ),
		'1624' => array( 'Yellow', '#ffcd01' ),
		'1672' => array( 'Gold', '#a57742' ),
		'1678' => array( 'Orange', '#da5f3d' ),
	);

	/**
	 * Constructor.
	 *
	 * @param AuditTrail            $audit       Audit trail.
	 * @param Transaction           $transaction Transaction.
	 * @param BaseProductRepository $bases       Base products.
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction, private BaseProductRepository $bases ) {
	}

	/**
	 * The packs, in the order shown.
	 *
	 * @return list<Pack>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$packs  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $pack ) {
			$clean = self::clean( $pack );

			if ( null !== $clean ) {
				$packs[] = $clean;
			}
		}

		return array() === $packs ? array( self::standard() ) : $packs;
	}

	/**
	 * A pack.
	 *
	 * @param string $id Pack id.
	 * @return Pack|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $pack ) {
			if ( $id === $pack['id'] ) {
				return $pack;
			}
		}

		return null;
	}

	/**
	 * Saves a pack.
	 *
	 * @param string                      $id   Pack id; empty for a new pack.
	 * @param string                      $name Name.
	 * @param string                      $max  Most threads on one side.
	 * @param array<array<string, mixed>> $rows Threads: `code`, `name`, `hex` and `remove`. Empty rows are left out.
	 * @return bool Whether anything changed.
	 * @throws DesignerException When the user may not or a value is refused.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save( string $id, string $name, string $max, array $rows ): bool {
		self::authorize();

		$packs   = self::all();
		$threads = array();

		foreach ( $rows as $row ) {
			$code = trim( is_string( $row['code'] ?? null ) ? $row['code'] : '' );
			$text = trim( sanitize_text_field( is_string( $row['name'] ?? null ) ? $row['name'] : '' ) );

			if ( true === ( $row['remove'] ?? false ) || ( '' === $code && '' === $text ) ) {
				continue;
			}

			$threads[] = array(
				'code' => $code,
				'name' => $text,
				'hex'  => is_string( $row['hex'] ?? null ) ? $row['hex'] : '',
			);
		}

		$index = '' === $id ? null : array_search( $id, array_column( $packs, 'id' ), true );

		if ( false === $index ) {
			self::reject( DesignerException::THREAD_PACK );
		}

		$pack = self::clean(
			array(
				'id'      => null === $index ? self::new_id( $name, $packs ) : $id,
				'name'    => trim( sanitize_text_field( $name ) ),
				'max'     => 1 === preg_match( '/^\d{1,3}$/', trim( $max ) ) ? (int) trim( $max ) : 0,
				'threads' => $threads,
			)
		) ?? self::reject( DesignerException::THREAD_PACK );

		if ( null !== $index && $packs[ $index ] === $pack ) {
			return false;
		}

		$previous = null === $index ? array() : $packs[ $index ]['threads'];

		if ( null === $index ) {
			$packs[] = $pack;
		} else {
			$packs[ $index ] = $pack;
		}

		$this->store( $packs, $pack['id'], $previous, $pack['threads'] );

		return true;
	}

	/**
	 * Deletes a pack no side of a base product uses.
	 *
	 * @param string $id Pack id.
	 * @throws DesignerException When the user may not, it is unknown or in use.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function delete( string $id ): void {
		self::authorize();

		$pack = self::find( $id ) ?? self::reject( DesignerException::THREAD_PACK );

		foreach ( $this->bases->all() as $base ) {
			foreach ( $base->views as $view ) {
				if ( $id === $view->thread_pack ) {
					self::reject( DesignerException::THREAD_PACK_IN_USE );
				}
			}
		}

		$left = array_values( array_filter( self::all(), static fn( array $other ): bool => $id !== $other['id'] ) );

		$this->store( $left, $id, $pack['threads'], array() );
	}

	/**
	 * Whether a print file keeps to a pack: every visible pixel looked at is
	 * one of its threads, fully opaque, and it uses no more of them than the
	 * pack allows. A sample of the pixels, on top of the administrator's
	 * approval. Without GD the file cannot be read and is let through.
	 *
	 * @param string                                                                                                   $path Path of the PNG.
	 * @param array{id: string, name: string, max: int, threads: list<array{code: string, name: string, hex: string}>} $pack The side's pack.
	 */
	public static function check( string $path, array $pack ): bool {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return true;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of the plugin's own directory.
		$image = false === $bytes || 'PNG' !== substr( $bytes, 1, 3 ) ? false : imagecreatefromstring( $bytes );

		if ( false === $image ) {
			return false;
		}

		imagepalettetotruecolor( $image );

		$used = self::threads_used( $image, array_map( static fn( array $thread ): int => (int) hexdec( substr( $thread['hex'], 1 ) ), $pack['threads'] ) );

		return null !== $used && $used <= $pack['max'];
	}

	/**
	 * How many threads an image uses.
	 *
	 * @param GdImage         $image   The image.
	 * @param array<int, int> $threads The pack's threads (0xRRGGBB).
	 * @return int|null Null when a pixel is not a thread.
	 */
	private static function threads_used( GdImage $image, array $threads ): ?int {
		$width  = imagesx( $image );
		$height = imagesy( $image );
		$step   = max( 1, (int) ceil( sqrt( $width * $height / self::MAX_SAMPLES ) ) );
		$used   = array();
		$seen   = array();

		foreach ( range( 0, $height - 1, $step ) as $y ) {
			foreach ( range( 0, $width - 1, $step ) as $x ) {
				$pixel = imagecolorat( $image, $x, $y );

				if ( false === $pixel ) {
					return null;
				}

				$alpha = ( $pixel >> 24 ) & 0x7F;

				if ( 127 === $alpha ) {
					continue;
				}

				if ( 0 !== $alpha ) {
					return null;
				}

				$seen[ $pixel ] ??= self::thread_of( $pixel, $threads );

				if ( -1 === $seen[ $pixel ] ) {
					return null;
				}

				$used[ $seen[ $pixel ] ] = true;
			}
		}

		return count( $used );
	}

	/**
	 * The thread a colour is, within the tolerance.
	 *
	 * @param int             $color   0xRRGGBB.
	 * @param array<int, int> $threads The pack's threads (0xRRGGBB).
	 * @return int The thread, or -1.
	 */
	private static function thread_of( int $color, array $threads ): int {
		foreach ( $threads as $thread ) {
			if (
				abs( ( ( $color >> 16 ) & 0xFF ) - ( ( $thread >> 16 ) & 0xFF ) ) <= self::TOLERANCE
				&& abs( ( ( $color >> 8 ) & 0xFF ) - ( ( $thread >> 8 ) & 0xFF ) ) <= self::TOLERANCE
				&& abs( ( $color & 0xFF ) - ( $thread & 0xFF ) ) <= self::TOLERANCE
			) {
				return $thread;
			}
		}

		return -1;
	}

	/**
	 * The Gelato pack.
	 *
	 * @return Pack
	 */
	private static function standard(): array {
		$threads = array();

		foreach ( self::GELATO as $code => [ $name, $hex ] ) {
			$threads[] = array(
				'code' => (string) $code,
				'name' => $name,
				'hex'  => $hex,
			);
		}

		return array(
			'id'      => self::DEFAULT_ID,
			'name'    => 'Gelato',
			'max'     => 6,
			'threads' => $threads,
		);
	}

	/**
	 * A valid pack, or null.
	 *
	 * @param mixed $pack Stored or entered pack.
	 * @return Pack|null
	 */
	private static function clean( mixed $pack ): ?array {
		if ( ! is_array( $pack ) || ! is_string( $pack['id'] ?? null ) || ! is_string( $pack['name'] ?? null ) || ! is_int( $pack['max'] ?? null ) || ! is_array( $pack['threads'] ?? null ) ) {
			return null;
		}

		$threads = array();

		foreach ( $pack['threads'] as $thread ) {
			$code = is_array( $thread ) ? ( $thread['code'] ?? null ) : null;
			$name = is_array( $thread ) ? ( $thread['name'] ?? null ) : null;
			$hex  = is_array( $thread ) ? ( $thread['hex'] ?? null ) : null;

			if ( ! is_string( $code ) || ! is_string( $name ) || ! is_string( $hex ) || 1 !== preg_match( '/^[A-Za-z0-9-]{1,16}$/', $code ) || '' === $name || mb_strlen( $name ) > 40 || 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) || isset( $threads[ $code ] ) ) {
				return null;
			}

			$threads[ $code ] = array(
				'code' => $code,
				'name' => $name,
				'hex'  => strtolower( $hex ),
			);
		}

		$count = count( $threads );

		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $pack['id'] ) || '' === $pack['name'] || mb_strlen( $pack['name'] ) > 60 || $count < 1 || $count > self::MAX_THREADS || $pack['max'] < 1 || $pack['max'] > $count ) {
			return null;
		}

		return array(
			'id'      => $pack['id'],
			'name'    => $pack['name'],
			'max'     => $pack['max'],
			'threads' => array_values( $threads ),
		);
	}

	/**
	 * The id of a new pack.
	 *
	 * @param string           $name  Its name.
	 * @param array<int, Pack> $packs The packs.
	 */
	private static function new_id( string $name, array $packs ): string {
		$base   = substr( sanitize_title( $name ), 0, 28 );
		$id     = $base;
		$number = 2;

		while ( in_array( $id, array_column( $packs, 'id' ), true ) ) {
			$id = $base . '-' . $number;
			++$number;
		}

		return $id;
	}

	/**
	 * Stores the packs, audited in the same transaction.
	 *
	 * @param array<int, Pack>   $packs    The packs.
	 * @param string             $id       The pack that changed.
	 * @param array<int, Thread> $previous Its threads before.
	 * @param array<int, Thread> $threads  Its threads now.
	 * @throws DatabaseException When they cannot be stored.
	 */
	private function store( array $packs, string $id, array $previous, array $threads ): void {
		$this->transaction->run(
			function () use ( $packs, $id, $previous, $threads ): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting'        => self::OPTION . ':' . $id,
						'previous_value' => implode( ',', array_column( $previous, 'code' ) ),
						'value'          => implode( ',', array_column( $threads, 'code' ) ),
					)
				);

				if ( ! update_option( self::OPTION, $packs, false ) ) {
					throw new DatabaseException( 'Could not save the embroidery packs.' );
				}
			}
		);
	}

	/**
	 * Requires `merchboard_manage_designer`.
	 *
	 * @throws DesignerException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			throw new DesignerException( DesignerException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Refuses a request.
	 *
	 * @param string $reason Reason.
	 * @throws DesignerException Always.
	 */
	private static function reject( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
