<?php
/**
 * The product designer's notifications and its Designs page (step 30.9).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use Closure;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Notifications\Admin\NotificationSwitches;
use MerchBoard\Modules\Notifications\Email\EmailRenderer;
use MerchBoard\Modules\Notifications\EmailMessage;
use MerchBoard\Modules\Notifications\NotificationData;
use MerchBoard\Modules\Notifications\NotificationPreferences;
use MerchBoard\Modules\Notifications\NotificationRepository;
use MerchBoard\Modules\Notifications\NotificationSender;
use MerchBoard\Modules\Notifications\NotificationSettings;
use MerchBoard\Modules\Notifications\NotificationType;
use MerchBoard\Modules\Notifications\NotificationTypes;
use MerchBoard\Modules\Notifications\Notifier;
use MerchBoard\Modules\ProductDesigner\Admin\DesignsPage;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\RecordingMailer;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The designer's types join ADR-079's catalog through the filter, in their
 * own group: the Notifications page neither shows nor saves them, the
 * designer's page does, and they are sent like any other notification.
 */
#[CoversClass( DesignerNotifications::class )]
#[CoversClass( NotificationTypes::class )]
#[CoversClass( NotificationSettings::class )]
#[CoversClass( DesignsPage::class )]
final class DesignerNotificationsTest extends IntegrationTestCase {

	/**
	 * The catalog filter of this test.
	 *
	 * @var Closure(mixed): array<mixed>
	 */
	private Closure $filter;

	private NotificationTypes $types;

	private NotificationRepository $notifications;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( NotificationSettings::OPTION );
		wp_set_current_user( 1 );

