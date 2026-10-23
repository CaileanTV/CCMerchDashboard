<?php
/**
 * The address of a webhook.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Which addresses a webhook may have (ADR-073): only `https` on the default
 * port, no user name or password in the address, a named host (never an IP
 * address, `localhost` or a local name such as `*.local`) and at most
 * MAX_LENGTH characters. A Discord webhook must be one of Discord's own
 * webhook addresses. Sending also goes through `wp_safe_remote_post()`,
 * which refuses hosts that resolve to private addresses.
 */
final class WebhookUrl {

	/**
	 * Longest address.
	 */
	public const MAX_LENGTH = 500;

	/**
	 * Discord's hosts.
	 */
	private const DISCORD_HOSTS = array( 'discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com' );

	/**
	 * The checked address with its host and a hint for the lists, or null.
	 *
	 * @param string $url    Address.
	 * @param string $format AlertWebhook::JSON or AlertWebhook::DISCORD.
	 * @return array{url: string, host: string, hint: string}|null
	 */
	public static function check( string $url, string $format ): ?array {
		$url = trim( $url );

		if ( '' === $url || strlen( $url ) > self::MAX_LENGTH || 1 === preg_match( '/[\s\x00-\x1f\x7f]/', $url ) ) {
			return null;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! in_array( $parts['port'] ?? 443, array( 443 ), true ) ) {
			return null;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( ! self::public_host( $host ) ) {
			return null;
		}

		if ( AlertWebhook::DISCORD === $format && ( ! in_array( $host, self::DISCORD_HOSTS, true ) || ! str_starts_with( (string) ( $parts['path'] ?? '' ), '/api/webhooks/' ) ) ) {
			return null;
		}

		return array(
			'url'  => $url,
			'host' => $host,
			'hint' => mb_substr( $host, 0, 19 ) . '…' . substr( $url, -4 ),
		);
	}

	/**
	 * Whether a host is a public name.
	 *
	 * @param string $host Lower-case host.
	 */
	private static function public_host( string $host ): bool {
		if ( '' === $host || str_starts_with( $host, '[' ) || false !== filter_var( $host, FILTER_VALIDATE_IP ) || 1 !== preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host ) ) {
			return false;
		}

		foreach ( array( '.local', '.localhost', '.internal', '.lan', '.home' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return false;
			}
		}

		// A host of digits only (an IP address without dots in any part) is not a name.
		return 1 === preg_match( '/[a-z]/', (string) strrchr( $host, '.' ) );
	}
}
