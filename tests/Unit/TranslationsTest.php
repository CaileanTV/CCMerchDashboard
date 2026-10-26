<?php
/**
 * Consistency checks of the shipped translations (phase 15).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The Danish translation is complete and safe: every string of the POT is
 * translated, placeholders are kept, no markup is added, and the JavaScript
 * JSON belongs to the dashboard bundle.
 */
#[CoversNothing]
final class TranslationsTest extends TestCase {

	private const LANGUAGES = __DIR__ . '/../../languages/';

	/**
	 * Entries of a PO/POT file: msgid → list of msgstr (one per plural form).
	 *
	 * @param string $file File.
	 * @return array<string, array<int, string>>
	 */
	private static function entries( string $file ): array {
		$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$entries = array();

		$blocks = preg_split( '/\n\n+/', trim( $content ) );

		foreach ( false === $blocks ? array() : $blocks as $block ) {
			$fields = array();
			$key    = null;

			foreach ( explode( "\n", $block ) as $line ) {
				if ( 1 === preg_match( '/^(msgid|msgid_plural|msgstr(?:\[\d\])?) "(.*)"$/', $line, $m ) ) {
					$key            = $m[1];
					$fields[ $key ] = stripcslashes( $m[2] );
				} elseif ( null !== $key && 1 === preg_match( '/^"(.*)"$/', $line, $m ) ) {
					$fields[ $key ] .= stripcslashes( $m[1] );
				}
			}

			if ( ! isset( $fields['msgid'] ) || '' === $fields['msgid'] ) {
				continue;
			}

			$entries[ $fields['msgid'] ] = array_values( array_filter( $fields, static fn( string $field ): bool => str_starts_with( $field, 'msgstr' ), ARRAY_FILTER_USE_KEY ) );
		}

		return $entries;
	}

	/**
	 * Sorted printf placeholders of a string.
	 *
	 * @param string $text Text.
	 * @return array<int, string>
	 */
	private static function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $m );
		$found = $m[0];
		sort( $found );

		return $found;
	}

	public function test_every_string_is_translated_to_danish(): void {
		$template = self::entries( self::LANGUAGES . 'merchboard.pot' );
		$danish   = self::entries( self::LANGUAGES . 'merchboard-da_DK.po' );

		self::assertGreaterThan( 200, count( $template ) );
		self::assertSame( array(), array_keys( array_diff_key( $template, $danish ) ), 'Strings missing in the Danish PO.' );

		foreach ( $danish as $msgid => $translations ) {
			foreach ( $translations as $translation ) {
				self::assertNotSame( '', trim( $translation ), 'Untranslated: ' . $msgid );
			}
		}
	}

	public function test_translations_keep_placeholders_and_add_no_markup(): void {
		foreach ( self::entries( self::LANGUAGES . 'merchboard-da_DK.po' ) as $msgid => $translations ) {
			foreach ( $translations as $translation ) {
				self::assertSame( self::placeholders( $msgid ), self::placeholders( $translation ), 'Placeholders differ: ' . $msgid );
				self::assertSame( substr_count( $msgid, '<' ), substr_count( $translation, '<' ), 'Markup added or removed: ' . $msgid );
			}
		}
	}

	public function test_compiled_files_exist(): void {
		$mo = self::LANGUAGES . 'merchboard-da_DK.mo';

		self::assertFileExists( $mo );
		// GNU MO magic number (little endian).
		self::assertSame( 'de120495', bin2hex( (string) file_get_contents( $mo, false, null, 0, 4 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
	}

	public function test_script_translations_belong_to_the_dashboard_bundle(): void {
		// WordPress looks for {domain}-{locale}-{md5 of the script path relative to the plugin}.json.
		$file = self::LANGUAGES . 'merchboard-da_DK-' . md5( 'build/dashboard.js' ) . '.json';
		self::assertFileExists( $file );

		$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		self::assertIsArray( $json );
		self::assertSame( 'build/dashboard.js', $json['source'] ?? null );

		$messages = $json['locale_data']['messages'] ?? array();
		self::assertIsArray( $messages );
		self::assertSame( 'da_DK', $messages['']['lang'] ?? null );
		self::assertSame( array( 'Nettosalg (ekskl. moms)' ), $messages['Net sales (excl. VAT)'] ?? null );
		self::assertSame( array( 'Sendt til godkendelse.' ), $messages['Sent for approval.'] ?? null );
		self::assertArrayNotHasKey( 'Overview', $messages, 'Section labels come translated from the server.' );

		$danish = self::entries( self::LANGUAGES . 'merchboard-da_DK.po' );

		foreach ( $messages as $msgid => $translation ) {
			if ( '' !== $msgid ) {
				self::assertSame( $danish[ $msgid ] ?? null, $translation, 'JSON differs from the PO: ' . $msgid );
			}
		}
	}
}
