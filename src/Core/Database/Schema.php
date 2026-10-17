<?php
/**
 * Ordered list of schema migrations.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use MerchBoard\Core\Database\Migrations\AddCreatorShareModel;
use MerchBoard\Core\Database\Migrations\AddCreatorVatAndSalesTax;
use MerchBoard\Core\Database\Migrations\AddPayoutGiftCards;
use MerchBoard\Core\Database\Migrations\CreateAuditLogTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorCategoriesTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorVatHistoryTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorMetaTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorPricingTables;
use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;
use MerchBoard\Core\Database\Migrations\CreateGiftCardAssessmentTables;
use MerchBoard\Core\Database\Migrations\CreateOperationsTable;
use MerchBoard\Core\Database\Migrations\CreatePayoutRecalculationExportsTable;
use MerchBoard\Core\Database\Migrations\CreatePayoutRecalculationTables;
use MerchBoard\Core\Database\Migrations\CreatePayoutTables;
use MerchBoard\Core\Database\Migrations\CreateSalesLedgerTables;
use MerchBoard\Core\Database\Migrations\CreateSettlementTables;

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
			1  => new CreateCreatorsTable(),
			2  => new CreateAuditLogTable(),
			3  => new CreateCreatorMetaTable(),
			4  => new CreateCreatorCategoriesTable(),
			5  => new CreateSalesLedgerTables(),
			6  => new CreateCreatorPricingTables(),
			7  => new AddCreatorVatAndSalesTax(),
			8  => new AddCreatorShareModel(),
			9  => new CreatePayoutTables(),
			10 => new AddPayoutGiftCards(),
			11 => new CreateSettlementTables(),
			12 => new CreateGiftCardAssessmentTables(),
			13 => new CreateOperationsTable(),
			14 => new CreatePayoutRecalculationTables(),
			15 => new CreatePayoutRecalculationExportsTable(),
			16 => new CreateCreatorVatHistoryTable(),
		);
	}
}
