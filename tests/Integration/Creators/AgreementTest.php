<?php
/**
 * Integration tests of the creators' agreement (ADR-092).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Creators;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Admin\AgreementSettingsSection;
use MerchBoard\Modules\Creators\Agreement\Agreement;
use MerchBoard\Modules\Creators\Agreement\AgreementException;
use MerchBoard\Modules\Creators\Agreement\AgreementRepository;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\Rest\MeAgreementController;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Only creators who signed up accept the agreement: the first version and
 * every material one, never for someone viewing as them, and with the
 * confirmation of being 18 or older. Payouts wait for it.
 */
#[CoversClass( Agreement::class )]
#[CoversClass( AgreementRepository::class )]
#[CoversClass( MeAgreementController::class )]
#[CoversClass( AgreementSettingsSection::class )]
final class AgreementTest extends IntegrationTestCase {

	private Agreement $agreement;

	private int $user = 0;

	private ?Closure $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		wp_set_current_user( 1 );

		$this->agreement = $this->service( Agreement::class );
		$this->user      = $this->create_user( 'signed' );
		$controller      = new MeAgreementController( $this->service( CreatorContext::class ), $this->agreement, $this->service( Logger::class ) );
		$this->register  = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		delete_option( Agreement::DRAFT_OPTION );
		delete_option( 'merchboard_settings' );
		delete_user_meta( 1, ImpersonationSession::META );
		parent::tearDown();
	}

	public function test_publishing_needs_the_capability_a_text_and_values_for_its_placeholders(): void {
		wp_set_current_user( $this->user );
		$this->refused( AgreementException::NOT_AUTHORIZED, fn() => $this->agreement->publish( '<p>Text</p>', false ) );

		wp_set_current_user( 1 );
		$this->refused( AgreementException::EMPTY_TEXT, fn() => $this->agreement->publish( '<p> </p>', false ) );
		$this->refused( AgreementException::PLACEHOLDERS, fn() => $this->agreement->publish( '<p>CVR-nr. [CVR]</p>', false ) );
		self::assertNull( $this->agreement->current() );
		self::assertStringContainsString( 'Samarbejdsaftale for creators', $this->agreement->draft(), 'The plugin\'s draft until something is saved.' );

		self::assertSame( 1, $this->agreement->publish( '<p>Version one</p><script>alert(1)</script>', false ) );
		$current = $this->agreement->current();
		self::assertNotNull( $current );
		self::assertSame( array( 1, '<p>Version one</p>alert(1)', true ), array( $current->version, $current->body, $current->material ), 'Sanitized; the first version is always material.' );
		self::assertSame( 1, $this->audit_count( 'agreement_published' ) );

		update_option( 'merchboard_settings', array( 'company_name' => 'Merch ApS' ) );
		$this->agreement->publish( '<p>Between [COMPANY] and you</p>', false );
		update_option( 'merchboard_settings', array( 'company_name' => 'Other ApS' ) );
		self::assertSame( '<p>Between Merch ApS and you</p>', $this->agreement->current()?->body, 'A version keeps the text that was accepted (review 32.7).' );
		self::assertStringContainsString( '[COMPANY]', $this->agreement->draft(), 'The draft keeps the placeholder.' );
	}

	public function test_only_creators_who_signed_up_accept_the_first_and_material_versions(): void {
		$signed   = $this->signed_up();
		$existing = $this->service( CreatorService::class )->create( 'Existing', CreatorStatus::Active, null );

		self::assertSame( array( false, true ), array( $this->agreement->satisfied( $signed ), $this->agreement->satisfied( $existing ) ), 'No agreement yet: a creator who signed up cannot go on.' );

		$this->agreement->publish( '<p>One</p>', false );
		self::assertTrue( $this->agreement->status( $signed )['must_accept'] );
		self::assertFalse( $this->agreement->status( $existing )['must_accept'], 'Existing creators accept nothing (ADR-092).' );

		$this->refused( AgreementException::ADULT_REQUIRED, fn() => $this->agreement->accept( $signed, $this->user, 1, false ) );
		$this->refused( AgreementException::OUTDATED, fn() => $this->agreement->accept( $signed, $this->user, 2, true ) );
		$this->agreement->accept( $signed, $this->user, 1, true );
		$this->agreement->accept( $signed, $this->user, 1, true );
		self::assertSame( 1, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE creator_id = %d', $this->wpdb->prefix . Tables::AGREEMENT_ACCEPTANCES, $signed->id ) ), 'Accepting again stores nothing.' );
		self::assertTrue( $this->agreement->satisfied( $signed ) );

		$this->agreement->publish( '<p>Two</p>', false );
		self::assertSame( array( 2, 1, false, true ), array_values( array_intersect_key( $this->agreement->status( $signed ), array_flip( array( 'must_accept', 'satisfied', 'accepted', 'current' ) ) ) ), 'A minor version is only announced.' );

		$this->agreement->publish( '<p>Three</p>', true );
		self::assertTrue( $this->agreement->status( $signed )['must_accept'], 'A material version is accepted again.' );
		self::assertFalse( $this->agreement->satisfied( $signed ) );
		self::assertSame( array( 'account_agreement_updated', 'account_agreement_updated' ), $this->notifications( $signed->id ), 'Versions 2 and 3 are announced to the creator; existing creators hear nothing.' );
		self::assertSame( array(), $this->notifications( $existing->id ) );
	}

	public function test_nobody_accepts_for_a_creator_while_viewing_as_them(): void {
		$signed = $this->signed_up();
		$this->agreement->publish( '<p>One</p>', false );
		update_user_meta(
			1,
			ImpersonationSession::META,
			array(
				'creator_id' => $signed->id,
				'started_at' => time(),
				'expires_at' => time() + 600,
				'token'      => ImpersonationSession::token(),
			)
		);

		$this->refused( AgreementException::IMPERSONATING, fn() => $this->agreement->accept( $signed, 1, 1, true ) );
	}

	public function test_the_creator_reads_and_accepts_it_in_the_dashboard_and_payouts_wait_for_it(): void {
		$signed = $this->signed_up();
		$this->agreement->publish( '<p>Read me</p>', false );
		wp_set_current_user( $this->user );

		$shown = $this->call( 'GET' );
		self::assertSame( 200, $shown->get_status() );
		self::assertSame( array( true, true, false, 0, 1, '<p>Read me</p>' ), array( $shown->get_data()['required'], $shown->get_data()['must_accept'], $shown->get_data()['satisfied'], $shown->get_data()['accepted'], $shown->get_data()['version'], $shown->get_data()['text'] ) );

		try {
			$this->payout_requests()->request( $signed->id, 'DKK', 'all', 'bank', array(), 'key-agreement-1', $this->user );
			self::fail( 'A payout waits for the agreement.' );
		} catch ( PayoutException $e ) {
			self::assertSame( PayoutException::AGREEMENT_REQUIRED, $e->reason() );
		}

		$refused = $this->call(
			'POST',
			array(
				'version' => 1,
				'adult'   => false,
			)
		);
		self::assertSame( array( 400, 'adult_required' ), array( $refused->get_status(), $refused->get_data()['data']['reason'] ?? null ) );

		$accepted = $this->call(
			'POST',
			array(
				'version' => 1,
				'adult'   => true,
			)
		);
		self::assertSame( 200, $accepted->get_status() );
		self::assertSame( array( false, true, 1 ), array( $accepted->get_data()['must_accept'], $accepted->get_data()['satisfied'], $accepted->get_data()['accepted'] ) );
		self::assertSame( 1, $this->audit_count( 'agreement_accepted' ) );
	}

	public function test_the_settings_tab_saves_a_draft_and_publishes_versions(): void {
		$section = $this->service( AgreementSettingsSection::class );

		self::assertSame( 'saved', $section->handle( array( 'text' => '<p>Draft</p>' ) ) );
		self::assertNull( $this->agreement->current(), 'A draft is not published.' );
		self::assertSame( '<p>Draft</p>', $this->agreement->draft() );
		self::assertSame(
			'published',
			$section->handle(
				array(
					'text'    => '<p>Draft</p>',
					'publish' => '1',
				)
			)
		);
		self::assertSame(
			'agreement_placeholders',
			$section->handle(
				array(
					'text'    => '<p>[CVR]</p>',
					'publish' => '1',
				)
			)
		);

		ob_start();
		$section->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Published: version 1', $html );
		self::assertStringContainsString( 'name="material"', $html );
	}

	/**
	 * Payout requests with the agreement's gate, as the WooCommerce module
	 * wires it (the module needs WooCommerce, which these tests run without).
	 */
	private function payout_requests(): PayoutRequests {
		$clock      = static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$repository = new PayoutRepository( $this->wpdb );
		$creators   = new CreatorRepository( $this->wpdb );

		return new PayoutRequests(
			$repository,
			new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $repository, $clock ),
			new PayoutCipher( base64_encode( random_bytes( 32 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
			new PostMetaPricingProductStore(),
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class ),
			$clock,
			null,
			function ( int $creator_id ) use ( $creators ): bool {
				$creator = $creators->find( $creator_id );

				return null === $creator || $this->agreement->satisfied( $creator );
			}
		);
	}

	/**
	 * A creator who signed up, linked to the test user.
	 */
	private function signed_up(): Creator {
		$id = $this->service( CreatorService::class )->create( 'Signed', CreatorStatus::Active, $this->user )->id;
		$this->wpdb->update( $this->wpdb->prefix . Tables::CREATORS, array( 'agreement_required' => 1 ), array( 'id' => $id ) );
		$creator = ( new CreatorRepository( $this->wpdb ) )->find( $id );
		self::assertNotNull( $creator );

		return $creator;
	}

	/**
	 * A request to `/me/agreement`.
	 *
	 * @param string               $method Method.
	 * @param array<string, mixed> $body   Body.
	 */
	private function call( string $method, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/merchboard/v1' . MeAgreementController::ROUTE );

		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Expects a refusal with a reason.
	 *
	 * @param string   $reason    Reason.
	 * @param callable $operation Operation.
	 */
	private function refused( string $reason, callable $operation ): void {
		try {
			$operation();
			self::fail( 'Expected a refusal: ' . $reason );
		} catch ( AgreementException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}

	/**
	 * Audit records of an action.
	 *
	 * @param string $action Action.
	 */
	private function audit_count( string $action ): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action = %s', $this->wpdb->prefix . Tables::AUDIT_LOG, $action ) );
	}

	/**
	 * A creator's notifications of a new agreement version.
	 *
	 * @param int $creator_id Creator.
	 * @return list<string>
	 */
	private function notifications( int $creator_id ): array {
		return array_map( 'strval', $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT type FROM %i WHERE creator_id = %d AND type = %s ORDER BY id', $this->wpdb->prefix . Tables::NOTIFICATIONS, $creator_id, Agreement::NOTIFICATION ) ) );
	}
}