		$this->filter        = static fn( mixed $types ): array => array_merge( is_array( $types ) ? $types : array(), DesignerNotifications::all() );
		add_filter( NotificationTypes::FILTER, $this->filter );
		$this->types         = new NotificationTypes();
		$this->notifications = new NotificationRepository( $this->wpdb );
	}

	protected function tearDown(): void {
		remove_filter( NotificationTypes::FILTER, $this->filter );
		delete_option( NotificationSettings::OPTION );
		$_GET = array();
		parent::tearDown();
	}

	public function test_the_designer_s_types_join_the_catalog_in_their_own_group(): void {
		$intruders = static fn( mixed $types ): array => array_merge(
			is_array( $types ) ? $types : array(),
			array(
				self::foreign( 'sale_new', DesignerNotifications::GROUP ),
				self::foreign( 'designer_elsewhere', 'sales' ),
			)
		);
		add_filter( NotificationTypes::FILTER, $intruders, 20 );
		$types     = new NotificationTypes();

		self::assertSame( array( 'designer_submitted', 'designer_approved', 'designer_change_approved', 'designer_rejected', 'admin_designer_submitted', 'admin_designer_change_submitted', 'admin_designer_apply_failed' ), array_keys( $types->in_groups( array( DesignerNotifications::GROUP ) ) ) );
		self::assertSame( 'sales', $types->get( 'sale_new' )?->group, 'A taken key keeps the catalog\'s type.' );
		self::assertNull( $types->get( 'designer_elsewhere' ), 'A module type in a catalog group is ignored.' );
		self::assertCount( 36, $types->in_groups( array_keys( NotificationTypes::groups() ) ), 'The Notifications page shows the catalog only.' );
		remove_filter( NotificationTypes::FILTER, $intruders, 20 );

		$sender = $this->sender();

		foreach ( $this->types->in_groups( array( DesignerNotifications::GROUP ) ) as $type ) {
			$mail = $sender->compose( $type, $type->sample, NotificationType::ADMIN === $type->audience ? '' : 'Nova' );

			self::assertStringContainsString( 'Stream tee', $mail['subject'] . $mail['text'], $type->key );
			if ( ! in_array( $type->key, array( DesignerNotifications::REJECTED, DesignerNotifications::APPLY_FAILED ), true ) ) {
				self::assertStringContainsString( number_format_i18n( 249, 2 ), $mail['html'], $type->key . ': the price' );
			}
			self::assertNotSame( '', $type->text( new NotificationData( $type->sample ) ), $type->key );
			self::assertFalse( NotificationType::ADMIN === $type->audience && $type->dashboard, $type->key );
		}
	}

	public function test_each_page_saves_only_its_own_types(): void {
		$settings = $this->settings();
		$catalog  = array();

		foreach ( $this->types->in_groups( array_keys( NotificationTypes::groups() ) ) as $type ) {
			$catalog[ $type->key ] = array(
				'email'     => $type->email,
				'dashboard' => $type->dashboard,
			);
		}

		self::assertSame( 11, $settings->save( array(), array( DesignerNotifications::GROUP ) ), 'All designer switches off: two for each of the four creator types, one for each of the three administrator types.' );
		self::assertSame( 0, $settings->save( $catalog ), 'The Notifications page leaves the designer\'s choices alone.' );
		self::assertFalse( $settings->enabled( $this->type( DesignerNotifications::RECEIPT ), NotificationSettings::EMAIL ) );
		self::assertFalse( $settings->enabled( $this->type( DesignerNotifications::SUBMITTED ), NotificationSettings::EMAIL ) );
		self::assertTrue( $settings->enabled( $this->type( 'sale_new' ), NotificationSettings::EMAIL ) );

		self::assertSame( 1, $settings->save( array( DesignerNotifications::SUBMITTED => array( 'email' => '1' ) ), array( DesignerNotifications::GROUP ) ) );
		self::assertTrue( $settings->enabled( $this->type( DesignerNotifications::SUBMITTED ), NotificationSettings::EMAIL ) );
	}

	public function test_a_submission_notifies_like_any_other_notification(): void {
		$user    = $this->create_user( 'nova' );
		$creator = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $user )->id;
		$this->execute( 'DELETE FROM %i', $this->table( Tables::NOTIFICATIONS ) );
		$notifier = $this->notifier();
		$data     = array(
			'title'    => 'Stream tee',
			'number'   => '1',
			'price'    => '179.94',
			'currency' => 'DKK',
		);

		self::assertNotNull( $notifier->notify( DesignerNotifications::SUBMITTED, $creator, $data, 'designer_version:7' ) );
		self::assertNotNull( $notifier->notify( DesignerNotifications::RECEIPT, $creator, $data, 'designer_version:7' ) );
		self::assertNull( $notifier->notify( DesignerNotifications::RECEIPT, $creator, $data, 'designer_version:7' ), 'Once per version.' );

		$rows = $this->rows( Tables::NOTIFICATIONS );
		self::assertSame( array( array( 'admin_designer_submitted', 'admin', null ), array( 'designer_submitted', 'creator', (string) $creator ) ), array_map( static fn( array $row ): array => array( $row['type'], $row['audience'], $row['creator_id'] ), $rows ) );
		self::assertStringContainsString( 'Nova', (string) $rows[0]['data'], 'The administrator\'s e-mail names the creator.' );
	}

	public function test_the_designs_page_lists_the_waiting_designs_and_the_designer_s_notifications(): void {
		$page = new DesignsPage( new DesignerProductRepository( $this->wpdb ), new BaseProductRepository( $this->wpdb ), $this->service( CreatorRepository::class ), $this->types, new NotificationSwitches( $this->settings(), $this->sender() ), 2 );

		self::assertStringContainsString( 'No designs are waiting for approval.', $this->render( $page ) );

		$user    = $this->create_user( 'nova' );
		$creator = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $user )->id;
		$now     = gmdate( 'Y-m-d H:i:s' );
		$this->wpdb->insert( $this->table( Tables::DESIGNER_PRODUCTS ), array( 'creator_id' => $creator, 'base_id' => 3, 'creation_token' => str_repeat( 'a', 32 ), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$product = (int) $this->wpdb->insert_id;
		$this->wpdb->insert( $this->table( Tables::DESIGNER_VERSIONS ), array( 'design_product_id' => $product, 'creator_id' => $creator, 'number' => 1, 'status' => 'pending', 'pending_key' => (string) $product, 'base_version' => 1, 'title' => 'Stream <tee>', 'description' => '', 'colors' => '[]', 'sizes' => '[]', 'sides' => '[]', 'design' => '{}', 'quoted_price' => '179.9400', 'created_at' => $now, 'updated_at' => $now, 'submitted_at' => $now ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$queue = $this->render( $page );
		self::assertStringContainsString( 'Stream &lt;tee&gt;', $queue );
		self::assertStringContainsString( 'Nova', $queue );
		self::assertStringContainsString( '179.94', $queue );
		self::assertStringContainsString( 'A new product.', $queue );

		$_GET['view'] = 'notifications';
		$tab          = $this->render( $page );
		self::assertStringContainsString( 'notifications[designer_submitted][dashboard]', $tab );
		self::assertStringContainsString( 'notifications[admin_designer_change_submitted][email]', $tab );
		self::assertStringNotContainsString( 'notifications[sale_new]', $tab, 'Only the designer\'s types.' );
		self::assertStringContainsString( 'merchboard_designer_notifications', $tab );

		$_GET['preview'] = DesignerNotifications::RECEIPT;
		self::assertStringContainsString( 'Send a test e-mail to me', $this->render( $page ) );
	}

	/**
	 * A page's output.
	 *
	 * @param DesignsPage $page Page.
	 */
	private function render( DesignsPage $page ): string {
		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	/**
	 * A minimal type of another module.
	 *
	 * @param string $key   Key.
	 * @param string $group Group.
	 */
	private static function foreign( string $key, string $group ): NotificationType {
		return new NotificationType(
			$key,
			NotificationType::CREATOR,
			$group,
			static fn(): string => 'Label',
			static fn(): string => 'When.',
			true,
			true,
			false,
			'designer',
			array(),
			static fn(): string => 'Text',
			static fn(): EmailMessage => new EmailMessage( 'Subject', 'Heading' )
		);
	}

	/**
	 * A type of the catalog.
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
	 * The sender with a recording mailer.
	 */
	private function sender(): NotificationSender {
		return new NotificationSender( $this->notifications, $this->types, $this->service( CreatorRepository::class ), new EmailRenderer(), new RecordingMailer(), $this->service( DashboardRoute::class ), new FakeJobQueue(), $this->service( Logger::class ) );
	}

	/**
	 * The notifier with a fake queue.
	 */
	private function notifier(): Notifier {
		return new Notifier( $this->types, $this->settings(), new NotificationPreferences(), $this->notifications, $this->service( CreatorRepository::class ), new FakeJobQueue(), $this->sender(), $this->service( Logger::class ) );
	}
}
