<?php
/**
 * Rendering tests for the creators admin screen (form and notices).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators\Admin;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\Creators\Admin\CreatorAdminActions;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreatorsAdminPage::class )]
final class CreatorsAdminPageTest extends TestCase {

	use CreatorAdminEnvironment;

	private CreatorsAdminPage $page;

	/**
	 * Previous $_GET, restored after each test.
	 *
	 * @var array<mixed>
	 */
	private array $saved_get = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture.
		$this->page      = new CreatorsAdminPage( new CreatorRepository( $this->db ), $this->users, new TransactionSupport( $this->db ) );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( string $s ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ) );
		Functions\when( 'absint' )->alias( static fn( mixed $v ): int => abs( (int) $v ) );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action ): void {
				echo '<input type="hidden" name="_wpnonce" value="nonce:' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		// Like WordPress, selected() compares string casts; enums would fatal here.
		Functions\when( 'selected' )->alias(
			static function ( mixed $selected, mixed $current ): string {
				return ( (string) $selected === (string) $current ) ? " selected='selected'" : '';
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function ( string $text ): void {
				echo '<button type="submit">' . $text . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => gmdate( 'Y-m-d H:i', $ts ) );
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title = '', array $args = array() ): void {
				throw new WpDie( $message, (int) ( $args['response'] ?? 500 ) );
			}
		);
	}

	protected function tearDown(): void {
		$_GET = $this->saved_get;
		parent::tearDown();
	}

	public function test_new_form_renders_with_safe_default_status_and_bound_nonce(): void {
		$html = $this->render( array( 'action' => 'new' ) );

		self::assertStringContainsString( 'value="' . CreatorAdminActions::SAVE_ACTION . '"', $html );
		self::assertStringContainsString( 'nonce:' . CreatorAdminActions::save_nonce_action( 0 ), $html );
		self::assertStringContainsString( "<option value=\"inactive\" selected='selected'>", $html, 'New creators default to inactive.' );
		self::assertStringContainsString( 'Nova (nova)', $html );
	}

	public function test_edit_form_selects_current_values_and_hides_users_linked_elsewhere(): void {
		$this->service->create( 'Studio <b>Nova</b>', CreatorStatus::Active, 42 );
		$this->service->create( 'Other', CreatorStatus::Active, 43 );

		$html = $this->render(
			array(
				'action'  => 'edit',
				'creator' => '1',
			)
		);

		self::assertStringContainsString( 'nonce:' . CreatorAdminActions::save_nonce_action( 1 ), $html );
		self::assertStringContainsString( "<option value=\"active\" selected='selected'>", $html );
		self::assertStringContainsString( "<option value=\"42\" selected='selected'>", $html );
		self::assertStringNotContainsString( 'Other (other)', $html, 'A user linked to another creator is not offered.' );
		self::assertStringNotContainsString( '<b>Nova</b>', $html, 'Display name is escaped.' );
		self::assertStringContainsString( 'Studio &lt;b&gt;Nova&lt;/b&gt;', $html );
	}

	public function test_unknown_creator_shows_not_found(): void {
		$html = $this->render(
			array(
				'action'  => 'edit',
				'creator' => '999',
			)
		);

		self::assertStringContainsString( 'Creator not found', $html );
	}

	public function test_only_whitelisted_notices_are_rendered_and_escaped(): void {
		self::assertStringContainsString( 'notice-success', $this->render( array( 'action' => 'new', 'merchboard_notice' => 'created' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( 'notice', $this->render( array( 'action' => 'new', 'merchboard_notice' => '<script>alert(1)</script>' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_no_engine_notice_when_the_tables_support_transactions(): void {
		self::assertStringNotContainsString( 'storage engine', $this->render( array( 'action' => 'new' ) ) );
	}

	public function test_explains_that_changes_are_disabled_on_a_non_transactional_engine(): void {
		$this->db->engines['merchboard_audit_log'] = 'MyISAM';

		$html = $this->render( array( 'action' => 'new' ) );

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'Creator changes are disabled', $html );
		self::assertStringContainsString( 'InnoDB', $html );
		self::assertStringNotContainsString( 'wp_merchboard_audit_log', $html, 'No table names in the UI.' );
	}

	public function test_page_refuses_users_without_the_capability(): void {
		$this->caps = array( 'read' );

		$this->expectException( WpDie::class );

		$this->render( array( 'action' => 'new' ) );
	}

	/**
	 * Renders the page for the given query parameters.
	 *
	 * @param array<string, string> $get Query parameters.
	 */
	private function render( array $get ): string {
		$_GET = $get;
		ob_start();

		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}
}
