<?php
/**
 * Fake gift card adapter that also offers the optional order lookup.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardAvailability;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardOrderRegistry;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProvider;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviderException;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardSnapshot;
use Throwable;

/**
 * Wraps FakeGiftCardProvider (an adapter without the optional
 * GiftCardOrderRegistry, as ADR-057 accepts it) and adds the order lookup
 * with its own availability, the way YithGiftCardProvider does (G1, owner
 * decisions B4/B5 2026-10-16). The cards, the adapter's availability and its
 * failure stay on the wrapped fake.
 */
final class FakeOrderRegistryGiftCardProvider implements GiftCardProvider, GiftCardOrderRegistry {

	/**
	 * Orders the "plugin" recorded on each card, by card id (PHP keys numeric ids as int).
	 *
	 * @var array<int|string, list<int>>
	 */
	public array $orders = array();

	/**
	 * Availability of the lookup while the adapter itself is available.
	 *
	 * @var GiftCardAvailability
	 */
	public GiftCardAvailability $registry;

	/**
	 * Failure of the lookup only (null = none).
	 *
	 * @var Throwable|null
	 */
	public ?Throwable $registry_fail = null;

	/**
	 * Card ids asked for, one list per call.
	 *
	 * @var list<list<string>>
	 */
	public array $lookups = array();

	/**
	 * Constructor.
	 *
	 * @param FakeGiftCardProvider $inner The adapter.
	 */
	public function __construct( public FakeGiftCardProvider $inner ) {
		$this->registry = GiftCardAvailability::available( '1.0.0' );
	}

	public function id(): string {
		return $this->inner->id();
	}

	public function label(): string {
		return $this->inner->label();
	}

	public function availability(): GiftCardAvailability {
		return $this->inner->availability();
	}

	public function find_by_code( string $code ): ?GiftCardSnapshot {
		return $this->inner->find_by_code( $code );
	}

	public function find_by_id( string $external_id ): ?GiftCardSnapshot {
		return $this->inner->find_by_id( $external_id );
	}

	public function reveal_code( string $external_id ): string {
		return $this->inner->reveal_code( $external_id );
	}

	public function admin_url( ?string $external_id ): string {
		return $this->inner->admin_url( $external_id );
	}

	public function order_registry_availability(): GiftCardAvailability {
		$availability = $this->inner->availability();

		return $availability->available ? $this->registry : $availability;
	}

	public function orders_paid_with( array $external_ids ): array {
		if ( ! $this->order_registry_availability()->available ) {
			throw new GiftCardProviderException( GiftCardProviderException::UNAVAILABLE );
		}

		$this->lookups[] = $external_ids;

		if ( null !== $this->registry_fail ) {
			throw $this->registry_fail;
		}

		$result = array();

		foreach ( $external_ids as $external_id ) {
			foreach ( $this->inner->cards as $card ) {
				if ( $card->external_id === $external_id ) {
					$result[ $external_id ] = $this->orders[ $external_id ] ?? array();
				}
			}
		}

		return $result;
	}
}
