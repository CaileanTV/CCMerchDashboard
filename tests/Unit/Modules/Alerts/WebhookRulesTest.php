<?php
/**
 * Tests for the webhooks' address rules, signature and encryption domain.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhook;
use MerchBoard\Modules\Alerts\Webhooks\WebhookPayloads;
use MerchBoard\Modules\Alerts\Webhooks\WebhookUrl;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Step 25.12.2 (ADR-073): only public https addresses (Discord's own for a
 * Discord webhook), the signature over "timestamp.body", and the webhooks'
 * own encryption key.
 */
#[CoversClass( WebhookUrl::class )]
#[CoversClass( WebhookPayloads::class )]
#[CoversClass( ChatCipher::class )]
final class WebhookRulesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The stub of wp_parse_url().
	}

	/**
	 * Addresses a JSON webhook refuses.
	 *
	 * @return array<string, array{string}>
	 */
	public static function refused(): array {
		return array(
			'http'           => array( 'http://bot.example.com/hook' ),
			'IPv4'           => array( 'https://192.168.1.10/hook' ),
			'IPv6'           => array( 'https://[::1]/hook' ),
			'localhost'      => array( 'https://localhost/hook' ),
			'local name'     => array( 'https://nas.local/hook' ),
			'internal name'  => array( 'https://api.internal/hook' ),
			'single label'   => array( 'https://intranet/hook' ),
			'user and pass'  => array( 'https://user:pw@bot.example.com/hook' ),
			'other port'     => array( 'https://bot.example.com:8443/hook' ),
			'space'          => array( 'https://bot.example.com/a hook' ),
			'empty'          => array( '' ),
			'too long'       => array( 'https://bot.example.com/' . str_repeat( 'a', 500 ) ),
			'numeric labels' => array( 'https://1.2.3.4.5/hook' ),
		);
	}

	/**
	 * Refuses an address.
	 *
	 * @param string $url Address.
	 */
	#[DataProvider( 'refused' )]
	public function test_unsafe_addresses_are_refused( string $url ): void {
		self::assertNull( WebhookUrl::check( $url, AlertWebhook::JSON ) );
	}

	public function test_a_public_https_address_gives_its_host_and_hint(): void {
		self::assertSame(
			array(
				'url'  => 'https://bot.example.com:443/merch/hook',
				'host' => 'bot.example.com',
				'hint' => 'bot.example.com…hook',
			),
			WebhookUrl::check( '  https://bot.example.com:443/merch/hook ', AlertWebhook::JSON )
		);
	}

	public function test_discord_needs_its_own_webhook_address(): void {
		self::assertNotNull( WebhookUrl::check( 'https://discord.com/api/webhooks/1/token', AlertWebhook::DISCORD ) );
		self::assertNotNull( WebhookUrl::check( 'https://canary.discord.com/api/webhooks/1/token', AlertWebhook::DISCORD ) );
		self::assertNull( WebhookUrl::check( 'https://discord.com/channels/1/2', AlertWebhook::DISCORD ) );
		self::assertNull( WebhookUrl::check( 'https://discord.com.evil.example/api/webhooks/1/token', AlertWebhook::DISCORD ) );
		self::assertNull( WebhookUrl::check( 'https://bot.example.com/api/webhooks/1/token', AlertWebhook::DISCORD ) );
	}

	public function test_the_signature_covers_the_timestamp_and_the_body(): void {
		self::assertSame( 'sha256=' . hash_hmac( 'sha256', '1700000000.{"a":1}', 'secret' ), WebhookPayloads::signature( 'secret', 1700000000, '{"a":1}' ) );
		self::assertNotSame( WebhookPayloads::signature( 'secret', 1700000000, '{"a":1}' ), WebhookPayloads::signature( 'secret', 1700000001, '{"a":1}' ) );
	}

	public function test_the_webhooks_have_their_own_encryption_key(): void {
		$webhooks = new ChatCipher( 'webhooks', 'salt' );

		if ( ! $webhooks->available() ) {
			self::markTestSkipped( 'Sodium is not available.' );
		}

		$stored = (string) $webhooks->encrypt( 'https://discord.com/api/webhooks/1/token' );
		self::assertSame( 'https://discord.com/api/webhooks/1/token', $webhooks->decrypt( $stored ) );
		self::assertNull( ( new ChatCipher( ChatPlatform::Twitch, 'salt' ) )->decrypt( $stored ), 'Another domain cannot read it.' );
	}
}
