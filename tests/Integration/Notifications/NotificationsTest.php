<?php
/**
 * Notifications: catalog, storage, choices and sending (phase 29, ADR-079).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Notifications;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Notifications\Email\EmailRenderer;
use MerchBoard\Modules\Notifications\Email\EmailStyle;
use MerchBoard\Modules\Notifications\Notification;
use MerchBoard\Modules\Notifications\NotificationData;
use MerchBoard\Modules\Notifications\NotificationPreferences;
use MerchBoard\Modules\Notifications\NotificationRepository;
use MerchBoard\Modules\Notifications\NotificationSender;
use MerchBoard\Modules\Notifications\NotificationSettings;
use MerchBoard\Modules\Notifications\NotificationsCreatorCleanup;
use MerchBoard\Modules\Notifications\NotificationType;
use MerchBoard\Modules\Notifications\NotificationTypes;
use MerchBoard\Modules\Notifications\Notifier;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\RecordingMailer;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The core of the notifications without the triggers (29.5–29.6): what the
 * catalog renders, what is stored for which choice, and how e-mails go out.
 */
#[CoversClass( Notifier::class )]
#[CoversClass( NotificationSender::class )]
#[CoversClass( NotificationRepository::class )]
#[CoversClass( NotificationSettings::class )]
#[CoversClass( EmailRenderer::class )]
final class NotificationsTest extends IntegrationTestCase {

	private NotificationRepository $notifications;

	private NotificationTypes $types;

	private FakeJobQueue $queue;

	private RecordingMailer $mail;

	private int $nova;

	private int $nova_user;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( NotificationSettings::OPTION );

