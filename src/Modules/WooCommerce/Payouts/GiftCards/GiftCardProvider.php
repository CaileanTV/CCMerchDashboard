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
	 * The plugin's own admin page: creating a card (null) or one card.
	 *
	 * @param string|null $external_id The card's id, null for "create".
	 */
	public function admin_url( ?string $external_id ): ?string;
}
