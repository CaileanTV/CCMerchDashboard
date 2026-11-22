<?php
/**
 * Saved selections that hold a collection, against a real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Community;

use Closure;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Community\MerchAd\MerchAdRepository;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionRepository;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubCampaign;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountRepository;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * ADR-120, status note 2026-11-22: what was saved while a collection was an
 * ordinary product still works. The panel and the ad show its designs; the
 * discount leaves it out.
 */
#[CoversClass( MerchAdRepository::class )]
#[CoversClass( TwitchExtensionRepository::class )]
#[CoversClass( TwitchSubDiscountRepository::class )]
final class CollectionSelectionsTest extends IntegrationTestCase {

	private const COLLECTION = 700;

	private const RED = 701;

	private const BLUE = 702;

	private const MUG = 703;

	private int $creator;

	private CreatorMetaStore $meta;

	private Transaction $transaction;

	private AuditTrail $audit;

	private Closure $owns;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->creator     = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, null )->id;
		$this->meta        = $this->service( CreatorMetaStore::class );
		$this->transaction = $this->service( Transaction::class );
		$this->audit       = $this->service( AuditTrail::class );
		$this->owns        = static fn( int $creator_id, int $product_id ): bool => in_array( $product_id, array( self::RED, self::BLUE, self::MUG ), true );
	}

	public function test_the_panel_shows_the_designs_and_can_be_saved(): void {
		$this->store(
			CreatorMetaStore::TWITCH_EXTENSION,
			array(
				'enabled'     => true,
				'product_ids' => array( self::COLLECTION, self::MUG ),
				'note'        => '',
			)
		);
		$panel = new TwitchExtensionRepository( $this->meta, $this->audit, $this->transaction, $this->owns, self::as_designs() );

		self::assertSame( array( self::RED, self::BLUE, self::MUG ), $panel->find( $this->creator )->product_ids );

		$saved = $panel->save(
			$this->creator,
			array(
				'enabled'     => false,
				'product_ids' => array( self::COLLECTION, self::MUG ),
			)
		);

		self::assertSame( array( self::RED, self::BLUE, self::MUG ), $saved->product_ids, 'Also from a page opened before the update.' );
	}

	public function test_the_ad_shows_the_designs(): void {
		$this->store(
			CreatorMetaStore::MERCH_AD,
			array(
				'enabled'     => true,
				'product_ids' => array( self::COLLECTION ),
			)
		);
		$ad = new MerchAdRepository( $this->meta, $this->audit, $this->transaction, $this->owns, self::as_designs() );

		self::assertSame( array( self::RED, self::BLUE ), $ad->find( $this->creator )->product_ids );
	}

	public function test_the_discount_leaves_the_collection_out_and_can_still_be_switched_off(): void {
		$id = str_repeat( 'a', 16 );
		$this->store(
			CreatorMetaStore::TWITCH_SUB_DISCOUNT,
			array(
				'enabled'   => true,
				'gifted'    => true,
				'campaigns' => array(
					array(
						'id'          => $id,
						'name'        => 'Tees',
						'enabled'     => true,
						'tiers'       => array(
							1 => 500,
							2 => 500,
							3 => 500,
						),
						'scope'       => TwitchSubCampaign::SELECTED,
						'product_ids' => array( self::COLLECTION ),
					),
				),
			)
		);
		$discount = new TwitchSubDiscountRepository(
			$this->meta,
			$this->audit,
			$this->transaction,
			$this->owns,
			static fn(): bool => true,
			static fn(): array => array(),
			static fn( array $ids ): array => array_values( array_filter( $ids, static fn( int $product ): bool => self::COLLECTION !== $product ) )
		);

		$campaign = $discount->find( $this->creator )->campaigns[0];

		self::assertSame( array(), $campaign->product_ids );
		self::assertFalse( $campaign->active( '2026-11-22' ), 'Nobody gets a discount they did not get before.' );

		$saved = $discount->save(
			$this->creator,
			array(
				'enabled'   => false,
				'gifted'    => true,
				'campaigns' => array(
					array(
						'id'          => $id,
						'name'        => 'Tees',
						'enabled'     => true,
						'tiers'       => array(
							1 => 5,
							2 => 5,
							3 => 5,
						),
						'scope'       => TwitchSubCampaign::SELECTED,
						'product_ids' => array(),
					),
				),
			)
		);

		self::assertFalse( $saved->enabled );
	}

	/**
	 * The collection as its two designs.
	 *
	 * @return Closure(int, list<int>): list<int>
	 */
	private static function as_designs(): Closure {
		return static function ( int $creator_id, array $ids ): array {
			$products = array();

			foreach ( $ids as $id ) {
				array_push( $products, ...( self::COLLECTION === $id ? array( self::RED, self::BLUE ) : array( (int) $id ) ) );
			}

			return $products;
		};
	}

	/**
	 * Saves a value as a version before 2.4.0 left it.
	 *
	 * @param string               $key   Meta key.
	 * @param array<string, mixed> $value Value.
	 */
	private function store( string $key, array $value ): void {
		$this->transaction->run(
			function () use ( $key, $value ): void {
				$this->meta->set( $this->creator, $key, $value );
			}
		);
	}
}
