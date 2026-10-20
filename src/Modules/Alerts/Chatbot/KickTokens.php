<?php
/**
 * The creators' Kick tokens.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * A creator's Kick access and refresh token (ADR-069 pkt. 5, the owner's
 * approval 2026-10-20). Kick sends a bot message only with the
 * broadcaster's own token, so it is stored, encrypted with ChatCipher
 * (Kick's domain) in `merchboard_alert_chatbot`. Plain tokens exist only in
 * memory while a request to Kick is made: they are never returned to a
 * browser, logged or audited. The access token is renewed shortly before it
 * expires; the new pair is stored in one write, so a rotated refresh token
 * is never lost.
 */
final class KickTokens {

	/**
	 * An access token is renewed this long before it expires (s).
	 */
	private const MARGIN = 300;

	/**
	 * Constructor.
	 *
	 * @param KickAccount              $account  The Kick app.
	 * @param KickApi                  $api      Kick.
	 * @param ChatCipher               $cipher   Encryption (Kick's domain).
	 * @param ChatbotChannelRepository $channels Storage.
	 */
	public function __construct( private KickAccount $account, private KickApi $api, private ChatCipher $cipher, private ChatbotChannelRepository $channels ) {
	}

	/**
	 * Stores a creator's tokens.
	 *
	 * @param int                                                     $creator_id Creator.
	 * @param array{access: string, refresh: string, expires_in: int} $tokens     Tokens from Kick.
	 * @throws KickException When they cannot be encrypted (no sodium).
	 * @throws DatabaseException When they cannot be stored.
	 */
	public function store( int $creator_id, array $tokens ): void {
		$this->channels->store_tokens( $creator_id, ChatPlatform::Kick, $this->encrypt( $tokens['access'] ), $this->encrypt( $tokens['refresh'] ), time() + max( 60, $tokens['expires_in'] ) );
	}

	/**
	 * A valid access token of the creator: the stored one, or a renewed one
	 * when it expires soon, cannot be read or $fresh asks for it.
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $fresh      Renew it (after Kick refused it).
	 * @throws KickException UNAUTHORIZED when there is no token or Kick refuses the refresh token (the creator revoked the access); others when Kick cannot be reached.
	 * @throws DatabaseException When the tokens cannot be read or stored.
	 */
	public function access( int $creator_id, bool $fresh = false ): string {
		$stored  = $this->channels->tokens( $creator_id, ChatPlatform::Kick );
		$access  = null === $stored ? null : $this->cipher->decrypt( $stored['access'] );
		$refresh = null === $stored ? null : $this->cipher->decrypt( $stored['refresh'] );

		$refresh = self::require_refresh( null === $stored ? null : $refresh );

		if ( ! $fresh && null !== $stored && null !== $access && $stored['expires'] > time() + self::MARGIN ) {
			return $access;
		}

		$tokens = $this->renew( $refresh );
		$this->store( $creator_id, $tokens );

		return $tokens['access'];
	}

	/**
	 * New tokens for a refresh token.
	 *
	 * @param string $refresh Refresh token.
	 * @return array{access: string, refresh: string, expires_in: int}
	 * @throws KickException UNAUTHORIZED when Kick refuses the refresh token; others when Kick cannot be reached.
	 */
	private function renew( string $refresh ): array {
		try {
			return $this->api->refresh( $this->account->client_id(), $this->account->require_secret(), $refresh );
		} catch ( KickException $e ) {
			throw new KickException( KickException::INVALID_CREDENTIALS === $e->reason() ? KickException::UNAUTHORIZED : $e->reason() );
		}
	}

	/**
	 * Requires a readable refresh token.
	 *
	 * @param string|null $refresh Refresh token.
	 * @throws KickException UNAUTHORIZED when there is none.
	 */
	private static function require_refresh( ?string $refresh ): string {
		if ( null === $refresh ) {
			throw new KickException( KickException::UNAUTHORIZED );
		}

		return $refresh;
	}

	/**
	 * Encrypts a token.
	 *
	 * @param string $plain Token.
	 * @throws KickException When it cannot be encrypted (no sodium).
	 */
	private function encrypt( string $plain ): string {
		$encrypted = $this->cipher->encrypt( $plain );

		if ( null === $encrypted ) {
			throw new KickException( KickException::UNREADABLE );
		}

		return $encrypted;
	}

	/**
	 * Revokes a creator's tokens at Kick (before they are forgotten).
	 * Failures are ignored: the tokens are deleted anyway.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the tokens cannot be read.
	 */
	public function revoke( int $creator_id ): void {
		$stored = $this->channels->tokens( $creator_id, ChatPlatform::Kick );

		if ( null === $stored ) {
			return;
		}

		foreach ( array(
			'refresh_token' => $stored['refresh'],
			'access_token'  => $stored['access'],
		) as $hint => $ciphertext ) {
			$token = $this->cipher->decrypt( $ciphertext );

			if ( null !== $token ) {
				$this->api->revoke( $token, $hint );
			}
		}
	}
}
