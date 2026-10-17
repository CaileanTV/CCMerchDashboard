<?php
/**
 * Storage of recalculation exports (ADR-062 pkt. 9, ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Recalculation;

use DateTimeImmutable;
use JsonException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_payout_recalculation_exports` and the reads an export is made
 * from. An export is inserted and filled once, inside the transaction that
 * creates it; it is never changed or deleted afterwards.
 */
final class PayoutRecalculationExportRepository {

	/**
	 * Audit actions of a recalculation's lifecycle (its timeline). Exports and
	 * downloads are not part of it, so two exports of the same state compare
	 * equal.
	 */
	private const TIMELINE_ACTIONS = array(
		AuditAction::PayoutRecalculationPreviewed,
		AuditAction::PayoutRecalculationApproved,
		AuditAction::PayoutRecalculationRejected,
		AuditAction::PayoutRecalculationReopened,
		AuditAction::PayoutAdjustmentExecuted,
		AuditAction::PayoutRecalculationItemFailed,
		AuditAction::PayoutRecalculationExecuted,
		AuditAction::PayoutRecalculationClosed,
	);

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Inserts an empty export to reserve its id (inside the creating transaction).
	 *
	 * @param int               $recalculation_id Recalculation.
	 * @param string            $filters          Filters as JSON.
	 * @param int|null          $created_by       Acting user.
	 * @param DateTimeImmutable $now              Time.
	 * @return int Export id.
	 * @throws DatabaseException When the insert fails.
	 */
	public function reserve( int $recalculation_id, string $filters, ?int $created_by, DateTimeImmutable $now ): int {
		$wpdb = $this->wpdb;
		$this->write( $wpdb->prepare( "INSERT INTO %i (recalculation_id, filters, files, created_by, created_at) VALUES (%d, %s, '', NULLIF(%d, 0), %s)", $this->table( Tables::RECALCULATION_EXPORTS ), $recalculation_id, $filters, $created_by ?? 0, PayoutRecalculationRepository::utc( $now ) ) );
		$id = (int) $wpdb->insert_id;

		if ( $id < 1 ) {
			throw new DatabaseException( 'Could not store the export.' );
		}

		return $id;
	}

