<?php
/**
 * Tests for the integrity check of recalculation exports (ADR-062 pkt. 9).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts\Recalculation;

use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculationExports;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PayoutRecalculationExports::class )]
final class ExportChecksumsTest extends TestCase {

	public function test_an_intact_export_passes(): void {
		$export = self::export();

		self::assertSame(
			array(
				'items.csv'     => 'ok',
				'rows.csv'      => 'ok',
				'timeline.csv'  => 'ok',
				'manifest.json' => 'ok',
				'SHA256SUMS'    => 'ok',
			),
			PayoutRecalculationExports::check( $export['files'], $export['manifest'], $export['sums'] )
		);
	}

	public function test_a_changed_or_missing_file_is_reported(): void {
		$export              = self::export();
		$files               = $export['files'];
		$files['items.csv'] .= "9,changed\n";
		$missing             = $export['files'];
		unset( $missing['rows.csv'] );

		self::assertSame( 'changed', PayoutRecalculationExports::check( $files, $export['manifest'], $export['sums'] )['items.csv'] );
		self::assertSame( 'missing', PayoutRecalculationExports::check( $missing, $export['manifest'], $export['sums'] )['rows.csv'] );
	}

	public function test_a_changed_manifest_or_checksum_list_is_reported(): void {
		$export   = self::export();
		$manifest = $export['files'];
		$sums     = $export['files'];

		$manifest['manifest.json'] = str_replace( '"complete": true', '"complete": false', $manifest['manifest.json'] );
		$sums['SHA256SUMS']        = str_replace( 'items.csv', 'items2.csv', $sums['SHA256SUMS'] );

		self::assertSame( 'changed', PayoutRecalculationExports::check( $manifest, $export['manifest'], $export['sums'] )['manifest.json'] );
		$status = PayoutRecalculationExports::check( $sums, $export['manifest'], $export['sums'] );
		self::assertSame( array( 'changed', 'changed' ), array( $status['SHA256SUMS'], $status['items.csv'] ), 'A data file whose line is gone from SHA256SUMS does not pass either.' );
	}

	public function test_sha256sums_has_the_format_of_sha256sum(): void {
		$sums = PayoutRecalculationExports::parse_sums( hash( 'sha256', 'a' ) . "  items.csv\nnot a line\n" . hash( 'sha256', 'b' ) . "  manifest.json\n" );

		self::assertSame( array( 'items.csv' => hash( 'sha256', 'a' ), 'manifest.json' => hash( 'sha256', 'b' ) ), $sums ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * An export as PayoutRecalculationExports builds it: data files, a
	 * manifest with their checksums, and SHA256SUMS of data files and manifest.
	 *
	 * @return array{files: array<string, string>, manifest: string, sums: string}
	 */
	private static function export(): array {
		$files  = array(
			'items.csv'    => "item_id,difference\n1,8.0000\n",
			'rows.csv'     => "event_key,difference\ns:1:1,4.0000\n",
			'timeline.csv' => "time_utc,action\n2026-11-30 12:00:00,payout_recalculation_previewed\n",
		);
		$listed = array();

		foreach ( $files as $name => $content ) {
			$listed[] = array(
				'name'   => $name,
				'sha256' => hash( 'sha256', $content ),
			);
		}

		$files['manifest.json'] = (string) json_encode( array( 'complete' => true, 'files' => $listed ), JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$sums                   = '';

		foreach ( array( 'items.csv', 'rows.csv', 'timeline.csv', 'manifest.json' ) as $name ) {
			$sums .= hash( 'sha256', $files[ $name ] ) . '  ' . $name . "\n";
		}

		$files['SHA256SUMS'] = $sums;

		return array(
			'files'    => $files,
			'manifest' => hash( 'sha256', $files['manifest.json'] ),
			'sums'     => hash( 'sha256', $sums ),
		);
	}
}
