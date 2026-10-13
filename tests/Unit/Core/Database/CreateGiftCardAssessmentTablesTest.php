<?php
/**
 * Tests for schema migration 12.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Database;

use MerchBoard\Core\Database\Migrations\CreateGiftCardAssessmentTables;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreateGiftCardAssessmentTables::class )]
final class CreateGiftCardAssessmentTablesTest extends TestCase {

	private const CHARSET = 'DEFAULT CHARACTER SET utf8mb4';

	public function test_it_is_schema_version_12(): void {
		self::assertInstanceOf( CreateGiftCardAssessmentTables::class, Schema::migrations()[12] );
	}

	public function test_assessments_schema_has_one_row_per_order(): void {
		$sql = CreateGiftCardAssessmentTables::assessments_schema( 'wp_merchboard_order_gift_card_assessments', self::CHARSET );

		self::assertStringStartsWith( "CREATE TABLE wp_merchboard_order_gift_card_assessments (\n", $sql );
		foreach ( array(
			"\norder_id bigint(20) unsigned NOT NULL,\n",
			"\nstatus varchar(16) NOT NULL,\n",
			"\nreason_code varchar(32) DEFAULT NULL,\n",
			"\nsource varchar(12) DEFAULT NULL,\n",
			"\nassessed_at datetime DEFAULT NULL,\n",
			"\nassessed_by bigint(20) unsigned DEFAULT NULL,\n",
			"\ndecision_reason varchar(500) DEFAULT NULL,\n",
			"\nupdated_at datetime NOT NULL,\n",
			'PRIMARY KEY  (order_id)',
			"\nKEY status_updated (status,updated_at)\n",
		) as $fragment ) {
			self::assertStringContainsString( $fragment, $sql );
		}
	}

	public function test_payouts_schema_stores_references_only(): void {
		$sql = CreateGiftCardAssessmentTables::payouts_schema( 'wp_merchboard_order_gift_card_payouts', self::CHARSET );

		foreach ( array(
			"\norder_id bigint(20) unsigned NOT NULL,\n",
			"\npayout_id bigint(20) unsigned NOT NULL,\n",
			"\ncreator_id bigint(20) unsigned NOT NULL,\n",
			"\ngift_card_provider varchar(32) NOT NULL,\n",
			"\ngift_card_external_id varchar(64) NOT NULL,\n",
			"\nrecorded_at datetime NOT NULL,\n",
			'PRIMARY KEY  (order_id,payout_id)',
			"\nKEY creator_id (creator_id),\n",
			"\nKEY payout_id (payout_id)\n",
		) as $fragment ) {
			self::assertStringContainsString( $fragment, $sql );
		}
		foreach ( array( 'code', 'hash', 'secret' ) as $absent ) {
			self::assertStringNotContainsString( $absent, $sql, 'A gift card code is never stored.' );
		}
	}

	public function test_attempts_and_state_schemas(): void {
		$attempts = CreateGiftCardAssessmentTables::attempts_schema( 'wp_merchboard_order_gift_card_attempts', self::CHARSET );
		$state    = CreateGiftCardAssessmentTables::state_schema( 'wp_merchboard_g1_state', self::CHARSET );

		foreach ( array( "\nattempts int(10) unsigned NOT NULL,\n", "\nlast_attempt_at datetime NOT NULL,\n", "\nlast_error_code varchar(32) DEFAULT NULL,\n", 'PRIMARY KEY  (order_id)' ) as $fragment ) {
			self::assertStringContainsString( $fragment, $attempts );
		}
		foreach ( array( "\nid tinyint(3) unsigned NOT NULL,\n", "\ncutoff_utc datetime DEFAULT NULL,\n", "\nactivated_at datetime DEFAULT NULL,\n", "\nactivated_by bigint(20) unsigned DEFAULT NULL,\n", 'PRIMARY KEY  (id)' ) as $fragment ) {
			self::assertStringContainsString( $fragment, $state );
		}
	}
}
