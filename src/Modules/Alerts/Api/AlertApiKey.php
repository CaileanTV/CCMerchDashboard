<?php
/**
 * A creator's API key of the alert API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Api;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A key as stored (ADR-073): the public lookup part and an HMAC of the
 * secret part, never the key itself. Times are UTC.
 */
final readonly class AlertApiKey {

	/**
	 * The start of every key.
	 */
	public const PREFIX = 'mbk_';

	/**
	 * Constructor.
	 *
	 * @param int                    $id           Id.
	 * @param int                    $creator_id   Creator.
	 * @param string                 $name         The creator's name for the key.
	 * @param string                 $lookup       Public lookup part (12 hex characters).
	 * @param string                 $secret_hash  HMAC-SHA256 of the secret part.
	 * @param DateTimeImmutable      $created_at   Made.
	 * @param DateTimeImmutable|null $last_used_at Last used; null when never.
	 * @param DateTimeImmutable|null $revoked_at   Revoked; null while it works.
	 */
	public function __construct(
		public int $id,
		public int $creator_id,
		public string $name,
		public string $lookup,
		public string $secret_hash,
		public DateTimeImmutable $created_at,
		public ?DateTimeImmutable $last_used_at,
		public ?DateTimeImmutable $revoked_at
	) {
	}

	/**
	 * Whether the key still works.
	 */
	public function is_active(): bool {
		return null === $this->revoked_at;
	}

	/**
	 * The key for the creator's list: the name and the start of the key
	 * only, so the creator can tell their keys apart.
	 *
	 * @return array{id: int, name: string, hint: string, created_at: string, last_used_at: string|null}
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'name'         => $this->name,
			'hint'         => self::PREFIX . $this->lookup . '_…',
			'created_at'   => $this->created_at->format( DATE_ATOM ),
			'last_used_at' => $this->last_used_at?->format( DATE_ATOM ),
		);
	}
}
