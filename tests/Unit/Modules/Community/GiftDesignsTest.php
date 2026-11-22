<?php
/**
 * Tests for the choice of design in a collection's gift box.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Community\Gifting\GiftDesigns;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( GiftDesigns::class )]
final class GiftDesignsTest extends TestCase {

	private const COLLECTION = 10;

	/**
	 * The designs that can be given now.
	 *
	 * @var array<int, array{creator: int, name: string}>
	 */
	private array $giftable = array();

	protected function setUp(): void {
		parent::setUp();

		$this->giftable = array(
			21 => array(
				'creator' => 7,
				'name'    => 'Tee – Kill Steal',
			),
			22 => array(
				'creator' => 7,
				'name'    => 'Tee – Det Fint',
			),
		);
	}

	private function designs(): GiftDesigns {
		$collections = $this->createStub( DesignCollections::class );
		$collections->method( 'members' )->willReturn(
			array(
				array(
					'id'    => 21,
					'label' => 'Kill Steal',
				),
				array(
					'id'    => 22,
					'label' => ' ',
				),
				array(
					'id'    => 23,
					'label' => 'Sold out',
				),
			)
		);

		return new GiftDesigns( $collections, fn( int $id ): ?array => $this->giftable[ $id ] ?? null );
	}

	public function test_nothing_is_preselected_without_the_buyers_choice(): void {
		self::assertSame(
			array(
				'designs' => array(
					array(
						'id'   => 21,
						'name' => 'Kill Steal',
					),
					array(
						'id'   => 22,
						'name' => 'Tee – Det Fint',
					),
				),
				'chosen'  => 0,
				'creator' => 7,
			),
			$this->designs()->choice( self::COLLECTION, 0 )
		);
	}

	public function test_only_a_design_that_can_be_given_is_preselected(): void {
		self::assertSame( 22, $this->designs()->choice( self::COLLECTION, 22 )['chosen'] ?? null );
		self::assertSame( 0, $this->designs()->choice( self::COLLECTION, 23 )['chosen'] ?? null, 'Cannot be given.' );
		self::assertSame( 0, $this->designs()->choice( self::COLLECTION, 99 )['chosen'] ?? null, 'Not in the collection.' );
	}

	public function test_several_creators_name_no_channel(): void {
		$this->giftable[22]['creator'] = 8;

		$choice = $this->designs()->choice( self::COLLECTION, 0 );

		self::assertNotNull( $choice );
		self::assertNull( $choice['creator'] );
		self::assertCount( 2, $choice['designs'] );
	}

	public function test_without_a_design_to_give_there_is_no_box(): void {
		$this->giftable = array();

		self::assertNull( $this->designs()->choice( self::COLLECTION, 21 ) );
	}

	public function test_the_box_requires_a_choice_and_sends_the_designs_id(): void {
		$html = $this->box( 0 );

		self::assertStringContainsString( 'Which design do you give?', $html );
		self::assertMatchesRegularExpression( '/<select[^>]+name="merchboard_gift"[^>]+required>/', $html );
		self::assertStringContainsString( '<option value="21">Kill Steal</option>', $html );
		self::assertStringNotContainsString( ' selected', $html );
		self::assertStringNotContainsString( 'value="10"', str_replace( '<option value="10">10</option>', '', $html ), 'Never the collection.' );
	}

	public function test_the_box_preselects_the_buyers_design(): void {
		self::assertStringContainsString( '<option value="21" selected>Kill Steal</option>', $this->box( 21 ) );
	}

	/**
	 * The template on a collection's page.
	 *
	 * @param int $chosen The buyer's design.
	 */
	private function box( int $chosen ): string {
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_parse_url' )->justReturn( '' );
		Functions\when( 'wp_parse_str' )->justReturn( null );

		$merchboard_box = array(
			'action'   => 'https://shop.test/product/tee/',
			'product'  => self::COLLECTION,
			'designs'  => array(
				array(
					'id'   => 21,
					'name' => 'Kill Steal',
				),
			),
			'chosen'   => $chosen,
			'query'    => 'merchboard_gift',
			'quantity' => 'merchboard_gift_quantity',
			'nonce'    => 'nonce',
			'max'      => 10,
			'name'     => '',
			'login'    => '',
			'shipping' => '50',
		);

		ob_start();
		require dirname( __DIR__, 4 ) . '/templates/community/gift-box.php';

		return (string) ob_get_clean();
	}
}
