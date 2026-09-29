<?php
/**
 * Records failure marker calls for the sales synchronization tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use Closure;
use DateTimeImmutable;
use MerchBoard\Modules\WooCommerce\Sales\SalesFailureCode;
use MerchBoard\Modules\WooCommerce\Sales\SalesFailureMarker;

/**
 * Keeps "record:<order>:<code>" and "clear:<order>" in call order; an
 * optional callback runs on every call (e.g. a nested synchronization, as
 * HPOS' save after a meta change would trigger).
 */
final class RecordingFailureMarker implements SalesFailureMarker {

	/**
	 * Calls.
	 *
	 * @var list<string>
	 */
	public array $calls = array();

	/**
	 * Runs on every call with the order id.
	 *
	 * @var (Closure(int): void)|null
	 */
	public ?Closure $on_call = null;

	/**
	 * Records a failure.
	 *
	 * @param int               $order_id Order id.
	 * @param SalesFailureCode  $code     Code.
	 * @param DateTimeImmutable $at       Time.
	 */
	public function record( int $order_id, SalesFailureCode $code, DateTimeImmutable $at ): void {
		$this->calls[] = 'record:' . $order_id . ':' . $code->value;
		$this->notify( $order_id );
	}

	/**
	 * Records a clear.
	 *
	 * @param int $order_id Order id.
	 */
	public function clear( int $order_id ): void {
		$this->calls[] = 'clear:' . $order_id;
		$this->notify( $order_id );
	}

	/**
	 * Runs the callback.
	 *
	 * @param int $order_id Order id.
	 */
	private function notify( int $order_id ): void {
		if ( null !== $this->on_call ) {
			( $this->on_call )( $order_id );
		}
	}
}
