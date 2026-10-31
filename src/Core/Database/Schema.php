<?php
/**
 * Ordered list of schema migrations.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use MerchBoard\Core\Database\Migrations\AddAlertEventAmounts;
use MerchBoard\Core\Database\Migrations\AddAlertOrderLogIndexes;
use MerchBoard\Core\Database\Migrations\AddChatbotPlatforms;
use MerchBoard\Core\Database\Migrations\AddCreatorShareModel;
use MerchBoard\Core\Database\Migrations\AddDesignerBaseImport;
use MerchBoard\Core\Database\Migrations\AddDesignerExtraSidePrice;
use MerchBoard\Core\Database\Migrations\AddDesignerShopPrice;
use MerchBoard\Core\Database\Migrations\CreateAlertEventsTable;
use MerchBoard\Core\Database\Migrations\CreateAlertChatbotTables;
use MerchBoard\Core\Database\Migrations\CreateAlertOverlaysTable;
use MerchBoard\Core\Database\Migrations\AddCreatorVatAndSalesTax;
use MerchBoard\Core\Database\Migrations\AddPayoutGiftCards;
use MerchBoard\Core\Database\Migrations\AddSalesDesignerAmounts;
use MerchBoard\Core\Database\Migrations\CreateAlertApiKeysTable;
use MerchBoard\Core\Database\Migrations\CreateAlertWebhookTables;
use MerchBoard\Core\Database\Migrations\CreateAuditLogTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorCodeTables;
use MerchBoard\Core\Database\Migrations\CreateCreatorCategoriesTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorVatHistoryTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorMetaTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorPricingTables;
use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;
use MerchBoard\Core\Database\Migrations\CreateGiftCardAssessmentTables;
use MerchBoard\Core\Database\Migrations\CreateNotificationsTable;
use MerchBoard\Core\Database\Migrations\CreateNewsTables;
use MerchBoard\Core\Database\Migrations\CreateOperationsTable;
use MerchBoard\Core\Database\Migrations\CreatePayoutRecalculationExportsTable;
use MerchBoard\Core\Database\Migrations\CreatePayoutRecalculationTables;
use MerchBoard\Core\Database\Migrations\CreatePayoutTables;
use MerchBoard\Core\Database\Migrations\CreateProductDesignerTables;
use MerchBoard\Core\Database\Migrations\CreateSalesLedgerTables;
use MerchBoard\Core\Database\Migrations\CreateSettlementTables;
use MerchBoard\Core\Database\Migrations\GrantExistingAlertApiAccess;
use MerchBoard\Core\Database\Migrations\AddAlertEventImportedFlag;
use MerchBoard\Core\Database\Migrations\RemoveImportOptions;
use MerchBoard\Core\Database\Migrations\AddCreatorDeletedAt;
use MerchBoard\Core\Database\Migrations\CreateCategoryProposalsTable;
use MerchBoard\Core\Database\Migrations\CreateProductRemovalRequestsTable;
use MerchBoard\Core\Database\Migrations\MoveProfileToProposals;
use MerchBoard\Core\Database\Migrations\WidenChatbotChannels;

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
			17 => new CreateCreatorCodeTables(),
			18 => new CreateAlertEventsTable(),
			19 => new CreateAlertOverlaysTable(),
			20 => new CreateAlertChatbotTables(),
			21 => new AddChatbotPlatforms(),
			22 => new WidenChatbotChannels(),
			23 => new AddAlertOrderLogIndexes(),
			24 => new AddAlertEventAmounts(),
			25 => new CreateNewsTables(),
			26 => new CreateAlertApiKeysTable(),
			27 => new CreateAlertWebhookTables(),
			28 => new GrantExistingAlertApiAccess(),
			29 => new AddAlertEventImportedFlag(),
			30 => new RemoveImportOptions(),
			31 => new AddCreatorDeletedAt(),
			32 => new CreateCategoryProposalsTable(),
			33 => new MoveProfileToProposals(),
			34 => new CreateNotificationsTable(),
			35 => new CreateProductDesignerTables(),
			36 => new AddSalesDesignerAmounts(),
			37 => new AddDesignerShopPrice(),
			38 => new AddDesignerBaseImport(),
			39 => new AddDesignerExtraSidePrice(),
			40 => new CreateProductRemovalRequestsTable(),
		);
	}
}
