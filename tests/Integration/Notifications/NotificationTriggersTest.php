<?php
/**
 * The creator events that notify (phase 29, step 29.5, ADR-079).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Notifications;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Access\Impersonation;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\News\NewsRepository;
use MerchBoard\Modules\News\NewsService;
use MerchBoard\Modules\Notifications\Notifier;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Account, news and "View as creator" events of the real services reach the
 * notifications through `merchboard_notify` after their commit. Payouts,
 * settlement documents, sales, codes and proposals are covered in their
 * own tests (MePayoutsEndpointTest, CreatorCodesTest, CategoryProposalsTest).
 */
#[CoversClass( Notifier::class )]
final class NotificationTriggersTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		delete_user_meta( 1, ImpersonationSession::META );
		parent::tearDown();
	}

	public function test_a_linked_user_is_welcomed_once_and_status_changes_are_told(): void {
		$creators = $this->service( CreatorService::class );
		$user     = $this->create_user( 'welcome-one' );
		$creator  = $creators->create( 'Welcome', CreatorStatus::Active, $user );
		$ghost    = $creators->create( 'Ghost', CreatorStatus::Active, null );

		$creators->update( $creator->id, 'Welcome', CreatorStatus::Active, null );
		$creators->update( $creator->id, 'Welcome', CreatorStatus::Active, $user );
		$other = $this->create_user( 'welcome-two' );
		$creators->update( $creator->id, 'Welcome', CreatorStatus::Active, $other );
		$creators->set_status( $creator->id, CreatorStatus::Inactive );
		$creators->set_status( $creator->id, CreatorStatus::Active );

		$rows = $this->rows( Tables::NOTIFICATIONS );
		self::assertSame(
			array( 'account_welcome', 'account_welcome', 'account_status', 'account_status' ),
			array_column( $rows, 'type' ),
			'Once per user (linking the same user again welcomes nobody); none without a user.'
		);
		self::assertSame( array( (string) $creator->id ), array_values( array_unique( array_map( 'strval', array_column( $rows, 'creator_id' ) ) ) ) );
		self::assertNotContains( (string) $ghost->id, array_map( 'strval', array_column( $rows, 'creator_id' ) ) );
		self::assertSame( array( array( 'active' => false ), array( 'active' => true ) ), array_map( static fn( array $row ): mixed => json_decode( (string) $row['data'], true ), array_slice( $rows, 2 ) ) );
		self::assertSame( array( '0', '0', '1', '1' ), array_map( 'strval', array_column( $rows, 'in_dashboard' ) ), 'The welcome is only an e-mail.' );
	}

	public function test_news_is_told_once_to_the_creators_who_see_it(): void {
		$creators = $this->service( CreatorService::class );
		$nova     = $creators->create( 'Nova', CreatorStatus::Active, $this->create_user( 'nova' ) )->id;
		$resting  = $creators->create( 'Resting', CreatorStatus::Inactive, null )->id;
		$this->execute( 'DELETE FROM %i', $this->table( Tables::NOTIFICATIONS ) );

		$news = new NewsService( new NewsRepository( $this->wpdb ), $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$id   = $news->create( 'New: banners', '<p>Banners</p>' );
		$news->publish( $id );
		$news->withdraw( $id );
		$news->publish( $id );

		$rows = $this->rows( Tables::NOTIFICATIONS );
		self::assertSame( array( 'news_published', 'news_published' ), array_column( $rows, 'type' ), 'Once per creator, also after withdrawing and publishing again.' );
		self::assertEqualsCanonicalizing( array( (string) $nova, (string) $resting ), array_map( 'strval', array_column( $rows, 'creator_id' ) ) );
		self::assertSame( array( 'title' => 'New: banners' ), json_decode( (string) $rows[0]['data'], true ) );

		// A creator created after the publication does not see the message.
		$this->wpdb->update( $this->table( Tables::NEWS ), array( 'published_at' => '2020-01-01 00:00:00' ), array( 'id' => $id ) );
		$later = $creators->create( 'Later', CreatorStatus::Active, null )->id;
		$news->withdraw( $id );
		$news->publish( $id );
		self::assertNotContains( (string) $later, array_map( 'strval', array_column( $this->rows( Tables::NOTIFICATIONS ), 'creator_id' ) ) );
	}

	public function test_the_creator_sees_that_an_administrator_viewed_their_dashboard(): void {
		$nova = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->create_user( 'nova' ) )->id;
		$this->execute( 'DELETE FROM %i', $this->table( Tables::NOTIFICATIONS ) );

		$this->service( Impersonation::class )->start( $nova );

		$rows = $this->rows( Tables::NOTIFICATIONS );
		self::assertSame( array( array( 'impersonation_started', (string) $nova, '1', 'none' ) ), array_map( static fn( array $row ): array => array( $row['type'], (string) $row['creator_id'], (string) $row['in_dashboard'], $row['email_status'] ), $rows ), 'At the bell only; never who.' );
		self::assertSame( '[]', $rows[0]['data'] );
	}
}
