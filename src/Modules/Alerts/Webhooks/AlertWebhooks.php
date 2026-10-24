<?php
/**
 * The creators' webhooks of the alert API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Webhooks;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Api\AlertApiAccess;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;

defined( 'ABSPATH' ) || exit;

/**
 * Adding, changing and deleting webhooks (step 25.12.2, ADR-073).
 *
 * - **Formats:** generic JSON (one signed event per paid order, SparkyBot's
 *   format) or Discord (the chat message in a Discord channel).
 * - **Secret:** a JSON webhook signs every request. The creator pastes the
 *   receiver's secret (for example from SparkyBot's dashboard) or leaves the
 *   field empty to get one (`whsec_…`, shown once). Discord's address is
 *   its own secret.
 * - **Storage:** the address and the secret are encrypted (ChatCipher,
 *   domain `webhooks`); without sodium no webhook can be added.
 * - **Limits:** at most MAX_WEBHOOKS per creator; only while the
 *   administrator has the API on and has given the creator access to
 *   webhooks (step 25.12.4). Deleting and switching off always work.
 * - **Audit:** added, changed and deleted, with the id and format; never
 *   the address or the secret.
 *
 * Callers pass the creator that CreatorContext resolved (ADR-007).
 */
class AlertWebhooks {

	/**
	 * Webhooks per creator.
	 */
	public const MAX_WEBHOOKS = 3;

	/**
	 * Longest name.
	 */
	public const NAME_MAX = 60;

	/**
	 * Longest Discord template.
	 */
	public const TEMPLATE_MAX = 500;

	/**
	 * Constructor.
	 *
	 * @param AlertWebhookRepository $webhooks    Storage.
	 * @param AlertApiSwitch         $api         The administrator's switch.
	 * @param AuditTrail             $audit       Audit log.
	 * @param Transaction            $transaction Database transaction.
	 * @param ChatCipher             $cipher      Encryption (domain `webhooks`).
	 * @param AlertApiAccess         $access      The creators' access (step 25.12.4).
	 */
	public function __construct( private AlertWebhookRepository $webhooks, private AlertApiSwitch $api, private AuditTrail $audit, private Transaction $transaction, private ChatCipher $cipher, private AlertApiAccess $access ) {
	}

