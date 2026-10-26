<?php
/**
 * Deleting a creator (phase 27, step 27.3, ADR-076).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Creators;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsCreatorCleanup;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Creators\Admin\CreatorDeletionActions;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\CreatorDeletion;
use MerchBoard\Modules\Creators\CreatorDeletionException;
use MerchBoard\Modules\Creators\CreatorException;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\News\NewsCreatorCleanup;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponLookup;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponRecord;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeService;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\WooCommerceCreatorCleanup;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeCouponIssuer;
use MerchBoard\Tests\Integration\Support\FakeCouponLookup;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A creator without financial history is deleted completely; one with
 * history stays anonymized; both lose their product links, category,
 * alerts, news reads, meta and user link. The deletion is refused without
 * the right, the typed name, while a payout waits or while WooCommerce is
 * needed.
 */
#[CoversClass( CreatorDeletion::class )]
#[CoversClass( WooCommerceCreatorCleanup::class )]
#[CoversClass( AlertsCreatorCleanup::class )]
#[CoversClass( NewsCreatorCleanup::class )]
final class CreatorDeletionTest extends IntegrationTestCase {

	private int $nova;

	private int $nova_user;

	private int $orion;

	/**
	 * Products created by the test, by name.
	 *
	 * @var array<string, int>
	 */
	private array $products = array();

	/**
	 * Attachments created by the test, by name.
	 *
	 * @var array<string, int>
	 */
	private array $files = array();

	/**
	 * Platforms the chatbot was disconnected from.
	 *
	 * @var list<string>
	 */
	private array $disconnected = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$creators        = $this->service( CreatorService::class );
		$this->nova_user = $this->create_user( 'nova' );
		$this->nova      = $creators->create( 'Nova', CreatorStatus::Active, $this->nova_user )->id;
		$this->orion     = $creators->create( 'Orion', CreatorStatus::Active, $this->create_user( 'orion' ) )->id;

