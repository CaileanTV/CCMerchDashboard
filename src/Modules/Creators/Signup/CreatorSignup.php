<?php
/**
 * Creators who sign themselves up (ADR-091).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Signup;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\Agreement\Agreement;
use MerchBoard\Modules\Creators\Agreement\AgreementRepository;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\UserAccounts;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-091:
 * - A visitor gives a creator name, an e-mail, a channel link, confirms being
 *   18 or older and accepts the agreement. The plugin creates a WordPress
 *   user without a role and a creator who is active, not live, and must
 *   accept the agreement; the acceptance is stored. The e-mail "Confirm your
 *   e-mail and choose a password" carries WordPress' own link to set a
 *   password. Only when it is used (`after_password_reset`) does the user get
 *   the role `merchboard_creator`, and with it the dashboard.
 * - The visitor chooses their username. An e-mail or username that has an
 *   account is refused with its reason: the owner allowed the page to say
 *   so (2026-11-06), and the person can log in and sign up from there.
 * - A logged-in user who is not a creator is linked at once (their account
 *   exists already) and gets the role.
 * - Spam: a honeypot field, at least 3 seconds to fill in the form (a signed
 *   start time), at most 5 sign-ups an hour per address (a keyed hash, kept
 *   in a transient for an hour) and 3 per e-mail.
 * - An unverified sign-up is removed after 7 days with its user, only when
 *   the plugin created the user and it has no role.
 */
class CreatorSignup {

	private const MIN_SECONDS = 3;

	private const MAX_FORM_AGE = DAY_IN_SECONDS;

	private const PER_ADDRESS = 5;

	private const PER_EMAIL = 3;

	private const EXPIRES = 'P7D';

	/**
	 * Current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository   $creators    Creators.
	 * @param SignupRepository    $signups     Sign-ups.
	 * @param Agreement           $agreement   The agreement.
	 * @param AgreementRepository $agreements  Acceptances (removed with an expired sign-up).
	 * @param UserAccounts        $users       Role changes.
	 * @param AuditTrail          $audit       Audit trail.
	 * @param Transaction         $transaction Transactions.
	 * @param SignupMails         $mails       The e-mails.
	 * @param Logger              $logger      Technical log.
	 * @param Closure|null        $clock       Current time (tests).
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 */
	public function __construct(
		private CreatorRepository $creators,
		private SignupRepository $signups,
		private Agreement $agreement,
		private AgreementRepository $agreements,
		private UserAccounts $users,
		private AuditTrail $audit,
		private Transaction $transaction,
		private SignupMails $mails,
		private Logger $logger,
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Whether the page is open: switched on, and a version of the agreement
	 * is published.
	 *
	 * @throws DatabaseException When the agreement cannot be read.
	 */
	public function open(): bool {
		return SignupSettings::enabled() && null !== $this->agreement->current();
	}

	/**
	 * The signed start time of a form.
	 */
	public static function form_token(): string {
		$time = (string) time();

		return $time . '.' . hash_hmac( 'sha256', 'signup|' . $time, wp_salt( 'nonce' ) );
	}

	/**
	 * Signs a visitor or the logged-in user up.
	 *
	 * @param array<string, mixed> $input   Form input (unslashed).
	 * @param string               $address The visitor's IP address (only hashed).
	 * @return string `sent` (check your e-mail) or `linked` (the logged-in user is a creator now).
	 * @throws SignupException When the sign-up is refused (nothing is stored).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function submit( array $input, string $address ): string {
		if ( ! $this->open() ) {
			self::refuse( SignupException::CLOSED );
		}

		// The honeypot: a field people do not see. Answer as if it worked.
		if ( '' !== self::text( $input, 'website' ) ) {
			return 'sent';
		}

		$this->check_form( self::text( $input, 'started' ) );

		$name    = trim( sanitize_text_field( self::text( $input, 'name' ) ) );
		$channel = self::channel( self::text( $input, 'channel' ) );
		$user_id = get_current_user_id();

		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 60 ) {
			self::refuse( SignupException::NAME );
		}

		if ( '1' !== self::text( $input, 'adult' ) ) {
			self::refuse( SignupException::ADULT );
		}

		if ( '1' !== self::text( $input, 'agree' ) ) {
			self::refuse( SignupException::AGREEMENT );
		}

		$version = (int) self::text( $input, 'version' );

		$current = $this->agreement->current()->version ?? 0;

		if ( $current !== $version ) {
			self::refuse( SignupException::OUTDATED );
		}

		$this->limit( 'address', $address, self::PER_ADDRESS );

		if ( $user_id > 0 ) {
			$this->link_current( $user_id, $name, $channel, $version );

			return 'linked';
		}

		$email = sanitize_email( self::text( $input, 'email' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			self::refuse( SignupException::EMAIL );
		}

		$username = self::username( self::text( $input, 'username' ) );

		if ( false !== email_exists( $email ) ) {
			self::refuse( SignupException::EMAIL_TAKEN );
		}

		if ( false !== username_exists( $username ) ) {
			self::refuse( SignupException::USERNAME_TAKEN );
		}

		$this->limit( 'email', strtolower( $email ), self::PER_EMAIL );
		$this->create( $email, $username, $name, $channel, $version );

		return 'sent';
	}

	/**
	 * The e-mail is verified (the user chose their first password): the user
	 * gets the creator role, and the administrator is told
	 * (`after_password_reset`). Never throws.
	 *
	 * @param mixed $user The user (WP_User).
	 */
	public function verified( mixed $user ): void {
		$user_id = is_object( $user ) && isset( $user->ID ) && is_numeric( $user->ID ) ? (int) $user->ID : 0;

		try {
			$signup = $user_id > 0 ? $this->signups->for_user( $user_id ) : null;

			if ( null === $signup || $signup['verified'] || ! $this->signups->verify( $user_id, ( $this->clock )() ) ) {
				return;
			}

			$this->users->grant_creator_role( $user_id );
			$this->audit->record( AuditAction::CreatorSignupVerified, $signup['creator_id'] );
			$this->notify_admin( $signup['creator_id'], $signup['channel_url'] );
		} catch ( Throwable $e ) {
			$this->logger->error( 'A verified sign-up could not be completed.', array( 'error' => $e::class ) );
		}
	}

	/**
	 * Removes unverified sign-ups past their expiry with the user the plugin
	 * created (daily). Never throws.
	 */
	public function clean_up(): void {
		try {
			$expired = $this->signups->expired( ( $this->clock )() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Expired sign-ups could not be read.', $e->context() );

			return;
		}

		foreach ( $expired as $signup ) {
			try {
				$this->remove( $signup['creator_id'], $signup['user_id'] );
			} catch ( Throwable $e ) {
				$this->logger->error( 'An expired sign-up could not be removed.', array( 'error' => $e::class ) );
			}
		}
	}

	/**
	 * A new user without a role, the creator, the acceptance and the e-mail.
	 *
	 * @param string $email    E-mail.
	 * @param string $username The chosen username.
	 * @param string $name     Creator name.
	 * @param string $channel  Channel link.
	 * @param int    $version  Agreement version.
	 * @throws DatabaseException When it cannot be stored (the user is removed again).
	 */
	private function create( string $email, string $username, string $name, string $channel, int $version ): void {
		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32 ),
				'display_name' => $name,
				'role'         => '',
			)
		);

