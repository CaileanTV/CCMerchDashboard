<?php
/**
 * A provider that leaves something behind after rendering an import.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-122: Printify renders an import with temporary products in a shop.
 * CatalogImport calls forget() when an import is finished, has failed or
 * starts over, so none of them stays. It never throws: what it cannot
 * remove is logged by the import.
 */
interface CatalogCleanup {

	/**
	 * Removes what the import's renders left at the provider.
	 *
	 * @param array<string, mixed> $import Import data (its `renders`).
	 * @return list<string> The renders' keys that could not be removed.
	 */
	public function forget( array $import ): array;
}
