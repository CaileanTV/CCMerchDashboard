<?php
/**
 * Migration 35: the product designer's tables (phase 30, ADR-080).
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
 * Creates the tables of the product designer (ADR-080–082):
 *
 * - `merchboard_designer_bases`: base products an administrator defines
 *   (own, Printful or Gelato), with colours, sizes, base price incl. VAT and
 *   one mockup template per side (JSON). `version` grows with every change
 *   of price or template.
 * - `merchboard_designer_products`: a creator's designer product; the
 *   WooCommerce product once applied (`wc_product_id`, UNIQUE), the creation
 *   token that marks it (legacy guard) and the live version.
 * - `merchboard_designer_versions`: the versions of a designer product
 *   (draft → pending → approved/rejected/withdrawn → superseded).
 *   `pending_key` is unique while a version waits, so a product has at most
 *   one pending change; the approved version stays on sale until a change is
 *   applied.
 * - `merchboard_designer_assets`: uploaded images of creators and the
 *   administrator's cliparts (`creator_id` NULL); files are private.
 * - `merchboard_designer_provider_tasks`: mockup tasks at Printful or
 *   Gelato (ADR-081/082), polled in the background.
 * - `merchboard_designer_campaigns`: the company's own campaigns, in which a
 *   discount is shared with the creator (ADR-080 pkt. 9).
 *
 * Amounts are decimals incl. VAT (`decimal(19,4)`, as the sales ledger).
 */
final class CreateProductDesignerTables implements Migration {

	/**
	 * Creates the tables.
	 *
	 * @param wpdb $wpdb Database.
	 * @throws DatabaseException When a table is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$collate = $wpdb->get_charset_collate();

		foreach ( self::schemas( $wpdb, $collate ) as $table => $sql ) {
			dbDelta( $sql );

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create a product designer table.', array( 'table' => $table ) );
			}
		}
	}

	/**
	 * The tables' definitions (dbDelta format), by prefixed name.
	 *
	 * @param wpdb   $wpdb    Database.
	 * @param string $collate Charset and collation.
	 * @return array<string, string>
	 */
	public static function schemas( wpdb $wpdb, string $collate ): array {
		$bases     = Tables::name( $wpdb, Tables::DESIGNER_BASES );
		$products  = Tables::name( $wpdb, Tables::DESIGNER_PRODUCTS );
		$versions  = Tables::name( $wpdb, Tables::DESIGNER_VERSIONS );
		$assets    = Tables::name( $wpdb, Tables::DESIGNER_ASSETS );
		$tasks     = Tables::name( $wpdb, Tables::DESIGNER_PROVIDER_TASKS );
		$campaigns = Tables::name( $wpdb, Tables::DESIGNER_CAMPAIGNS );

		return array(
			$bases     => "CREATE TABLE {$bases} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
status varchar(10) NOT NULL DEFAULT 'draft',
source varchar(10) NOT NULL DEFAULT 'own',
provider_ref varchar(191) NULL,
provider_template varchar(191) NULL,
name varchar(191) NOT NULL,
description text NOT NULL,
base_price decimal(19,4) NOT NULL,
currency char(3) NOT NULL,
category_id bigint(20) unsigned NULL,
colors longtext NOT NULL,
sizes longtext NOT NULL,
views longtext NOT NULL,
version int(10) unsigned NOT NULL DEFAULT 1,
created_at datetime NOT NULL,
created_by bigint(20) unsigned NULL,
updated_at datetime NOT NULL,
updated_by bigint(20) unsigned NULL,
PRIMARY KEY  (id),
KEY status (status),
KEY source (source)
) {$collate};",
			$products  => "CREATE TABLE {$products} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
base_id bigint(20) unsigned NOT NULL,
wc_product_id bigint(20) unsigned NULL,
creation_token char(32) NOT NULL,
live_version_id bigint(20) unsigned NULL,
status varchar(10) NOT NULL DEFAULT 'draft',
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY wc_product_id (wc_product_id),
UNIQUE KEY creation_token (creation_token),
KEY creator (creator_id,status),
KEY base (base_id,status)
) {$collate};",
			$versions  => "CREATE TABLE {$versions} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
design_product_id bigint(20) unsigned NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
number int(10) unsigned NOT NULL,
status varchar(10) NOT NULL DEFAULT 'draft',
pending_key varchar(40) NULL,
base_version int(10) unsigned NOT NULL,
title varchar(191) NOT NULL,
description text NOT NULL,
colors longtext NOT NULL,
sizes longtext NOT NULL,
sides longtext NOT NULL,
design longtext NOT NULL,
profit_kind varchar(8) NOT NULL DEFAULT 'fixed',
profit_value decimal(19,4) NOT NULL DEFAULT 0,
quoted_price decimal(19,4) NULL,
files longtext NULL,
mockup_state varchar(10) NOT NULL DEFAULT 'none',
apply_state varchar(10) NOT NULL DEFAULT 'none',
apply_step varchar(32) NULL,
apply_error varchar(191) NULL,
provider_ref varchar(191) NULL,
note text NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
submitted_at datetime NULL,
decided_at datetime NULL,
decided_by bigint(20) unsigned NULL,
PRIMARY KEY  (id),
UNIQUE KEY product_number (design_product_id,number),
UNIQUE KEY pending_key (pending_key),
KEY creator (creator_id,status),
KEY queue (status,submitted_at)
) {$collate};",
			$assets    => "CREATE TABLE {$assets} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NULL,
kind varchar(10) NOT NULL,
name varchar(191) NOT NULL,
file varchar(64) NOT NULL,
mime varchar(32) NOT NULL,
width int(10) unsigned NOT NULL,
height int(10) unsigned NOT NULL,
bytes bigint(20) unsigned NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY owner (creator_id,kind)
) {$collate};",
			$tasks     => "CREATE TABLE {$tasks} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
version_id bigint(20) unsigned NOT NULL,
provider varchar(10) NOT NULL,
side varchar(32) NOT NULL,
task_key varchar(191) NULL,
status varchar(10) NOT NULL DEFAULT 'queued',
attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
next_poll_at datetime NULL,
result longtext NULL,
error varchar(191) NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY version (version_id),
KEY poll (status,next_poll_at)
) {$collate};",
			$campaigns => "CREATE TABLE {$campaigns} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
name varchar(191) NOT NULL,
starts_at datetime NOT NULL,
ends_at datetime NOT NULL,
coupon_codes longtext NOT NULL,
sale_prices tinyint(1) NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
created_by bigint(20) unsigned NULL,
updated_at datetime NOT NULL,
updated_by bigint(20) unsigned NULL,
PRIMARY KEY  (id),
KEY period (starts_at,ends_at)
) {$collate};",
		);
	}
}
