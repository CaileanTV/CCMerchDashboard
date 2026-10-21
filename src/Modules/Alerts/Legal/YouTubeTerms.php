<?php
/**
 * Acceptance of the YouTube chatbot's privacy policy and terms.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Legal;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotException;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeAccount;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Who accepted which version of the YouTube chatbot's privacy policy and
 * terms (ADR-071). A creator accepts before adding the bot to the channel,
 * the administrator before logging in the bot account; both are stored on
 * the user (user meta `merchboard_youtube_terms_accepted`, `{version, at}`)
 * and audited with the version and the role. The bot's accepted version is
 * also kept with the bot (YouTubeAccount).
 *
 * After a new version, YouTube messages are not sent to a creator who has
 * not accepted it, and the bot is not ready until the administrator has.
 */
final class YouTubeTerms {

	/**
	 * User meta: the accepted version and when (`{version, at}`).
	 */
	public const META = 'merchboard_youtube_terms_accepted';

	/**
	 * Constructor.
	 *
	 * @param YouTubeLegal      $legal       The texts and their version.
	 * @param YouTubeAccount    $account     The Google app and the bot.
	 * @param CreatorRepository $creators    The creators' users.
	 * @param AuditTrail        $audit       Audit trail.
	 * @param Transaction       $transaction Database transaction.
	 */
	public function __construct( private YouTubeLegal $legal, private YouTubeAccount $account, private CreatorRepository $creators, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * Whether the texts are published (YouTube can only be used then).
	 */
	public function published(): bool {
		return $this->legal->published();
	}

	/**
	 * The current version (0 while a draft).
	 */
	public function version(): int {
		return $this->legal->version();
	}

	/**
	 * The public URL of a page in the site's language (Danish or English).
	 *
	 * @param string $page 'home', 'privacy' or 'terms'.
	 */
	public function url( string $page ): string {
		return $this->legal->url( $page, str_starts_with( determine_locale(), 'da' ) ? 'da' : 'en' );
	}

	/**
	 * Whether a user accepted the current version.
	 *
	 * @param int $user_id User.
	 */
	public function user_accepted( int $user_id ): bool {
		$stored = get_user_meta( $user_id, self::META, true );

		return $this->published() && is_array( $stored ) && ( $stored['version'] ?? null ) === $this->version();
	}

	/**
	 * Whether a creator (the creator's user) accepted the current version.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the creator cannot be read.
	 */
	public function creator_accepted( int $creator_id ): bool {
		$user = $this->creators->find( $creator_id )?->user_id;

		return null !== $user && $this->user_accepted( $user );
	}

	/**
	 * Whether the bot may write: the administrator accepted the current
	 * version for it.
	 */
	public function bot_accepted(): bool {
		return $this->published() && $this->account->terms_version() === $this->version();
	}

	/**
	 * A creator's user accepts the current version.
	 *
	 * @param int $creator_id Creator (from CreatorContext).
	 * @param int $user_id    The creator's user.
	 * @throws ChatbotException When the texts are not published.
	 * @throws DatabaseException When it cannot be audited (nothing is stored).
	 */
	public function accept_creator( int $creator_id, int $user_id ): void {
		$this->accept( $user_id, $creator_id, 'creator' );
	}

	/**
	 * The administrator accepts the current version for the bot.
	 *
	 * @param int $user_id The administrator.
	 * @throws ChatbotException When the texts are not published or the user may not manage the bot.
	 * @throws DatabaseException When it cannot be audited (nothing is stored).
	 */
	public function accept_bot( int $user_id ): void {
		// The capability is checked first (YouTubeAccount), so nothing is audited for a user who may not.
		$this->account->note_terms( $this->require_published() );
		$this->accept( $user_id, null, 'admin' );
	}

	/**
	 * Stores and audits an acceptance.
	 *
	 * @param int      $user_id    User.
	 * @param int|null $creator_id Creator; null for the administrator.
	 * @param string   $role       'creator' or 'admin'.
	 * @throws ChatbotException When the texts are not published.
	 * @throws DatabaseException When it cannot be audited (nothing is stored).
	 */
	private function accept( int $user_id, ?int $creator_id, string $role ): void {
		$version = $this->require_published();

		$this->transaction->run(
			function () use ( $creator_id, $role, $version ): void {
				$this->audit->record(
					AuditAction::AlertYouTubeTermsAccepted,
					$creator_id,
					array(
						'version' => $version,
						'role'    => $role,
					)
				);
			}
		);
		update_user_meta(
			$user_id,
			self::META,
			array(
				'version' => $version,
				'at'      => time(),
			)
		);
	}

	/**
	 * The current version of published texts.
	 *
	 * @throws ChatbotException TERMS_REQUIRED while the texts are a draft.
	 */
	private function require_published(): int {
		if ( ! $this->published() ) {
			throw new ChatbotException( ChatbotException::TERMS_REQUIRED );
		}

		return $this->version();
	}
}
