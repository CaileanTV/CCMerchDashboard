<?php
/**
 * Integration tests of creators who sign themselves up (ADR-091).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Creators;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\Agreement\Agreement;
use MerchBoard\Modules\Creators\Agreement\AgreementRepository;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\Signup\CreatorSignup;
use MerchBoard\Modules\Creators\Signup\SignupException;
use MerchBoard\Modules\Creators\Signup\SignupMails;
use MerchBoard\Modules\Creators\Signup\SignupPage;
use MerchBoard\Modules\Creators\Signup\SignupRepository;
use MerchBoard\Modules\Creators\Signup\SignupSettings;
use MerchBoard\Modules\Creators\UserAccounts;
use MerchBoard\Modules\Notifications\Email\EmailRenderer;
use MerchBoard\Modules\Notifications\Email\Mailer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A visitor signs up, gets an e-mail and becomes a creator (not live) only
 * when choosing the first password; an existing account is never revealed;
 * spam is held off; an unverified sign-up is removed with its user.
 */
#[CoversClass( CreatorSignup::class )]
#[CoversClass( SignupRepository::class )]
#[CoversClass( SignupPage::class )]
final class CreatorSignupTest extends IntegrationTestCase {

	private int $offset = 10;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$GLOBALS['merchboard_test_mails'] = array();
		wp_set_current_user( 1 );
		$this->service( Agreement::class )->publish( '<p>Our agreement</p>', false );
		SignupSettings::save( true, 0 );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		delete_option( SignupSettings::OPTION );
		delete_option( Agreement::DRAFT_OPTION );
		$sql = $this->wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $this->wpdb->options, '%merchboard_signup_%' );

		if ( is_string( $sql ) ) {
			$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		}

		wp_cache_flush();
		parent::tearDown();
	}

	public function test_a_visitor_becomes_a_creator_only_after_choosing_a_password(): void {
		self::assertSame( 'sent', $this->signup()->submit( $this->input(), '192.0.2.1' ) );

		$user = get_user_by( 'email', 'nova@example.com' );
		self::assertNotFalse( $user );
		self::assertSame( array(), array_values( array_filter( (array) $user->roles ) ), 'No role before the e-mail is verified.' );
		$creator = ( new CreatorRepository( $this->wpdb ) )->find_by_user_id( $user->ID );
		self::assertNotNull( $creator );
		self::assertSame( array( 'Nova', true, false, true ), array( $creator->display_name, $creator->is_active(), $creator->is_live(), $creator->agreement_required ) );
		self::assertSame( 1, ( new AgreementRepository( $this->wpdb ) )->accepted( $creator->id ), 'The agreement was accepted with the sign-up.' );
		self::assertCount( 1, $GLOBALS['merchboard_test_mails'] );
		self::assertSame( 'Confirm your e-mail and choose a password', $GLOBALS['merchboard_test_mails'][0]['subject'] );
		self::assertStringContainsString( 'action=rp', (string) $GLOBALS['merchboard_test_mails'][0]['message'] );
		self::assertSame( array(), $this->rows( Tables::NOTIFICATIONS ), 'The link is never stored in the notifications.' );

		$this->signup()->verified( $user );

		$user = get_user_by( 'id', $user->ID );
		self::assertNotFalse( $user );
		self::assertContains( 'merchboard_creator', (array) $user->roles );
		self::assertSame( array( 'admin_creator_signed_up' ), array_column( $this->rows( Tables::NOTIFICATIONS ), 'type' ) );
		self::assertSame( array( 'creator_signed_up', 'agreement_accepted', 'creator_signup_verified' ), $this->audit_actions() );
	}

	public function test_an_existing_account_is_never_revealed(): void {
		$taken = $this->create_user( 'taken' );
		wp_update_user(
			array(
				'ID'         => $taken,
				'user_email' => 'taken@example.com',
			)
		);
		$GLOBALS['merchboard_test_mails'] = array();

		self::assertSame( 'sent', $this->signup()->submit( $this->input( array( 'email' => 'taken@example.com' ) ), '192.0.2.2' ), 'The same answer as for a new e-mail.' );
		self::assertSame( 'You already have an account with us', $GLOBALS['merchboard_test_mails'][0]['subject'] ?? null );
		self::assertSame( array(), $this->rows( Tables::CREATORS ) );
	}

	public function test_a_logged_in_user_is_linked_at_once(): void {
		$user = $this->create_user( 'shopper' );
		wp_set_current_user( $user );

		self::assertSame( 'linked', $this->signup()->submit( $this->input( array( 'email' => '' ) ), '192.0.2.3' ) );
		$data = get_userdata( $user );
		self::assertNotFalse( $data );
		self::assertContains( 'merchboard_creator', (array) $data->roles );
		self::assertFalse( ( new CreatorRepository( $this->wpdb ) )->find_by_user_id( $user )?->is_live() );
		$this->refused( SignupException::ALREADY, fn() => $this->signup()->submit( $this->input(), '192.0.2.3' ) );
	}

	public function test_spam_and_incomplete_forms_are_refused(): void {
		self::assertSame( 'sent', $this->signup()->submit( $this->input( array( 'website' => 'http://spam.example' ) ), '192.0.2.4' ), 'The honeypot answers as if it worked.' );
		self::assertSame( array(), $this->rows( Tables::CREATORS ) );

		$this->offset = 1;
		$this->refused( SignupException::TOO_FAST, fn() => $this->signup()->submit( $this->input(), '192.0.2.4' ) );
		$this->offset = 10;
		$this->refused( SignupException::EXPIRED_FORM, fn() => $this->signup()->submit( $this->input( array( 'started' => '123.forged' ) ), '192.0.2.4' ) );
		$this->refused( SignupException::ADULT, fn() => $this->signup()->submit( $this->input( array( 'adult' => '' ) ), '192.0.2.4' ) );
		$this->refused( SignupException::AGREEMENT, fn() => $this->signup()->submit( $this->input( array( 'agree' => '' ) ), '192.0.2.4' ) );
		$this->refused( SignupException::OUTDATED, fn() => $this->signup()->submit( $this->input( array( 'version' => '2' ) ), '192.0.2.4' ) );
		$this->refused( SignupException::CHANNEL, fn() => $this->signup()->submit( $this->input( array( 'channel' => 'http://twitch.tv/nova' ) ), '192.0.2.4' ) );
		$this->refused( SignupException::NAME, fn() => $this->signup()->submit( $this->input( array( 'name' => 'N' ) ), '192.0.2.4' ) );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->signup()->submit( $this->input( array( 'email' => "spam{$i}@example.com" ) ), '192.0.2.5' );
		}

		$this->refused( SignupException::TOO_MANY, fn() => $this->signup()->submit( $this->input( array( 'email' => 'spam6@example.com' ) ), '192.0.2.5' ) );

		SignupSettings::save( false, 0 );
		$this->refused( SignupException::CLOSED, fn() => $this->signup()->submit( $this->input(), '192.0.2.6' ) );
	}

	public function test_an_unverified_sign_up_is_removed_with_its_user_after_7_days(): void {
		$this->signup()->submit( $this->input(), '192.0.2.7' );
		$user = get_user_by( 'email', 'nova@example.com' );
		self::assertNotFalse( $user );

		$this->offset = 6 * DAY_IN_SECONDS;
		$this->signup()->clean_up();
		self::assertNotFalse( get_userdata( $user->ID ), 'Not before 7 days.' );

		$this->offset = 8 * DAY_IN_SECONDS;
		$this->signup()->clean_up();

		self::assertFalse( get_userdata( $user->ID ) );
		self::assertSame( array(), $this->rows( Tables::CREATORS ) );
		self::assertSame( 0, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->wpdb->prefix . Tables::CREATOR_SIGNUPS ) ) );
		self::assertSame( array(), $this->rows( Tables::AGREEMENT_ACCEPTANCES ) );
		self::assertContains( 'creator_signup_expired', $this->audit_actions() );
	}

	public function test_the_page_answers_only_while_open_and_checks_its_nonce(): void {
		$page = $this->service( SignupPage::class );

		self::assertSame( 'form', $page->respond( false )['state'] ?? null );

		$_POST = array( '_wpnonce' => 'forged' );
		self::assertSame( SignupException::EXPIRED_FORM, $page->respond( true )['error'] ?? null );
		$_POST = array();

		SignupSettings::save( false, 0 );
		$closed = $page->respond( false );
		self::assertSame( array( 404, 'closed' ), array( $closed['status'] ?? null, $closed['state'] ) );
	}

	/**
	 * The service with a clock `offset` seconds after now.
	 */
	private function signup(): CreatorSignup {
		return new CreatorSignup(
			new CreatorRepository( $this->wpdb ),
			new SignupRepository( $this->wpdb ),
			$this->service( Agreement::class ),
			new AgreementRepository( $this->wpdb ),
			$this->service( UserAccounts::class ),
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class ),
			new SignupMails( new EmailRenderer(), new Mailer() ),
			$this->service( Logger::class ),
			static fn(): string => 'https://example.com/creator-dashboard/opret/',
			fn(): DateTimeImmutable => ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+' . $this->offset . ' seconds' )
		);
	}

	/**
	 * A filled-in form.
	 *
	 * @param array<string, string> $changes Fields to change.
	 * @return array<string, string>
	 */
	private function input( array $changes = array() ): array {
		return array_merge(
			array(
				'name'    => 'Nova',
				'email'   => 'nova@example.com',
				'channel' => 'https://www.twitch.tv/nova',
				'adult'   => '1',
				'agree'   => '1',
				'version' => '1',
				'started' => CreatorSignup::form_token(),
				'website' => '',
			),
			$changes
		);
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
		} catch ( SignupException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}

	/**
	 * The audit actions, oldest first.
	 *
	 * @return list<string>
	 */
	private function audit_actions(): array {
		return array_map( 'strval', $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT action FROM %i WHERE action LIKE %s OR action = %s ORDER BY id', $this->wpdb->prefix . Tables::AUDIT_LOG, 'creator_sign%', 'agreement_accepted' ) ) );
	}
}
