<?php
/**
 * Tests for new creator discount codes.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\CreatorCodes;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeGenerator;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Name in capitals, a dash and four random characters without look-alikes
 * (ADR-078).
 */
#[CoversClass( CreatorCodeGenerator::class )]
final class CreatorCodeGeneratorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'remove_accents' )->alias(
			static fn( string $text ): string => strtr(
				$text,
				array(
					'ø' => 'o',
					'Å' => 'A',
					'å' => 'a',
					'æ' => 'ae',
					'é' => 'e',
				)
			)
		);
	}

	/**
	 * Names and the expected prefix.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function names(): array {
		return array(
			'plain'         => array( 'Nova', 'NOVA' ),
			'spaces, signs' => array( 'Nova <b>Live</b>!', 'NOVABLIVEB' ),
			'accents'       => array( 'Søren Ågård', 'SORENAGARD' ),
			'long'          => array( 'The Very Long Creator Name', 'THEVERYLON' ),
			'nothing left'  => array( '✨✨', 'CREATOR' ),
			'digits kept'   => array( 'ch4nten', 'CH4NTEN' ),
		);
	}

	#[DataProvider( 'names' )]
	public function test_the_code_is_the_name_a_dash_and_four_random_characters( string $name, string $prefix ): void {
		$code = CreatorCodeGenerator::candidate( $name );

		self::assertMatchesRegularExpression( '/^' . preg_quote( $prefix, '/' ) . '-[' . CreatorCodeGenerator::ALPHABET . ']{4}$/', $code );
	}

	public function test_the_random_part_has_no_look_alikes_and_varies(): void {
		self::assertDoesNotMatchRegularExpression( '/[01IOL]/', CreatorCodeGenerator::ALPHABET );

		$codes = array();

		for ( $i = 0; $i < 50; $i++ ) {
			$codes[] = CreatorCodeGenerator::candidate( 'Nova' );
		}

		self::assertGreaterThan( 40, count( array_unique( $codes ) ), 'About 900 000 combinations per name.' );
	}
}
