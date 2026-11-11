<?php
/**
 * The shop-wide switches of the community features (ADR-108).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Community;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Community\CommunitySettings;
use MerchBoard\Tests\Integration\IntegrationTestCase;

/**
 * Off by default; only administrators change them; every change is audited.
 */
final class CommunitySettingsTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( Settings::OPTION );
	}

	protected function tearDown(): void {
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_an_administrator_switches_the_features_and_each_change_is_audited(): void {
		$service = new CommunitySettings( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );

		self::assertFalse( Settings::twitch_sub_discount_enabled() );
		self::assertFalse( Settings::twitch_gifting_enabled() );

		self::assertSame( array( Settings::COMMUNITY_TWITCH_GIFTING_ENABLED ), $service->save( array( Settings::COMMUNITY_TWITCH_GIFTING_ENABLED => true ) ) );
		self::assertTrue( Settings::twitch_gifting_enabled() );
		self::assertFalse( Settings::twitch_sub_discount_enabled() );

		$audit = array_values( array_filter( $this->rows( Tables::AUDIT_LOG ), static fn( array $row ): bool => 'settings_updated' === $row['action'] ) );
		self::assertCount( 1, $audit );
		self::assertSame(
			array(
				'setting'        => Settings::COMMUNITY_TWITCH_GIFTING_ENABLED,
				'previous_value' => false,
				'value'          => true,
			),
			json_decode( (string) $audit[0]['context'], true )
		);

		self::assertSame( array(), $service->save( array( Settings::COMMUNITY_TWITCH_GIFTING_ENABLED => true ) ), 'No change, nothing stored.' );
		self::assertSame( array( Settings::COMMUNITY_TWITCH_GIFTING_ENABLED ), $service->save( array() ), 'Missing switches are off.' );
		self::assertFalse( Settings::twitch_gifting_enabled() );

		wp_set_current_user( $this->create_user( 'creator_user' ) );
		self::assertNull( $service->save( array( Settings::COMMUNITY_TWITCH_SUB_DISCOUNT_ENABLED => true ) ), 'Only administrators.' );
		self::assertFalse( Settings::twitch_sub_discount_enabled() );
	}
}
