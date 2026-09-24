<?php
/**
 * Data access for the audit trail.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Audit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only access to `{prefix}merchboard_audit_log`.
 *
 * There are deliberately no update or delete methods: audit records are never
 * changed by the application. Removal only happens through the opt-in
 * "delete all data on uninstall" (roadmap phase 9). Like other repositories it
 * performs no authorization; only prepared queries and whitelisted tables.
 */
final class AuditRepository {

	/**
	 * Maximum records returned by a read.
	 */
	private const MAX_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Appends a record.
	 *
	 * @param AuditAction          $action        Action.
	 * @param int|null             $actor_user_id Actor; null for system actions.
	 * @param int|null             $creator_id    Affected creator.
	 * @param array<string, mixed> $context       Already sanitized context.
	 * @param string               $created_at    UTC DATETIME ('Y-m-d H:i:s').
	 * @return int Record id.
	 * @throws InvalidArgumentException When an id is not positive.
	 * @throws DatabaseException When the insert fails.
	 */
	public function append( AuditAction $action, ?int $actor_user_id, ?int $creator_id, array $context, string $created_at ): int {
		foreach ( array( $actor_user_id, $creator_id ) as $id ) {
			if ( null !== $id && $id < 1 ) {
				throw new InvalidArgumentException( 'Audit ids must be positive integers or null.' );
			}
		}

		// Non-ASCII is escaped (\uXXXX), so the stored JSON is plain ASCII and the
		// insert cannot fail on a table charset without 4-byte UTF-8 (e.g. legacy
		// utf8mb3), which would roll back the business change with it. Invalid
		// UTF-8 or non-finite numbers are substituted instead of failing the write.
		$json = array() === $context ? null : wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );

		$inserted = $this->wpdb->insert(
			Tables::name( $this->wpdb, Tables::AUDIT_LOG ),
			array(
				'created_at'    => $created_at,
				'action'        => $action->value,
				'actor_user_id' => $actor_user_id,
				'creator_id'    => $creator_id,
				'context'       => false === $json ? null : $json,
			),
			array( '%s', '%s', '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			throw new DatabaseException( 'Could not write audit record.', array( 'action' => $action->value ) );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Most recent records for a creator, newest first. An invalid stored row
	 * raises a DatabaseException.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $limit      Maximum number of records (1-200).
	 * @return list<AuditEntry>
	 * @throws InvalidArgumentException When the creator id is not positive.
	 */
	public function for_creator( int $creator_id, int $limit = 50 ): array {
		if ( $creator_id < 1 ) {
			throw new InvalidArgumentException( 'Creator id must be a positive integer.' );
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );
		$wpdb  = $this->wpdb;
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, action, actor_user_id, creator_id, context FROM %i WHERE creator_id = %d ORDER BY id DESC LIMIT %d',
				Tables::name( $wpdb, Tables::AUDIT_LOG ),
				$creator_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Builds an entry from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row does not match the schema.
	 */
	private function hydrate( array $row ): AuditEntry {
		$action     = AuditAction::tryFrom( is_string( $row['action'] ?? null ) ? $row['action'] : '' );
		$created_at = is_string( $row['created_at'] ?? null )
			? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $row['created_at'], new DateTimeZone( 'UTC' ) )
			: false;

		if ( null === $action || false === $created_at || ! is_numeric( $row['id'] ?? null ) ) {
			throw new DatabaseException( 'Invalid audit row.' );
		}

		$context = is_string( $row['context'] ?? null ) ? json_decode( $row['context'], true ) : array();

		return new AuditEntry(
			(int) $row['id'],
			$action,
			is_numeric( $row['actor_user_id'] ?? null ) ? (int) $row['actor_user_id'] : null,
			is_numeric( $row['creator_id'] ?? null ) ? (int) $row['creator_id'] : null,
			is_array( $context ) ? $context : array(),
			$created_at
		);
	}
}
