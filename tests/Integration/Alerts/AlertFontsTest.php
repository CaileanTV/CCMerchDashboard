<?php
/**
 * Integration tests for the alert font library.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminActions;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;
use MerchBoard\Modules\Alerts\Admin\FontsSettingsSection;
use MerchBoard\Modules\Alerts\Fonts\AlertFont;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Fonts\AlertFontsException;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use WP_Error;

/**
 * ADR-067: only `merchboard_manage_alerts` changes the library; the key is
 * never audited or shown; only catalogue families can be approved; every
 * change is audited together with the option.
 */
#[CoversClass( AlertFonts::class )]
#[CoversClass( AlertsAdminActions::class )]
#[CoversClass( AlertsAdminPage::class )]
final class AlertFontsTest extends IntegrationTestCase {

	private const KEY = 'AIzaTestKey_0123456789-abcdef';

	/**
	 * Requests sent to "Google".
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * The answer of "Google".
	 *
	 * @var mixed
	 */
	private mixed $response = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		add_filter( 'wp_die_handler', array( self::class, 'die_handler' ) );
		$this->response = self::google(
			200,
			array(
				'items' => array(
					array(
						'family'   => 'Roboto',
						'category' => 'sans-serif',
						'variants' => array( 'regular', '700' ),
					),
					array(
						'family'   => 'Lobster',
						'category' => 'display',
						'variants' => array( 'regular' ),
					),
					array(
						'family'   => 'Evil";}',
						'category' => 'display',
					),
					array(
						'family'   => 'Roboto',
						'category' => 'serif',
					),
				),
			)
		);
	}

	protected function tearDown(): void {
		remove_filter( 'wp_die_handler', array( self::class, 'die_handler' ) );
		remove_all_filters( 'pre_update_option_' . AlertFonts::APPROVED_OPTION );
		delete_option( AlertFonts::KEY_OPTION );
		delete_option( AlertFonts::APPROVED_OPTION );
		delete_transient( AlertFonts::CATALOG_KEY );
		parent::tearDown();
	}

	public function test_the_key_is_stored_without_autoload_and_never_audited(): void {
		$fonts = $this->fonts();
		self::assertFalse( $fonts->has_key() );

		$fonts->set_key( '  ' . self::KEY . '  ' );
		self::assertTrue( $fonts->has_key() );
		self::assertSame( self::KEY, get_option( AlertFonts::KEY_OPTION ) );
		self::assertSame( 'off', $this->autoload( AlertFonts::KEY_OPTION ) );

		$fonts->set_key( '' );
		self::assertFalse( $fonts->has_key() );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'alert_fonts_key_changed', 'alert_fonts_key_changed' ), array_column( $audit, 'action' ) );
		self::assertSame( array( 'removed' => true ), json_decode( (string) $audit[1]['context'], true ) );
		self::assertStringNotContainsString( self::KEY, (string) wp_json_encode( $audit ) );

		try {
			$fonts->set_key( 'short' );
			self::fail( 'Expected AlertFontsException.' );
		} catch ( AlertFontsException $e ) {
			self::assertSame( AlertFontsException::INVALID_KEY, $e->reason() );
		}
	}

	public function test_sync_needs_a_key_and_keeps_only_valid_unique_families(): void {
		$fonts = $this->fonts();

		try {
			$fonts->sync();
			self::fail( 'Expected AlertFontsException.' );
		} catch ( AlertFontsException $e ) {
			self::assertSame( AlertFontsException::NO_KEY, $e->reason() );
		}

		self::assertSame( array(), $this->requests, 'Nothing is sent without a key.' );

		$fonts->set_key( self::KEY );
		self::assertSame( 2, $fonts->sync() );
		self::assertTrue( $fonts->has_catalog() );
		$request = $this->only_request();
		self::assertStringStartsWith( AlertFonts::API_URL . '?', $request['url'] );
		self::assertStringContainsString( 'key=' . self::KEY, $request['url'] );
		self::assertSame( 0, $request['args']['redirection'] );

		self::assertSame( array( 'Roboto', 'Lobster' ), array_map( static fn( AlertFont $font ): string => $font->family, $fonts->catalog( '', 50 ) ), 'Popularity order, the first of a duplicate.' );
		self::assertSame( array( 'Lobster' ), array_map( static fn( AlertFont $font ): string => $font->family, $fonts->catalog( 'LOB', 50 ) ) );
		self::assertCount( 1, $fonts->catalog( '', 1 ) );
		self::assertSame( array( 'families' => 2 ), json_decode( (string) $this->rows( Tables::AUDIT_LOG )[1]['context'], true ) );
	}

	public function test_google_errors_keep_the_old_catalogue(): void {
		$fonts = $this->fonts();
		$fonts->set_key( self::KEY );
		$fonts->sync();

		foreach (
			array(
				AlertFontsException::INVALID_KEY => self::google( 403, array( 'error' => array() ) ),
				AlertFontsException::UNAVAILABLE => new WP_Error( 'http_request_failed', 'Timeout' ),
			) as $reason => $response
		) {
			$this->response = $response;

			try {
				$fonts->sync();
				self::fail( 'Expected AlertFontsException.' );
			} catch ( AlertFontsException $e ) {
				self::assertSame( $reason, $e->reason() );
			}
		}

		self::assertCount( 2, $fonts->catalog( '', 50 ) );
	}

	public function test_only_catalogue_families_are_approved_and_removal_is_audited(): void {
		$fonts = $this->fonts();
		$fonts->set_key( self::KEY );
		$fonts->sync();

		self::assertCount( count( AlertFonts::STANDARD_FONTS ), $fonts->approved(), 'The standard fonts are ready.' );
		self::assertFalse( $fonts->approve( 'Roboto' ), 'A standard font is approved already.' );
		self::assertTrue( $fonts->approve( 'Lobster' ) );
		self::assertFalse( $fonts->approve( 'Lobster' ), 'Already approved.' );
		self::assertCount( count( AlertFonts::STANDARD_FONTS ) + 1, $fonts->approved(), 'A new font comes on top of them.' );
		self::assertSame( array( 400, 700 ), $fonts->find( 'Roboto' )?->weights );
		self::assertSame( 'off', $this->autoload( AlertFonts::APPROVED_OPTION ) );

		try {
			$fonts->approve( 'Comic Sans' );
			self::fail( 'Expected AlertFontsException.' );
		} catch ( AlertFontsException $e ) {
			self::assertSame( AlertFontsException::UNKNOWN_FONT, $e->reason() );
		}

		self::assertTrue( $fonts->remove( 'Roboto' ) );
		self::assertFalse( $fonts->remove( 'Roboto' ) );
		self::assertNull( $fonts->find( 'Roboto' ) );

		$audit = array_slice( $this->rows( Tables::AUDIT_LOG ), 2 );
		self::assertSame( array( 'alert_font_approved', 'alert_font_removed' ), array_column( $audit, 'action' ) );
		self::assertSame( array( 'family' => 'Roboto' ), json_decode( (string) $audit[1]['context'], true ) );
	}

	public function test_a_failed_option_write_rolls_back_the_audit_record(): void {
		$fonts = $this->fonts();
		$fonts->set_key( self::KEY );
		$fonts->sync();
		$before = $this->count_rows( Tables::AUDIT_LOG );
		add_filter( 'pre_update_option_' . AlertFonts::APPROVED_OPTION, static fn( mixed $value, mixed $old ): mixed => $old, 10, 2 );

		try {
			$fonts->approve( 'Lobster' );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not save the approved fonts.', $e->getMessage() );
		}

		self::assertSame( $before, $this->count_rows( Tables::AUDIT_LOG ) );
		self::assertNull( $fonts->find( 'Lobster' ) );
	}

	public function test_users_without_the_capability_change_nothing(): void {
		wp_set_current_user( $this->create_user( 'fonts-subscriber' ) );
		$fonts = $this->fonts();

		foreach (
			array(
				static fn() => $fonts->set_key( self::KEY ),
				static fn() => $fonts->sync(),
				static fn() => $fonts->approve( 'Roboto' ),
				static fn() => $fonts->remove( 'Roboto' ),
			) as $change
		) {
			try {
				$change();
				self::fail( 'Expected AlertFontsException.' );
			} catch ( AlertFontsException $e ) {
				self::assertSame( AlertFontsException::NOT_AUTHORIZED, $e->reason() );
			}
		}

		self::assertFalse( $fonts->has_key() );
		self::assertSame( array(), $this->requests );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_the_admin_actions_check_the_nonce_per_family_and_redirect_with_a_code(): void {
		$actions = new AlertsAdminActions( $this->fonts(), new RecordingLogger() );

		$url = $actions->process(
			array(
				'action'   => AlertsAdminActions::KEY_ACTION,
				'_wpnonce' => wp_create_nonce( AlertsAdminActions::KEY_ACTION ),
				'key'      => self::KEY,
			)
		);
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=key_saved', $url );

		$url = $actions->process(
			array(
				'action'   => AlertsAdminActions::SYNC_ACTION,
				'_wpnonce' => wp_create_nonce( AlertsAdminActions::SYNC_ACTION ),
			)
		);
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=synced', $url );

		$approve = array(
			'action'   => AlertsAdminActions::APPROVE_ACTION,
			'family'   => 'Lobster',
			'_wpnonce' => wp_create_nonce( AlertsAdminActions::nonce_action( AlertsAdminActions::APPROVE_ACTION, 'Lobster' ) ),
		);
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=approved', $actions->process( $approve ) );
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=no_changes', $actions->process( $approve ) );

		$url = $actions->process(
			array(
				'action'   => AlertsAdminActions::APPROVE_ACTION,
				'family'   => 'Nope',
				'_wpnonce' => wp_create_nonce( AlertsAdminActions::nonce_action( AlertsAdminActions::APPROVE_ACTION, 'Nope' ) ),
			)
		);
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=unknown_font', $url );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'The link you followed has expired.' );
		$actions->process( array_merge( $approve, array( 'family' => 'Roboto' ) ) );
	}

	public function test_the_page_never_shows_the_key_and_lists_the_library(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$fonts = $this->fonts();
		$fonts->set_key( self::KEY );
		$fonts->sync();
		$fonts->approve( 'Roboto' );

		ob_start();
		( new FontsSettingsSection( $fonts ) )->render();
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( self::KEY, $html );
		self::assertStringContainsString( 'A key is saved. It is never shown again.', $html );
		self::assertStringContainsString( '<td>Roboto</td><td>sans-serif</td>', $html );
		self::assertStringContainsString( 'value="' . AlertsAdminActions::APPROVE_ACTION . '"', $html, 'Lobster can be approved.' );
		self::assertStringContainsString( 'value="' . AlertsAdminActions::REMOVE_ACTION . '"', $html );
		self::assertNotNull( AlertsAdminPage::result_message( 'approved' ) );
		self::assertNull( AlertsAdminPage::result_message( '<script>' ) );
	}

	/**
	 * The library with a recording HTTP client.
	 */
	private function fonts(): AlertFonts {
		return new AlertFonts(
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class ),
			function ( string $url, array $args ): mixed {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return $this->response;
			}
		);
	}

	/**
	 * The one request sent to "Google".
	 *
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private function only_request(): array {
		self::assertCount( 1, $this->requests );

		return $this->requests[0];
	}

	/**
	 * A wp_remote_get() answer.
	 *
	 * @param int          $code Status.
	 * @param array<mixed> $body JSON body.
	 * @return array<string, mixed>
	 */
	private static function google( int $code, array $body ): array {
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * The autoload flag of an option.
	 *
	 * @param string $option Option.
	 */
	private function autoload( string $option ): string {
		return (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $this->wpdb->options, $option ) );
	}

	/**
	 * Makes wp_die() throw, so a refused request ends the test.
	 */
	public static function die_handler(): callable {
		return static function ( mixed $message ): never {
			throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
		};
	}
}
