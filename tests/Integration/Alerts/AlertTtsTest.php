<?php
/**
 * Integration tests for the read-out of alerts (ADR-089).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use Closure;
use DateTimeImmutable;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Tts\AlertTts;
use MerchBoard\Modules\Alerts\Tts\TtsSettings;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * ADR-089: off until switched on with a key; a text is made once and
 * stored; the monthly limit, a least amount or a failure gives no read-out;
 * the key is never audited or logged.
 */
#[CoversClass( AlertTts::class )]
#[CoversClass( TtsSettings::class )]
final class AlertTtsTest extends IntegrationTestCase {

	private const KEY = 'AIzaSyTestKey_0123456789abcdef';

	private TtsSettings $settings;

	/**
	 * Requests sent to the fake Google.
	 *
	 * @var list<array{url: string, body: array<mixed>, key: string}>
	 */
	private array $sent = array();

	/**
	 * The fake Google's answer.
	 *
	 * @var array{0: int, 1: string}|null
	 */
	private ?array $answer = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		wp_set_current_user( 1 );
		$this->settings = new TtsSettings( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$this->answer   = array( 200, (string) wp_json_encode( array( 'audioContent' => base64_encode( 'ID3-fake-mp3' ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Google's answer.
	}

	protected function tearDown(): void {
		AlertTts::remove_all();

		foreach ( array( 'merchboard_tts_paused', 'merchboard_tts_cleaned', 'merchboard_tts_tests_1' ) as $transient ) {
			delete_transient( $transient );
		}

		foreach ( array( TtsSettings::OPTION, TtsSettings::KEY_OPTION, TtsSettings::USAGE_OPTION ) as $option ) {
			delete_option( $option );
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_nothing_is_read_out_until_switched_on_with_a_key(): void {
		$tts = $this->tts();

		self::assertSame( '', $tts->url_for( self::look(), self::event(), 'nova bought Hoodie' ) );
		$this->settings->save( true, 1000, '' );
		self::assertSame( '', $tts->url_for( self::look(), self::event(), 'nova bought Hoodie' ), 'No key yet.' );
		self::assertSame( array(), $this->sent );
	}

	public function test_a_text_is_made_once_stored_and_counted(): void {
		$this->settings->save( true, 1000, self::KEY );
		$tts = $this->tts();

		$url = $tts->url_for( self::look(), self::event(), "nova  bought\nHoodie" );

		self::assertStringContainsString( '/merchboard-alerts/tts/', $url );
		self::assertStringEndsWith( '.mp3', $url );
		self::assertCount( 1, $this->sent );
		self::assertSame( 'nova bought Hoodie', $this->sent[0]['body']['input']['text'] ?? null );
		self::assertSame( 'en-GB-Standard-B', $this->sent[0]['body']['voice']['name'] ?? null );
		self::assertSame( self::KEY, $this->sent[0]['key'] );
		self::assertStringNotContainsString( self::KEY, $this->sent[0]['url'], 'The key is a header, never in the URL.' );
		self::assertStringNotContainsString( hash( 'sha256', 'en-GB-Standard-B|nova bought Hoodie' ), $url, 'The name cannot be guessed from the text.' );
		self::assertSame( 18, $this->settings->used() );

		$uploads = wp_upload_dir( null, false );
		self::assertSame( 'ID3-fake-mp3', file_get_contents( $uploads['basedir'] . '/' . AlertTts::FOLDER . '/' . basename( $url ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reads a local file.

		self::assertSame( $url, $tts->url_for( self::look(), self::event(), 'nova bought Hoodie' ), 'The same text is not made again.' );
		self::assertCount( 1, $this->sent );
	}

	public function test_a_creator_who_is_not_live_gets_no_read_out(): void {
		$this->settings->save( true, 1000, self::KEY );
		$live = false;
		$tts  = $this->tts(
			static function () use ( &$live ): bool {
				return $live;
			}
		);

		self::assertSame( '', $tts->url_for( self::look(), self::event(), 'nova bought Hoodie' ), 'Not live yet (ADR-091).' );
		self::assertCount( 0, $this->sent, 'Nothing is sent to the read-out service.' );

		$live = true;
		self::assertNotSame( '', $tts->url_for( self::look(), self::event(), 'nova bought Hoodie' ) );
	}

	public function test_test_alerts_make_at_most_ten_read_outs_an_hour(): void {
		$this->settings->save( true, 100000, self::KEY );
		$tts  = $this->tts();
		$test = new AlertEvent( 1, 0, '0001', 0, 10, 0, 'Hoodie', '', 1, 0, array(), 'TestFan', new DateTimeImmutable(), 0, '249.0000', '249.0000', 'DKK' );

		for ( $i = 0; $i < AlertTts::TESTS_PER_HOUR; $i++ ) {
			self::assertNotSame( '', $tts->url_for( self::look(), $test, 'test ' . $i ) );
		}

		self::assertSame( '', $tts->url_for( self::look(), $test, 'one more test' ), 'The eleventh new test text is not made.' );
		self::assertNotSame( '', $tts->url_for( self::look(), $test, 'test 3' ), 'A test already made is still played.' );
		self::assertNotSame( '', $tts->url_for( self::look(), self::event(), 'a real purchase' ), 'Purchases are not limited by tests.' );
		delete_transient( 'merchboard_tts_tests_1' );
	}

	public function test_the_least_amount_the_monthly_limit_and_failures_give_no_read_out(): void {
		$this->settings->save( true, 20, self::KEY );
		$tts = $this->tts();

		self::assertSame( '', $tts->url_for( self::look( 500 ), self::event(), 'nova bought Hoodie' ), 'Below the least amount.' );
		self::assertSame( '', $tts->url_for( self::look(), self::event(), str_repeat( 'x', 21 ) ), 'Over the monthly limit.' );

		$this->answer = array( 403, '{}' );
		self::assertSame( '', $tts->url_for( self::look(), self::event(), 'refused' ) );
		self::assertSame( 0, $this->settings->used(), 'A failure is not counted.' );
		$this->answer = array( 200, (string) wp_json_encode( array( 'audioContent' => base64_encode( 'ID3' ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Google's answer.
		self::assertSame( '', $tts->url_for( self::look(), self::event(), 'after the failure' ), 'Nothing is made for a while after a failure.' );
		self::assertCount( 1, $this->sent );
	}

	public function test_a_busy_generation_lock_prevents_provider_calls(): void {
		$this->settings->save( true, 20, self::KEY );
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$lock  = 'merchboard_tts_' . md5( DB_NAME . '|' . $this->wpdb->options );
		self::assertSame( '1', $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) );
		try {
			self::assertSame( '', $this->tts()->url_for( self::look(), self::event(), 'contending request' ) );
			self::assertCount( 0, $this->sent );
			self::assertSame( 0, $this->settings->used() );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			$other->close();
		}
		self::assertNotSame( '', $this->tts()->url_for( self::look(), self::event(), 'contending request' ) );
		self::assertSame( '', $this->tts()->url_for( self::look(), self::event(), 'another request' ) );
		self::assertCount( 1, $this->sent );
	}

	public function test_usage_is_reserved_before_http_and_uncertain_failures_keep_it(): void {
		$this->settings->save( true, 20, self::KEY );
		$tts = new AlertTts(
			$this->settings,
			$this->service( Logger::class ),
			function (): ?array {
				self::assertSame( 18, $this->settings->used(), 'Reserved before the provider is called.' );
				return null;
			}
		);
		self::assertSame( '', $tts->url_for( self::look(), self::event(), 'nova bought Hoodie' ) );
		self::assertSame( 18, $this->settings->used(), 'A timeout may have incurred provider usage.' );
	}

	public function test_the_key_is_checked_encrypted_and_never_audited(): void {
		try {
			$this->settings->save( true, 1000, 'not a key' );
			self::fail( 'An invalid key is refused.' );
		} catch ( AlertSettingsException $e ) {
			self::assertSame( AlertSettingsException::INVALID_TTS_KEY, $e->reason() );
		}

		$this->settings->save( true, 1000, self::KEY );

		self::assertSame( self::KEY, $this->settings->key() );
		self::assertStringNotContainsString( self::KEY, (string) get_option( TtsSettings::KEY_OPTION ) );
		self::assertStringNotContainsString( self::KEY, (string) wp_json_encode( $this->audit_rows() ) );

		$this->settings->clear_key();
		self::assertFalse( $this->settings->available() );
	}

	/**
	 * The service with the fake Google.
	 *
	 * @param Closure|null $live Whether a creator is live; null: always.
	 * @phpstan-param (Closure(int): bool)|null $live
	 */
	private function tts( ?Closure $live = null ): AlertTts {
		return new AlertTts(
			$this->settings,
			$this->service( Logger::class ),
			function ( string $url, string $body, string $key ): ?array {
				$data         = json_decode( $body, true );
				$this->sent[] = array(
					'url'  => $url,
					'body' => is_array( $data ) ? $data : array(),
					'key'  => $key,
				);

				return $this->answer;
			},
			$live
		);
	}

	/**
	 * Settings with the read-out on.
	 *
	 * @param int $min_amount Least amount.
	 */
	private static function look( int $min_amount = 0 ): OverlaySettings {
		return OverlaySettings::from_array(
			array(
				'tts' => array(
					'enabled'    => true,
					'voice'      => 'en-GB-Standard-B',
					'min_amount' => $min_amount,
				),
			)
		);
	}

	/**
	 * An alert of 249 kr.
	 */
	private static function event(): AlertEvent {
		return new AlertEvent( 1, 7, '7', 70, 10, 0, 'Hoodie', '', 1, 0, array(), 'nova', new DateTimeImmutable(), 1, '249.0000', '249.0000', 'DKK' );
	}

	/**
	 * The audit rows' details.
	 *
	 * @return list<mixed>
	 */
	private function audit_rows(): array {
		return ( (array) $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT context FROM %i', Tables::name( $this->wpdb, Tables::AUDIT_LOG ) ) ) );
	}
}
