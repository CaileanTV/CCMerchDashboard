<?php
/**
 * Integration tests for filling in missing pricing snapshots (ADR-090).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CreatorPricingActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorPricingSection;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorPricingSettings;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatHistoryRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\PricingBackfillLine;
use MerchBoard\Modules\WooCommerce\Pricing\PricingException;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingBackfill;
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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A creator's order lines whose payout is unknown get their pricing snapshot
 * filled in after an administrator has seen them (the owner's wish
 * 2026-11-04): the VAT status of a snapshot without one, or a whole
 * snapshot with the share that applies now. Lines that cannot be filled in,
 * known lines and other creators are never touched.
 */
#[CoversClass( SalesLinePricingBackfill::class )]
final class SalesLinePricingBackfillTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private SalesLinePricingBackfill $backfill;

	private CreatorVatHistoryRepository $history;

	private int $next_order = 9101;

	private CreatorPricingSection $section;

	private CreatorPricingActions $actions;

	private MarkupResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		wp_set_current_user( 1 );

		$creators       = new CreatorRepository( $this->wpdb );
		$markups        = new CreatorMarkupRepository( $this->wpdb );
		$this->posts    = new ProductPosts( $this->wpdb );
		$this->history  = new CreatorVatHistoryRepository( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$resolver       = new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) );
		$this->resolver = $resolver;
		$this->recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), $resolver, new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), new CreatorVatRepository( $this->wpdb ) );
		$this->reader   = new FakeOrderReader();
		$this->sync     = new SalesSynchronizer(
			new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) ),
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) )
		);
		$this->backfill = new SalesLinePricingBackfill( $this->wpdb, new SalesLinePricingRepository( $this->wpdb ), $resolver, new PostMetaPricingProductStore(), $this->history, $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$vat            = new CreatorVatRepository( $this->wpdb );
		$this->section  = new CreatorPricingSection( new PostMetaProductStore(), new PostMetaPricingProductStore(), $resolver, $markups, $vat, $this->history, $this->backfill );
		$this->actions  = new CreatorPricingActions(
			new CreatorPricingSettings( $creators, $markups, new ProductMarkupRepository( $this->wpdb ), $links, new PostMetaPricingProductStore(), $this->service( AuditTrail::class ), $this->service( Transaction::class ), $vat, $this->history ),
			new RecordingLogger(),
			$this->backfill
		);
	}

	public function test_the_creator_page_shows_the_lines_and_its_form_fills_them_in(): void {
		$kenny   = $this->creator( 'Kenny' );
		$missing = $this->order( $kenny, false );
		$creator = ( new CreatorRepository( $this->wpdb ) )->find( $kenny );
		self::assertNotNull( $creator );

		ob_start();
		$this->section->render( $creator );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'id="merchboard-pricing-backfill"', $html );
		self::assertStringContainsString( 'A pricing snapshot is made', $html );
		self::assertSame( 1, preg_match( '/name="fingerprint" value="([0-9a-f]{64})"/', $html, $found ) );

		$input = array(
			'action'      => CreatorPricingActions::BACKFILL_ACTION,
			'creator_id'  => (string) $kenny,
			'_wpnonce'    => wp_create_nonce( CreatorPricingActions::nonce_action( CreatorPricingActions::BACKFILL_ACTION, $kenny ) ),
			'fingerprint' => $found[1] ?? '',
			'vat_source'  => 'history',
		);

		self::assertStringContainsString( '0%3A' . CreatorPricingActions::BACKFILL_UNCONFIRMED, rawurlencode( $this->actions->handle( $input ) ), 'Not ticked: nothing is filled in.' );
		self::assertNull( ( new SalesLinePricingRepository( $this->wpdb ) )->find( $missing ) );
		self::assertStringContainsString( '0%3Abackfill_done', rawurlencode( $this->actions->handle( $input + array( 'confirm' => '1' ) ) ) );
		self::assertSame( 2000, ( new SalesLinePricingRepository( $this->wpdb ) )->find( $missing )?->share_bps );

		ob_start();
		$this->section->render( $creator );
		self::assertStringNotContainsString( 'merchboard-pricing-backfill', (string) ob_get_clean(), 'Nothing left: the section is gone.' );
	}

	public function test_missing_snapshots_and_vat_are_filled_in_and_the_balance_becomes_known(): void {
		$kenny = $this->creator( 'Kenny' );
		$other = $this->creator( 'Other' );

		$known    = $this->order( $kenny, true );
		$no_vat   = $this->order( $kenny, true );
		$missing  = $this->order( $kenny, false );
		$no_tax   = $this->order( $kenny, false );
		$foreign  = $this->order( $other, false );
		$pricing  = Tables::name( $this->wpdb, Tables::SALES_LINE_PRICING );
		$taxes    = Tables::name( $this->wpdb, Tables::SALES_TAX );
		$sales    = Tables::name( $this->wpdb, Tables::SALES );
		$snapshot = new SalesLinePricingRepository( $this->wpdb );

		// A snapshot without a VAT status, and a line whose tax was never recorded.
		$this->wpdb->query( (string) $this->wpdb->prepare( 'UPDATE %i SET vat_registered = NULL WHERE order_item_id = %d', $pricing, $no_vat ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$this->wpdb->query( (string) $this->wpdb->prepare( 'DELETE t FROM %i t JOIN %i r ON r.event_key = t.event_key WHERE r.order_item_id = %d', $taxes, $sales, $no_tax ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.

		self::assertSame( 3, $this->unknown_rows( $kenny ), 'Before: the line without VAT, the one without a snapshot and the one without tax.' );

		$plan = $this->backfill->plan( $kenny );

		self::assertSame( array( $no_vat, $missing, $no_tax ), array_map( static fn( PricingBackfillLine $line ): int => $line->order_item_id, $plan ), 'Known lines and other creators are not listed.' );
		self::assertSame( array( PricingBackfillLine::VAT, PricingBackfillLine::SNAPSHOT, PricingBackfillLine::SKIP ), array_map( static fn( PricingBackfillLine $line ): string => $line->outcome, $plan ) );
		self::assertSame( PricingBackfillLine::NO_TAX, $plan[2]->reason );
		self::assertSame( array( null, null ), array( $plan[0]->history_vat, $plan[1]->history_vat ), 'No VAT history.' );
		self::assertSame( array( 2000, 2000 ), array( $plan[0]->share_bps, $plan[1]->share_bps ) );
		self::assertSame( SalesAmount::parse( '16.00' ), $plan[1]->payout_units( null ), 'Not VAT registered: 20 % of 80.00 excl. VAT.' );

		$done = $this->backfill->apply( $kenny, SalesLinePricingBackfill::fingerprint( $plan ), null );

		self::assertSame(
			array(
				'vat'       => 1,
				'snapshot'  => 1,
				'withdrawn' => 0,
			),
			$done
		);
		self::assertFalse( $snapshot->find( $no_vat )?->vat_registered, 'Without history: not VAT registered.' );
		self::assertSame( array( 2000, false ), array( $snapshot->find( $missing )?->share_bps, $snapshot->find( $missing )?->vat_registered ) );
		self::assertTrue( $snapshot->find( $known )?->vat_registered, 'A known line is unchanged.' );
		self::assertNull( $snapshot->find( $no_tax ), 'A line without tax is not filled in.' );
		self::assertNull( $snapshot->find( $foreign ), 'Another creator is not touched.' );
		self::assertSame( 1, $this->unknown_rows( $kenny ), 'Only the line without tax stays unknown.' );
		self::assertSame( 1, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action = %s AND creator_id = %d', Tables::name( $this->wpdb, Tables::AUDIT_LOG ), 'sales_line_pricing_backfilled', $kenny ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
	}

	public function test_the_vat_history_on_the_sale_date_applies_and_a_choice_overrides_it(): void {
		$kenny = $this->creator( 'Kenny' );
		$first = $this->order( $kenny, false );

		$this->history->insert( $kenny, true, '2026-01-01', '2026-01-02', 'CVR 1', 'Registered', 1, new DateTimeImmutable( '2026-01-02 10:00:00', new DateTimeZone( 'UTC' ) ) );

		$plan = $this->backfill->plan( $kenny );

		self::assertTrue( $plan[0]->history_vat, 'The history says VAT registered on the sale date.' );
		self::assertSame( SalesAmount::parse( '20.00' ), $plan[0]->payout_units( null ), 'VAT registered: 20 % of 100.00 incl. VAT.' );

		$this->backfill->apply( $kenny, SalesLinePricingBackfill::fingerprint( $plan ), false );

		self::assertFalse( ( new SalesLinePricingRepository( $this->wpdb ) )->find( $first )?->vat_registered, 'The administrator\'s choice wins.' );
	}

	public function test_a_page_shown_for_other_lines_fills_in_nothing(): void {
		$kenny = $this->creator( 'Kenny' );
		$this->order( $kenny, false );

		$stale = SalesLinePricingBackfill::fingerprint( $this->backfill->plan( $kenny ) );
		$late  = $this->order( $kenny, false );

		try {
			$this->backfill->apply( $kenny, $stale, null );
			self::fail( 'A stale page must be refused.' );
		} catch ( PricingException $e ) {
			self::assertSame( PricingException::BACKFILL_CHANGED, $e->reason() );
		}

		self::assertNull( ( new SalesLinePricingRepository( $this->wpdb ) )->find( $late ), 'Nothing was filled in.' );
	}

	public function test_only_lines_in_the_balance_window_are_listed_and_a_zero_share_is_skipped(): void {
		$kenny = $this->creator( 'Kenny' );
		$zero  = $this->creator( 'Zero', true, 0 );
		$old   = $this->order( $kenny, false );

		// The settlement start lies after the sale (2026-09-01): the balance does not count it (30.15 review).
		$items = static fn( array $plan ): array => array_map( static fn( PricingBackfillLine $line ): int => $line->order_item_id, $plan );

		self::assertSame( array( $old ), $items( $this->backfill->plan( $kenny ) ), 'Without a start the whole history counts.' );
		self::assertSame( array( $old ), $items( $this->windowed( '2026-08-01 00:00:00' )->plan( $kenny ) ), 'After the start: listed.' );
		self::assertSame( array(), $this->windowed( '2026-10-01 00:00:00' )->plan( $kenny ), 'Before the start: not in the balance, not listed.' );

		$this->order( $zero, false );
		$plan = $this->backfill->plan( $zero );

		self::assertSame( array( PricingBackfillLine::SKIP, PricingBackfillLine::NO_MARKUP ), array( $plan[0]->outcome ?? null, $plan[0]->reason ?? null ), 'A 0 % share would make the payout a known 0: set the markup first.' );
	}

	public function test_a_new_order_line_is_not_vat_registered_by_default(): void {
		$kenny = $this->creator( 'Kenny', null );
		$line  = $this->order( $kenny, true );

		self::assertFalse( ( new SalesLinePricingRepository( $this->wpdb ) )->find( $line )?->vat_registered, 'A new creator\'s order line is "not VAT registered", never unknown.' );
	}

	/**
	 * Payout rows of a creator that cannot be calculated.
	 *
	 * @param int $creator Creator.
	 */
	private function unknown_rows( int $creator ): int {
		$summary = ( new LedgerCreatorPayouts( $this->wpdb ) )->earned( $creator, null, new DateTimeImmutable( '2027-01-01 00:00:00', new DateTimeZone( 'UTC' ) ), 0 );

		return $summary['DKK']->unknown_rows ?? 0;
	}

	/**
	 * One paid order of 100.00 incl. VAT (80.00 + 20.00) with one line of a new product of the creator.
	 *
	 * @param int  $creator  Creator.
	 * @param bool $snapshot Whether the line gets its snapshot when it is created.
	 * @return int The order item.
	 */
	/**
	 * The backfill with a settlement start for every creator.
	 *
	 * @param string $start Start (UTC).
	 */
	private function windowed( string $start ): SalesLinePricingBackfill {
		return new SalesLinePricingBackfill( $this->wpdb, new SalesLinePricingRepository( $this->wpdb ), $this->resolver, new PostMetaPricingProductStore(), $this->history, $this->service( AuditTrail::class ), $this->service( Transaction::class ), null, static fn(): DateTimeImmutable => new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) ) );
	}

	private function order( int $creator, bool $snapshot ): int {
		$order   = $this->next_order++;
		$item    = 5000 + $order;
		$product = $this->posts->add( 'MB-BACKFILL product', 'publish', array( (string) $creator ) );
		$this->posts->meta( $product, '_regular_price', '100' );
		$this->posts->meta( $product, '_price', '100' );

		if ( $snapshot ) {
			$this->recorder->record( $item, $order, $product, 0, 'DKK' );
		}

		$this->reader->orders[ $order ] = array(
			'status'    => 'processing',
			'currency'  => 'DKK',
			'date_paid' => '2026-09-01 10:05:00',
			'lines'     => array( new OrderLineSnapshot( $item, $product, 0, 'MB-BACKFILL line', 1, SalesAmount::parse( '80' ), $creator, SalesAmount::parse( '20' ) ) ),
			'refunds'   => array(),
		);
		$this->sync->sync( $order );

		return $item;
	}

	/**
	 * An active creator with a markup (20 % by default).
	 *
	 * @param string    $name Name.
	 * @param bool|null $vat  Current VAT status; null leaves the default.
	 * @param int       $bps  Markup in basis points.
	 */
	private function creator( string $name, ?bool $vat = true, int $bps = 2000 ): int {
		$creator = $this->service( CreatorService::class )->create( $name, CreatorStatus::Active, null );
		( new CreatorMarkupRepository( $this->wpdb ) )->save( $creator->id, new MarkupBps( $bps ) );

		if ( null !== $vat ) {
			( new CreatorVatRepository( $this->wpdb ) )->save( $creator->id, $vat );
		}

		return $creator->id;
	}
}