	/**
	 * Stores the files of a reserved export, once (only while it is empty).
	 *
	 * @param int                   $id              Export.
	 * @param array<string, string> $files           File name => content.
	 * @param string                $manifest_sha256 SHA-256 of manifest.json.
	 * @param string                $sums_sha256     SHA-256 of SHA256SUMS.
	 * @throws DatabaseException When it fails or the export is not empty.
	 */
	public function fill( int $id, array $files, string $manifest_sha256, string $sums_sha256 ): void {
		try {
			$json = json_encode( $files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON_THROW_ON_ERROR.
		} catch ( JsonException ) {
			throw new DatabaseException( 'Could not encode the export.' );
		}

		$this->write( $this->wpdb->prepare( "UPDATE %i SET files = %s, manifest_sha256 = %s, sums_sha256 = %s WHERE id = %d AND files = ''", $this->table( Tables::RECALCULATION_EXPORTS ), $json, $manifest_sha256, $sums_sha256, $id ) );
	}

	/**
	 * An export, or null.
	 *
	 * @param int $id Export.
	 * @return array{id: int, recalculation_id: int, filters: string, files: array<string, string>|null, manifest_sha256: string, sums_sha256: string, created_by: int|null, created_at: string}|null Files null when they cannot be read.
	 * @throws DatabaseException When the read fails.
	 */
	public function find( int $id ): ?array {
		$wpdb = $this->wpdb;
		$rows = $this->rows( $wpdb->prepare( 'SELECT id, recalculation_id, filters, files, manifest_sha256, sums_sha256, created_by, created_at FROM %i WHERE id = %d', $this->table( Tables::RECALCULATION_EXPORTS ), $id ) );

		return array() === $rows ? null : self::export( $rows[0] );
	}

	/**
	 * The exports of a recalculation, newest first.
	 *
	 * @param int $recalculation_id Recalculation.
	 * @return list<array{id: int, recalculation_id: int, filters: string, files: array<string, string>|null, manifest_sha256: string, sums_sha256: string, created_by: int|null, created_at: string}>
	 * @throws DatabaseException When the read fails.
	 */
	public function for_recalculation( int $recalculation_id ): array {
		$wpdb = $this->wpdb;

		return array_map( array( self::class, 'export' ), $this->rows( $wpdb->prepare( 'SELECT id, recalculation_id, filters, files, manifest_sha256, sums_sha256, created_by, created_at FROM %i WHERE recalculation_id = %d ORDER BY id DESC', $this->table( Tables::RECALCULATION_EXPORTS ), $recalculation_id ) ) );
	}

	/**
	 * The latest earlier export of a recalculation with the same filters.
	 *
	 * @param int    $recalculation_id Recalculation.
	 * @param string $filters          Filters as JSON.
	 * @param int    $before           Export id it must precede.
	 * @return array{id: int, recalculation_id: int, filters: string, files: array<string, string>|null, manifest_sha256: string, sums_sha256: string, created_by: int|null, created_at: string}|null
	 * @throws DatabaseException When the read fails.
	 */
	public function previous( int $recalculation_id, string $filters, int $before ): ?array {
		$wpdb = $this->wpdb;
		$rows = $this->rows( $wpdb->prepare( "SELECT id, recalculation_id, filters, files, manifest_sha256, sums_sha256, created_by, created_at FROM %i WHERE recalculation_id = %d AND filters = %s AND id < %d AND files <> '' ORDER BY id DESC LIMIT 1", $this->table( Tables::RECALCULATION_EXPORTS ), $recalculation_id, $filters, $before ) );

		return array() === $rows ? null : self::export( $rows[0] );
	}

	/**
	 * The audit records of a recalculation's lifecycle, oldest first.
	 *
	 * @param string $reference Recalculation reference (R2-000001).
	 * @return list<array{created_at: string, action: string, actor_user_id: int|null, creator_id: int|null, context: string}>
	 * @throws DatabaseException When the read fails.
	 */
	public function timeline( string $reference ): array {
		$wpdb    = $this->wpdb;
		$actions = array_map( static fn( AuditAction $action ): string => $action->value, self::TIMELINE_ACTIONS );
		$holders = implode( ', ', array_fill( 0, count( $actions ), '%s' ) );
		$sql     = $wpdb->prepare( "SELECT created_at, action, actor_user_id, creator_id, context FROM %i WHERE action IN ({$holders}) AND context LIKE %s ORDER BY id", $this->table( Tables::AUDIT_LOG ), ...array_merge( $actions, array( '%' . $wpdb->esc_like( '"reference":"' . $reference . '"' ) . '%' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders only.

		return array_map(
			static fn( array $row ): array => array(
				'created_at'    => (string) $row['created_at'],
				'action'        => (string) $row['action'],
				'actor_user_id' => null === $row['actor_user_id'] ? null : (int) $row['actor_user_id'],
				'creator_id'    => null === $row['creator_id'] ? null : (int) $row['creator_id'],
				'context'       => (string) $row['context'],
			),
			$this->rows( $sql )
		);
	}

	/**
	 * The adjustment of each executed item of a recalculation, with the
	 * settlement document that settled it.
	 *
	 * @param int $recalculation_id Recalculation.
	 * @return array<int, array{id: int, amount: string, document: string}> Keyed by item id.
	 * @throws DatabaseException When the read fails.
	 */
	public function adjustments( int $recalculation_id ): array {
		$wpdb   = $this->wpdb;
		$result = array();
		$rows   = $this->rows( $wpdb->prepare( 'SELECT a.id, a.recalculation_item_id, a.amount, d.document_number FROM %i a LEFT JOIN %i d ON d.id = a.settled_document_id WHERE a.recalculation_id = %d ORDER BY a.id', $this->table( Tables::PAYOUT_ADJUSTMENTS ), $this->table( Tables::SETTLEMENT_DOCUMENTS ), $recalculation_id ) );

		foreach ( $rows as $row ) {
			$result[ (int) $row['recalculation_item_id'] ] = array(
				'id'       => (int) $row['id'],
				'amount'   => (string) $row['amount'],
				'document' => null === $row['document_number'] ? '' : (string) $row['document_number'],
			);
		}

		return $result;
	}

	/**
	 * An export from a row.
	 *
	 * @param array<mixed> $row Row.
	 * @return array{id: int, recalculation_id: int, filters: string, files: array<string, string>|null, manifest_sha256: string, sums_sha256: string, created_by: int|null, created_at: string}
	 */
	private static function export( array $row ): array {
		$files = null;

		try {
			$decoded = json_decode( (string) $row['files'], true, 4, JSON_THROW_ON_ERROR );

			if ( is_array( $decoded ) ) {
				$files = array();

				foreach ( $decoded as $name => $content ) {
					if ( is_string( $content ) ) {
						$files[ (string) $name ] = $content;
					}
				}
			}
		} catch ( JsonException ) {
			$files = null;
		}

		return array(
			'id'               => (int) $row['id'],
			'recalculation_id' => (int) $row['recalculation_id'],
			'filters'          => (string) $row['filters'],
			'files'            => $files,
			'manifest_sha256'  => (string) $row['manifest_sha256'],
			'sums_sha256'      => (string) $row['sums_sha256'],
			'created_by'       => null === $row['created_by'] ? null : (int) $row['created_by'],
			'created_at'       => (string) $row['created_at'],
		);
	}

	/**
	 * Runs a write that must change exactly one row.
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 * @throws DatabaseException When it fails or changes no row.
	 */
	private function write( ?string $sql ): void {
		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the export write.' );
		}

		$wpdb   = $this->wpdb;
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the result of $wpdb->prepare().

		if ( false === $result || '' !== $wpdb->last_error || 1 !== $result ) {
			throw new DatabaseException( 'Could not write the export.' );
		}
	}

	/**
	 * Rows of a prepared SELECT.
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 * @return list<array<mixed>>
	 * @throws DatabaseException When the read fails.
	 */
	private function rows( ?string $sql ): array {
		$wpdb = $this->wpdb;
		$rows = null === $sql ? null : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers pass the result of $wpdb->prepare(); never cached.

		if ( null === $sql || '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the exports.' );
		}

		return $rows;
	}

	/**
	 * A full table name.
	 *
	 * @param string $table Tables constant.
	 */
	private function table( string $table ): string {
		return Tables::name( $this->wpdb, $table );
	}
}
