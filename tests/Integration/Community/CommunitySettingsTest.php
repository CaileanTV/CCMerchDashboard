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
use MerchBoard\Core\Settings\CommunityLink;
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

	public function test_the_link_name_is_chosen_audited_and_old_links_keep_working(): void {
		$service = new CommunitySettings( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );

		self::assertSame( 'merchboard_gift_claim', CommunityLink::name(), 'Unchanged until chosen.' );

		self::assertSame( array( Settings::COMMUNITY_LINK_PARAM ), $service->save( array( Settings::COMMUNITY_LINK_PARAM => 'gave' ) ) );
		self::assertSame( 'gave', CommunityLink::name() );
		self::assertStringContainsString( '?gave=abc', CommunityLink::url( 'abc' ) );

		self::assertSame( array( Settings::COMMUNITY_LINK_PARAM ), $service->save( array( Settings::COMMUNITY_LINK_PARAM => 'gift' ) ) );
		self::assertSame( array( 'gift', 'gave', 'merchboard_gift_claim' ), CommunityLink::names(), 'Links already passed on keep working.' );
		self::assertSame( 'abc', CommunityLink::token( array( 'gave' => 'abc' ) ) );

		self::assertSame( array(), $service->save( array( Settings::COMMUNITY_LINK_PARAM => 'page' ) ), 'A reserved name is never stored.' );
		self::assertSame( 'gift', CommunityLink::name() );

		$audit = array_values( array_filter( $this->rows( Tables::AUDIT_LOG ), static fn( array $row ): bool => 'settings_updated' === $row['action'] ) );
		self::assertSame(
			array(
				'setting'        => Settings::COMMUNITY_LINK_PARAM,
				'previous_value' => 'gave',
				'value'          => 'gift',
			),
			json_decode( (string) ( $audit[ count( $audit ) - 1 ]['context'] ?? '' ), true )
		);
	}
}
