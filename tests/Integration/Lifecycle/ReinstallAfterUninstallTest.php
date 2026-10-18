<?php
/**
 * A reinstall over the history the opt-in uninstall keeps (ADR-059, B2).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\SchemaEraser;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Lifecycle\RestoredHistory;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Settings\SettingsService;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use WP_User;

/**
 * The history is made by the real services: three sales, a completed bank
 * payout with its settlement document AFR-000001 and a creator linked to a
 * WordPress user. After the opt-in uninstall the plugin is installed again
 * (every migration runs over the kept tables). Synthetic data only.
 */
final class ReinstallAfterUninstallTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private int $user;

	private int $creator;

	/**
	 * Definitions of the kept tables on a fresh install (SHOW CREATE TABLE
	 * without the AUTO_INCREMENT counter).
	 *
	 * @var array<string, string>
	 */
	private array $fresh = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->fresh = $this->definitions();
		$this->posts = new ProductPosts( $this->wpdb );
		$this->seed();
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		delete_option( RestoredHistory::OPTION );
		delete_option( Settings::OPTION );
		$this->install();
		parent::tearDown();
	}

	public function test_the_kept_history_survives_the_reinstall_byte_for_byte_with_the_fresh_schema(): void {
		$this->enable_deletion();
		$kept = $this->kept_rows();
		$max  = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT MAX(id) FROM %i', $this->table( Tables::CREATORS ) ) );

		self::assertTrue( $this->uninstaller()->run() );

		$archived = $this->kept_rows();
		self::assertSame( array_diff_key( $kept, array( 'creators' => 0 ) ), array_diff_key( $archived, array( 'creators' => 0 ) ), 'Payouts, documents, rows, counters and audit are untouched.' );
		self::assertNull( get_option( Migrator::VERSION_OPTION, null ), 'The schema version is deleted.' );

		$this->install();

		self::assertSame( '17', (string) get_option( Migrator::VERSION_OPTION ), 'Every migration ran again.' );
		self::assertSame( $archived, $this->kept_rows(), 'The migrations changed no kept row.' );
		self::assertSame( $this->fresh, $this->definitions(), 'Columns and indexes of the kept tables are those of a fresh install.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES ), 'The dropped tables start empty.' );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUT_SECRETS ) );
		self::assertSame( $max, RestoredHistory::max_creator_id(), 'The marker survives with the highest archived id: its creators table exists.' );
	}

	public function test_no_one_gets_access_again_and_new_creators_never_see_the_kept_history(): void {
		$this->enable_deletion();
		self::assertTrue( $this->uninstaller()->run() );
		$this->install();

		$context = $this->service( CreatorContext::class );
		self::assertNull( $context->for_user( $this->user ) );
		$user = get_userdata( $this->user );
		self::assertInstanceOf( WP_User::class, $user );
		$user->add_role( Capabilities::CREATOR_ROLE );
		clean_user_cache( $this->user );
		self::assertNull( $context->for_user( $this->user ), 'Even with the role again: no record is linked to the user.' );

		$creators = new CreatorRepository( $this->wpdb );
		self::assertNull( $creators->find_by_user_id( $this->user ) );
		self::assertTrue( RestoredHistory::is_archived( $this->creator ) );

		$new_user = $this->create_user( 'reinstalled_creator' );
		$new      = $this->service( CreatorService::class )->create( 'After reinstall', CreatorStatus::Active, $new_user );
		self::assertGreaterThan( $this->creator, $new->id, 'The kept AUTO_INCREMENT continues: no id is reused.' );
		self::assertFalse( RestoredHistory::is_archived( $new->id ) );
		self::assertSame( $new->id, $context->for_user( $new_user )?->id );

		$payouts = new PayoutRepository( $this->wpdb );
		self::assertSame( 0, $payouts->count_for_creator( $new->id ), 'The new creator sees no kept payout.' );
		self::assertSame( 1, $payouts->count_for_creator( $this->creator ), 'The kept payout stays with its archived creator.' );
	}

	public function test_a_fresh_install_without_the_kept_creators_table_forgets_the_marker(): void {
		$this->enable_deletion();
		self::assertTrue( $this->uninstaller()->run() );
		self::assertNotNull( RestoredHistory::max_creator_id() );

		// The kept tables removed by hand.
		foreach ( Tables::retained_on_uninstall() as $table ) {
			$this->execute( 'DROP TABLE IF EXISTS %i', $this->table( $table ) );
		}

		$this->forget_table_metadata();
		$this->install();

		self::assertNull( RestoredHistory::max_creator_id(), 'New creators starting at id 1 are never shown as archived.' );
	}

	/**
	 * A linked active creator with three sales and a completed bank payout
	 * (AFR-000001), and an unlinked inactive creator.
	 */
	private function seed(): void {
		$this->user    = $this->create_user( 'kept_creator' );
		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $this->service( CreatorService::class )->create( 'Kept creator', CreatorStatus::Active, $this->user )->id;
		$creators->create( 'Kept inactive', CreatorStatus::Inactive );
		$markups = new CreatorMarkupRepository( $this->wpdb );
		$vat     = new CreatorVatRepository( $this->wpdb );
		$markups->save( $this->creator, new MarkupBps( 2000 ) );
		$vat->save( $this->creator, true );

		$transaction = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$product     = $this->posts->add( 'MB-059 product', 'publish', array( (string) $this->creator ) );
		$this->posts->meta( $product, '_regular_price', '100' );
		$this->posts->meta( $product, '_price', '100' );
		$links    = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), $vat );
		$reader   = new FakeOrderReader();
		$sync     = new SalesSynchronizer( new SalesRepository( $this->wpdb, $transaction ), new SalesPlanner(), $reader, new RecordingFailureMarker(), new RecordingLogger(), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) ) );

		for ( $order = 9591; $order <= 9593; $order++ ) {
			$recorder->record( 1000 + $order, $order, $product, 0, 'DKK' );
			$reader->orders[ $order ] = array(
				'status'    => 'processing',
				'currency'  => 'DKK',
				'date_paid' => '2026-09-01 10:05:00',
				'lines'     => array( new OrderLineSnapshot( 1000 + $order, $product, 0, 'MB-059 line', 1, SalesAmount::parse( '80' ), $this->creator, SalesAmount::parse( '20' ) ) ),
				'refunds'   => array(),
			);
			$sync->sync( $order );
		}

		$clock      = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$audit      = $this->service( AuditTrail::class );
		$cipher     = new PayoutCipher( base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$repository = new PayoutRepository( $this->wpdb );
		$balances   = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $repository, $clock );
		$documents  = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $repository, new LedgerCreatorPayouts( $this->wpdb ), $creators, $vat, $audit, $transaction, $clock );
		$requests   = new PayoutRequests( $repository, $balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock );
		$decisions  = new PayoutDecisions( $repository, $balances, $cipher, $audit, $transaction, $clock, $documents );

		$payout = $requests->request( $this->creator, 'DKK', '20', 'bank', array( 'registration' => '1234', 'account' => '0012345678' ), 'key-059-0001', 1 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$decisions->complete( $payout->id );

		self::assertSame( 1, $this->count_rows( Tables::SETTLEMENT_DOCUMENTS ), 'AFR-000001 exists.' );
		self::assertSame( 1, $this->count_rows( Tables::PAYOUT_SECRETS ), 'Its details row (emptied).' );
	}

	/**
	 * Rows of every kept table, as stored, in a stable order.
	 *
	 * @return array<string, list<string>>
	 */
	private function kept_rows(): array {
		$rows = array();

		foreach ( Tables::retained_on_uninstall() as $table ) {
			$dump = array_map( 'wp_json_encode', (array) $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i', $this->table( $table ) ), ARRAY_A ) );
			sort( $dump );
			$rows[ str_replace( 'merchboard_', '', $table ) ] = array_map( 'strval', $dump );
		}

		return $rows;
	}

	/**
	 * SHOW CREATE TABLE of the kept tables, without the AUTO_INCREMENT counter.
	 *
	 * @return array<string, string>
	 */
	private function definitions(): array {
		$definitions = array();

		foreach ( Tables::retained_on_uninstall() as $table ) {
			$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SHOW CREATE TABLE %i', $this->table( $table ) ), ARRAY_N );
			self::assertIsArray( $row, $table );
			$definitions[ $table ] = (string) preg_replace( '/ AUTO_INCREMENT=\d+/', '', (string) $row[1] );
		}

		return $definitions;
	}

	private function enable_deletion(): void {
		self::assertTrue( $this->service( SettingsService::class )->set_delete_data_on_uninstall( true, 'DELETE' ) );
	}

	private function uninstaller(): Uninstaller {
		return new Uninstaller( $this->wpdb, new SchemaEraser( $this->wpdb, new RecordingLogger() ), new RecordingLogger() );
	}
}
