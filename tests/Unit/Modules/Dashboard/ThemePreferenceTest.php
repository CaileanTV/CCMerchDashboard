<?php
/**
 * Tests for the dashboard theme preference (ADR-044).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Dashboard;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Modules\Dashboard\ThemePreference;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass( ThemePreference::class )]
final class ThemePreferenceTest extends TestCase {

	/**
	 * User meta per user id and key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = array();

	/**
	 * Whether writes silently fail (a storage error).
	 *
	 * @var bool
	 */
	private bool $broken = false;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_user_meta' )->alias( fn( int $user_id, string $key ): mixed => $this->meta[ $user_id ][ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, mixed $value ): bool {
				if ( $this->broken ) {
					return false;
				}

				$this->meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( int $user_id, string $key ): bool {
				if ( $this->broken ) {
					return false;
				}

				unset( $this->meta[ $user_id ][ $key ] );
				return true;
			}
		);
	}

	public function test_the_six_themes_of_adr_043_and_the_meta_key(): void {
		self::assertSame( array( 'light', 'dark-black', 'dark-green', 'dark-blue', 'dark-red', 'dark-pink' ), ThemePreference::THEMES );
		self::assertSame( 'merchboard_dashboard_theme', ThemePreference::META_KEY );
		self::assertContains( ThemePreference::META_KEY, Uninstaller::USER_META_KEYS, 'The uninstall cleanup lists the key.' );
	}

	/**
	 * Every theme can be saved and read back.
	 *
	 * @return array<string, array{string}>
	 */
	public static function themes(): array {
		return array_combine( ThemePreference::THEMES, array_map( static fn( string $theme ): array => array( $theme ), ThemePreference::THEMES ) );
	}

	/**
	 * Save and read back.
	 *
	 * @param string $theme Theme.
	 */
	#[DataProvider( 'themes' )]
	public function test_each_theme_is_saved_for_that_user_only( string $theme ): void {
		$this->meta[8][ ThemePreference::META_KEY ] = 'light';

		( new ThemePreference() )->save( 7, $theme );

		self::assertSame( $theme, ( new ThemePreference() )->for_user( 7 ) );
		self::assertSame( 'light', $this->meta[8][ ThemePreference::META_KEY ], 'Another user is unchanged.' );
	}

	public function test_saving_the_same_theme_again_is_not_an_error(): void {
		$preference = new ThemePreference();
		$preference->save( 7, 'dark-blue' );

		// update_user_meta() reports false for an unchanged value.
		$this->broken = true;
		$preference->save( 7, 'dark-blue' );

		self::assertSame( 'dark-blue', $preference->for_user( 7 ) );
	}

	public function test_null_removes_the_explicit_choice(): void {
		$this->meta[7][ ThemePreference::META_KEY ] = 'dark-red';

		( new ThemePreference() )->save( 7, null );

		self::assertArrayNotHasKey( ThemePreference::META_KEY, $this->meta[7] );
		self::assertNull( ( new ThemePreference() )->for_user( 7 ) );
	}

	/**
	 * Values that are not a theme.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid(): array {
		return array(
			'empty'          => array( '' ),
			'unknown'        => array( 'dark-purple' ),
			'system guess'   => array( 'dark' ),
			'different case' => array( 'Light' ),
			'padded'         => array( ' light' ),
			'markup'         => array( 'light"><script>' ),
		);
	}

	/**
	 * Invalid values are refused and nothing is stored.
	 *
	 * @param string $value Value.
	 */
	#[DataProvider( 'invalid' )]
	public function test_invalid_values_are_refused( string $value ): void {
		$this->meta[7][ ThemePreference::META_KEY ] = 'dark-green';

		try {
			( new ThemePreference() )->save( 7, $value );
			self::fail( 'Expected an exception.' );
		} catch ( InvalidArgumentException ) {
			self::assertSame( 'dark-green', $this->meta[7][ ThemePreference::META_KEY ], 'Nothing changed.' );
		}

		self::assertFalse( ThemePreference::is_theme( $value ) );
	}

	/**
	 * A stored value that is not a theme counts as no choice.
	 *
	 * @param string $value Value.
	 */
	#[DataProvider( 'invalid' )]
	public function test_an_invalid_stored_value_counts_as_no_choice( string $value ): void {
		$this->meta[7][ ThemePreference::META_KEY ] = $value;

		self::assertNull( ( new ThemePreference() )->for_user( 7 ) );
	}

	public function test_no_user_has_no_theme_and_cannot_save(): void {
		self::assertNull( ( new ThemePreference() )->for_user( 0 ) );

		$this->expectException( InvalidArgumentException::class );
		( new ThemePreference() )->save( 0, 'light' );
	}

	public function test_a_failed_write_is_an_error(): void {
		$this->broken = true;

		$this->expectException( RuntimeException::class );
		( new ThemePreference() )->save( 7, 'dark-pink' );
	}
}
