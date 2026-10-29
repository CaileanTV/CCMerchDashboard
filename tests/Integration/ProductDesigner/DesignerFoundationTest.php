<?php
/**
 * The product designer's foundation (phase 30, step 30.2, ADR-080).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerSettingsActions;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerSettingsSection;
use MerchBoard\Modules\ProductDesigner\DesignerCreatorCleanup;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerSettings;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Migration 35, the capability, the switches with audit and the creator
 * cleanup. The designer itself follows in later steps.
 */
#[CoversClass( DesignerSettings::class )]
#[CoversClass( DesignerSettingsActions::class )]
#[CoversClass( DesignerCreatorCleanup::class )]
final class DesignerFoundationTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_migration_35_creates_the_designer_tables_with_their_unique_keys(): void {
		$expected = array(
			Tables::DESIGNER_BASES          => array( 'base_price', 'views', 'version' ),
			Tables::DESIGNER_PRODUCTS       => array( 'wc_product_id', 'creation_token', 'live_version_id' ),
			Tables::DESIGNER_VERSIONS       => array( 'pending_key', 'design', 'profit_kind', 'profit_value', 'apply_step' ),
			Tables::DESIGNER_ASSETS         => array( 'creator_id', 'file', 'bytes' ),
			Tables::DESIGNER_PROVIDER_TASKS => array( 'provider', 'task_key', 'next_poll_at' ),
			Tables::DESIGNER_CAMPAIGNS      => array( 'starts_at', 'ends_at', 'coupon_codes', 'sale_prices' ),
		);

