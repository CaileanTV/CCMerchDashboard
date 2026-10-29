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
 * cliparts (`clipart`); creators' uploads follow in step 30.8.
 */
class DesignerAssetRepository {

	public const LAYER = 'layer';

	public const CLIPART = 'clipart';

	private const COLUMNS = 'id, creator_id, kind, name, file, mime, width, height, bytes, created_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
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