		// Nova: a manual and a category-managed product, a category, alerts, a news read, creator meta and two files.
		$this->product( 'manual', $this->nova, 'admin' );
		$this->product( 'category', $this->nova, 'category' );
		$this->product( 'orion', $this->orion, 'admin' );
		( new CreatorCategoryRepository( $this->wpdb ) )->insert( 501, $this->nova, 1 );
		( new AlertOverlayRepository( $this->wpdb ) )->create( $this->nova, OverlayId::generate(), OverlaySettings::defaults() );
		( new AlertEventRepository( $this->wpdb ) )->insert( new AlertEvent( $this->nova, 77, '1077', 1, 70, 0, 'Hoodie', '', 1, 0, array(), 'fan', new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) );
		$this->wpdb->insert( $this->table( Tables::NEWS_READS ), array( 'news_id' => 1, 'creator_id' => $this->nova ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test fixture.
		$this->service( CreatorMetaStore::class )->set( $this->nova, CreatorMetaStore::ALERT_SOUND_LIMITS, array( 'max_seconds' => 9 ) );
		$this->file( 'own', $this->nova, false );
		$this->file( 'merchalert', $this->nova, true );

		// Orion: financial history (its VAT status history).
		$this->wpdb->insert( $this->table( Tables::CREATOR_VAT_HISTORY ), array( 'creator_id' => $this->orion, 'vat_registered' => 0, 'effective_from' => '2026-01-01', 'checked_on' => '2026-01-01', 'documentation_reference' => 'x', 'reason' => 'test', 'recorded_at' => '2026-01-01 00:00:00' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test fixture.
	}

	protected function tearDown(): void {
		foreach ( array_merge( $this->products, $this->files ) as $id ) {
			wp_delete_post( $id, true );
		}

		parent::tearDown();
	}

	public function test_a_creator_without_history_is_deleted_completely(): void {
		self::assertSame( CreatorDeletion::MODE_DELETED, $this->deletion()->preview( $this->find( $this->nova ) )['mode'] );

		self::assertSame( CreatorDeletion::MODE_DELETED, $this->deletion()->delete( $this->nova, ' Nova ' ) );

		self::assertNull( $this->service( CreatorRepository::class )->find( $this->nova ), 'No history: the row is gone.' );
		self::assertSame( array( '', '' ), array( get_post_meta( $this->products['manual'], ProtectedProductMeta::LINK_KEY, true ), get_post_meta( $this->products['category'], ProtectedProductMeta::LINK_KEY, true ) ), 'Every product lost its link, manual or by category.' );
		self::assertSame( (string) $this->orion, get_post_meta( $this->products['orion'], ProtectedProductMeta::LINK_KEY, true ), 'Another creator\'s product stays.' );
		self::assertNull( ( new CreatorCategoryRepository( $this->wpdb ) )->find( 501 ) );
		self::assertSame( array( 0, 0, 0 ), array( count( $this->rows( Tables::ALERT_OVERLAYS ) ), count( $this->rows( Tables::ALERT_EVENTS ) ), (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( Tables::NEWS_READS ) ) ) ) );
		self::assertNull( $this->service( CreatorMetaStore::class )->get( $this->nova, CreatorMetaStore::ALERT_SOUND_LIMITS ) );
		self::assertSame( array( 'twitch', 'kick', 'youtube' ), $this->disconnected );

		$user = get_userdata( $this->nova_user );
		self::assertNotFalse( $user, 'The WordPress user is never deleted.' );
		self::assertNotContains( Capabilities::CREATOR_ROLE, $user->roles );
		self::assertNull( get_post( $this->files['own'] ), 'The creator\'s own upload is deleted.' );
		self::assertNotNull( get_post( $this->files['merchalert'] ), 'MerchAlert\'s file stays.' );
		self::assertSame( '', get_post_meta( $this->files['merchalert'], AlertSounds::OWNER_META, true ) );

		$audit = $this->audit( 'creator_deleted' );
		self::assertCount( 1, $audit );
		self::assertSame(
			array(
				'deletion'    => 'deleted',
				'former_name' => 'Nova',
			),
			array_intersect_key( $audit[0], array_flip( array( 'deletion', 'former_name' ) ) )
		);
		self::assertSame( array( 1, 2, 1, 1, 1, 1 ), array( $audit[0]['woocommerce_categories'] ?? 0, $audit[0]['woocommerce_products'] ?? 0, $audit[0]['alerts_overlays'] ?? 0, $audit[0]['alerts_events'] ?? 0, $audit[0]['news_reads'] ?? 0, $audit[0]['alerts_files'] ?? 0 ) );
	}

	public function test_a_creator_with_history_is_kept_anonymized(): void {
		self::assertSame( CreatorDeletion::MODE_ANONYMIZED, $this->deletion()->delete( $this->orion, 'Orion' ) );

		$creators = $this->service( CreatorRepository::class );
		$orion    = $creators->find( $this->orion );
		self::assertNotNull( $orion );
		self::assertTrue( $orion->is_deleted() );
		self::assertSame( array( 'Deleted creator #' . $this->orion, null, CreatorStatus::Inactive ), array( $orion->display_name, $orion->user_id, $orion->status ) );
		self::assertCount( 1, $this->rows( Tables::CREATOR_VAT_HISTORY ), 'Financial history is never touched.' );
		self::assertSame( '', get_post_meta( $this->products['orion'], ProtectedProductMeta::LINK_KEY, true ) );

		self::assertSame( array( 'Nova' ), array_map( static fn( $c ): string => $c->display_name, $creators->paginate( 1, 20 )['items'] ), 'The list hides deleted creators.' );
		self::assertSame( array( $this->orion ), array_map( static fn( $c ): int => $c->id, $creators->paginate( 1, 20, null, 'created_at', 'desc', true )['items'] ) );
		self::assertSame( array( 1, 1 ), array( $creators->deleted_count(), array_sum( $creators->status_counts() ) ) );

		try {
			$this->service( CreatorService::class )->set_status( $this->orion, CreatorStatus::Active );
			self::fail( 'A deleted creator was changed.' );
		} catch ( CreatorException $e ) {
			self::assertSame( CreatorException::DELETED, $e->reason() );
		}

		self::assertSame( CreatorDeletionException::ALREADY_DELETED, $this->refusal( $this->deletion(), $this->orion, 'Deleted creator #' . $this->orion ) );
	}

	public function test_links_that_never_create_sales_do_not_stop_the_deletion(): void {
		// A variation, meta of a deleted post and a product with two entries (invalid) point at Orion (CI on MySQL, 27.4).
		$variation                   = (int) wp_insert_post(
			array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'post_parent' => $this->products['orion'],
				'post_title'  => 'variation',
			)
		);
		$this->products['variation'] = $variation;
		$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $variation, ProtectedProductMeta::LINK_KEY, (string) $this->orion );
		$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, 99999999, ProtectedProductMeta::LINK_KEY, (string) $this->orion );
		$this->product( 'invalid', $this->orion, 'admin' );
		$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $this->products['invalid'], ProtectedProductMeta::LINK_KEY, (string) $this->nova );
		wp_cache_delete( $this->products['invalid'], 'post_meta' );

		try {
			self::assertSame( 2, $this->deletion()->preview( $this->find( $this->orion ) )['products'], 'Only parent products count.' );
			self::assertSame( CreatorDeletion::MODE_ANONYMIZED, $this->deletion()->delete( $this->orion, 'Orion' ) );
			self::assertSame( '', get_post_meta( $this->products['orion'], ProtectedProductMeta::LINK_KEY, true ) );
			self::assertCount( 2, get_post_meta( $this->products['invalid'], ProtectedProductMeta::LINK_KEY, false ), 'An invalid link is left as it is; it never creates sales.' );
		} finally {
			$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, 99999999 );
		}
	}

	public function test_it_is_refused_without_the_right_the_name_while_a_payout_waits_or_without_woocommerce(): void {
		wp_set_current_user( $this->nova_user );
		self::assertSame( CreatorDeletionException::NOT_AUTHORIZED, $this->refusal( $this->deletion(), $this->nova, 'Nova' ) );
		wp_set_current_user( 1 );

		self::assertSame( CreatorDeletionException::NOT_CONFIRMED, $this->refusal( $this->deletion(), $this->nova, 'nova' ) );
		self::assertSame( CreatorDeletionException::NOT_FOUND, $this->refusal( $this->deletion(), 999999, 'Ghost' ) );

		$this->wpdb->insert( $this->table( Tables::PAYOUTS ), array( 'creator_id' => $this->nova, 'currency' => 'DKK', 'amount' => '100', 'price_decimals' => 2, 'method' => 'bank', 'status' => 'pending', 'idempotency_key' => 'k1', 'requested_at' => '2026-10-25 00:00:00', 'updated_at' => '2026-10-25 00:00:00' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test fixture.
		self::assertSame( CreatorDeletionException::PAYOUTS_PENDING, $this->refusal( $this->deletion(), $this->nova, 'Nova' ) );
		$this->execute( 'DELETE FROM %i', $this->table( Tables::PAYOUTS ) );

		$without = $this->deletion( false );
		self::assertFalse( $without->preview( $this->find( $this->nova ) )['ready'] );
		self::assertSame( CreatorDeletionException::NEEDS_WOOCOMMERCE, $this->refusal( $without, $this->nova, 'Nova' ) );

		self::assertTrue( $this->find( $this->nova )->is_active(), 'Nothing changed.' );
		self::assertSame( array(), $this->audit( 'creator_deleted' ) );
	}

	public function test_a_name_with_markup_or_repeated_spaces_can_be_confirmed(): void {
		$this->service( CreatorRepository::class )->rename( $this->nova, 'Nova  <3' );

		self::assertSame( CreatorDeletion::MODE_DELETED, $this->deletion()->delete( $this->nova, 'Nova <3' ), 'As the admin sees it in the label (27.4 review).' );
	}

	public function test_the_admin_form_needs_the_nonce_and_redirects_with_a_notice(): void {
		$actions = new CreatorDeletionActions( $this->deletion(), $this->service( Logger::class ) );
		$input   = array(
			'creator_id' => (string) $this->nova,
			'_wpnonce'   => wp_create_nonce( CreatorDeletionActions::nonce_action( $this->nova ) ),
		);

		self::assertStringContainsString( CreatorsAdminPage::NOTICE_ARG . '=' . CreatorDeletionException::NOT_CONFIRMED, $actions->process( $input + array( CreatorDeletionActions::CONFIRM_FIELD => 'Orion' ) ) );
		self::assertStringContainsString( CreatorsAdminPage::NOTICE_ARG . '=' . CreatorDeletionActions::DELETED_NOTICE, $actions->process( $input + array( CreatorDeletionActions::CONFIRM_FIELD => 'Nova' ) ) );
	}

	/**
	 * The deletion with the modules' cleaners (WooCommerce's optional).
	 *
	 * @param bool $woocommerce Whether WooCommerce's cleaner is present.
	 */
	private function deletion( bool $woocommerce = true ): CreatorDeletion {
		$audit       = $this->service( AuditTrail::class );
		$transaction = $this->service( Transaction::class );
		$creators    = $this->service( CreatorRepository::class );
		$cleaners    = array();

		if ( $woocommerce ) {
			$coupons    = new class() implements CouponLookup {
				public function find( string $code ): ?CouponRecord {
					return null;
				}

				public function is_gift_card( string $code ): ?bool {
					return false;
				}
			};
			$cleaners[] = new WooCommerceCreatorCleanup(
				new PostMetaProductStore(),
				new ProductCreatorLink( new PostMetaProductStore(), $creators, $audit, $transaction, $this->service( ProtectedProductMeta::class ) ),
				new CreatorCategoryService( new CreatorCategoryRepository( $this->wpdb ), $creators, $audit, $transaction ),
				new CreatorCodeService( $creators, new CreatorCodeRepository( $this->wpdb ), $coupons, new FakeCouponIssuer( new FakeCouponLookup() ), $transaction, $audit ),
				new CreatorCodeRepository( $this->wpdb ),
				$this->wpdb
			);
		}

		$cleaners[] = new AlertsCreatorCleanup(
			function ( int $creator_id, ChatPlatform $platform ): bool {
				$this->disconnected[] = $platform->value;

				return false;
			},
			$this->wpdb,
			$this->service( Logger::class )
		);
		$cleaners[] = new NewsCreatorCleanup( $this->wpdb );

		return new CreatorDeletion( $creators, $this->service( CreatorService::class ), $this->service( CreatorMetaStore::class ), $audit, $transaction, $this->wpdb, $cleaners, static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-25 12:00:00', new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Why a deletion was refused.
	 *
	 * @param CreatorDeletion $deletion     The deletion.
	 * @param int             $creator_id   Creator.
	 * @param string          $confirmation Typed name.
	 */
	private function refusal( CreatorDeletion $deletion, int $creator_id, string $confirmation ): string {
		try {
			$deletion->delete( $creator_id, $confirmation );
		} catch ( CreatorDeletionException $e ) {
			return $e->reason();
		}

		self::fail( 'Not refused.' );
	}

	/**
	 * A creator that exists.
	 *
	 * @param int $creator_id Creator.
	 */
	private function find( int $creator_id ): \MerchBoard\Modules\Creators\Creator {
		$creator = $this->service( CreatorRepository::class )->find( $creator_id );
		self::assertNotNull( $creator );

		return $creator;
	}

	/**
	 * A product linked to a creator.
	 *
	 * @param string $name       Name.
	 * @param int    $creator_id Creator.
	 * @param string $source     The link's origin.
	 */
	private function product( string $name, int $creator_id, string $source ): void {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
		$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $id, ProtectedProductMeta::LINK_KEY, (string) $creator_id );
		$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $id, ProtectedProductMeta::SOURCE_KEY, $source );
		wp_cache_delete( $id, 'post_meta' );
		$this->products[ $name ] = $id;
	}

	/**
	 * An uploaded sound of a creator.
	 *
	 * @param string $name       File name.
	 * @param int    $creator_id Creator.
	 * @param bool   $imported   Whether MerchAlert's import took it over.
	 */
	private function file( string $name, int $creator_id, bool $imported ): void {
		$id = wp_insert_attachment(
			array(
				'post_title'     => $name,
				'post_mime_type' => 'audio/mpeg',
				'post_status'    => 'inherit',
			),
			'2026/10/' . $name . '.mp3'
		);
		self::assertGreaterThan( 0, $id );
		update_post_meta( $id, AlertSounds::OWNER_META, (string) $creator_id );

		if ( $imported ) {
			update_post_meta( $id, Uninstaller::ALERT_IMPORTED_META, '1' );
		}

		$this->files[ $name ] = $id;
	}

	/**
	 * The contexts of an audit action, oldest first.
	 *
	 * @param string $action Action.
	 * @return list<array<string, mixed>>
	 */
	private function audit( string $action ): array {
		$contexts = array();

		foreach ( $this->rows( Tables::AUDIT_LOG ) as $row ) {
			if ( $action === $row['action'] ) {
				$context    = json_decode( (string) $row['context'], true );
				$contexts[] = is_array( $context ) ? $context : array();
			}
		}

		return $contexts;
	}
}
