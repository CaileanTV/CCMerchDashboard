<?php
/**
 * A gift card plugin, read only.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\GiftCards;

defined( 'ABSPATH' ) || exit;

/**
 * The small, replaceable contract between the payout logic and a gift card
 * plugin (ADR-057). An adapter only reads facts about cards that an admin
 * created by hand in the plugin; it never creates, changes, disables or
 * deletes a card. Every rule (amount = balance = payout, currency, status,
 * one card per payout) is the payout logic's, so the same rules apply to
 * every adapter.
 *
 * "Not found" is null, as in the other readers of the project; real
 * failures are a GiftCardProviderException with a fixed code. Neither the
 * snapshot, an exception message nor a log contains a card's code.
 */
interface GiftCardProvider {

	/**
	 * Stable provider id, stored on the payout (e.g. `yith-wcgc`).
	 */
	public function id(): string;

	/**
	 * The plugin's name, for the admin's choice in Settings.
	 */
	public function label(): string;

	/**
	 * Whether the plugin is installed, active, of a tested version and reads
	 * as expected.
	 */
	public function availability(): GiftCardAvailability;

	/**
	 * Exactly one card by the code an admin entered.
	 *
	 * @param string $code The card's code.
	 * @return GiftCardSnapshot|null Null when no card has the code.
	 * @throws GiftCardProviderException When several cards have it, its data cannot be read exactly, or the read fails.
	 */
	public function find_by_code( string $code ): ?GiftCardSnapshot;

	/**
	 * A registered card again, by its id in the plugin.
	 *
	 * @param string $external_id The card's id in the plugin.
	 * @return GiftCardSnapshot|null Null when the card no longer exists.
	 * @throws GiftCardProviderException When its data cannot be read exactly or the read fails.
	 */
	public function find_by_id( string $external_id ): ?GiftCardSnapshot;

	/**
	 * The card's code, only for the creator's private view of their payout.
	 *
	 * @param string $external_id The card's id in the plugin.
	 * @throws GiftCardProviderException When the card or its code cannot be read.
	 */
	public function reveal_code( string $external_id ): string;

	/**
	 * The orders the plugin itself recorded as paid (wholly or partly) with
	 * each card (G1, report 16.3): only the plugin's own registration, never
	 * a guess from amounts, times or coupon texts. A card without recorded
	 * orders maps to an empty list; a card that no longer exists is missing
	 * from the result.
	 *
	 * @phpstan-param list<string> $external_ids
	 *
	 * @param string[] $external_ids The cards' ids in the plugin.
	 * @return array<string, list<int>> Order ids by card id.
	 * @throws GiftCardProviderException When a registration cannot be read exactly or the read fails.
	 */
	public function orders_paid_with( array $external_ids ): array;

	/**
	 * The plugin's own admin page: creating a card (null) or one card.
	 *
	 * @param string|null $external_id The card's id, null for "create".
	 */
	public function admin_url( ?string $external_id ): ?string;
}
