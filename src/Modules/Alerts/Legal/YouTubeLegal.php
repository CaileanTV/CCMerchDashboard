<?php
/**
 * The privacy policy and terms of the YouTube chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Legal;

use Closure;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotException;

defined( 'ABSPATH' ) || exit;

/**
 * The texts Google requires for the YouTube chatbot (ADR-071): a privacy
 * policy and terms of service, in English and Danish, editable by the
 * administrator and shown on public pages (LegalPage).
 *
 * - **Placeholders:** `[COMPANY]`, `[CVR]`, `[ADDRESS]` and `[EMAIL]` are
 *   filled from the settings when a page is shown (CompanyDetails). Until
 *   every placeholder in the texts has a value and the texts are saved, they
 *   are a draft (version 0): the pages answer 404 and YouTube cannot be
 *   connected. The shipped drafts are YouTubeLegalDefaults.
 * - **Version:** the first publication is version 1; a later save raises
 *   the version only when everyone must accept again, so a correction does
 *   not. Users accept a version (YouTubeTerms).
 * - **Storage:** an option without autoload; HTML is limited to
 *   `wp_kses_post()` when saved and again when shown. Changes require
 *   `merchboard_manage_alerts` and are audited without the text.
 */
final class YouTubeLegal {

	/**
	 * Option (no autoload) holding the texts and the version.
	 */
	public const OPTION = 'merchboard_alert_youtube_legal';

	/**
	 * The documents.
	 */
	public const DOCUMENTS = array( 'privacy', 'terms' );

	/**
	 * The languages; the first is the default (the URLs given to Google).
	 */
	public const LANGUAGES = array( 'en', 'da' );

	/**
	 * Placeholders of the drafts that must be replaced before publication.
	 */
	public const PLACEHOLDERS = array( '[COMPANY]', '[CVR]', '[ADDRESS]', '[EMAIL]' );

	/**
	 * Longest text in characters.
	 */
	private const MAX_LENGTH = 100000;

	/**
	 * The dashboard URL of a path.
	 *
	 * @var Closure(string): string
	 */
	private Closure $dashboard_url;

	/**
	 * Constructor.
	 *
	 * @param AuditTrail  $audit         Audit trail.
	 * @param Transaction $transaction   Database transaction.
	 * @param Closure     $dashboard_url The dashboard URL of a path.
	 *
	 * @phpstan-param Closure(string): string $dashboard_url
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction, Closure $dashboard_url ) {
		$this->dashboard_url = $dashboard_url;
	}

	/**
	 * A document in a language as edited (the stored text, or the draft),
	 * with its placeholders, limited to `wp_kses_post()`.
	 *
	 * @param string $document 'privacy' or 'terms'.
	 * @param string $language 'en' or 'da'.
	 */
	public function text( string $document, string $language ): string {
		$stored = $this->stored()['texts'][ self::key( $document, $language ) ] ?? '';

		return wp_kses_post( '' !== $stored ? $stored : YouTubeLegalDefaults::text( $document, $language ) );
	}

	/**
	 * A document in a language as shown: every placeholder with a registered
	 * value is replaced by it (CompanyDetails, escaped); one without stays.
	 *
	 * @param string $document 'privacy' or 'terms'.
	 * @param string $language 'en' or 'da'.
	 */
	public function rendered( string $document, string $language ): string {
		$values = array_filter( CompanyDetails::values(), static fn( string $value ): bool => '' !== $value );

		return wp_kses_post( strtr( $this->text( $document, $language ), array_map( 'esc_html', $values ) ) );
	}

	/**
	 * The published version (0 while the texts are a draft).
	 */
	public function version(): int {
		return $this->stored()['version'];
	}

	/**
	 * Whether the texts are published.
	 */
	public function published(): bool {
		return $this->version() > 0;
	}

	/**
	 * When the current version was published (Unix time, 0 for none).
	 */
	public function published_at(): int {
		return $this->stored()['published_at'];
	}

	/**
	 * The placeholders in the texts without a registered value (they keep
	 * the texts a draft).
	 *
	 * @return list<string>
	 */
	public function placeholders(): array {
		$texts = array();

		foreach ( self::DOCUMENTS as $document ) {
			foreach ( self::LANGUAGES as $language ) {
				$texts[] = $this->text( $document, $language );
			}
		}

		return self::placeholders_in( implode( "\n", $texts ) );
	}

