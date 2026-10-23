<?php
/**
 * A creator's webhook.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Webhooks;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A webhook as stored (ADR-073): the address and the signing secret are
 * encrypted (AlertWebhooks reads them); the host and a short hint are for
 * the lists. Times are UTC.
 */
final readonly class AlertWebhook {

	public const JSON    = 'json';
	public const DISCORD = 'discord';

	/**
	 * Constructor.
	 *
	 * @param int                    $id               Id.
	 * @param int                    $creator_id       Creator.
	 * @param string                 $name             The creator's name for it.
	 * @param string                 $format           JSON or DISCORD.
	 * @param string                 $url_encrypted    The address, encrypted.
	 * @param string                 $url_host         The address' host.
	 * @param string                 $url_hint         The host and the end of the address.
	 * @param string|null            $secret_encrypted The signing secret, encrypted; null for Discord.
	 * @param string                 $template         Discord's message template ('' for the default).
	 * @param bool                   $enabled          Switched on.
	 * @param int                    $failures         Failed deliveries in a row.
	 * @param DateTimeImmutable|null $paused_at        Paused after too many failures; null while it works.
	 * @param DateTimeImmutable|null $last_delivery_at The last delivery; null before the first.
	 * @param DateTimeImmutable      $created_at       Added.
	 */
	public function __construct(
		public int $id,
		public int $creator_id,
		public string $name,
		public string $format,
		public string $url_encrypted,
		public string $url_host,
		public string $url_hint,
		public ?string $secret_encrypted,
		public string $template,
		public bool $enabled,
		public int $failures,
		public ?DateTimeImmutable $paused_at,
		public ?DateTimeImmutable $last_delivery_at,
		public DateTimeImmutable $created_at
	) {
	}

	/**
	 * Whether purchases are sent to it.
	 */
	public function is_active(): bool {
		return $this->enabled && null === $this->paused_at;
	}

	/**
	 * The webhook for the creator's list: never the address or the secret.
	 *
	 * @return array{id: int, name: string, format: string, host: string, hint: string, template: string, enabled: bool, paused: bool, failures: int, created_at: string, last_delivery_at: string|null}
	 */
	public function to_array(): array {
		return array(
			'id'               => $this->id,
			'name'             => $this->name,
			'format'           => $this->format,
			'host'             => $this->url_host,
			'hint'             => $this->url_hint,
			'template'         => $this->template,
			'enabled'          => $this->enabled,
			'paused'           => null !== $this->paused_at,
			'failures'         => $this->failures,
			'created_at'       => $this->created_at->format( DATE_ATOM ),
			'last_delivery_at' => $this->last_delivery_at?->format( DATE_ATOM ),
		);
	}
}
