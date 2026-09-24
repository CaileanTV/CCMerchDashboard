<?php
/**
 * Shared WordPress stubs for creator administration tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;

/**
 * Wires the real service, repositories, audit trail and transaction to a
 * FakeDatabase, and stubs the WordPress functions they use. The current user
 * (id and capabilities) is controlled per test.
 */
trait CreatorAdminEnvironment {

	private FakeDatabase $db;

	private FakeUserAccounts $users;

	private CreatorService $service;

	/**
	 * Capabilities of the current user.
	 *
	 * @var string[]
	 */
	private array $caps = array();

	private int $current_user_id = 7;

	private string $now = '2026-09-24 12:00:00';

	/**
	 * Capabilities passed to current_user_can(), in order.
	 *
	 * @var list<string>
	 */
	private array $capability_checks = array();

	/**
	 * Sets up the environment; call from setUp() after parent::setUp().
	 */
	private function set_up_creator_admin(): void {
		$this->caps  = array( Capabilities::MANAGE_CREATORS );
		$this->db    = new FakeDatabase();
		$this->users = new FakeUserAccounts(
			array(
				42 => 'Nova (nova)',
				43 => 'Other (other)',
			)
		);

		Functions\when( 'current_user_can' )->alias(
			function ( string $capability ): bool {
				$this->capability_checks[] = $capability;
				return in_array( $capability, $this->caps, true );
			}
		);
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current_user_id );
		Functions\when( 'current_time' )->alias( fn(): string => $this->now );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->service = new CreatorService(
			new CreatorRepository( $this->db ),
			new AuditTrail( new AuditRepository( $this->db ) ),
			new Transaction( $this->db, new TransactionSupport( $this->db ) ),
			$this->users
		);
	}

	/**
	 * Audit rows with decoded context.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function audit_rows(): array {
		return array_map(
			static function ( array $row ): array {
				$row['context'] = null === $row['context'] ? null : json_decode( (string) $row['context'], true );
				return $row;
			},
			$this->db->rows( 'merchboard_audit_log' )
		);
	}

	/**
	 * Audit action values in order.
	 *
	 * @return list<string|null>
	 */
	private function audit_actions(): array {
		return array_column( $this->db->rows( 'merchboard_audit_log' ), 'action' );
	}
}
