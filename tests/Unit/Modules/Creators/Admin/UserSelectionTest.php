<?php
/**
 * Tests for the user search endpoint and the profile section.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators\Admin;

use Brain\Monkey\Functions;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorUserSearch;
use MerchBoard\Modules\Creators\Admin\UserProfileSection;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_User;

#[CoversClass( CreatorUserSearch::class )]
#[CoversClass( UserProfileSection::class )]
final class UserSelectionTest extends TestCase {

	use CreatorAdminEnvironment;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();
		$this->users->users[44] = 'Nora Nordic (nora)';

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce, string $action ): int|false => 'valid:' . $action === $nonce ? 1 : false );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( strip_tags( $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_kses' )->alias( static fn( string $html ): string => $html );
	}

	public function test_search_returns_login_and_label_only_and_skips_linked_users(): void {
		$this->service->create( 'Taken', CreatorStatus::Active, 42 );

		$result = $this->search( 'No' );

		self::assertSame( 200, $result['status'] );
		self::assertSame(
			array(
				array(
					'login' => 'nora',
					'label' => 'Nora Nordic (nora)',
				),
			),
			$result['data'],
			'Nova (42) is linked to a creator and not offered; no ids, no e-mail.'
		);
	}

	public function test_short_terms_return_nothing(): void {
		self::assertSame( array(), $this->search( 'n' )['data'] );
		self::assertSame( array(), $this->search( ' ' )['data'] );
	}

	public function test_search_requires_the_capability_and_a_valid_nonce(): void {
		self::assertSame( 403, $this->search( 'No', 'forged' )['status'] );
		self::assertSame( 403, $this->search( 'No', null )['status'] );

		foreach ( array( array( 'read' ), array( Capabilities::ACCESS_DASHBOARD ), array( Capabilities::MANAGE ), array( Capabilities::MANAGE_SETTINGS ) ) as $caps ) {
			$this->caps = $caps;
			$result     = $this->search( 'No' );
			self::assertSame( 403, $result['status'] );
			self::assertSame( array(), $result['data'] );
		}
	}

	public function test_search_handler_is_admin_only(): void {
		( new CreatorUserSearch( $this->users, new CreatorRepository( $this->db ) ) )->register();

		self::assertNotFalse( has_action( 'wp_ajax_merchboard_search_users' ) );
		self::assertFalse( has_action( 'wp_ajax_nopriv_merchboard_search_users' ), 'No endpoint for logged-out visitors.' );
	}

	public function test_profile_section_offers_to_create_a_creator_for_an_unlinked_user(): void {
		$html = $this->profile( 44 );

		self::assertStringContainsString( 'This user is not linked to a creator.', $html );
		self::assertStringContainsString( 'page=merchboard-creators&action=new&user_id=44', $html );
	}

	public function test_profile_section_links_the_creator_with_escaped_name_and_status(): void {
		$this->service->create( 'Studio <b>Nova</b>', CreatorStatus::Inactive, 42 );

		$html = $this->profile( 42 );

		self::assertStringContainsString( 'page=merchboard-creators&action=edit&creator=1', $html );
		self::assertStringContainsString( 'Studio &lt;b&gt;Nova&lt;/b&gt;', $html );
		self::assertStringContainsString( '(Inactive)', $html );
	}

	public function test_profile_section_is_hidden_without_the_capability_or_user(): void {
		$this->caps = array( 'edit_users', 'manage_options', Capabilities::MANAGE_SETTINGS );
		self::assertSame( '', $this->profile( 42 ) );

		$this->caps = array( Capabilities::MANAGE_CREATORS );
		ob_start();
		( new UserProfileSection( new CreatorRepository( $this->db ) ) )->render( 'not a user' );
		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Runs the search endpoint.
	 *
	 * @param string      $term  Term.
	 * @param string|null $nonce Nonce (default: valid).
	 * @return array{status: int, data: list<array{login: string, label: string}>}
	 */
	private function search( string $term, ?string $nonce = 'valid:merchboard_search_users' ): array {
		$input = array( 'term' => $term );

		if ( null !== $nonce ) {
			$input['nonce'] = $nonce;
		}

		return ( new CreatorUserSearch( $this->users, new CreatorRepository( $this->db ) ) )->search( $input );
	}

	/**
	 * Renders the profile section for a user id.
	 *
	 * @param int $user_id User id.
	 */
	private function profile( int $user_id ): string {
		$user     = new WP_User();
		$user->ID = $user_id;

		ob_start();
		( new UserProfileSection( new CreatorRepository( $this->db ) ) )->render( $user );

		return (string) ob_get_clean();
	}
}
