<?php
/**
 * Migration 45: creators who sign themselves up, the agreement and going live (phase 32).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database\Migrations;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migration;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-091 and ADR-092:
 * - `merchboard_creators.awaiting_live` (1 while a self-signed-up creator
 *   has not gone live; 0 for every existing creator and every creator an
 *   administrator creates, so nothing changes for them) and
 *   `agreement_required` (1 when the creator must accept the agreement);
 * - `merchboard_agreement_versions`: the published texts, never changed;
 * - `merchboard_agreement_acceptances`: who accepted which version, only added to;
 * - `merchboard_creator_signups`: a self-signup until its e-mail is verified;
 * - `merchboard_go_live_requests`: the creators' requests to go live, one
 *   pending per creator.
 *
 * Idempotent: columns are added only when missing, tables via dbDelta().
 */
final class CreateCreatorSignupTables implements Migration {

	/**
	 * Columns added to the creators table and their definitions.
	 */
	private const CREATOR_COLUMNS = array(
		'awaiting_live'      => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
		'agreement_required' => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
	);

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column or table is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$creators = Tables::name( $wpdb, Tables::CREATORS );

		foreach ( self::CREATOR_COLUMNS as $column => $definition ) {
			if ( ! self::has_column( $wpdb, $creators, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$creators}` ADD COLUMN `{$column}` {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables and constant column definitions; versioned migration.
			}

			if ( ! self::has_column( $wpdb, $creators, $column ) ) {
				throw new DatabaseException( 'Could not add a column for creators who sign themselves up.' );
			}
		}

		$charset = $wpdb->get_charset_collate();

		foreach ( self::schemas( $wpdb, $charset ) as $table => $schema ) {
			dbDelta( $schema );

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create a table for creators who sign themselves up.' );
			}
		}
	}

	/**
	 * CREATE TABLE statements in dbDelta() format, keyed by the full table
	 * name. Times are UTC. No foreign keys (ADR-019/020).
	 * - A version's `body` is the sanitized text (wp_kses_post), `material`
	 *   whether accepting it again is required.
	 * - `active_creator_id` of a go-live request is set only while it is
	 *   pending, so the unique key allows one pending request per creator.
	 * - `created_user` of a signup is 1 when the plugin created the WordPress
	 *   user (only then may an unverified signup delete it, ADR-091 pkt. 6).
	 *
	 * @param wpdb   $wpdb    WordPress database object.
	 * @param string $charset `$wpdb->get_charset_collate()`.
	 * @return array<string, string>
	 */
	public static function schemas( wpdb $wpdb, string $charset ): array {
		$versions    = Tables::name( $wpdb, Tables::AGREEMENT_VERSIONS );
		$acceptances = Tables::name( $wpdb, Tables::AGREEMENT_ACCEPTANCES );
		$signups     = Tables::name( $wpdb, Tables::CREATOR_SIGNUPS );
		$requests    = Tables::name( $wpdb, Tables::GO_LIVE_REQUESTS );

		return array(
			$versions    => "CREATE TABLE {$versions} (
version int(10) unsigned NOT NULL,
body longtext NOT NULL,
material tinyint(1) unsigned NOT NULL DEFAULT 0,
published_at datetime NOT NULL,
published_by bigint(20) unsigned DEFAULT NULL,
PRIMARY KEY  (version)
) {$charset};",
			$acceptances => "CREATE TABLE {$acceptances} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned DEFAULT NULL,
version int(10) unsigned NOT NULL,
adult_confirmed tinyint(1) unsigned NOT NULL DEFAULT 0,
accepted_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY creator_version (creator_id,version)
) {$charset};",
			$signups     => "CREATE TABLE {$signups} (
creator_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
channel_url varchar(255) NOT NULL DEFAULT '',
created_user tinyint(1) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
expires_at datetime DEFAULT NULL,
verified_at datetime DEFAULT NULL,
PRIMARY KEY  (creator_id),
UNIQUE KEY user_id (user_id),
KEY expires_at (expires_at)
) {$charset};",
			$requests    => "CREATE TABLE {$requests} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
active_creator_id bigint(20) unsigned DEFAULT NULL,
status varchar(16) NOT NULL,
note varchar(500) DEFAULT NULL,
requested_at datetime NOT NULL,
requested_by bigint(20) unsigned DEFAULT NULL,
decided_at datetime DEFAULT NULL,
decided_by bigint(20) unsigned DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY active_creator_id (active_creator_id),
KEY creator_status (creator_id,status)
) {$charset};",
		);
	}

	/**
	 * Whether a table has a column.
	 *
	 * @param wpdb   $wpdb   WordPress database object.
	 * @param string $table  Full table name.
	 * @param string $column Column.
	 */
	private static function has_column( wpdb $wpdb, string $table, string $column ): bool {
		return $column === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
	}
}