	/**
	 * Whether a creator may use webhooks (the administrator's access, step 25.12.4).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function allowed( int $creator_id ): bool {
		return $this->access->webhooks( $creator_id );
	}

	/**
	 * A creator's webhooks.
	 *
	 * @param int $creator_id Creator.
	 * @return list<AlertWebhook>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function for_creator( int $creator_id ): array {
		return $this->webhooks->for_creator( $creator_id );
	}

	/**
	 * Adds a webhook.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $name       The creator's name for it.
	 * @param string $format     AlertWebhook::JSON or DISCORD.
	 * @param string $url        Address.
	 * @param string $secret     The receiver's secret; '' to get one (JSON only).
	 * @param string $template   Discord's template ('' for the default).
	 * @return array{webhook: AlertWebhook, secret: string|null} The webhook, and a made secret (shown once).
	 * @throws WebhookException When the API is off, the creator has no access or an input is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( int $creator_id, string $name, string $format, string $url, string $secret, string $template ): array {
		if ( ! $this->api->enabled() ) {
			self::refuse( WebhookException::DISABLED );
		}

		if ( ! $this->allowed( $creator_id ) ) {
			self::refuse( WebhookException::NO_ACCESS );
		}

		if ( ! $this->cipher->available() ) {
			self::refuse( WebhookException::ENCRYPTION_UNAVAILABLE );
		}

		$name     = self::name( $name );
		$template = self::template( $template );

		if ( ! in_array( $format, array( AlertWebhook::JSON, AlertWebhook::DISCORD ), true ) ) {
			self::refuse( WebhookException::INVALID_FORMAT );
		}

		$address = WebhookUrl::check( $url, $format );

		if ( null === $address ) {
			self::refuse( WebhookException::INVALID_URL );
		}

		$made   = null;
		$secret = trim( $secret );

		if ( AlertWebhook::JSON === $format && '' === $secret ) {
			$made   = 'whsec_' . bin2hex( random_bytes( 32 ) );
			$secret = $made;
		}

		if ( AlertWebhook::JSON === $format ) {
			self::check_secret( $secret );
		}

		$url_encrypted    = $this->cipher->encrypt( $address['url'] );
		$secret_encrypted = AlertWebhook::JSON === $format ? $this->cipher->encrypt( $secret ) : null;

		if ( null === $url_encrypted || ( AlertWebhook::JSON === $format && null === $secret_encrypted ) ) {
			self::refuse( WebhookException::ENCRYPTION_UNAVAILABLE );
		}

		$webhook = $this->transaction->run(
			function () use ( $creator_id, $name, $format, $url_encrypted, $address, $secret_encrypted, $template ): AlertWebhook {
				if ( count( $this->webhooks->for_creator( $creator_id ) ) >= self::MAX_WEBHOOKS ) {
					self::refuse( WebhookException::LIMIT );
				}

				$id = $this->webhooks->insert( $creator_id, $name, $format, $url_encrypted, $address['host'], $address['hint'], $secret_encrypted, AlertWebhook::DISCORD === $format ? $template : '' );

				$this->audit->record(
					AuditAction::AlertWebhookCreated,
					$creator_id,
					array(
						'webhook_id' => $id,
						'format'     => $format,
					)
				);

				return $this->webhooks->find( $id ) ?? self::refuse( WebhookException::UNKNOWN );
			}
		);

		return array(
			'webhook' => $webhook,
			'secret'  => $made,
		);
	}

	/**
	 * Changes a webhook: its name, Discord template, switch, or resumes it
	 * after a pause (clears the failures). Only the given changes apply.
	 *
	 * @phpstan-param array{name?: string, template?: string, enabled?: bool, resume?: bool} $changes
	 *
	 * @param int                  $creator_id Creator.
	 * @param int                  $id         Webhook.
	 * @param array<string, mixed> $changes    Changes.
	 * @throws WebhookException When it is not the creator's, switching on without access or an input is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update( int $creator_id, int $id, array $changes ): AlertWebhook {
		$webhook = $this->require( $creator_id, $id );
		$values  = array();
		$audited = array();

		if ( isset( $changes['name'] ) ) {
			$values['name'] = self::name( $changes['name'] );
			$audited[]      = 'renamed';
		}

		if ( isset( $changes['template'] ) && AlertWebhook::DISCORD === $webhook->format ) {
			$values['template'] = self::template( $changes['template'] );
			$audited[]          = 'template';
		}

		if ( true === ( $changes['enabled'] ?? null ) && ! $webhook->enabled && ! $this->allowed( $creator_id ) ) {
			// Switching off always works; switching on needs access.
			self::refuse( WebhookException::NO_ACCESS );
		}

		if ( isset( $changes['enabled'] ) && $changes['enabled'] !== $webhook->enabled ) {
			$values['enabled'] = $changes['enabled'] ? 1 : 0;
			$audited[]         = $changes['enabled'] ? 'enabled' : 'disabled';
		}

		if ( true === ( $changes['resume'] ?? false ) && null !== $webhook->paused_at ) {
			$values['paused_at'] = null;
			$values['failures']  = 0;
			$audited[]           = 'resumed';
		}

		if ( array() === $values ) {
			return $webhook;
		}

		$this->transaction->run(
			function () use ( $webhook, $values, $audited ): void {
				$this->webhooks->update( $webhook->id, $values );
				$this->changed( $webhook, implode( ',', $audited ), false );
			}
		);

		return $this->require( $creator_id, $id );
	}

	/**
	 * Gives a JSON webhook a new secret: the pasted one, or a made one.
	 *
	 * @param int    $creator_id Creator.
	 * @param int    $id         Webhook.
	 * @param string $secret     The receiver's secret; '' to get one.
	 * @return string|null A made secret (shown once); null when pasted.
	 * @throws WebhookException When it is not the creator's JSON webhook, the creator has no access or the secret is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function new_secret( int $creator_id, int $id, string $secret ): ?string {
		$webhook = $this->require( $creator_id, $id );

		if ( AlertWebhook::JSON !== $webhook->format ) {
			self::refuse( WebhookException::INVALID_FORMAT );
		}

		if ( ! $this->allowed( $creator_id ) ) {
			self::refuse( WebhookException::NO_ACCESS );
		}

		$secret = trim( $secret );
		$made   = '' === $secret ? 'whsec_' . bin2hex( random_bytes( 32 ) ) : null;
		$secret = $made ?? $secret;
		self::check_secret( $secret );
		$encrypted = $this->cipher->encrypt( $secret ) ?? self::refuse( WebhookException::ENCRYPTION_UNAVAILABLE );

		$this->transaction->run(
			function () use ( $webhook, $encrypted ): void {
				$this->webhooks->update( $webhook->id, array( 'secret_encrypted' => $encrypted ) );
				$this->changed( $webhook, 'secret', false );
			}
		);

		return $made;
	}

	/**
	 * Deletes a webhook and its deliveries.
	 *
	 * @param int $creator_id Creator.
	 * @param int $id         Webhook.
	 * @throws WebhookException When it is not the creator's.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $creator_id, int $id ): void {
		$webhook = $this->require( $creator_id, $id );

		$this->transaction->run(
			function () use ( $webhook ): void {
				$this->webhooks->delete( $webhook->id );
				$this->audit->record(
					AuditAction::AlertWebhookDeleted,
					$webhook->creator_id,
					array(
						'webhook_id' => $webhook->id,
						'format'     => $webhook->format,
					)
				);
			}
		);
	}

	/**
	 * Switches off all of a creator's webhooks (the administrator).
	 *
	 * @param int $creator_id Creator.
	 * @return int Webhooks switched off.
	 * @throws WebhookException When the current user may not manage alerts.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function disable_all( int $creator_id ): int {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			self::refuse( WebhookException::NOT_AUTHORIZED );
		}

		return $this->transaction->run(
			function () use ( $creator_id ): int {
				$count = 0;

				foreach ( $this->webhooks->for_creator( $creator_id ) as $webhook ) {
					if ( $webhook->enabled ) {
						$this->webhooks->update( $webhook->id, array( 'enabled' => 0 ) );
						$this->changed( $webhook, 'disabled', true );
						++$count;
					}
				}

				return $count;
			}
		);
	}

	/**
	 * A webhook's address; null when it cannot be read (another salt).
	 *
	 * @param AlertWebhook $webhook Webhook.
	 */
	public function url_of( AlertWebhook $webhook ): ?string {
		return $this->cipher->decrypt( $webhook->url_encrypted );
	}

