<?php
/**
 * Tests for "Fill in from the CVR number" (ADR-105).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts\Settlements;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CvrLookup;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CvrLookupException;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CvrLookup::class )]
#[CoversClass( CvrLookupException::class )]
final class CvrLookupTest extends TestCase {

	/**
	 * Transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = array(
		'company_name'  => 'CCMerch ApS',
		'company_email' => 'kontakt@shop.test',
	);

	/**
	 * Calls made: [url, args].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $calls = array();

	/**
	 * The next answer's body.
	 *
	 * @var string
	 */
	private string $body = '';

	private RecordingLogger $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = new RecordingLogger();
		Functions\when( 'get_option' )->alias(
			function ( string $name, mixed $fallback = false ): mixed {
				if ( Settings::OPTION === $name ) {
					return $this->settings;
				}

				return $fallback;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $key ): mixed => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, mixed $value ): bool {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $response ): string => (string) $response['body'] );
		Functions\stubTranslationFunctions();

		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
	}

	public function test_the_company_fills_the_form_and_is_cached(): void {
		$this->body = (string) wp_json_encode(
			array(
				'vat'      => 12345678,
				'name'     => 'Nova ApS',
				'address'  => 'Nørregade 2',
				'zipcode'  => '8000',
				'city'     => 'Aarhus C',
				'cityname' => null,
				'owners'   => array( array( 'name' => 'Not used' ) ),
			)
		);
		$lookup     = $this->lookup();

		self::assertSame(
			array(
				'legal_name' => 'Nova ApS',
				'address_1'  => 'Nørregade 2',
				'postcode'   => '8000',
				'city'       => 'Aarhus C',
				'country'    => 'Denmark',
			),
			$lookup->lookup( '1234 5678', 7 )
		);
		self::assertSame( 'https://cvrapi.dk/api?search=12345678&country=dk', $this->calls[0][0], 'Only the number is sent, over HTTPS.' );
		self::assertSame( 'CCMerch ApS - Merch Dashboard - kontakt@shop.test', $this->calls[0][1]['user-agent'] );
		self::assertSame( array( 5, 0 ), array( $this->calls[0][1]['timeout'], $this->calls[0][1]['redirection'] ) );

		$lookup->lookup( '12345678', 8 );
		self::assertCount( 1, $this->calls, 'The second lookup is answered from the cache.' );
		self::assertSame( array(), $this->logger->entries );
	}

	public function test_not_found_invalid_and_unreadable_answers(): void {
		$this->body = '{"error":"NOT_FOUND"}';
		$this->refused( '11111111', CvrLookup::NOT_FOUND );
		$this->refused( '11111111', CvrLookup::NOT_FOUND );
		self::assertCount( 1, $this->calls, '"Not found" is cached too.' );

		$this->body = '{"name":"","address":"x"}';
		$this->refused( '22222222', CvrLookup::NOT_FOUND );

		$this->body = '{"error":"INTERNAL_ERROR"}';
		$this->refused( '33333333', CvrLookup::UNAVAILABLE );

		$this->body = 'not json';
		$this->refused( '44444444', CvrLookup::UNAVAILABLE );
		$this->refused( '1234', CvrLookup::INVALID );
		self::assertCount( 4, $this->calls, 'An invalid number is never sent.' );
		self::assertStringNotContainsString( '4444', (string) wp_json_encode( $this->logger->entries ), 'Numbers are never logged.' );
	}

	public function test_hostile_values_are_dropped(): void {
		$this->body = (string) wp_json_encode(
			array(
				'name'    => 'Nova ApS',
				'address' => "Nørregade 2\n<script>",
				'zipcode' => 8000,
				'city'    => str_repeat( 'x', 101 ),
			)
		);

		$values = $this->lookup()->lookup( '12345678', 7 );
		self::assertSame( array( '', '8000', '' ), array( $values['address_1'], $values['postcode'], $values['city'] ) );
	}

	public function test_the_daily_limits_and_the_quota_stop_calls(): void {
		$this->body = '{"name":"Nova ApS"}';

		for ( $i = 0; $i < CvrLookup::CREATOR_LIMIT; $i++ ) {
			$this->lookup()->lookup( (string) ( 10000000 + $i ), 7 );
		}

		$this->refused( '19999999', CvrLookup::LIMIT, 7 );
		$this->lookup()->lookup( '19999999', 8 );
		self::assertCount( CvrLookup::CREATOR_LIMIT + 1, $this->calls, 'Another creator may still look up.' );

		$this->body = '{"error":"QUOTA_EXCEEDED"}';
		$this->refused( '29999999', CvrLookup::LIMIT, 9 );
		$this->refused( '39999999', CvrLookup::LIMIT, 9 );
		self::assertCount( CvrLookup::CREATOR_LIMIT + 2, $this->calls, 'After QUOTA_EXCEEDED nothing is sent for the rest of the day.' );
	}

	public function test_without_the_business_name_or_contact_e_mail_nothing_is_sent(): void {
		$this->settings = array( 'company_name' => 'CCMerch ApS' );

		self::assertFalse( $this->lookup()->available() );
		$this->refused( '12345678', CvrLookup::UNAVAILABLE );
		self::assertSame( array(), $this->calls );
	}

	/**
	 * The lookup with the recorded HTTP client.
	 */
	private function lookup(): CvrLookup {
		return new CvrLookup(
			$this->logger,
			function ( string $url, array $args ): array {
				$this->calls[] = array( $url, $args );

				return array( 'body' => $this->body );
			},
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-09 12:00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Requires a refusal.
	 *
	 * @param string $cvr     Number.
	 * @param string $reason  Expected code.
	 * @param int    $creator Creator.
	 */
	private function refused( string $cvr, string $reason, int $creator = 7 ): void {
		try {
			$this->lookup()->lookup( $cvr, $creator );
			self::fail( 'Expected CvrLookupException.' );
		} catch ( CvrLookupException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}
}
