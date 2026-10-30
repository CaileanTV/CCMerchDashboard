<?php
/**
 * Tests for the designer's one admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey\Functions;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerMenu;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DesignerMenu::class )]
final class DesignerMenuTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'admin_url' )->alias( static fn( string $path ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'sanitize_key' )->alias( static fn( string $key ): string => strtolower( $key ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	public function test_a_tab_has_one_address_on_one_page(): void {
		self::assertSame( 'https://shop.test/wp-admin/admin.php?view=new&page=merchboard-designer&tab=bases', DesignerMenu::url( DesignerMenu::BASES, array( 'view' => 'new' ) ) );
	}

	public function test_a_former_page_leads_to_its_tab_with_its_parameters(): void {
		self::assertStringContainsString( 'tab=designs', (string) DesignerMenu::former_url( array( 'page' => 'merchboard-designs' ) ) );
		self::assertStringContainsString( 'tab=campaigns', (string) DesignerMenu::former_url( array( 'page' => 'merchboard-campaigns' ) ) );
		self::assertStringContainsString( 'tab=notifications', (string) DesignerMenu::former_url( array( 'page' => 'merchboard-designs', 'view' => 'notifications' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'tab=cliparts', (string) DesignerMenu::former_url( array( 'page' => 'merchboard-base-products', 'view' => 'cliparts' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$url = (string) DesignerMenu::former_url(
			array(
				'page' => DesignerMenu::LINK_BASES,
				'base' => '5',
				'odd'  => array( 'x' ),
			)
		);

		self::assertStringContainsString( 'base=5', $url );
		self::assertStringContainsString( 'page=merchboard-designer', $url );
		self::assertStringNotContainsString( 'odd', $url );
	}

	public function test_any_other_address_stays(): void {
		self::assertNull( DesignerMenu::former_url( array( 'page' => 'merchboard-settings' ) ) );
		self::assertNull( DesignerMenu::former_url( array() ) );
	}
}
