<?php
/**
 * The registered gift card adapters and the one chosen in Settings.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\GiftCards;

use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up the gift card provider chosen in Settings
 * (`payout_gift_card_provider`, ADR-057). Before a provider is used it is in
 * exactly one state: NOT_CONFIGURED (none chosen), UNKNOWN_PROVIDER (the
 * choice is no registered adapter), UNAVAILABLE (its availability() is not
 * green) or AVAILABLE. Only AVAILABLE makes gift cards usable; bank and
 * PayPal never depend on it.
 */
final class GiftCardProviders {

	public const NOT_CONFIGURED = 'not_configured';

	public const UNKNOWN_PROVIDER = 'unknown_provider';

	public const UNAVAILABLE = 'unavailable';

	public const AVAILABLE = 'available';

	/**
	 * Adapters by id.
	 *
	 * @var array<string, GiftCardProvider>
	 */
	private array $providers = array();

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<GiftCardProvider> $providers
	 *
	 * @param GiftCardProvider[] $providers Registered adapters.
	 */
	public function __construct( array $providers ) {
		foreach ( $providers as $provider ) {
			$this->providers[ $provider->id() ] = $provider;
		}
	}

	/**
	 * The registered adapters by id.
	 *
	 * @return array<string, GiftCardProvider>
	 */
	public function all(): array {
		return $this->providers;
	}

	/**
	 * A registered adapter by id, whether chosen or not.
	 *
	 * @param string $id Provider id.
	 */
	public function by_id( string $id ): ?GiftCardProvider {
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * The state of the chosen provider and, when not available, its code.
	 *
	 * @return array{state: string, code: string|null}
	 */
	public function state(): array {
		$id = Settings::payout_gift_card_provider();

		if ( '' === $id ) {
			return array(
				'state' => self::NOT_CONFIGURED,
				'code'  => null,
			);
		}

		$provider = $this->by_id( $id );

		if ( null === $provider ) {
			return array(
				'state' => self::UNKNOWN_PROVIDER,
				'code'  => null,
			);
		}

		$availability = $provider->availability();

		if ( ! $availability->available ) {
			return array(
				'state' => self::UNAVAILABLE,
				'code'  => $availability->code,
			);
		}

		return array(
			'state' => self::AVAILABLE,
			'code'  => null,
		);
	}

	/**
	 * The chosen provider when it is available, otherwise null.
	 */
	public function usable(): ?GiftCardProvider {
		return self::AVAILABLE === $this->state()['state'] ? $this->by_id( Settings::payout_gift_card_provider() ) : null;
	}
}