		if ( ! is_int( $user_id ) ) {
			throw new DatabaseException( 'Could not create the user of a sign-up.' );
		}

		try {
			$now        = ( $this->clock )();
			$creator_id = $this->store( $user_id, $name, $channel, $version, true, $now->add( new DateInterval( self::EXPIRES ) ) );
		} catch ( Throwable $e ) {
			self::delete_user( $user_id );
			$this->logger->error( 'A sign-up could not be stored; its user was removed again.', array( 'error' => $e::class ) );

			throw new DatabaseException( 'Could not store the sign-up.' );
		}

		$user = get_userdata( $user_id );
		$key  = false === $user ? '' : get_password_reset_key( $user );

		if ( false === $user || ! is_string( $key ) ) {
			$this->logger->error( 'The verification link of a sign-up could not be made.', array( 'creator_id' => $creator_id ) );

			return;
		}

		$this->mails->verify( $email, $name, network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' ) );
	}

	/**
	 * Links the logged-in user, who is not a creator yet.
	 *
	 * @param int    $user_id User.
	 * @param string $name    Creator name.
	 * @param string $channel Channel link.
	 * @param int    $version Agreement version.
	 * @throws SignupException When the user is a creator already.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function link_current( int $user_id, string $name, string $channel, int $version ): void {
		if ( null !== $this->creators->find_by_user_id( $user_id ) ) {
			self::refuse( SignupException::ALREADY );
		}

		$creator_id = $this->store( $user_id, $name, $channel, $version, false, null );
		$this->users->grant_creator_role( $user_id );
		$this->notify_admin( $creator_id, $channel );
	}

	/**
	 * The creator, the acceptance and the sign-up in one transaction.
	 *
	 * @param int                    $user_id      User.
	 * @param string                 $name         Creator name.
	 * @param string                 $channel      Channel link.
	 * @param int                    $version      Agreement version.
	 * @param bool                   $created_user Whether the plugin created the user.
	 * @param DateTimeImmutable|null $expires_at   Expiry of an unverified sign-up; null when verified.
	 * @return int The creator.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function store( int $user_id, string $name, string $channel, int $version, bool $created_user, ?DateTimeImmutable $expires_at ): int {
		return $this->transaction->run(
			function () use ( $user_id, $name, $channel, $version, $created_user, $expires_at ): int {
				$now     = ( $this->clock )();
				$creator = $this->creators->create( $name, CreatorStatus::Active, $user_id, true );

				// The version was checked against the current one; Agreement::accept() would open a nested transaction.
				$this->agreements->accept( $creator->id, $user_id, $version, true, $now );
				$this->signups->insert( $creator->id, $user_id, $channel, $created_user, $now, $expires_at );
				$this->audit->record( AuditAction::CreatorSignedUp, $creator->id, array( 'existing_user' => ! $created_user ) );
				$this->audit->record( AuditAction::AgreementAccepted, $creator->id, array( 'version' => $version ) );

				return $creator->id;
			}
		);
	}

	/**
	 * Removes an expired sign-up: the creator, its acceptances and the user.
	 *
	 * @param int $creator_id Creator.
	 * @param int $user_id    User the plugin created.
	 * @throws DatabaseException When it cannot be removed.
	 */
	private function remove( int $creator_id, int $user_id ): void {
		$user = get_userdata( $user_id );

		// A user who got a role meanwhile (e.g. made a customer) is not ours to delete.
		if ( false !== $user && array() !== array_filter( (array) $user->roles ) ) {
			$this->signups->delete( $creator_id );

			return;
		}

		$this->transaction->run(
			function () use ( $creator_id ): void {
				$this->agreements->delete_for_unverified( $creator_id );
				$this->signups->delete( $creator_id );
				$this->creators->delete( $creator_id );
				$this->audit->record( AuditAction::CreatorSignupExpired, null, array( 'creator_id' => $creator_id ) );
			}
		);

		if ( false !== $user ) {
			self::delete_user( $user_id );
		}
	}

