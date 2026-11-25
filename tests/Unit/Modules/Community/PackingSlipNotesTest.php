<?php
/**
 * Tests for the creators' thank-you notes on the packing slip (phase 58).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use DateTimeImmutable;
use MerchBoard\Modules\Community\ThankYouNote\PackingSlipNotes;
use MerchBoard\Modules\Community\ThankYouNote\ThankYouNoteSettings;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Unit\Support\WcOrderStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use WC_Order;

#[CoversClass( PackingSlipNotes::class )]
#[CoversClass( ThankYouNoteSettings::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PackingSlipNotesTest extends TestCase {

	/**
	 * Creator by product: 11 and 12 are Nova's, 21 Mika's, 31 Inactive's,
	 * 41 Deleted's, 51 Silent's (note off), 61 is a collection, 71 nobody's.
	 */
	private const PRODUCTS = array(
		11 => 1,
		12 => 1,
		21 => 2,
		31 => 3,
		41 => 4,
		51 => 5,
		61 => 1,
	);

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-order.php';
		WcOrderStorage::reset();
	}

	public function test_each_active_creator_s_note_once_in_the_order_of_the_lines(): void {
		WcOrderStorage::add_order(
			100,
			'processing',
			array(
				WcOrderStorage::item( 1, 21, 0, 'Mug', 1, '99' ),
				WcOrderStorage::item( 2, 11, 0, 'Hoodie', 1, '449' ),
				WcOrderStorage::item( 3, 12, 13, 'Tee', 2, '398' ),
				WcOrderStorage::item( 4, 31, 0, 'Cap', 1, '199' ),
				WcOrderStorage::item( 5, 41, 0, 'Sticker', 1, '29' ),
				WcOrderStorage::item( 6, 51, 0, 'Poster', 1, '149' ),
				WcOrderStorage::item( 7, 61, 0, 'Collection', 1, '0' ),
				WcOrderStorage::item( 8, 71, 0, 'Shop product', 1, '49' ),
			)
		);

		self::assertSame(
			array(
				array(
					'creator_id' => 2,
					'name'       => 'Mika',
					'text'       => 'Tusind tak!',
				),
				array(
					'creator_id' => 1,
					'name'       => 'Nova',
					'text'       => "Tak fordi du støtter mig!\nKh. Nova",
				),
			),
			$this->notes()->notes( self::order( 100 ) )
		);
	}

	public function test_the_filter_adds_to_other_notes_and_takes_an_order_id(): void {
		WcOrderStorage::add_order( 100, 'processing', array( WcOrderStorage::item( 1, 11, 0, 'Hoodie', 1, '449' ) ) );
		$other = array( array( 'text' => 'From another plugin' ) );

		self::assertSame( array( $other[0], array( 'creator_id' => 1, 'name' => 'Nova', 'text' => "Tak fordi du støtter mig!\nKh. Nova" ) ), $this->notes()->filter( $other, 100 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertCount( 1, $this->notes()->filter( array(), '100' ) );
		self::assertSame( $other, $this->notes()->filter( $other, 999 ), 'No order.' );
		self::assertSame( $other, $this->notes()->filter( $other, 'nonsense' ) );
		self::assertSame( array(), $this->notes()->filter( 'not a list', null ) );
	}

	public function test_a_failure_gives_no_notes_instead_of_breaking_the_packing_slip(): void {
		WcOrderStorage::add_order( 100, 'processing', array( WcOrderStorage::item( 1, 11, 0, 'Hoodie', 1, '449' ) ) );
		$failing = new PackingSlipNotes(
			static fn(): ?int => throw new RuntimeException( 'Database down.' ),
			static fn(): ?Creator => null,
			static fn(): bool => false,
			static fn(): ThankYouNoteSettings => new ThankYouNoteSettings()
		);

		self::assertSame( array( 'kept' ), $failing->filter( array( 'kept' ), 100 ) );
	}

	public function test_a_stored_note_is_read_safely(): void {
		self::assertSame( array( false, '' ), self::read( null ) );
		self::assertSame( array( false, '' ), self::read( array( 'enabled' => true, 'text' => '' ) ), 'On without a text prints nothing.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( array( false, '' ), self::read( array( 'enabled' => true, 'text' => str_repeat( 'a', 301 ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( array( false, '' ), self::read( array( 'enabled' => 'yes', 'text' => 'Tak' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( array( true, 'Tak' ), self::read( array( 'enabled' => true, 'text' => 'Tak' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
	}

	/**
	 * Whether a stored note is on, and what is printed.
	 *
	 * @param mixed $stored Stored value.
	 * @return array{bool, string}
	 */
	private static function read( mixed $stored ): array {
		$note = ThankYouNoteSettings::from_stored( $stored );

		return array( $note->enabled, $note->printed() );
	}

	/**
	 * The notes with this test's creators.
	 */
	private function notes(): PackingSlipNotes {
		$now      = new DateTimeImmutable( '2026-11-25 12:00:00' );
		$creators = array(
			1 => new Creator( 1, 10, 'Nova', CreatorStatus::Active, $now, $now ),
			2 => new Creator( 2, 20, 'Mika', CreatorStatus::Active, $now, $now ),
			3 => new Creator( 3, 30, 'Inactive', CreatorStatus::Inactive, $now, $now ),
			4 => new Creator( 4, 40, 'Deleted', CreatorStatus::Active, $now, $now, $now ),
			5 => new Creator( 5, 50, 'Silent', CreatorStatus::Active, $now, $now ),
		);
		$notes    = array(
			1 => new ThankYouNoteSettings( true, "Tak fordi du støtter mig!\nKh. Nova" ),
			2 => new ThankYouNoteSettings( true, 'Tusind tak!' ),
			3 => new ThankYouNoteSettings( true, 'Inactive' ),
			4 => new ThankYouNoteSettings( true, 'Deleted' ),
			5 => new ThankYouNoteSettings( false, 'Off' ),
		);

		return new PackingSlipNotes(
			static fn( int $product_id ): ?int => self::PRODUCTS[ $product_id ] ?? null,
			static fn( int $creator_id ): ?Creator => $creators[ $creator_id ] ?? null,
			static fn( int $product_id ): bool => 61 === $product_id,
			static fn( int $creator_id ): ThankYouNoteSettings => $notes[ $creator_id ] ?? new ThankYouNoteSettings()
		);
	}

	/**
	 * A stored order.
	 *
	 * @param int $id Order id.
	 */
	private static function order( int $id ): WC_Order {
		$order = wc_get_order( $id );

		self::assertInstanceOf( WC_Order::class, $order );

		return $order;
	}
}
