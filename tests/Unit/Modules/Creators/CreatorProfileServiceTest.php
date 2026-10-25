<?php
/**
 * Tests for the creator's own profile (phase 14).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorException;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorProfile;
use MerchBoard\Modules\Creators\CreatorProfileException;
use MerchBoard\Modules\Creators\CreatorProfileService;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\SocialLinks;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( CreatorProfileService::class )]
#[CoversClass( CreatorProfile::class )]
#[CoversClass( CreatorProfileException::class )]
#[CoversClass( CreatorMetaStore::class )]
#[CoversClass( SocialLinks::class )]
final class CreatorProfileServiceTest extends TestCase {

	private FakeDatabase $db;

	private CreatorRepository $creators;

	/**
	 * In-memory creator meta: creator id → key → value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = array();

	/**
	 * When true, meta writes silently do nothing (a failed write).
	 *
	 * @var bool
	 */
	private bool $meta_writes_fail = false;

	private int $current_user_id = 5;

	protected function setUp(): void {
		parent::setUp();
		$this->db       = new FakeDatabase();
		$this->creators = new CreatorRepository( $this->db );

		Functions\when( 'current_time' )->justReturn( '2026-09-25 12:00:00' );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current_user_id );
		// No "view as creator" session (ADR-075).
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): string|false => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stand-in.
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.
		Functions\when( 'sanitize_key' )->alias( static fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
		// Like WordPress: tags removed, line breaks kept.
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( string $text ): string => strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'wp_slash' )->alias( static fn( mixed $value ): mixed => is_string( $value ) ? addslashes( $value ) : $value );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_metadata' )->alias(
			fn( string $type, int $id, string $key ): array => array_key_exists( $key, $this->meta[ $id ] ?? array() ) ? array( $this->meta[ $id ][ $key ] ) : array()
		);
		Functions\when( 'update_metadata' )->alias(
			function ( string $type, int $id, string $key, mixed $value ): bool {
				if ( ! $this->meta_writes_fail ) {
					$this->meta[ $id ][ $key ] = is_string( $value ) ? stripslashes( $value ) : $value;
				}

				return ! $this->meta_writes_fail;
			}
		);
		Functions\when( 'delete_metadata' )->alias(
			function ( string $type, int $id, string $key ): bool {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
	}

	public function test_profile_of_a_new_creator_is_empty_but_complete(): void {
		$profile = $this->service()->profile( $this->creator() );

		self::assertSame(
			array(
				'display_name' => 'Nova',
				'bio'          => '',
				'social_links' => array(
					'website'   => '',
					'instagram' => '',
					'tiktok'    => '',
					'youtube'   => '',
					'x'         => '',
					'facebook'  => '',
				),
			),
			$profile->to_array()
		);
	}

	public function test_update_changes_only_given_fields_and_writes_one_audit_record(): void {
		$creator = $this->creator();

		$profile = $this->service()->update(
			$creator,
			array(
				'bio'          => "Hello\nfrom <b>Nova</b> \\o/",
				'social_links' => array( 'instagram' => ' https://instagram.com/nova ' ),
			)
		);

		self::assertSame( 'Nova', $profile->display_name );
		self::assertSame( "Hello\nfrom Nova \\o/", $profile->bio, 'Tags stripped, line breaks and backslashes kept.' );
		self::assertSame( 'https://instagram.com/nova', $profile->social_links['instagram'] );
		self::assertSame( '', $profile->social_links['x'] );
		self::assertSame( array( 'instagram' => 'https://instagram.com/nova' ), $this->meta[ $creator->id ]['social_links'], 'Only set links are stored.' );

		$audit = $this->db->rows( 'merchboard_audit_log' );
		self::assertCount( 1, $audit );
		self::assertSame( 'creator_updated', $audit[0]['action'] );
		self::assertSame( '5', $audit[0]['actor_user_id'], 'The creator\'s own user.' );
		self::assertSame( '{"changed_fields":["bio","social_links"]}', $audit[0]['context'], 'No values in the audit record.' );

		self::assertEquals( $profile, $this->service()->profile( $creator ), 'Reads back the stored profile.' );
	}

	public function test_display_name_change_renames_the_creator(): void {
		$creator = $this->creator();

		$this->service()->update( $creator, array( 'display_name' => '  Nova Studio  ' ) );

		self::assertSame( 'Nova Studio', $this->creators->find( $creator->id )?->display_name );
		self::assertSame( '{"changed_fields":["display_name"]}', $this->db->rows( 'merchboard_audit_log' )[0]['context'] );
	}

	public function test_unchanged_values_write_nothing(): void {
		$creator = $this->creator();

		$this->service()->update( $creator, array( 'display_name' => 'Nova', 'bio' => '', 'social_links' => array( 'x' => '' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
		self::assertSame( array(), $this->meta );
	}

	public function test_clearing_values_deletes_the_meta(): void {
		$creator = $this->creator();
		$this->service()->update( $creator, array( 'bio' => 'x', 'social_links' => array( 'x' => 'https://x.com/nova' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->service()->update( $creator, array( 'bio' => '  ', 'social_links' => array( 'x' => '' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( array(), $this->meta[ $creator->id ] );
	}

	public function test_fields_outside_the_whitelist_are_ignored(): void {
		$creator = $this->creator();

		$this->service()->update(
			$creator,
			array(
				'status'     => 'inactive',
				'user_id'    => 99,
				'id'         => 42,
				'creator_id' => 42,
				'slug'       => 'x',
			)
		);

		$stored = $this->creators->find( $creator->id );
		self::assertNotNull( $stored );
		self::assertSame( CreatorStatus::Active, $stored->status );
		self::assertSame( 5, $stored->user_id );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	/**
	 * Invalid input and the expected field errors.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, string>}>
	 */
	public static function invalid_input(): array {
		return array(
			'empty display name'     => array( array( 'display_name' => '   ' ), array( 'display_name' => 'required' ) ),
			'long display name'      => array( array( 'display_name' => str_repeat( 'a', 192 ) ), array( 'display_name' => 'invalid' ) ),
			'display name array'     => array( array( 'display_name' => array( 'x' ) ), array( 'display_name' => 'invalid' ) ),
			'bio too long'           => array( array( 'bio' => str_repeat( 'æ', 1001 ) ), array( 'bio' => 'too_long' ) ),
			'bio not a string'       => array( array( 'bio' => 42 ), array( 'bio' => 'invalid' ) ),
			'links not an object'    => array( array( 'social_links' => 'https://x.com' ), array( 'social_links' => 'invalid' ) ),
			'unknown platform'       => array( array( 'social_links' => array( 'myspace' => 'https://myspace.com' ) ), array( 'social_links.myspace' => 'invalid' ) ),
			'http link'              => array( array( 'social_links' => array( 'x' => 'http://x.com/nova' ) ), array( 'social_links.x' => 'invalid_url' ) ),
			'javascript link'        => array( array( 'social_links' => array( 'x' => 'javascript:alert(1)' ) ), array( 'social_links.x' => 'invalid_url' ) ),
			'no host'                => array( array( 'social_links' => array( 'x' => 'https:///nova' ) ), array( 'social_links.x' => 'invalid_url' ) ),
			'credentials in link'    => array( array( 'social_links' => array( 'x' => 'https://a:b@x.com' ) ), array( 'social_links.x' => 'invalid_url' ) ),
			'quote in link'          => array( array( 'social_links' => array( 'x' => 'https://x.com/"onmouseover=' ) ), array( 'social_links.x' => 'invalid_url' ) ),
			'link too long'          => array( array( 'social_links' => array( 'x' => 'https://x.com/' . str_repeat( 'a', 190 ) ) ), array( 'social_links.x' => 'invalid_url' ) ),
			'link not a string'      => array( array( 'social_links' => array( 'x' => array() ) ), array( 'social_links.x' => 'invalid_url' ) ),
			'several fields at once' => array(
				array(
					'display_name' => '',
					'bio'          => str_repeat( 'a', 1001 ),
				),
				array(
					'display_name' => 'required',
					'bio'          => 'too_long',
				),
			),
		);
	}

	/**
	 * One invalid field rejects the whole update; nothing is saved.
	 *
	 * @param array<string, mixed>  $input  Input.
	 * @param array<string, string> $errors Expected errors.
	 */
	#[DataProvider( 'invalid_input' )]
	public function test_invalid_input_is_rejected_and_nothing_is_saved( array $input, array $errors ): void {
		$creator = $this->creator();

		try {
			$this->service()->update( $creator, $input + array( 'social_links' => array( 'website' => 'https://nova.example' ) ) );
			self::fail( 'Expected CreatorProfileException.' );
		} catch ( CreatorProfileException $e ) {
			self::assertSame( $errors, array_intersect_key( $e->errors(), $errors ) );
		}

		self::assertSame( array(), $this->meta );
		self::assertSame( 'Nova', $this->creators->find( $creator->id )?->display_name );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	/**
	 * Creators the service must refuse (it never trusts the caller).
	 *
	 * @return array<string, array{int, CreatorStatus, int}>
	 */
	public static function foreign_creators(): array {
		return array(
			'another user\'s creator' => array( 6, CreatorStatus::Active, 5 ),
			'inactive creator'        => array( 5, CreatorStatus::Inactive, 5 ),
			'logged out'              => array( 5, CreatorStatus::Active, 0 ),
		);
	}

	/**
	 * Authorization inside the service.
	 *
	 * @param int           $owner   User the creator is linked to.
	 * @param CreatorStatus $status  Creator status.
	 * @param int           $current Current user.
	 */
	#[DataProvider( 'foreign_creators' )]
	public function test_the_service_refuses_creators_that_are_not_the_current_users_active_creator( int $owner, CreatorStatus $status, int $current ): void {
		$creator               = $this->creators->create( 'Other', $status, $owner );
		$this->current_user_id = $current;

		try {
			$this->service()->update( $creator, array( 'bio' => 'hacked' ) );
			self::fail( 'Expected CreatorException.' );
		} catch ( CreatorException $e ) {
			self::assertSame( CreatorException::NOT_AUTHORIZED, $e->reason() );
		}

		self::assertSame( array(), $this->meta );
	}

	public function test_a_failed_meta_write_rolls_back_the_rename_and_the_audit_record(): void {
		$creator                = $this->creator();
		$this->meta_writes_fail = true;

		try {
			$this->service()->update( $creator, array( 'display_name' => 'Renamed', 'bio' => 'x' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not save creator profile.', $e->getMessage() );
		}

		self::assertSame( 'Nova', $this->creators->find( $creator->id )?->display_name );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	public function test_a_failed_audit_write_rolls_back_the_change(): void {
		$creator                  = $this->creator();
		$this->db->fail_writes_to = array( 'merchboard_audit_log' );

		$this->expectException( DatabaseException::class );

		try {
			$this->service()->update( $creator, array( 'display_name' => 'Renamed' ) );
		} finally {
			self::assertSame( 'Nova', $this->creators->find( $creator->id )?->display_name );
		}
	}

	public function test_corrupt_stored_values_are_ignored(): void {
		$creator                    = $this->creator();
		$this->meta[ $creator->id ] = array(
			'bio'          => array( 'not a string' ),
			'social_links' => array(
				'x'       => 'javascript:alert(1)',
				'website' => 'https://nova.example',
				'evil'    => 'https://evil.example',
			),
		);

		$profile = $this->service()->profile( $creator );

		self::assertSame( '', $profile->bio );
		self::assertSame( '', $profile->social_links['x'] );
		self::assertSame( 'https://nova.example', $profile->social_links['website'] );
		self::assertArrayNotHasKey( 'evil', $profile->social_links );
	}

	public function test_meta_store_accepts_only_whitelisted_keys(): void {
		$store = new CreatorMetaStore( $this->db );

		self::assertSame( 'wp_merchboard_creatormeta', $this->db->__get( 'merchboard_creatormeta' ), 'Table registered for the metadata API.' );

		$this->expectException( InvalidArgumentException::class );
		$store->get( 1, 'wp_capabilities' );
	}

	private function creator(): Creator {
		return $this->creators->create( 'Nova', CreatorStatus::Active, 5 );
	}

	private function service(): CreatorProfileService {
		return new CreatorProfileService(
			$this->creators,
			new CreatorMetaStore( $this->db ),
			new AuditTrail( new AuditRepository( $this->db ) ),
			new Transaction( $this->db, new TransactionSupport( $this->db ) )
		);
	}
}
