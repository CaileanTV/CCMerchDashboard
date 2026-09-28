<?php
/**
 * One of several concurrent "requests" in SalesRepositoryTest: waits for a
 * common start time, then takes the order's ledger lock and opens cycle 1 with
 * one sale, unless the stored state already has a cycle. Holds the lock
 * briefly so the others have to wait. Prints "<inserted>:<skipped>".
 *
 * Usage: php sales-lock-contender.php <start unix time with microseconds> <order id>
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleEvent;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleEventType;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntryType;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerState;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerWrite;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;

require dirname( __DIR__ ) . '/config.php';
require ABSPATH . 'wp-settings.php';
require_once dirname( __DIR__, 3 ) . '/src/Core/Autoloader.php';

MerchBoard\Core\Autoloader::register( 'MerchBoard\\', dirname( __DIR__, 3 ) . '/src/' );

$merchboard_start = (float) ( $argv[1] ?? 0 );
$merchboard_order = (int) ( $argv[2] ?? 0 );

while ( microtime( true ) < $merchboard_start ) {
	usleep( 1000 );
}

$merchboard_wpdb       = $GLOBALS['wpdb'];
$merchboard_repository = new SalesRepository( $merchboard_wpdb, new Transaction( $merchboard_wpdb, new TransactionSupport( $merchboard_wpdb ) ), 20 );

$merchboard_result = $merchboard_repository->with_order_lock(
	$merchboard_order,
	static fn() => $merchboard_repository->write(
		$merchboard_order,
		static function ( SalesLedgerState $state ) use ( $merchboard_order ): SalesLedgerWrite {
			usleep( 300000 );

			if ( 0 !== $state->cycles->latest_cycle() ) {
				return new SalesLedgerWrite();
			}

			$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

			return new SalesLedgerWrite(
				array( new SalesEntry( 's:501:1', 3, $merchboard_order, 501, 1, 0, 20, 0, 'Contender', SalesEntryType::Sale, null, 1, '10.0000', 'DKK', $now ) ),
				array( new SalesCycleEvent( $merchboard_order, 1, SalesCycleEventType::Open, $now ) )
			);
		}
	)
);

echo ( count( $merchboard_result->inserted_entries ) + $merchboard_result->inserted_cycle_events ) . ':' . ( count( $merchboard_result->skipped_entries ) + $merchboard_result->skipped_cycle_events );
