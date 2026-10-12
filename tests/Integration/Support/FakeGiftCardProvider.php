<?php
/**
 * Programmable gift card adapter for tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardAvailability;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProvider;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviderException;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardSnapshot;
use Throwable;

/**
 * Cards in memory by code, a failure to throw and a hook on reads; records
 * which method was called with which card id (never a code). Contains no
 * rule: the payout logic decides whether a card fits (report 18.4). Talks to
 * no plugin, no network.
 */
final class FakeGiftCardProvider implements GiftCardProvider {

	/**
	 * Cards by code.
	 *
	 * @var array<string, GiftCardSnapshot>
	 */
	public array $cards = array();

	/**
	 * Thrown by every read when set.
	 *
	 * @var Throwable|null
	 */
	public ?Throwable $fail = null;

	/**
	 * Called before every read, e.g. to change a card meanwhile.
	 *
	 * @var callable|null
	 */
	public $on_read = null;

	/**
	 * Reads: [method, card id or '' for a lookup by code].
	 *
	 * @var list<array{string, string}>
	 */
	public array $reads = array();

	public GiftCardAvailability $availability;

	/**
	 * Constructor.
	 *
	 * @param string $provider_id Provider id.
	 */
	public function __construct( public string $provider_id = 'fake' ) {
		$this->availability = GiftCardAvailability::available( '1.0.0' );
	}

	public function id(): string {
		return $this->provider_id;
	}

	public function label(): string {
		return 'Fake gift cards';
	}

	public function availability(): GiftCardAvailability {
		return $this->availability;
	}

	public function find_by_code( string $code ): ?GiftCardSnapshot {
		$this->read( 'find_by_code', '' );

		return $this->cards[ $code ] ?? null;
	}

	public function find_by_id( string $external_id ): ?GiftCardSnapshot {
		$this->read( 'find_by_id', $external_id );

		foreach ( $this->cards as $card ) {
			if ( $card->external_id === $external_id ) {
				return $card;
			}
		}

		return null;
	}

	public function reveal_code( string $external_id ): string {
		$this->read( 'reveal_code', $external_id );

		foreach ( $this->cards as $code => $card ) {
			if ( $card->external_id === $external_id ) {
				return (string) $code;
			}
		}

		throw new GiftCardProviderException( GiftCardProviderException::READ_FAILED );
	}

	public function admin_url( ?string $external_id ): string {
		return 'https://shop.test/fake-gift-cards/' . ( $external_id ?? 'new' );
	}

	/**
	 * Records a read, runs the hook and throws the failure.
	 *
	 * @param string $method      Method.
	 * @param string $external_id Card id or ''.
	 * @throws Throwable The configured failure.
	 */
	private function read( string $method, string $external_id ): void {
		$this->reads[] = array( $method, $external_id );

		if ( null !== $this->on_read ) {
			( $this->on_read )();
		}

		if ( null !== $this->fail ) {
			throw $this->fail;
		}
	}
}