		foreach ( $expected as $table => $columns ) {
			$present = array_column( (array) $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( $table ) ), ARRAY_A ), 'Field' );

			foreach ( $columns as $column ) {
				self::assertContains( $column, $present, $table . '.' . $column );
			}
		}

		$unique = array_column( (array) $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW INDEX FROM %i WHERE Non_unique = 0', $this->table( Tables::DESIGNER_VERSIONS ) ), ARRAY_A ), 'Key_name' );
		self::assertContains( 'pending_key', $unique, 'At most one pending change per designer product.' );
		self::assertContains( 'product_number', $unique );
		self::assertSame( 37, (int) get_option( 'merchboard_db_version' ) );
	}

	public function test_only_administrators_get_the_designer_capability(): void {
		self::assertTrue( user_can( 1, Capabilities::MANAGE_DESIGNER ) );
		self::assertFalse( user_can( $this->create_user( 'designer-subscriber' ), Capabilities::MANAGE_DESIGNER ) );
	}

	public function test_the_switches_are_off_by_default_and_every_change_is_audited(): void {
		self::assertSame( array( false, false, false ), array( Settings::designer_enabled(), Settings::printful_enabled(), Settings::gelato_enabled() ) );

		$settings = $this->settings();
		$changed  = $settings->save(
			array(
				Settings::DESIGNER_ENABLED => true,
				Settings::GELATO_ENABLED   => true,
				'unknown'                  => true,
			)
		);

		self::assertSame( array( Settings::DESIGNER_ENABLED, Settings::GELATO_ENABLED ), $changed );
		self::assertSame( array( true, false, true ), array( Settings::designer_enabled(), Settings::printful_enabled(), Settings::gelato_enabled() ) );
		self::assertSame( array(), $settings->save( array( Settings::DESIGNER_ENABLED => true, Settings::GELATO_ENABLED => true ) ), 'Unchanged: nothing is audited.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$audit = array_map( static fn( array $row ): mixed => json_decode( (string) $row['context'], true ), array_values( array_filter( $this->rows( Tables::AUDIT_LOG ), static fn( array $row ): bool => 'settings_updated' === $row['action'] ) ) );
		self::assertSame( array( Settings::DESIGNER_ENABLED, Settings::GELATO_ENABLED ), array_column( $audit, 'setting' ) );

		wp_set_current_user( $this->create_user( 'designer-editor' ) );

		try {
			$settings->save( array( Settings::DESIGNER_ENABLED => false ) );
			self::fail( 'Only users with merchboard_manage_designer.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::NOT_AUTHORIZED, $e->reason() );
		}
	}

	public function test_the_settings_form_saves_with_a_nonce_and_redirects(): void {
		$actions = new DesignerSettingsActions( $this->settings(), $this->service( Logger::class ) );
		$url     = $actions->save(
			array(
				'_wpnonce'                 => wp_create_nonce( DesignerSettingsActions::SAVE_ACTION ),
				Settings::PRINTFUL_ENABLED => '1',
			)
		);

		self::assertStringContainsString( DesignerSettingsActions::RESULT_ARG . '=saved', $url );
		self::assertStringEndsWith( '#merchboard-designer', $url );
		self::assertTrue( Settings::printful_enabled() );

		ob_start();
		( new DesignerSettingsSection() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="' . Settings::PRINTFUL_ENABLED . '" value="1" checked', $html );
		self::assertStringContainsString( 'name="' . Settings::DESIGNER_ENABLED . '" value="1">', $html );
	}

	public function test_deleting_a_creator_removes_only_designer_data_that_never_reached_the_shop(): void {
		$creator = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, null );
		$other   = $this->service( CreatorService::class )->create( 'Orion', CreatorStatus::Active, null );
		$draft   = $this->designer_product( $creator->id, null );
		$shipped = $this->designer_product( $creator->id, 9001 );
		$theirs  = $this->designer_product( $other->id, null );
		$this->version( $draft, $creator->id );
		$this->version( $shipped, $creator->id );
		$this->version( $theirs, $other->id );
		$this->asset( $creator->id );
		$this->asset( null );

		$found = $this->service( CreatorRepository::class )->find( $creator->id );
		self::assertNotNull( $found );
		$taken  = array();
		$counts = ( new DesignerCreatorCleanup(
			$this->wpdb,
			new DesignerFiles(),
			static function ( int $id ) use ( &$taken ): bool {
				$taken[] = $id;

				return true;
			}
		) )->remove( $found );

		self::assertSame(
			array(
				'tasks'       => 0,
				'versions'    => 1,
				'products'    => 1,
				'assets'      => 1,
				'files'       => 1,
				'unpublished' => 1,
			),
			$counts
		);
		self::assertSame( array( $shipped ), $taken, 'The product in the shop is taken out of it.' );
		self::assertSame( array( (string) $shipped, (string) $theirs ), array_map( 'strval', array_column( $this->rows( Tables::DESIGNER_PRODUCTS ), 'id' ) ), 'A product in the shop stays for the order history; other creators are untouched.' );
		self::assertCount( 2, $this->rows( Tables::DESIGNER_VERSIONS ) );
		self::assertSame( array( null ), array_column( $this->rows( Tables::DESIGNER_ASSETS ), 'creator_id' ), 'The administrator\'s cliparts stay.' );
	}

	/**
	 * The switches' service (the module loads only with WooCommerce).
	 */
	private function settings(): DesignerSettings {
		return new DesignerSettings( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
	}

	/**
	 * Inserts a designer product.
	 *
	 * @param int      $creator_id    Creator.
	 * @param int|null $wc_product_id WooCommerce product, or null.
	 */
	private function designer_product( int $creator_id, ?int $wc_product_id ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'creator_id'     => $creator_id,
			'base_id'        => 1,
			'creation_token' => bin2hex( random_bytes( 16 ) ),
			'status'         => null === $wc_product_id ? 'draft' : 'live',
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		if ( null !== $wc_product_id ) {
			$row['wc_product_id'] = $wc_product_id;
		}

		self::assertNotFalse( $this->wpdb->insert( $this->table( Tables::DESIGNER_PRODUCTS ), $row ) );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Inserts a version.
	 *
	 * @param int $product_id Designer product.
	 * @param int $creator_id Creator.
	 */
	private function version( int $product_id, int $creator_id ): void {
		$now = gmdate( 'Y-m-d H:i:s' );
		self::assertNotFalse(
			$this->wpdb->insert(
				$this->table( Tables::DESIGNER_VERSIONS ),
				array(
					'design_product_id' => $product_id,
					'creator_id'        => $creator_id,
					'number'            => 1,
					'base_version'      => 1,
					'title'             => 'Tee',
					'description'       => '',
					'colors'            => '[]',
					'sizes'             => '[]',
					'sides'             => '[]',
					'design'            => '{}',
					'created_at'        => $now,
					'updated_at'        => $now,
				)
			)
		);
	}

	/**
	 * Inserts an asset.
	 *
	 * @param int|null $creator_id Creator, or null for a clipart.
	 */
	private function asset( ?int $creator_id ): void {
		$row = array(
			'kind'       => null === $creator_id ? 'clipart' : 'upload',
			'name'       => 'logo.png',
			'file'       => bin2hex( random_bytes( 16 ) ) . '.png',
			'mime'       => 'image/png',
			'width'      => 100,
			'height'     => 100,
			'bytes'      => 1000,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( null !== $creator_id ) {
			$row['creator_id'] = $creator_id;
		}

		self::assertNotFalse( $this->wpdb->insert( $this->table( Tables::DESIGNER_ASSETS ), $row ) );
	}
}
