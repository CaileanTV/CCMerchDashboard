<?php
/**
 * Ordered list of schema migrations.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use MerchBoard\Core\Database\Migrations\CreateAuditLogTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorCategoriesTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorMetaTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;
use MerchBoard\Core\Database\Migrations\CreateSalesLedgerTables;

defined( 'ABSPATH' ) || exit;

/**
 * The schema version is the highest key in migrations().
 *
 * To change the schema, add a new migration with the next integer key. Never
 * edit or renumber a migration that has been released.
 */
final class Schema {

	/**
	 * Migrations keyed by the schema version they produce.
	 *
	 * @return array<int, Migration>
	 */
	public static function migrations(): array {
		return array(
			1 => new CreateCreatorsTable(),
			2 => new CreateAuditLogTable(),
			3 => new CreateCreatorMetaTable(),
			4 => new CreateCreatorCategoriesTable(),
			5 => new CreateSalesLedgerTables(),
		);
	}
}
