<?php
/**
 * Ordered list of schema migrations.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;

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
		);
	}
}
