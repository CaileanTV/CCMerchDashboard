<?php
/**
 * Schema version 25: news for the creators (ADR-072, step 25.11).
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
 * `merchboard_news`: the administrator's messages to the creators ("What's
 * new"): a one-line title, the body as cleaned HTML (NewsHtml), the status
 * (draft, published, withdrawn) and when it was published.
 *
 * `merchboard_news_reads`: one row per message and creator with when the
 * creator saw it and closed it; times only, no text.
 *
 * Idempotent: dbDelta() and a table check afterwards.
 */
final class CreateNewsTables implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a table does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( array( Tables::NEWS, Tables::NEWS_READS ) as $name ) {
			$table = Tables::name( $wpdb, $name );

			dbDelta( self::schema( $name, $table, $wpdb->get_charset_collate() ) );

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create the news tables.' );
			}
		}
	}

	/**
	 * CREATE TABLE statement in dbDelta() format.
	 *
	 * @param string $name            Tables constant.
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function schema( string $name, string $table, string $charset_collate ): string {
		if ( Tables::NEWS_READS === $name ) {
			return "CREATE TABLE {$table} (
news_id bigint(20) unsigned NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
seen_at datetime NULL,
closed_at datetime NULL,
PRIMARY KEY  (news_id,creator_id),
KEY creator_id (creator_id)
) {$charset_collate};";
		}

		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
title varchar(150) NOT NULL,
body longtext NOT NULL,
status varchar(20) NOT NULL,
published_at datetime NULL,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY status_published (status,published_at)
) {$charset_collate};";
	}
}
