<?php
/**
 * The gift card adapter contract stays ADR-057's; G1's order lookup is
 * optional and kept away from money and from G1's activation (G1, B2–B5).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts;

use Closure;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardOrderRegistry;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProvider;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\PayoutGiftCardCouponGuard;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Reflection and the source's tokens (comments excluded).
 */
final class GiftCardOrderRegistryContractTest extends TestCase {

	public function test_the_adapter_contract_is_exactly_adr_057(): void {
		self::assertSame( array( 'admin_url', 'availability', 'find_by_code', 'find_by_id', 'id', 'label', 'reveal_code' ), self::methods( GiftCardProvider::class ) );
	}

	public function test_the_order_lookup_is_a_separate_optional_interface(): void {
		$registry = new ReflectionClass( GiftCardOrderRegistry::class );

		self::assertTrue( $registry->isInterface() );
		self::assertSame( array(), $registry->getInterfaceNames(), 'It does not extend the adapter contract.' );
		self::assertSame( array( 'order_registry_availability', 'orders_paid_with' ), self::methods( GiftCardOrderRegistry::class ) );
		self::assertNotContains( GiftCardOrderRegistry::class, ( new ReflectionClass( GiftCardProvider::class ) )->getInterfaceNames() );
	}

	public function test_money_and_the_sales_sync_do_not_use_the_plugins_order_records(): void {
		$root = dirname( __DIR__, 5 ) . '/src/Modules/WooCommerce/';

		foreach ( array(
			'Payouts/PayoutBalances.php',
			'Payouts/PayoutDecisions.php',
			'Payouts/PayoutGiftCards.php',
			'Payouts/PayoutRepository.php',
			'Payouts/PayoutRequests.php',
			'Payouts/Settlements/SettlementDocuments.php',
			'Payouts/Settlements/SettlementRepository.php',
			'Pricing/LedgerCreatorPayouts.php',
			'Pricing/LedgerCreatorProfits.php',
			'Sales/SalesRepository.php',
			'Sales/SalesSynchronizer.php',
		) as $file ) {
			$names = self::names( $root . $file );

			foreach ( array( 'PayoutGiftCardOrders', 'GiftCardOrderRegistry', 'orders_paid_with', 'order_registry_availability', 'PayoutGiftCardCouponGuard' ) as $name ) {
				self::assertNotContains( $name, $names, $file . ' uses ' . $name . '.' );
			}
		}
	}

	public function test_the_coupon_check_and_the_views_do_not_read_g1_activation(): void {
		$root = dirname( __DIR__, 5 ) . '/src/Modules/WooCommerce/';

		foreach ( array(
			'Payouts/GiftCards/PayoutGiftCardOrders.php',
			'Payouts/GiftCards/PayoutGiftCardCouponGuard.php',
			'Admin/OrderHistoryPage.php',
			'Admin/PayoutsPage.php',
		) as $file ) {
			$names = self::names( $root . $file );

			foreach ( array( 'G1_STATE', 'G1StateRow', 'G1Activation', 'G1ActivationResult', 'ORDER_GIFT_CARD_ASSESSMENTS', 'G1AssessmentRow' ) as $name ) {
				self::assertNotContains( $name, $names, $file . ' uses ' . $name . '.' );
			}
		}

		// The guard gets only its check and a logger: nothing to read G1's activation with.
		$parameters = ( new ReflectionMethod( PayoutGiftCardCouponGuard::class, '__construct' ) )->getParameters();
		self::assertSame(
			array( Closure::class, Logger::class ),
			array_map( static fn( \ReflectionParameter $parameter ): string => $parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : '', $parameters )
		);
	}

	/**
	 * Public method names, sorted.
	 *
	 * @param class-string $type Class or interface.
	 * @return list<string>
	 */
	private static function methods( string $type ): array {
		$names = array_map( static fn( ReflectionMethod $method ): string => $method->getName(), ( new ReflectionClass( $type ) )->getMethods( ReflectionMethod::IS_PUBLIC ) );
		sort( $names );

		return $names;
	}

	/**
	 * Every name in a PHP file's code (identifiers, the last segment of
	 * qualified names, variables' property and method names), comments and
	 * strings excluded.
	 *
	 * @param string $file Path.
	 * @return list<string>
	 */
	private static function names( string $file ): array {
		$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file.
		self::assertIsString( $source, $file );
		$names = array();

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ), true ) ) {
				$parts   = explode( '\\', $token[1] );
				$names[] = (string) end( $parts );
			}
		}

		return array_values( array_unique( $names ) );
	}
}
