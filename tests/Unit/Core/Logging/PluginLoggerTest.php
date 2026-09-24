<?php
/**
 * Tests for the logger, levels and backends.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Logging;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use MerchBoard\Core\Logging\ErrorLogWriter;
use MerchBoard\Core\Logging\LogContext;
use MerchBoard\Core\Logging\LogLevel;
use MerchBoard\Core\Logging\PluginLogger;
use MerchBoard\Core\Logging\WooCommerceLogWriter;
use MerchBoard\Tests\Unit\Support\RecordingLogWriter;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;

#[CoversClass( PluginLogger::class )]
#[CoversClass( LogLevel::class )]
#[CoversClass( ErrorLogWriter::class )]
#[CoversClass( WooCommerceLogWriter::class )]
final class PluginLoggerTest extends TestCase {

	public function test_each_level_reaches_the_writer_with_its_level(): void {
		$writer = new RecordingLogWriter();
		$logger = new PluginLogger( LogLevel::Debug, $writer );

		$logger->debug( 'D.' );
		$logger->info( 'I.' );
		$logger->warning( 'W.' );
		$logger->error( 'E.' );

		self::assertSame(
			array( LogLevel::Debug, LogLevel::Info, LogLevel::Warning, LogLevel::Error ),
			array_column( $writer->writes, 'level' )
		);
		self::assertSame( array( 'D.', 'I.', 'W.', 'E.' ), array_column( $writer->writes, 'message' ) );
	}

	public function test_levels_below_the_threshold_are_dropped(): void {
		$writer = new RecordingLogWriter();
		$logger = new PluginLogger( LogLevel::Warning, $writer );

		$logger->debug( 'D.' );
		$logger->info( 'I.' );
		$logger->warning( 'W.' );
		$logger->error( 'E.' );

		self::assertSame( array( 'W.', 'E.' ), array_column( $writer->writes, 'message' ) );
	}

	public function test_context_is_passed_sanitized(): void {
		$writer = new RecordingLogWriter();

		( new PluginLogger( LogLevel::Debug, $writer ) )->error(
			"Failed.\nforged",
			array(
				'schema_version' => 1,
				'password'       => 'hunter2',
			)
		);

		self::assertSame( 'Failed. forged', $writer->writes[0]['message'] );
		self::assertSame(
			array(
				'schema_version' => 1,
				'password'       => LogContext::REDACTED,
			),
			$writer->writes[0]['context']
		);
	}

	public function test_default_threshold_follows_wp_debug(): void {
		self::assertSame( LogLevel::Debug, LogLevel::default_threshold( true ) );
		self::assertSame( LogLevel::Warning, LogLevel::default_threshold( false ) );
	}

	public function test_level_values_match_woocommerce_and_psr3_names(): void {
		self::assertSame(
			array( 'debug', 'info', 'warning', 'error' ),
			array_map( static fn( LogLevel $l ): string => $l->value, LogLevel::cases() )
		);
	}

	public function test_threshold_can_be_raised_or_lowered_via_filter(): void {
		Filters\expectApplied( 'merchboard_log_level' )->once()->with( 'warning' )->andReturn( 'info' );

		self::assertSame( 'info', $this->threshold_of( PluginLogger::for_wordpress() ) );
	}

	public function test_invalid_filtered_threshold_falls_back_to_default(): void {
		Filters\expectApplied( 'merchboard_log_level' )->once()->andReturn( 'trace' );

		self::assertSame( 'warning', $this->threshold_of( PluginLogger::for_wordpress() ) );
	}

	public function test_error_log_writer_formats_one_line_with_json_context(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$lines  = array();
		$writer = new ErrorLogWriter(
			static function ( string $line ) use ( &$lines ): bool {
				$lines[] = $line;
				return true;
			}
		);

		$writer->write( LogLevel::Error, 'Database migration failed.', array( 'schema_version' => 1 ) );
		$writer->write( LogLevel::Info, 'No context.', array() );

		self::assertSame(
			array(
				'[merchboard] ERROR: Database migration failed. {"schema_version":1}',
				'[merchboard] INFO: No context.',
			),
			$lines
		);
	}

	public function test_without_woocommerce_entries_go_to_the_fallback(): void {
		self::assertFalse( function_exists( 'wc_get_logger' ), 'WooCommerce must not be loaded in the main test process.' );
		$fallback = new RecordingLogWriter();

		( new WooCommerceLogWriter( $fallback ) )->write( LogLevel::Error, 'E.', array( 'a' => 1 ) );

		self::assertSame( 'E.', $fallback->writes[0]['message'] );
		self::assertSame( array( 'a' => 1 ), $fallback->writes[0]['context'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_woocommerce_loaded_but_not_initialised_uses_the_fallback(): void {
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-logger.php';
		$fallback = new RecordingLogWriter();

		( new WooCommerceLogWriter( $fallback ) )->write( LogLevel::Info, 'Early.', array() );

		self::assertSame( 'Early.', $fallback->writes[0]['message'] );
		self::assertSame( array(), \MerchBoardTestWcLogger::$entries );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_with_woocommerce_entries_go_to_wc_logger_with_source(): void {
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-logger.php';
		do_action( 'woocommerce_init' );
		$fallback = new RecordingLogWriter();

		( new WooCommerceLogWriter( $fallback ) )->write( LogLevel::Warning, 'W.', array( 'a' => 1 ) );

		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'W.',
					'context' => array(
						'a'      => 1,
						'source' => 'merchboard',
					),
				),
			),
			\MerchBoardTestWcLogger::$entries
		);
		self::assertSame( array(), $fallback->writes );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_failing_woocommerce_logger_falls_back_without_losing_the_entry(): void {
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-logger.php';
		do_action( 'woocommerce_init' );
		\MerchBoardTestWcLogger::$throw = new RuntimeException( 'handler broken' );
		$fallback                       = new RecordingLogWriter();

		( new WooCommerceLogWriter( $fallback ) )->write( LogLevel::Error, 'E.', array( 'a' => 1 ) );

		self::assertSame( 'E.', $fallback->writes[0]['message'] );
		self::assertSame(
			array(
				'a'                   => 1,
				'log_backend_failure' => RuntimeException::class,
			),
			$fallback->writes[0]['context']
		);
	}

	/**
	 * Reads the logger's threshold (private) for assertions.
	 *
	 * @param PluginLogger $logger Logger.
	 */
	private function threshold_of( PluginLogger $logger ): string {
		$threshold = ( new \ReflectionProperty( PluginLogger::class, 'threshold' ) )->getValue( $logger );

		self::assertInstanceOf( LogLevel::class, $threshold );

		return $threshold->value;
	}
}