	/**
	 * A JSON webhook's signing secret; null for Discord or when it cannot be read.
	 *
	 * @param AlertWebhook $webhook Webhook.
	 */
	public function secret_of( AlertWebhook $webhook ): ?string {
		return null === $webhook->secret_encrypted ? null : $this->cipher->decrypt( $webhook->secret_encrypted );
	}

	/**
	 * One of the creator's webhooks.
	 *
	 * @param int $creator_id Creator.
	 * @param int $id         Webhook.
	 * @throws WebhookException When it is not the creator's.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function require( int $creator_id, int $id ): AlertWebhook {
		return $this->webhooks->find_for_creator( $creator_id, $id ) ?? self::refuse( WebhookException::UNKNOWN );
	}

	/**
	 * Audits a change.
	 *
	 * @param AlertWebhook $webhook  Webhook.
	 * @param string       $change   What changed.
	 * @param bool         $by_admin By the administrator.
	 * @throws DatabaseException When the audit record cannot be written.
	 */
	private function changed( AlertWebhook $webhook, string $change, bool $by_admin ): void {
		$this->audit->record(
			AuditAction::AlertWebhookChanged,
			$webhook->creator_id,
			array(
				'webhook_id' => $webhook->id,
				'change'     => $change,
				'by_admin'   => $by_admin,
			)
		);
	}

	/**
	 * A cleaned name.
	 *
	 * @param string $name Name.
	 * @throws WebhookException When it is empty or too long.
	 */
	private static function name( string $name ): string {
		$name = trim( sanitize_text_field( $name ) );

		return '' === $name || mb_strlen( $name ) > self::NAME_MAX ? self::refuse( WebhookException::INVALID_NAME ) : $name;
	}

	/**
	 * A cleaned Discord template ('' for the default).
	 *
	 * @param string $template Template.
	 * @throws WebhookException When it is too long.
	 */
	private static function template( string $template ): string {
		$template = trim( sanitize_text_field( $template ) );

		return mb_strlen( $template ) > self::TEMPLATE_MAX ? self::refuse( WebhookException::INVALID_TEMPLATE ) : $template;
	}

	/**
	 * Checks a signing secret: 16–200 visible characters without spaces.
	 *
	 * @param string $secret Secret.
	 * @throws WebhookException When it is not one.
	 */
	private static function check_secret( string $secret ): void {
		if ( 1 !== preg_match( '/^[\x21-\x7e]{16,200}$/D', $secret ) ) {
			self::refuse( WebhookException::INVALID_SECRET );
		}
	}

	/**
	 * Refuses a change.
	 *
	 * @param string $reason WebhookException constant.
	 * @throws WebhookException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new WebhookException( $reason );
	}
}
