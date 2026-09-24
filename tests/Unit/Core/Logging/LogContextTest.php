<?php
/**
 * Tests for log sanitization.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Logging;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\LogContext;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

#[CoversClass( LogContext::class )]
final class LogContextTest extends TestCase {

	/**
	 * Keys whose values must never reach a log.
	 *
	 * @return array<string, array{string}>
	 */
	public static function sensitive_keys(): array {
		return array(
			'password'       => array( 'password' ),
			'user_pass'      => array( 'user_pass' ),
			'db password'    => array( 'DB_PASSWORD' ),
			'pwd'            => array( 'pwd' ),
			'secret'         => array( 'client_secret' ),
			'token'          => array( 'access_token' ),
			'api key'        => array( 'api_key' ),
			'api key camel'  => array( 'apiKey' ),
			'private key'    => array( 'private-key' ),
			'authorization'  => array( 'Authorization' ),
			'auth cookie'    => array( 'wordpress_logged_in_cookie' ),
			'session'        => array( 'session_id' ),
			'nonce'          => array( '_wpnonce' ),
			'salt'           => array( 'AUTH_SALT' ),
			'credentials'    => array( 'credentials' ),
			'card number'    => array( 'card_number' ),
			'cvv'            => array( 'cvv' ),
			'iban'           => array( 'iban' ),
			'customer email' => array( 'billing_email' ),
			'phone'          => array( 'phone' ),
			'address'        => array( 'shipping_address' ),
		);
	}

	#[DataProvider( 'sensitive_keys' )]
	public function test_redacts_values_of_sensitive_keys( string $key ): void {
		$clean = LogContext::sanitize( array( $key => 'hunter2-SECRET-VALUE' ) );

		self::assertSame( array( $key => LogContext::REDACTED ), $clean );
	}

	public function test_redacts_nested_sensitive_keys_and_whole_sensitive_arrays(): void {
		$clean = LogContext::sanitize(
			array(
				'request' => array(
					'user_id'  => 5,
					'password' => 'x',
				),
				'cookies' => array( 'a' => 'b' ),
			)
		);

		self::assertSame(
			array(
				'request' => array(
					'user_id'  => 5,
					'password' => LogContext::REDACTED,
				),
				'cookies' => LogContext::REDACTED,
			),
			$clean
		);
		self::assertStringNotContainsString( 'hunter2', (string) json_encode( $clean ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress.
	}

	public function test_keeps_diagnostic_scalars(): void {
		$context = array(
			'schema_version' => 1,
			'migration'      => 'MerchBoard\Core\Database\Migrations\CreateCreatorsTable',
			'ratio'          => 0.5,
			'ok'             => false,
			'missing'        => null,
		);

		self::assertSame( $context, LogContext::sanitize( $context ) );
	}

	public function test_foreign_exception_keeps_class_and_code_but_drops_message(): void {
		$clean = LogContext::sanitize(
			array( 'exception' => new RuntimeException( "Duplicate entry 'jane@example.com' in SELECT * FROM wp_users", 1062 ) )
		);

		self::assertSame(
			array(
				'exception' => array(
					'exception' => RuntimeException::class,
					'code'      => 1062,
				),
			),
			$clean
		);
	}

	public function test_own_exception_keeps_its_generic_message_without_trace_or_paths(): void {
		$clean = LogContext::sanitize( array( 'exception' => new DatabaseException( 'Could not create creator.' ) ) );

		self::assertSame(
			array(
				'exception' => array(
					'exception' => DatabaseException::class,
					'code'      => 0,
					'message'   => 'Could not create creator.',
				),
			),
			$clean
		);
		self::assertStringNotContainsString( '.php', (string) json_encode( $clean ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress.
	}

	public function test_objects_become_class_names(): void {
		self::assertSame(
			array( 'user' => array( 'object' => stdClass::class ) ),
			LogContext::sanitize( array( 'user' => new stdClass() ) )
		);
	}

	public function test_long_strings_are_truncated(): void {
		$clean = LogContext::sanitize( array( 'value' => str_repeat( 'a', 2000 ) ) );

		self::assertIsString( $clean['value'] );
		self::assertSame( 500 + mb_strlen( '…[truncated]' ), mb_strlen( $clean['value'] ) );
	}

	public function test_deep_nesting_is_truncated(): void {
		$clean = LogContext::sanitize( array( 'a' => array( 'b' => array( 'c' => array( 'd' => 1 ) ) ) ) );

		self::assertSame( array( 'a' => array( 'b' => array( 'c' => '[truncated]' ) ) ), $clean );
	}

	public function test_control_characters_are_removed_from_context_strings(): void {
		$clean = LogContext::sanitize( array( 'migration' => "class@anonymous\0/internal/eval.php\n\e[31m" ) );

		self::assertSame( array( 'migration' => 'class@anonymous /internal/eval.php [31m' ), $clean );
	}

	public function test_message_line_breaks_are_removed_to_prevent_log_forging(): void {
		self::assertSame(
			'Migration failed. [merchboard] ERROR: forged',
			LogContext::message( "Migration failed.\n[merchboard] ERROR: forged\r\n" )
		);
	}
}
