<?php
/**
 * The creators' stored platform tokens.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use Closure;
use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * A creator's access and refresh token on a platform that needs them
 * (Kick: ADR-069 pkt. 5; YouTube: ADR-070 pkt. 4, the owner's approvals
 * 2026-10-20). The tokens are stored encrypted with ChatCipher (the
 * platform's domain) in `merchboard_alert_chatbot`. Plain tokens exist only
 * in memory while a request to the platform is made: they are never
 * returned to a browser, logged or audited. The access token is renewed
 * shortly before it expires; the new pair is stored in one write, so a
 * rotated refresh token is never lost (a platform that keeps the refresh
 * token, like Google, returns '' and the stored one stays).
 */
final class CreatorTokens {

	/**
	 * An access token is renewed this long before it expires (s).
	 */
	private const MARGIN = 300;

	/**
	 * Renews tokens: refresh token to new tokens.
	 *
	 * @var Closure(string): array{access: string, refresh: string, expires_in: int}
	 */
	private Closure $refresh;

	/**
	 * Revokes a token at the platform: (token, 'access_token'|'refresh_token').
	 *
	 * @var Closure(string, string): void
	 */
	private Closure $revoke;

	/**
	 * Constructor.
	 *
	 * @param ChatPlatform             $platform Platform.
	 * @param ChatCipher               $cipher   Encryption (the platform's domain).
	 * @param ChatbotChannelRepository $channels Storage.
	 * @param Closure                  $refresh  Renews tokens; throws ChatbotException INVALID_CREDENTIALS when the platform refuses the refresh token.
	 * @param Closure                  $revoke   Revokes a token; failures are ignored.
	 *
	 * @phpstan-param Closure(string): array{access: string, refresh: string, expires_in: int} $refresh
	 * @phpstan-param Closure(string, string): void $revoke
	 */
	public function __construct( private ChatPlatform $platform, private ChatCipher $cipher, private ChatbotChannelRepository $channels, Closure $refresh, Closure $revoke ) {
		$this->refresh = $refresh;
		$this->revoke  = $revoke;
	}

	/**
	 * Stores a creator's tokens.
	 *
	 * @param int                                                     $creator_id Creator.
	 * @param array{access: string, refresh: string, expires_in: int} $tokens     Tokens from the platform.
	 * @throws ChatbotException When they cannot be encrypted (no sodium).
	 * @throws DatabaseException When they cannot be stored.
	 */
	public function store( int $creator_id, array $tokens ): void {
		$this->channels->store_tokens( $creator_id, $this->platform, $this->encrypt( $tokens['access'] ), $this->encrypt( $tokens['refresh'] ), time() + max( 60, $tokens['expires_in'] ) );
	}

	/**
	 * A valid access token of the creator: the stored one, or a renewed one
	 * when it expires soon, cannot be read or $fresh asks for it.
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $fresh      Renew it (after the platform refused it).
	 * @throws ChatbotException UNAUTHORIZED when there is no token or the platform refuses the refresh token (the creator revoked the access); others when the platform cannot be reached.
	 * @throws DatabaseException When the tokens cannot be read or stored.
	 */
	public function access( int $creator_id, bool $fresh = false ): string {
		$stored  = $this->channels->tokens( $creator_id, $this->platform );
		$access  = null === $stored ? null : $this->cipher->decrypt( $stored['access'] );
		$refresh = self::require_refresh( null === $stored ? null : $this->cipher->decrypt( $stored['refresh'] ) );

		if ( ! $fresh && null !== $stored && null !== $access && $stored['expires'] > time() + self::MARGIN ) {
			return $access;
		}

		$tokens = $this->renew( $refresh );

		if ( '' === $tokens['refresh'] ) {
			$tokens['refresh'] = $refresh;
		}

		$this->store( $creator_id, $tokens );

		return $tokens['access'];
	}

	/**
	 * Revokes a creator's tokens at the platform (before they are
	 * forgotten). Failures are ignored: the tokens are deleted anyway.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the tokens cannot be read.
	 */
	public function revoke( int $creator_id ): void {
		$stored = $this->channels->tokens( $creator_id, $this->platform );

		if ( null === $stored ) {
			return;
		}

		foreach ( array(
			'refresh_token' => $stored['refresh'],
			'access_token'  => $stored['access'],
		) as $hint => $ciphertext ) {
			$token = $this->cipher->decrypt( $ciphertext );

			if ( null !== $token ) {
				( $this->revoke )( $token, $hint );
			}
		}
	}

	/**
	 * New tokens for a refresh token.
	 *
	 * @param string $refresh Refresh token.
	 * @return array{access: string, refresh: string, expires_in: int}
	 * @throws ChatbotException UNAUTHORIZED when the platform refuses the refresh token; others when it cannot be reached.
	 */
	private function renew( string $refresh ): array {
		try {
			return ( $this->refresh )( $refresh );
		} catch ( ChatbotException $e ) {
			throw new ChatbotException( ChatbotException::INVALID_CREDENTIALS === $e->reason() ? ChatbotException::UNAUTHORIZED : $e->reason(), $e->status() );
		}
	}

	/**
	 * Requires a readable refresh token.
	 *
	 * @param string|null $refresh Refresh token.
	 * @throws ChatbotException UNAUTHORIZED when there is none.
	 */
	private static function require_refresh( ?string $refresh ): string {
		if ( null === $refresh ) {
			throw new ChatbotException( ChatbotException::UNAUTHORIZED );
		}

		return $refresh;
	}

	/**
	 * Encrypts a token.
	 *
	 * @param string $plain Token.
	 * @throws ChatbotException When it cannot be encrypted (no sodium).
	 */
	private function encrypt( string $plain ): string {
		$encrypted = $this->cipher->encrypt( $plain );

		if ( null === $encrypted ) {
			throw new ChatbotException( ChatbotException::UNREADABLE );
		}

		return $encrypted;
	}
}
