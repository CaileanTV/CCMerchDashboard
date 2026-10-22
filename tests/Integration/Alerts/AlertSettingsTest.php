<?php
/**
 * Integration tests for the alert kill switch.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Admin\AlertsSettingsActions;
use MerchBoard\Modules\Alerts\Admin\AlertsSettingsSection;
use MerchBoard\Modules\Alerts\AlertSettings;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * ADR-065 pkt. 6 and 7: only `merchboard_manage_alerts` switches alerts on
 * or off; a change is audited together with the option, an unchanged value
 * writes nothing.
 */
#[CoversClass( AlertSettings::class )]
#[CoversClass( AlertsSettingsActions::class )]
#[CoversClass( AlertsSettingsSection::class )]
final class AlertSettingsTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install();
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_update_option_' . Settings::OPTION );
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_administrators_get_the_capability(): void {
		self::assertTrue( current_user_can( Capabilities::MANAGE_ALERTS ), 'Role version 6.' );
	}

	public function test_switching_on_and_off_is_audited_and_unchanged_writes_nothing(): void {
		$settings = $this->settings();

		self::assertTrue( $settings->set_enabled( true ) );
		wp_cache_delete( 'alloptions', 'options' );
		self::assertTrue( Settings::alerts_enabled() );

		self::assertFalse( $settings->set_enabled( true ), 'Unchanged.' );
		self::assertTrue( $settings->set_enabled( false ) );
		self::assertFalse( Settings::alerts_enabled() );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertCount( 2, $audit, 'One record per change, none for the unchanged save.' );
		self::assertSame( 'settings_updated', $audit[0]['action'] );
		self::assertSame(
			array(
				'setting'        => 'alerts_enabled',
				'previous_value' => false,
				'value'          => true,
			),
			json_decode( (string) $audit[0]['context'], true )
		);
	}

	public function test_users_without_the_capability_are_refused_before_anything_is_written(): void {
		$user = wp_insert_user(
			array(
				'user_login' => 'alerts-subscriber',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		self::assertIsInt( $user );
		wp_set_current_user( $user );

		try {
			$this->settings()->set_enabled( true );
			self::fail( 'Expected AlertSettingsException.' );
		} catch ( AlertSettingsException $e ) {
			self::assertSame( AlertSettingsException::NOT_AUTHORIZED, $e->reason() );
		}

		self::assertFalse( Settings::alerts_enabled() );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_a_failed_option_write_rolls_back_the_audit_record(): void {
		add_filter( 'pre_update_option_' . Settings::OPTION, static fn( mixed $value, mixed $old ): mixed => $old, 10, 2 );

		try {
			$this->settings()->set_enabled( true );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not save settings.', $e->getMessage() );
		}

		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
		self::assertFalse( Settings::alerts_enabled() );
	}

	public function test_the_form_saves_with_a_valid_nonce_and_redirects_with_a_whitelisted_code(): void {
		$actions = new AlertsSettingsActions( $this->settings(), new RecordingLogger() );
		$nonce   = wp_create_nonce( AlertsSettingsActions::SAVE_ACTION );

		$url = $actions->save(
			array(
				'_wpnonce'                           => $nonce,
				AlertsSettingsActions::FIELD_ENABLED => '1',
			)
		);
		self::assertStringContainsString( AlertsSettingsActions::RESULT_ARG . '=enabled', $url );
		self::assertTrue( Settings::alerts_enabled() );

		$url = $actions->save( array( '_wpnonce' => $nonce ) );
		self::assertStringContainsString( AlertsSettingsActions::RESULT_ARG . '=disabled', $url, 'An unchecked box switches alerts off.' );
		self::assertFalse( Settings::alerts_enabled() );

		self::assertNotNull( AlertsSettingsSection::result_message( 'enabled' ) );
		self::assertNull( AlertsSettingsSection::result_message( '<script>' ) );
	}

	public function test_the_section_shows_the_status_and_no_warning_without_merchalert(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		ob_start();
		( new AlertsSettingsSection( new AlertsAvailability( static fn(): bool => false ) ) )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'id="merchboard-alerts"', $html );
		self::assertStringContainsString( 'Off. No alerts are recorded.', $html );
		self::assertStringNotContainsString( 'MerchAlert is active', $html, 'MerchAlert is not loaded in the test site.' );
	}

	public function test_the_checkout_username_field_is_saved_audited_and_validated(): void {
		$settings = $this->settings();

		self::assertTrue( $settings->set_checkout_username( true, "  Twitch \n username ", '' ) );
		self::assertSame( array( true, 'Twitch username', '' ), array( Settings::checkout_username_enabled(), Settings::checkout_username_label(), Settings::checkout_username_help() ) );
		self::assertFalse( $settings->set_checkout_username( true, 'Twitch username', '' ), 'Unchanged.' );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'checkout_username_enabled', 'checkout_username_label' ), array_map( static fn( array $row ): mixed => json_decode( (string) $row['context'], true )['setting'] ?? null, $audit ), 'One record per changed value.' );

		foreach ( array(
			array( str_repeat( 'x', 101 ), '', AlertSettingsException::INVALID_CHECKOUT_LABEL ),
			array( '', str_repeat( 'x', 301 ), AlertSettingsException::INVALID_CHECKOUT_HELP ),
		) as [ $label, $help, $reason ] ) {
			try {
				$settings->set_checkout_username( false, $label, $help );
				self::fail( 'Expected AlertSettingsException.' );
			} catch ( AlertSettingsException $e ) {
				self::assertSame( $reason, $e->reason() );
			}
		}

		self::assertTrue( Settings::checkout_username_enabled(), 'Nothing was saved.' );

		// The form: an unchecked box switches the field off; the outcome is whitelisted.
		$actions = new AlertsSettingsActions( $settings, new RecordingLogger() );
		$nonce   = wp_create_nonce( AlertsSettingsActions::CHECKOUT_ACTION );
		$url     = $actions->save_checkout(
			array(
				'_wpnonce'                                 => $nonce,
				AlertsSettingsActions::FIELD_CHECKOUT_LABEL => 'Twitch username',
				AlertsSettingsActions::FIELD_CHECKOUT_HELP => 'Shown on stream.',
			)
		);
		self::assertStringContainsString( AlertsSettingsActions::CHECKOUT_RESULT_ARG . '=saved', $url );
		self::assertSame( array( false, 'Shown on stream.' ), array( Settings::checkout_username_enabled(), Settings::checkout_username_help() ) );
		self::assertStringContainsString( '=invalid_checkout_label', $actions->save_checkout( array( '_wpnonce' => $nonce, AlertsSettingsActions::FIELD_CHECKOUT_LABEL => str_repeat( 'x', 101 ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertNotNull( AlertsSettingsSection::checkout_result_message( 'saved' ) );
		self::assertNull( AlertsSettingsSection::checkout_result_message( '<script>' ) );

		require_once ABSPATH . 'wp-admin/includes/template.php';
		ob_start();
		( new AlertsSettingsSection( new AlertsAvailability( static fn(): bool => false ) ) )->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'id="merchboard-checkout-username"', $html );
		self::assertStringContainsString( 'value="Shown on stream."', $html );
		self::assertStringContainsString( 'placeholder="Stream username"', $html, 'The default as a placeholder.' );
	}

	/**
	 * The service as the module builds it (the Alerts module only boots with
	 * WooCommerce, which the integration site does not load).
	 */
	private function settings(): AlertSettings {
		return new AlertSettings( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
	}
}
