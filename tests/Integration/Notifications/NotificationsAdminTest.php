<?php
/**
 * Merch Dashboard → Notifications (phase 29, step 29.3, ADR-079).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Notifications;

use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Notifications\Admin\NotificationsAdminActions;
use MerchBoard\Modules\Notifications\Admin\NotificationsAdminPage;
use MerchBoard\Modules\Notifications\NotificationSettings;
use MerchBoard\Modules\Notifications\NotificationTypes;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\WooCommerce\CreatorPricingAdminTest;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * The administrator turns notifications on and off, sees each as an
 * example (the e-mail in a sandboxed iframe) and sends a test e-mail.
 */
#[CoversClass( NotificationsAdminPage::class )]
#[CoversClass( NotificationsAdminActions::class )]
final class NotificationsAdminTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( NotificationSettings::OPTION );
		$GLOBALS['merchboard_test_mails'] = array();
		add_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		delete_option( NotificationSettings::OPTION );
		parent::tearDown();
	}

	public function test_the_page_lists_every_notification_with_its_switches(): void {
		$html  = $this->render();
		$types = $this->service( NotificationTypes::class );

		foreach ( $types->all() as $type ) {
			self::assertStringContainsString( esc_html( $type->label() ), $html, $type->key );
			self::assertStringContainsString( 'name="notifications[' . $type->key . '][email]"', $html, $type->key );
			self::assertSame( $type->has_dashboard() ? 1 : 0, substr_count( $html, 'name="notifications[' . $type->key . '][dashboard]"' ), $type->key );
			self::assertStringContainsString( 'preview=' . $type->key, $html, $type->key );
		}

		self::assertStringContainsString( (string) get_option( 'admin_email' ), $html, 'Who gets the administrator\'s e-mails.' );
		self::assertStringContainsString( 'No notification e-mails yet.', $html );
	}

	public function test_saving_turns_a_notification_off(): void {
		$choices = array();

		foreach ( $this->service( NotificationTypes::class )->all() as $type ) {
			if ( 'sale_new' !== $type->key ) {
				$choices[ $type->key ] = array(
					'email'     => $type->email ? '1' : '',
					'dashboard' => $type->dashboard ? '1' : '',
				);
			}
		}

		$choices['sale_new'] = array( 'dashboard' => '1' );

		self::assertStringContainsString(
			NotificationsAdminPage::RESULT_ARG . '=saved',
			$this->actions()->handle(
				$this->signed(
					array(
						'do'            => 'save',
						'notifications' => $choices,
					)
				)
			)
		);

		$type = $this->service( NotificationTypes::class )->get( 'sale_new' );
		self::assertNotNull( $type );
		self::assertFalse( $this->service( NotificationSettings::class )->enabled( $type, NotificationSettings::EMAIL ) );
		self::assertTrue( $this->service( NotificationSettings::class )->enabled( $type, NotificationSettings::DASHBOARD ) );
		self::assertStringNotContainsString( 'name="notifications[sale_new][email]" value="1" checked', $this->render() );
		self::assertSame( 'settings_updated', (string) ( $this->rows( Tables::AUDIT_LOG )[0]['action'] ?? '' ) );
	}

	public function test_the_example_shows_the_email_sandboxed_and_sends_a_test(): void {
		$_GET['preview'] = 'payout_rejected';
		$html            = $this->render();
		unset( $_GET['preview'] );

		self::assertStringContainsString( '<iframe title="E-mail example" sandbox="" srcdoc="', $html );
		self::assertStringContainsString( '&lt;h1', $html, 'The e-mail is escaped into srcdoc.' );
		self::assertStringContainsString( 'Your payout request of', $html, 'The dashboard line.' );
		self::assertStringContainsString( 'Subject: Your payout request was rejected', $html );
		self::assertStringContainsString( 'value="test"', $html );

		$url = $this->actions()->handle(
			$this->signed(
				array(
					'do'   => 'test',
					'type' => 'payout_rejected',
				)
			)
		);

		self::assertStringContainsString( NotificationsAdminPage::RESULT_ARG . '=test_sent', $url );
		self::assertCount( 1, $GLOBALS['merchboard_test_mails'] );
		self::assertSame( wp_get_current_user()->user_email, $GLOBALS['merchboard_test_mails'][0]['to'] );
		self::assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $GLOBALS['merchboard_test_mails'][0]['headers'] ) );
		self::assertStringContainsString(
			NotificationsAdminPage::RESULT_ARG . '=unknown_type',
			$this->actions()->handle(
				$this->signed(
					array(
						'do'   => 'test',
						'type' => 'nope',
					)
				)
			)
		);
	}

	public function test_only_an_administrator_with_a_valid_nonce(): void {
		$this->assert_dies(
			'link you followed has expired',
			array(
				'do'       => 'save',
				'_wpnonce' => 'bad',
			)
		);

		wp_set_current_user( $this->create_user( 'fan' ) );
		$this->assert_dies( 'not allowed to manage the notifications', $this->signed( array( 'do' => 'save' ) ) );
	}

	/**
	 * The rendered page.
	 */
	private function render(): string {
		ob_start();
		$this->service( NotificationsAdminPage::class )->render();

		return (string) ob_get_clean();
	}

	/**
	 * The form handler.
	 */
	private function actions(): NotificationsAdminActions {
		return $this->service( NotificationsAdminActions::class );
	}

	/**
	 * Form data with a valid nonce.
	 *
	 * @param array<string, mixed> $input Fields.
	 * @return array<string, mixed>
	 */
	private function signed( array $input ): array {
		return $input + array(
			'action'   => NotificationsAdminActions::ACTION,
			'_wpnonce' => wp_create_nonce( NotificationsAdminActions::ACTION ),
		);
	}

	/**
	 * Asserts that handling dies with a message.
	 *
	 * @param string               $message Part of the message.
	 * @param array<string, mixed> $input   Input.
	 */
	private function assert_dies( string $message, array $input ): void {
		try {
			$this->actions()->handle( $input );
			self::fail( 'Did not die.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( $message, $e->getMessage() );
		}
	}
}
