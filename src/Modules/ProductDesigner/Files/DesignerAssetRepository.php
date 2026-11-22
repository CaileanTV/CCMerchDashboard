<?php
/**
 * Records of the product designer's files.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Files;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_designer_assets` (ADR-080 pkt. 5): one row per stored file.
 * The administrator's files have no creator: template layers (`layer`) and
 * cliparts (`clipart`, and `artwork` with a definition, ADR-121); creators'
 * uploads follow in step 30.8.
 */
class DesignerAssetRepository {

	public const LAYER = 'layer';

	public const CLIPART = 'clipart';

	/**
	 * A clipart for chosen base products, maybe with coloured parts (ADR-121).
	 * Its own kind, so code from before schema 55 refuses it.
	 */
	public const ARTWORK = 'artwork';

	private const COLUMNS = 'id, creator_id, kind, name, file, mime, width, height, bytes, created_at';

	// Apart from COLUMNS: layers and uploads are read also if migration 55 has not run.
	private const CLIPART_COLUMNS = 'id, kind, name, file, mime, width, height, definition, retired_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Takes the named lock of an owner's files (waits up to 10 seconds).
	 *
	 * @param int $owner_id Creator.
	 * @throws DatabaseException When it stays busy.
	 */
	public function lock_owner( int $owner_id ): void {
		$wpdb     = $this->wpdb;
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', 'merchboard_designer_uploads_' . $owner_id, 10 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		if ( ! is_numeric( $acquired ) || 1 !== (int) $acquired ) {
			throw new DatabaseException( 'The creator\'s uploads are busy.' );
		}
	}

	/**
	 * Releases the named lock of an owner's files.
	 *
	 * @param int $owner_id Creator.
	 */
	public function unlock_owner( int $owner_id ): void {
		$wpdb = $this->wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'merchboard_designer_uploads_' . $owner_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
	}

	/**
	 * Records a stored file: the administrator's (no creator) or a creator's upload.
	 *
	 * @param string                                                                 $kind       LAYER, CLIPART or CreatorUploads::UPLOAD.
	 * @param string                                                                 $name       Name shown in the designer.
	 * @param array{file: string, mime: string, width: int, height: int, bytes: int} $stored     DesignerFiles::store().
	 * @param int|null                                                               $creator_id The uploading creator; null for the administrator's files.
	 * @return int The asset id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( string $kind, string $name, array $stored, ?int $creator_id = null ): int {
		$done = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The designer's own table.
			$this->table(),
			array(
				'creator_id' => $creator_id,
				'kind'       => $kind,
				'name'       => mb_substr( $name, 0, 191 ),
				'file'       => $stored['file'],
				'mime'       => $stored['mime'],
				'width'      => $stored['width'],
				'height'     => $stored['height'],
				'bytes'      => $stored['bytes'],
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		if ( 1 !== $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not record the designer file.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * An asset by id.
	 *
	 * @param int $id Id.
	 * @return array{id: int, creator_id: int|null, kind: string, name: string, file: string, mime: string, width: int, height: int, bytes: int, created_at: string}|null
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function find( int $id ): ?array {
		if ( $id < 1 ) {
			return null;
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the designer file.' );
		}

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The administrator's assets of a kind, newest first.
	 *
	 * @param string $kind LAYER or CLIPART.
	 * @return list<array{id: int, creator_id: int|null, kind: string, name: string, file: string, mime: string, width: int, height: int, bytes: int, created_at: string}>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function shared( string $kind ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id IS NULL AND kind = %s ORDER BY id DESC', $this->table(), $kind ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own table.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the designer files.' );
		}

		return array_map( self::hydrate( ... ), $rows );
	}

	/**
	 * A creator's assets of a kind, newest first.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $kind       Kind.
	 * @return list<array{id: int, creator_id: int|null, kind: string, name: string, file: string, mime: string, width: int, height: int, bytes: int, created_at: string}>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function owned( int $creator_id, string $kind ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d AND kind = %s ORDER BY id DESC', $this->table(), $creator_id, $kind ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own table.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the designer files.' );
		}

		return array_map( self::hydrate( ... ), $rows );
	}

	/**
	 * Records an artwork.
	 *
	 * @param string                                                                 $name       Name shown in the designer.
	 * @param array{file: string, mime: string, width: int, height: int, bytes: int} $stored     DesignerFiles::store() of its image or preview.
	 * @param ClipartDefinition                                                      $definition Its definition, of the same size.
	 * @return int The asset id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert_artwork( string $name, array $stored, ClipartDefinition $definition ): int {
		if ( $definition->width !== $stored['width'] || $definition->height !== $stored['height'] ) {
			throw new DatabaseException( 'The clipart\'s definition is of another size.' );
		}

		$done = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The designer's own table.
			$this->table(),
			array(
				'kind'       => self::ARTWORK,
				'name'       => mb_substr( $name, 0, 191 ),
				'file'       => $stored['file'],
				'mime'       => $stored['mime'],
				'width'      => $stored['width'],
				'height'     => $stored['height'],
				'bytes'      => $stored['bytes'],
				'definition' => $definition->to_json(),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( 1 !== $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not record the clipart.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * A clipart of either kind, also a removed one.
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function find_clipart( int $id ): ?Clipart {
		if ( $id < 1 ) {
			return null;
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::CLIPART_COLUMNS . ' FROM %i WHERE id = %d AND creator_id IS NULL AND kind IN (%s, %s)', $this->table(), $id, self::CLIPART, self::ARTWORK ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the clipart.' );
		}

		return is_array( $row ) ? self::clipart( $row ) : null;
	}

	/**
	 * Every clipart of both kinds, also removed ones, newest first.
	 *
	 * @return list<Clipart>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function cliparts(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::CLIPART_COLUMNS . ' FROM %i WHERE creator_id IS NULL AND kind IN (%s, %s) ORDER BY id DESC', $this->table(), self::CLIPART, self::ARTWORK ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own table.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the cliparts.' );
		}

		return array_map( self::clipart( ... ), $rows );
	}

	/**
	 * Removes a clipart from new designs. Its row and files stay, for the
	 * designs that use it.
	 *
	 * @param int $id Id.
	 * @return bool Whether this request removed it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function retire( int $id ): bool {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare( 'UPDATE %i SET retired_at = %s WHERE id = %d AND creator_id IS NULL AND kind IN (%s, %s) AND retired_at IS NULL', $this->table(), gmdate( 'Y-m-d H:i:s' ), $id, self::CLIPART, self::ARTWORK );
		$done = null === $sql ? false : $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the designer's own table.

		if ( false === $done ) {
			throw new DatabaseException( 'Could not remove the clipart.' );
		}

		return 1 === (int) $done;
	}

	/**
	 * Stores an artwork's base products; nothing else of it ever changes.
	 *
	 * @param Clipart    $clipart The artwork as read.
	 * @param int[]|null $bases   Base products; null for all.
	 * @throws DatabaseException When they are not valid or cannot be stored.
	 */
	public function set_bases( Clipart $clipart, ?array $bases ): void {
		$next = $clipart->definition?->with_bases( $bases );

		if ( null === $next ) {
			throw new DatabaseException( 'The clipart\'s base products are not valid.' );
		}

		$done = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table(),
			array( 'definition' => $next->to_json() ),
			array(
				'id'   => $clipart->id,
				'kind' => self::ARTWORK,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the clipart\'s base products.' );
		}
	}

	/**
	 * A clipart from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function clipart( array $row ): Clipart {
		$asset   = self::hydrate( $row );
		$retired = $row['retired_at'] ?? null;

		return new Clipart(
			$asset['id'],
			$asset['kind'],
			$asset['name'],
			$asset['file'],
			$asset['mime'],
			$asset['width'],
			$asset['height'],
			is_string( $retired ) && '' !== $retired ? $retired : null,
			self::ARTWORK === $asset['kind'] ? ClipartDefinition::from_stored( $row['definition'] ?? null, $asset['width'], $asset['height'] ) : null
		);
	}

	/**
	 * Deletes a record (the caller deletes the file).
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $id ): void {
		$done = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not delete the designer file.' );
		}
	}

	/**
	 * A record from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array{id: int, creator_id: int|null, kind: string, name: string, file: string, mime: string, width: int, height: int, bytes: int, created_at: string}
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): array {
		$file = (string) ( $row['file'] ?? '' );

		if ( 1 !== preg_match( DesignerFiles::NAME_PATTERN, $file ) || ! is_numeric( $row['id'] ?? null ) ) {
			throw new DatabaseException( 'Invalid designer file row.' );
		}

		return array(
			'id'         => (int) $row['id'],
			'creator_id' => is_numeric( $row['creator_id'] ?? null ) ? (int) $row['creator_id'] : null,
			'kind'       => (string) ( $row['kind'] ?? '' ),
			'name'       => (string) ( $row['name'] ?? '' ),
			'file'       => $file,
			'mime'       => (string) ( $row['mime'] ?? '' ),
			'width'      => (int) ( $row['width'] ?? 0 ),
			'height'     => (int) ( $row['height'] ?? 0 ),
			'bytes'      => (int) ( $row['bytes'] ?? 0 ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
		);
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::DESIGNER_ASSETS );
	}
}
