<?php
/**
 * Creator administration end to end: real users, roles and database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Creators;

use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorException;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;

final class CreatorServiceTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install();
	}

	public function test_full_lifecycle_writes_one_audit_record_per_change_and_syncs_the_role(): void {
		$service = $this->service( CreatorService::class );
		$user_id = $this->create_user( 'nova' );

		$creator = $service->create( 'Nova Æ 🎸', CreatorStatus::Inactive, $user_id );
		self::assertSame( array( 'subscriber', Capabilities::CREATOR_ROLE ), array_values( get_userdata( $user_id )->roles ?? array() ) );

		$service->update( $creator->id, 'Nova Renamed', CreatorStatus::Active, $user_id );
		$service->set_status( $creator->id, CreatorStatus::Inactive );
		$service->update( $creator->id, 'Nova Renamed', CreatorStatus::Inactive, null );

		self::assertSame( array( 'subscriber' ), array_values( get_userdata( $user_id )->roles ?? array() ) );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame(
			array( 'creator_created', 'creator_updated', 'creator_activated', 'creator_deactivated', 'creator_user_unlinked' ),
			array_column( $audit, 'action' )
		);
		self::assertSame( array( '1' ), array_values( array_unique( array_column( $audit, 'actor_user_id' ) ) ) );
		self::assertStringNotContainsString( 'Nova', (string) wp_json_encode( $audit ), 'No display names in the audit trail.' );

		$row = $this->rows( Tables::CREATORS )[0];
		self::assertSame( array( 'Nova Renamed', 'inactive', null ), array( $row['display_name'], $row['status'], $row['user_id'] ) );
	}

	public function test_users_without_the_capability_cannot_write_anything(): void {
		$service = $this->service( CreatorService::class );
		$creator = $service->create( 'Existing', CreatorStatus::Active, null );
		$rows    = $this->rows( Tables::CREATORS );

		foreach ( array( $this->create_user( 'plain_subscriber' ), $this->linked_creator_user() ) as $user_id ) {
			wp_set_current_user( $user_id );

			foreach ( array(
				static fn() => $service->create( 'Hijack', CreatorStatus::Active, $user_id ),
				static fn() => $service->update( $creator->id, 'Hijack', CreatorStatus::Inactive, null ),
				static fn() => $service->set_status( $creator->id, CreatorStatus::Inactive ),
			) as $attempt ) {
				try {
					$attempt();
					self::fail( 'Expected CreatorException.' );
				} catch ( CreatorException $e ) {
					self::assertSame( CreatorException::NOT_AUTHORIZED, $e->reason() );
				}
			}
		}

		self::assertSame( $rows[0], $this->rows( Tables::CREATORS )[0] );
		self::assertSame( 2, $this->count_rows( Tables::CREATORS ), 'Only the admin-created ones.' );
	}

	public function test_a_user_can_be_linked_to_one_creator_only(): void {
		$service = $this->service( CreatorService::class );
		$user_id = $this->create_user( 'single' );
		$service->create( 'First', CreatorStatus::Active, $user_id );
		$second = $service->create( 'Second', CreatorStatus::Active, null );

		try {
			$service->update( $second->id, 'Second', CreatorStatus::Active, $user_id );
			self::fail( 'Expected CreatorException.' );
		} catch ( CreatorException $e ) {
			self::assertSame( CreatorException::USER_ALREADY_LINKED, $e->reason() );
		}

		try {
			$service->create( 'Ghost link', CreatorStatus::Active, 999999 );
			self::fail( 'Expected CreatorException.' );
		} catch ( CreatorException $e ) {
			self::assertSame( CreatorException::USER_NOT_FOUND, $e->reason() );
		}

		self::assertSame( 2, $this->count_rows( Tables::AUDIT_LOG ), 'Rejected changes write no audit records.' );
	}

	/**
	 * A user that is linked to a creator (has the creator role).
	 */
	private function linked_creator_user(): int {
		$user_id = $this->create_user( 'linked_creator' );
		$this->service( CreatorService::class )->create( 'Linked', CreatorStatus::Active, $user_id );

		return $user_id;
	}
}