		$this->notifications = new NotificationRepository( $this->wpdb );
		$this->types         = new NotificationTypes();
		$this->queue         = new FakeJobQueue();
		$this->mail          = new RecordingMailer();
		$this->nova_user     = $this->create_user( 'nova' );
		$this->nova          = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->nova_user )->id;
		// Without the welcome the creation fired (29.5).
		$this->execute( 'DELETE FROM %i', $this->table( Tables::NOTIFICATIONS ) );
	}

	protected function tearDown(): void {
		delete_option( NotificationSettings::OPTION );
		parent::tearDown();
	}

	public function test_every_type_renders_its_sample_escaped(): void {
		$sender = $this->sender();

		self::assertCount( 36, $this->types->all(), '27 creator and 9 administrator notifications (features/notifications.md).' );

		foreach ( $this->types->all() as $type ) {
			$data                 = $type->sample;
			$data['order_number'] = '<script>alert(1)</script>';
			$mail                 = $sender->compose( $type, $data, 'Nova & Co' );

			self::assertNotSame( '', $mail['subject'], $type->key );
			self::assertStringNotContainsString( '<script>', $mail['html'], $type->key );
			self::assertStringContainsString( '<h1', $mail['html'], $type->key );
			self::assertStringContainsString( EmailStyle::DEFAULT_BASE, $mail['html'], $type->key . ': the accent color' );
			self::assertNotSame( '', trim( $mail['text'] ), $type->key );
			self::assertNotSame( '', $type->text( new NotificationData( $type->sample ) ), $type->key );
			self::assertNotSame( '', $type->label(), $type->key );
			self::assertArrayHasKey( $type->group, NotificationTypes::groups(), $type->key );
			self::assertFalse( NotificationType::ADMIN === $type->audience && $type->dashboard, $type->key . ': no bell for the administrator' );
		}

		$sale = $sender->compose( $this->type( 'sale_new' ), $this->type( 'sale_new' )->sample, 'Nova & Co' );
		self::assertStringContainsString( 'Hi Nova &amp; Co,', $sale['html'] );
		self::assertStringContainsString( number_format_i18n( 499, 2 ) . ' kr.', $sale['html'] );
		self::assertStringContainsString( (string) home_url(), $sale['html'], 'The button leads to the dashboard.' );
	}

	public function test_a_notification_is_stored_once_and_its_email_queued(): void {
		$notifier = $this->notifier();

		$id = $notifier->notify( 'sale_new', $this->nova, array( 'order_number' => '1042' ), 'order:1042' );
		self::assertNotNull( $id );
		self::assertNull( $notifier->notify( 'sale_new', $this->nova, array( 'order_number' => '1042' ), 'order:1042' ), 'The same order notifies once.' );

		$row = $this->notifications->find( (int) $id );
		self::assertNotNull( $row );
		self::assertSame( array( 'sale_new', 'creator', $this->nova, true, Notification::EMAIL_PENDING ), array( $row->type, $row->audience, $row->creator_id, $row->in_dashboard, $row->email_status ) );
		self::assertSame(
			array(
				array(
					'hook'  => NotificationSender::HOOK,
					'args'  => array( $id ),
					'delay' => 0,
				),
			),
			$this->queue->queued
		);
		self::assertSame( 1, $this->notifications->count_for_creator( $this->nova, true ) );

		self::assertNull( $notifier->notify( 'no_such_type', $this->nova ) );
		self::assertNull( $notifier->notify( 'sale_new', 999999 ), 'An unknown creator gets nothing.' );
	}

	public function test_the_administrators_and_the_creators_choices(): void {
		$notifier = $this->notifier();

		update_option(
			NotificationSettings::OPTION,
			array(
				'sale_new' => array(
					'email'     => false,
					'dashboard' => false,
				),
			)
		);
		self::assertNull( $notifier->notify( 'sale_new', $this->nova ), 'Off on both channels: nothing.' );

		update_option(
			NotificationSettings::OPTION,
			array(
				'sale_new' => array(
					'email'     => true,
					'dashboard' => false,
				),
			)
		);
		$email_only = $this->notifications->find( (int) $notifier->notify( 'sale_new', $this->nova, array(), 'a' ) );
		self::assertSame( array( false, Notification::EMAIL_PENDING ), array( $email_only?->in_dashboard, $email_only?->email_status ) );

		// The creator turns sales e-mails off; an important type is sent anyway.
		update_user_meta( $this->nova_user, NotificationPreferences::META, array( 'sale_new', 'payout_completed' ) );
		delete_option( NotificationSettings::OPTION );
		$sale   = $this->notifications->find( (int) $notifier->notify( 'sale_new', $this->nova, array(), 'b' ) );
		$payout = $this->notifications->find( (int) $notifier->notify( 'payout_completed', $this->nova, array(), 'c' ) );
		self::assertSame( array( true, Notification::EMAIL_NONE ), array( $sale?->in_dashboard, $sale?->email_status ) );
		self::assertSame( Notification::EMAIL_PENDING, $payout?->email_status, 'Important: the e-mail is not turned off.' );

		// A creator without a user: at the bell only.
		$bare  = $this->service( CreatorService::class )->create( 'Bare', CreatorStatus::Active, null )->id;
		$quiet = $this->notifications->find( (int) $notifier->notify( 'payout_completed', $bare ) );
		self::assertSame( array( true, Notification::EMAIL_SKIPPED ), array( $quiet?->in_dashboard, $quiet?->email_status ) );

		// An administrator notification: e-mail only, no creator.
		$admin = $this->notifications->find( (int) $notifier->notify( 'admin_payout_requested', $this->nova, array( 'creator' => 'Nova' ) ) );
		self::assertSame( array( 'admin', null, false, Notification::EMAIL_PENDING ), array( $admin?->audience, $admin?->creator_id, $admin?->in_dashboard, $admin?->email_status ) );
		self::assertNull( $notifier->notify( 'admin_code_reset', null ), 'Off by default.' );
	}

	public function test_the_email_is_sent_in_the_design_and_retried_after_a_failure(): void {
		$notifier = $this->notifier();
		$sender   = $this->sender();
		$sale     = (int) $notifier->notify(
			'payout_completed',
			$this->nova,
			array(
				'amount'          => '1250.00',
				'currency'        => 'DKK',
				'document_number' => 'AFR-000014',
			)
		);

		$sender->run( $sale );
		$sent = $this->notifications->find( $sale );
		self::assertSame( array( Notification::EMAIL_SENT, 1 ), array( $sent?->email_status, $sent?->email_attempts ) );
		self::assertCount( 1, $this->mail->sent );
		self::assertSame( 'nova@example.org', $this->mail->sent[0]['to'] );
		self::assertStringContainsString( number_format_i18n( 1250, 2 ) . ' kr.', $this->mail->sent[0]['html'] );
		self::assertStringContainsString( 'AFR-000014', $this->mail->sent[0]['text'] );

		$sender->run( $sale );
		self::assertCount( 1, $this->mail->sent, 'A sent e-mail is not sent again.' );

		// Failures: tried again after 1 and 5 minutes, then failed.
		$this->mail->fail    = true;
		$this->queue->queued = array();
		$failing             = (int) $notifier->notify( 'payout_rejected', $this->nova, array( 'amount' => '10.00' ) );

		foreach ( array( Notification::EMAIL_PENDING, Notification::EMAIL_PENDING, Notification::EMAIL_FAILED ) as $expected ) {
			$sender->run( $failing );
			self::assertSame( $expected, $this->notifications->find( $failing )?->email_status );
		}

		self::assertSame( array( 0, 60, 300 ), array_column( $this->queue->queued, 'delay' ) );
		$failed = $this->notifications->find( $failing );
		self::assertNotNull( $failed );
		self::assertSame( array( 'boom', 3 ), array( $failed->email_error, $failed->email_attempts ) );
	}

	public function test_without_a_queue_the_email_is_sent_at_the_end_of_the_request(): void {
		$this->queue->is_available = false;
		$notifier                  = $this->notifier();
		$id                        = (int) $notifier->notify( 'code_reset', $this->nova );

		self::assertSame( array(), $this->queue->queued );
		self::assertNotFalse( has_action( 'shutdown', array( $notifier, 'send_pending' ) ) );

		$notifier->send_pending();
		remove_action( 'shutdown', array( $notifier, 'send_pending' ) );
		self::assertSame( Notification::EMAIL_SENT, $this->notifications->find( $id )?->email_status );
	}

	public function test_the_bell_reading_cleanup_and_pruning(): void {
		$notifier = $this->notifier();
		$first    = (int) $notifier->notify( 'proposal_approved', $this->nova, array( 'field' => 'Banner' ) );
		$second   = (int) $notifier->notify( 'proposal_rejected', $this->nova, array( 'field' => 'Logo' ) );

		self::assertSame( array( $second, $first ), array_map( static fn( Notification $n ): int => $n->id, $this->notifications->for_creator( $this->nova, 10 ) ) );
		self::assertSame( 1, $this->notifications->mark_read( $this->nova, array( $first, 999999 ) ) );
		self::assertSame( 1, $this->notifications->count_for_creator( $this->nova, true ) );
		self::assertSame( 1, $this->notifications->mark_read( $this->nova, null ) );
		self::assertSame( 0, $this->notifications->count_for_creator( $this->nova, true ) );

		// Older than 180 days goes.
		$this->wpdb->update( Tables::name( $this->wpdb, Tables::NOTIFICATIONS ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 181 * DAY_IN_SECONDS ) ), array( 'id' => $first ) );
		self::assertSame( 1, $this->notifications->prune() );
		self::assertNull( $this->notifications->find( $first ) );

		$creator = $this->service( CreatorRepository::class )->find( $this->nova );
		self::assertNotNull( $creator );
		self::assertSame( array( 'notifications' => 1 ), ( new NotificationsCreatorCleanup( $this->notifications ) )->remove( $creator ) );
	}

	public function test_the_administrator_saves_choices_with_audit(): void {
		$settings = $this->settings();
		$choices  = array();

		foreach ( $this->types->all() as $type ) {
			$choices[ $type->key ] = array(
				'email'     => $type->email,
				'dashboard' => $type->dashboard,
			);
		}

		self::assertSame( 0, $settings->save( $choices ), 'The defaults: nothing changed.' );

		$choices['sale_new']['email'] = false;
		self::assertSame( 1, $settings->save( $choices ) );
		self::assertFalse( $settings->enabled( $this->type( 'sale_new' ), NotificationSettings::EMAIL ) );
		self::assertTrue( $settings->enabled( $this->type( 'sale_new' ), NotificationSettings::DASHBOARD ) );
		self::assertFalse( $settings->enabled( $this->type( 'admin_payout_requested' ), NotificationSettings::DASHBOARD ) );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( 'settings_updated', end( $audit )['action'] ?? null );
		self::assertStringContainsString( 'notifications.sale_new.email', (string) ( end( $audit )['context'] ?? '' ) );
	}

	/**
	 * A catalog type.
	 *
	 * @param string $key Key.
	 */
	private function type( string $key ): NotificationType {
		return $this->types->get( $key ) ?? self::fail( 'Unknown type ' . $key );
	}

	/**
	 * The administrator's choices.
	 */
	private function settings(): NotificationSettings {
		return new NotificationSettings( $this->types, $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
	}

	/**
	 * The sender with the fake mailer and queue.
	 */
	private function sender(): NotificationSender {
		return new NotificationSender( $this->notifications, $this->types, $this->service( CreatorRepository::class ), new EmailRenderer(), $this->mail, $this->service( DashboardRoute::class ), $this->queue, $this->service( Logger::class ) );
	}

	/**
	 * The notifier with the fake queue.
	 */
	private function notifier(): Notifier {
		return new Notifier( $this->types, $this->settings(), new NotificationPreferences(), $this->notifications, $this->service( CreatorRepository::class ), $this->queue, $this->sender(), $this->service( Logger::class ) );
	}
}