	/**
	 * Tells the administrator about a new creator.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $channel    Channel link.
	 */
	private function notify_admin( int $creator_id, string $channel ): void {
		do_action( 'merchboard_notify', 'admin_creator_signed_up', $creator_id, array( 'channel' => $channel ), 'signup:' . $creator_id );
	}

	/**
	 * Refuses a form that is not from the page, too old, or filled in too
	 * fast for a person.
	 *
	 * @param string $token The signed start time.
	 * @throws SignupException When it is refused.
	 */
	private function check_form( string $token ): void {
		[ $time, $signature ] = array_pad( explode( '.', $token, 2 ), 2, '' );

		if ( ! ctype_digit( $time ) || ! hash_equals( hash_hmac( 'sha256', 'signup|' . $time, wp_salt( 'nonce' ) ), $signature ) ) {
			self::refuse( SignupException::EXPIRED_FORM );
		}

		$elapsed = ( $this->clock )()->getTimestamp() - (int) $time;

		if ( $elapsed > self::MAX_FORM_AGE ) {
			self::refuse( SignupException::EXPIRED_FORM );
		}

		if ( $elapsed < self::MIN_SECONDS ) {
			self::refuse( SignupException::TOO_FAST );
		}
	}

	/**
	 * Counts a sign-up against a limit per hour.
	 *
	 * @param string $kind  'address' or 'email'.
	 * @param string $value The address or e-mail (only a keyed hash is kept).
	 * @param int    $max   Most per hour.
	 * @throws SignupException When the limit is reached.
	 */
	private function limit( string $kind, string $value, int $max ): void {
		$key   = 'merchboard_signup_' . substr( hash_hmac( 'sha256', $kind . '|' . $value, wp_salt( 'nonce' ) ), 0, 32 );
		$count = get_transient( $key );
		$count = is_numeric( $count ) ? (int) $count : 0;

		if ( $count >= $max ) {
			self::refuse( SignupException::TOO_MANY );
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}

	/**
	 * A channel link: an https URL of at most 255 characters.
	 *
	 * @param string $value Input.
	 * @throws SignupException When it is not one.
	 */
	private static function channel( string $value ): string {
		$url = esc_url_raw( trim( $value ), array( 'https' ) );

		if ( '' === $url || strlen( $url ) > 255 || ! str_starts_with( $url, 'https://' ) ) {
			self::refuse( SignupException::CHANNEL );
		}

		return $url;
	}

	/**
	 * A username as WordPress allows it (letters, digits and _ . - @),
	 * 3 to 60 characters, unchanged by the sanitizing.
	 *
	 * @param string $value Input.
	 * @throws SignupException When it is not one.
	 */
	private static function username( string $value ): string {
		$value    = trim( $value );
		$username = sanitize_user( $value, true );

		if ( $username !== $value || strlen( $username ) < 3 || strlen( $username ) > 60 ) {
			self::refuse( SignupException::USERNAME );
		}

		return $username;
	}

	/**
	 * Deletes a user the plugin created.
	 *
	 * @param int $user_id User.
	 */
	private static function delete_user( int $user_id ): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		wp_delete_user( $user_id );
	}

	/**
	 * A text field of the input.
	 *
	 * @param array<string, mixed> $input Input.
	 * @param string               $key   Field.
	 */
	private static function text( array $input, string $key ): string {
		return is_scalar( $input[ $key ] ?? null ) ? (string) $input[ $key ] : '';
	}

	/**
	 * Refuses the request.
	 *
	 * @param string $reason One of SignupException's constants.
	 * @throws SignupException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new SignupException( $reason );
	}
}