	/**
	 * The public URL of a page.
	 *
	 * @param string $page     'home', 'privacy' or 'terms'.
	 * @param string $language 'en' or 'da'.
	 */
	public function url( string $page, string $language = 'en' ): string {
		return ( $this->dashboard_url )( LegalPage::path( $page, $language ) );
	}

	/**
	 * Saves the texts; publishes them when no placeholder is left.
	 *
	 * @param array<string, string> $texts  Texts by `{document}_{language}` (HTML).
	 * @param bool                  $renew  Whether everyone must accept again (a new version).
	 * @return string Outcome: legal_draft, legal_published, legal_renewed, legal_saved, legal_placeholders, legal_empty or no_changes.
	 * @throws ChatbotException When the current user may not change it.
	 * @throws DatabaseException When the audit record cannot be written (nothing is saved).
	 */
	public function save( array $texts, bool $renew ): string {
		self::authorize();

		$clean = array();

		foreach ( self::DOCUMENTS as $document ) {
			foreach ( self::LANGUAGES as $language ) {
				$key           = self::key( $document, $language );
				$clean[ $key ] = mb_substr( trim( wp_kses_post( $texts[ $key ] ?? '' ) ), 0, self::MAX_LENGTH );

				if ( '' === wp_strip_all_tags( $clean[ $key ] ) ) {
					return 'legal_empty';
				}
			}
		}

		$stored  = $this->stored();
		$pending = array() !== self::placeholders_in( implode( "\n", $clean ) );

		// Published texts are never turned back into a draft.
		if ( $pending && $stored['version'] > 0 ) {
			return 'legal_placeholders';
		}

		$publish = ! $pending && ( 0 === $stored['version'] || $renew );

		if ( ! $publish && $clean === $stored['texts'] ) {
			return 'no_changes';
		}

		$version = $publish ? $stored['version'] + 1 : $stored['version'];

		$this->transaction->run(
			function () use ( $version, $publish ): void {
				$this->audit->record(
					AuditAction::AlertYouTubeLegalChanged,
					null,
					array(
						'version' => $version,
						'renewed' => $publish,
					)
				);
			}
		);
		update_option(
			self::OPTION,
			array(
				'texts'        => $clean,
				'version'      => $version,
				'published_at' => $publish ? time() : $stored['published_at'],
			),
			false
		);

		// Renewed terms: the creators with a YouTube channel must accept them again.
		if ( $publish && $version > 1 ) {
			/**
			 * Fires after the YouTube chatbot's terms were renewed (ADR-079).
			 *
			 * @param int $version The new version.
			 */
			do_action( 'merchboard_youtube_terms_renewed', $version );
		}

		return match ( true ) {
			$pending => 'legal_draft',
			$publish && 1 === $version => 'legal_published',
			$publish => 'legal_renewed',
			default => 'legal_saved',
		};
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws ChatbotException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new ChatbotException( ChatbotException::NOT_AUTHORIZED );
		}
	}

	/**
	 * The field key of a document in a language.
	 *
	 * @param string $document Document.
	 * @param string $language Language.
	 */
	public static function key( string $document, string $language ): string {
		return $document . '_' . $language;
	}

	/**
	 * The placeholders in a text that have no registered value.
	 *
	 * @param string $text Text.
	 * @return list<string>
	 */
	private static function placeholders_in( string $text ): array {
		$values = CompanyDetails::values();

		return array_values( array_filter( self::PLACEHOLDERS, static fn( string $placeholder ): bool => str_contains( $text, $placeholder ) && '' === ( $values[ $placeholder ] ?? '' ) ) );
	}

	/**
	 * The stored values, normalized.
	 *
	 * @return array{texts: array<string, string>, version: int, published_at: int}
	 */
	private function stored(): array {
		$raw   = get_option( self::OPTION, array() );
		$raw   = is_array( $raw ) ? $raw : array();
		$texts = array();

		foreach ( self::DOCUMENTS as $document ) {
			foreach ( self::LANGUAGES as $language ) {
				$key           = self::key( $document, $language );
				$texts[ $key ] = is_array( $raw['texts'] ?? null ) && is_string( $raw['texts'][ $key ] ?? null ) ? $raw['texts'][ $key ] : '';
			}
		}

		return array(
			'texts'        => $texts,
			'version'      => is_int( $raw['version'] ?? null ) ? max( 0, $raw['version'] ) : 0,
			'published_at' => is_int( $raw['published_at'] ?? null ) ? $raw['published_at'] : 0,
		);
	}
}
