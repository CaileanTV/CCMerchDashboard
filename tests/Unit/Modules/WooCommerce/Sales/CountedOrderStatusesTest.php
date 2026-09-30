<?php
/**
 * Tests for the counted order statuses at read time.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( CountedOrderStatuses::class )]
final class CountedOrderStatusesTest extends TestCase {

	private InMemoryOptions $options;

	protected function setUp(): void {
		parent::setUp();
		$this->options = InMemoryOptions::install();
	}

	public function test_default_applies_when_nothing_is_stored(): void {
		$statuses = new CountedOrderStatuses();

		self::assertSame( array( 'processing', 'completed' ), $statuses->statuses() );
		self::assertTrue( $statuses->counts( 'processing' ) );
		self::assertTrue( $statuses->counts( 'completed' ) );
		self::assertFalse( $statuses->counts( 'on-hold' ) );
		self::assertFalse( $statuses->counts( 'wc-completed' ), 'Statuses are compared without the wc- prefix.' );
	}

	public function test_the_stored_list_is_used(): void {
		$this->store( array( 'on-hold', 'completed' ) );
		$statuses = new CountedOrderStatuses();

		self::assertSame( array( 'on-hold', 'completed' ), $statuses->statuses() );
		self::assertTrue( $statuses->counts( 'on-hold' ) );
		self::assertFalse( $statuses->counts( 'processing' ), 'The default no longer applies.' );
	}

	public function test_underscore_statuses_count_exactly(): void {
		$this->store( array( 'awaiting_shipment', 'completed' ) );
		$statuses = new CountedOrderStatuses();

		self::assertSame( array( 'awaiting_shipment', 'completed' ), $statuses->statuses() );
		self::assertTrue( $statuses->counts( 'awaiting_shipment' ) );
		self::assertFalse( $statuses->counts( 'awaiting-shipment' ), 'Underscore and hyphen are different statuses.' );
		self::assertFalse( $statuses->counts( 'Awaiting_Shipment' ) );
	}

	public function test_stored_capitals_or_prefixes_never_count(): void {
		$this->store( array( 'Shipped', 'wc-shipped' ) );
		$statuses = new CountedOrderStatuses();

		self::assertSame( CountedOrderStatuses::DEFAULT, $statuses->statuses(), 'Nothing valid remains: the default applies.' );
		self::assertFalse( $statuses->counts( 'Shipped' ) );
	}

	public function test_a_changed_setting_applies_to_the_next_call(): void {
		$statuses = new CountedOrderStatuses();
		self::assertFalse( $statuses->counts( 'on-hold' ) );

		$this->store( array( 'on-hold' ) );

		self::assertTrue( $statuses->counts( 'on-hold' ), 'Read on every call, never cached.' );
		self::assertFalse( $statuses->counts( 'completed' ) );
	}

	public function test_the_deny_list_is_fixed(): void {
		self::assertSame( array( 'pending', 'failed', 'cancelled', 'refunded', 'checkout-draft', 'trash' ), CountedOrderStatuses::DENIED );
	}

	public function test_denied_statuses_never_count_even_when_stored(): void {
		$this->store( array( 'completed', 'pending', 'failed', 'cancelled', 'refunded', 'checkout-draft', 'trash' ) );
		$statuses = new CountedOrderStatuses();

		self::assertSame( array( 'completed' ), $statuses->statuses() );

		foreach ( CountedOrderStatuses::DENIED as $denied ) {
			self::assertFalse( $statuses->counts( $denied ), $denied );
		}
	}

	/**
	 * Stored values that leave nothing allowed.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function nothing_allowed(): array {
		return array(
			'only denied'   => array( array( 'pending', 'refunded' ) ),
			'empty'         => array( array() ),
			'garbage'       => array( 'completed' ),
			'invalid items' => array( array( 'Completed', 7, null ) ),
		);
	}

	#[DataProvider( 'nothing_allowed' )]
	public function test_falls_back_to_the_default_when_nothing_allowed_remains( mixed $stored ): void {
		$this->store( $stored );
		$statuses = new CountedOrderStatuses();

		self::assertSame( CountedOrderStatuses::DEFAULT, $statuses->statuses() );
		self::assertTrue( $statuses->counts( 'processing' ) );
		self::assertFalse( $statuses->counts( 'pending' ) );
	}

	public function test_a_status_that_is_no_longer_registered_still_counts(): void {
		// Registration is WooCommerce' business; reading never consults it.
		$this->store( array( 'shipped', 'completed' ) );

		self::assertTrue( ( new CountedOrderStatuses() )->counts( 'shipped' ) );
	}

	public function test_an_injected_source_is_filtered_the_same_way(): void {
		$statuses = new CountedOrderStatuses( static fn(): array => array( 'trash' ) );

		self::assertSame( CountedOrderStatuses::DEFAULT, $statuses->statuses() );
		self::assertTrue( CountedOrderStatuses::denied( 'trash' ) );
		self::assertFalse( CountedOrderStatuses::denied( 'on-hold' ) );
	}

	/**
	 * Stores the setting.
	 *
	 * @param mixed $statuses Stored value.
	 */
	private function store( mixed $statuses ): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::COUNTED_ORDER_STATUSES => $statuses );
	}
}
