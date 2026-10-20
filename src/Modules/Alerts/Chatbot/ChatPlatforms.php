<?php
/**
 * The platforms of the alert chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * The platform adapters by platform (ADR-069 pkt. 1), in the order the
 * dashboard and the admin page show them.
 */
final class ChatPlatforms {

	/**
	 * Adapters by platform value.
	 *
	 * @var array<string, ChatbotPlatform>
	 */
	private array $platforms = array();

	/**
	 * Constructor.
	 *
	 * @param ChatbotPlatform ...$platforms One adapter per platform.
	 */
	public function __construct( ChatbotPlatform ...$platforms ) {
		foreach ( $platforms as $platform ) {
			$this->platforms[ $platform->platform()->value ] = $platform;
		}
	}

	/**
	 * A platform's adapter.
	 *
	 * @param ChatPlatform $platform Platform.
	 * @throws InvalidArgumentException When the platform has no adapter (a programming error).
	 */
	public function get( ChatPlatform $platform ): ChatbotPlatform {
		return $this->platforms[ $platform->value ] ?? throw new InvalidArgumentException( 'No chatbot adapter for ' . $platform->value . '.' );
	}

	/**
	 * Every adapter.
	 *
	 * @return list<ChatbotPlatform>
	 */
	public function all(): array {
		return array_values( $this->platforms );
	}

	/**
	 * The adapters an administrator has set up.
	 *
	 * @return list<ChatbotPlatform>
	 */
	public function ready(): array {
		return array_values( array_filter( $this->platforms, static fn( ChatbotPlatform $platform ): bool => $platform->ready() ) );
	}

	/**
	 * Whether a URL can receive webhooks from a platform: HTTPS on port 443,
	 * not a local host. Elsewhere (e.g. Local) the admin page shows the
	 * platform's own chat instead.
	 *
	 * @param string $url Webhook URL.
	 */
	public static function reachable( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port = wp_parse_url( $url, PHP_URL_PORT );

		return str_starts_with( $url, 'https://' ) && ( null === $port || 443 === $port ) && 1 !== preg_match( '/(^localhost$|\.local$|\.test$|^127\.|^10\.|^192\.168\.)/', $host );
	}
}
