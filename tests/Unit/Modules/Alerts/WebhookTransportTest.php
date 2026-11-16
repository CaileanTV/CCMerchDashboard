<?php
/**
 * Webhook transport security regressions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Alerts\Webhooks\WebhookDispatcher;
use MerchBoard\Tests\Unit\TestCase;
use ReflectionClass;
use ReflectionMethod;
use WpOrg\Requests\Transport\Curl;
use WpOrg\Requests\Transport\Fsockopen;

/**
 * Address pinning must survive selection of the fallback transport.
 */
final class WebhookTransportTest extends TestCase {

	public function test_delivery_forces_curl_and_pins_the_validated_address(): void {
		require_once dirname( __DIR__, 4 ) . '/vendor/roots/wordpress-no-content/wp-includes/Requests/src/Autoload.php';
		\WpOrg\Requests\Autoload::register();
		$url   = 'https://receiver.example.com/hook';
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $name, callable $callback ) use ( &$hooks ): void {
				$hooks[ $name ] = $callback;
			}
		);
		Functions\when( 'remove_action' )->alias(
			static function ( string $name, callable $callback ) use ( &$hooks ): void {
				self::assertSame( $hooks[ $name ], $callback );
				unset( $hooks[ $name ] );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_safe_remote_post' )->alias(
			static function ( string $target, array $args ) use ( &$hooks, $url ): array {
				self::assertSame( $url, $target, 'Keep the hostname for TLS verification.' );
				$options = array( 'transport' => Fsockopen::class );
				$hooks['requests-requests.before_request']( 'https://other.example.com/', array(), null, 'POST', $options );
				self::assertSame( Fsockopen::class, $options['transport'] );
				$hooks['requests-requests.before_request']( $target, array(), null, 'POST', $options );
				self::assertSame( Curl::class, $options['transport'] );
				$handle = curl_init( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- Exercise the pinning hook with a real handle.
				$hooks['http_api_curl']( $handle, $args, $target );
				return array( 'response' => array( 'code' => 204 ) );
			}
		);
		Functions\expect( 'MerchBoard\\Modules\\Alerts\\Webhooks\\curl_setopt' )
			->once()
			->with( \Mockery::type( \CurlHandle::class ), CURLOPT_RESOLVE, array( 'receiver.example.com:443:93.184.216.34' ) )
			->andReturn( true );
		$dispatcher = ( new ReflectionClass( WebhookDispatcher::class ) )->newInstanceWithoutConstructor();
		$request    = new ReflectionMethod( WebhookDispatcher::class, 'request' );
		$response   = $request->invoke( $dispatcher, $url, 'receiver.example.com', '93.184.216.34', array() );
		self::assertSame( 204, $response['response']['code'] );
		self::assertSame( array(), $hooks, 'Temporary hooks are removed after delivery.' );
	}
}
