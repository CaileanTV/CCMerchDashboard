<?php
/**
 * Aggregated realized profit of a creator's ledger rows.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's realized profit (ADR-049) summed per product, per order line
 * and per period (ADR-050), for `/me/products`, `/me/orders` and `/me/sales`.
 * One implementation serves all three, so the endpoints can never disagree.
 * Only rows whose ledger `creator_id` is the creator count; the creator id
 * always comes from CreatorContext on the server.
 */
interface CreatorProfits {

	/**
	 * Profit per parent product (variations included), all time, per currency.
	 * A product without ledger rows of the creator is missing ("no sales").
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int   $creator_id  Creator id.
	 * @param int[] $product_ids Parent product ids.
	 * @return array<int, list<ProfitSummary>> By product id, sorted by currency.
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	public function by_product( int $creator_id, array $product_ids ): array;

	/**
	 * Profit of every order line of the creator in the given orders (also
	 * lines that are not on the caller's page), with the line's order.
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int   $creator_id Creator id.
	 * @param int[] $order_ids  Order ids.
	 * @return array<int, array{order_id: int, profit: ProfitSummary}> By order item id.
	 * @throws DatabaseException When the ledger cannot be read or a line mixes currencies.
	 */
	public function by_order_line( int $creator_id, array $order_ids ): array;

	/**
	 * Profit of the rows with `occurred_at` in [from, to), per currency.
	 *
	 * @param int               $creator_id Creator id.
	 * @param DateTimeImmutable $from       Start (inclusive).
	 * @param DateTimeImmutable $to         End (exclusive).
	 * @return array<string, ProfitSummary> By currency, sorted.
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	public function totals( int $creator_id, DateTimeImmutable $from, DateTimeImmutable $to ): array;
}
