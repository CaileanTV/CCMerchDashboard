<?php
/**
 * Schema version 33: the creators' bios and social links become proposals.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database\Migrations;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migration;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\Proposals\SocialPlatforms;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * From 1.3.0 the creator's description and social links live in their
 * product category, written only when an administrator approves them
 * (phase 28, ADR-077 pkt. 7, owner's decision 2026-10-26). Each creator's
 * stored `bio` becomes a pending `description` proposal and each social link
 * a pending `social_{platform}` proposal (`created_by` NULL). "Website" and
 * links outside the platform's domains are dropped. The two creator meta
 * keys are deleted afterwards.
 *
 * Idempotent: a field with a pending proposal is not proposed again, and
 * meta that is gone has nothing to move.
 */
final class MoveProfileToProposals implements Migration {

	/**
	 * The creator meta keys of the former profile.
	 */
	public const META_KEYS = array( 'bio', 'social_links' );

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a proposal or the deletion cannot be written.
	 */
	public function up( wpdb $wpdb ): void {
		$meta      = Tables::name( $wpdb, Tables::CREATOR_META );
		$proposals = Tables::name( $wpdb, Tables::CATEGORY_PROPOSALS );
		$rows      = $wpdb->get_results( $wpdb->prepare( 'SELECT merchboard_creator_id AS creator_id, meta_key, meta_value FROM %i WHERE meta_key IN (%s, %s) ORDER BY meta_id ASC', $meta, self::META_KEYS[0], self::META_KEYS[1] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration of the plugin's own table.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the creators\' profiles.' );
		}

		if ( array() === $rows ) {
			return;
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$dropped = 0;

		foreach ( $rows as $row ) {
			$creator_id = (int) $row['creator_id'];

			foreach ( self::values( (string) $row['meta_key'], maybe_unserialize( (string) $row['meta_value'] ), $dropped ) as $field => $value ) {
				$key     = $creator_id . ':' . $field;
				$pending = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pending_key = %s', $proposals, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration of the plugin's own table.

				if ( ! is_numeric( $pending ) ) {
					throw new DatabaseException( 'Could not read the category proposals.' );
				}

				if ( 0 < (int) $pending ) {
					continue;
				}

				$inserted = $wpdb->insert(
					$proposals,
					array(
						'creator_id'  => $creator_id,
						'field'       => $field,
						'value'       => $value,
						'status'      => 'pending',
						'pending_key' => $key,
						'created_at'  => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s' )
				);

				if ( 1 !== $inserted ) {
					throw new DatabaseException( 'Could not store a category proposal.' );
				}
			}
		}

		$delete = $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s)', $meta, self::META_KEYS[0], self::META_KEYS[1] );

		if ( null === $delete || false === $wpdb->query( $delete ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared just above; one-time migration of the plugin's own table.
			throw new DatabaseException( 'Could not delete the former profile fields.' );
		}

		wp_cache_flush_group( 'merchboard_creator_meta' );

		if ( $dropped > 0 && function_exists( 'error_log' ) ) {
			error_log( sprintf( 'Merch Dashboard: %d stored social links were not moved to proposals (website or another domain).', $dropped ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- One count, no personal data, during a one-time migration.
		}
	}

	/**
	 * The proposals of one stored meta value, by field.
	 *
	 * @param string $key     Meta key.
	 * @param mixed  $value   Stored value.
	 * @param int    $dropped Links that were not moved (counted up).
	 * @return array<string, string>
	 */
	private static function values( string $key, mixed $value, int &$dropped ): array {
		if ( 'bio' === $key ) {
			$bio = is_string( $value ) ? trim( $value ) : '';

			return '' === $bio ? array() : array( 'description' => mb_substr( $bio, 0, 1000 ) );
		}

		$links = array();

		foreach ( is_array( $value ) ? $value : array() as $platform => $url ) {
			$platform = (string) $platform;

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			if ( ! in_array( $platform, SocialPlatforms::PLATFORMS, true ) || ! SocialPlatforms::on_domain( $platform, $url ) ) {
				++$dropped;
				continue;
			}

			$links[ 'social_' . $platform ] = $url;
		}

		return $links;
	}
}
