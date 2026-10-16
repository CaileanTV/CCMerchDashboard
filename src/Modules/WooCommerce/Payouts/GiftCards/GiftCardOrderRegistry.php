<?php
/**
 * Optional capability of a gift card adapter: the orders a card paid.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\GiftCards;

defined( 'ABSPATH' ) || exit;

/**
 * An adapter MAY also implement this interface (G1, owner decisions B4/B5
 * 2026-10-16). It is not part of the GiftCardProvider contract of ADR-057:
 * an adapter without it stays fully usable for requests, registration and
 * the creator's view; the orders its cards paid are then shown as "not
 * supported". Callers check `instanceof GiftCardOrderRegistry` and then
 * order_registry_availability() before calling orders_paid_with().
 *
 * The lookup has its own availability, so a plugin version that lacks what
 * the lookup needs makes only the lookup unavailable, never the adapter
 * (GiftCardProvider::availability() stays as accepted for phase 23.7).
 *
 * What the lookup returns is the plugin's own record, not a confirmed payout
 * status: the G1 assessment stays the authoritative source (E2), and no
 * payout, commission or other financial decision may rest on it alone.
 */
interface GiftCardOrderRegistry {

	/**
	 * Whether the order lookup can be used now. Unavailable when the adapter
	 * itself is, or with GiftCardAvailability::SELF_TEST_FAILED when the
	 * plugin lacks the record the lookup reads.
	 */
	public function order_registry_availability(): GiftCardAvailability;

	/**
	 * The orders the plugin itself recorded as paid (wholly or partly) with
	 * each card: only the plugin's own registration, never a guess from
	 * amounts, times or coupon texts. A card without recorded orders maps to
	 * an empty list; a card that no longer exists is missing from the result.
	 *
	 * @phpstan-param list<string> $external_ids
	 *
	 * @param string[] $external_ids The cards' ids in the plugin.
	 * @return array<string, list<int>> Order ids by card id.
	 * @throws GiftCardProviderException With UNAVAILABLE when the lookup is not available, otherwise when a registration cannot be read exactly or the read fails.
	 */
	public function orders_paid_with( array $external_ids ): array;
}
