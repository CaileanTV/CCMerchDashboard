<?php
/**
 * The Prodigi API key.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;
use MerchBoard\Modules\ProductDesigner\DesignerException;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-084: the API key is encrypted with sodium in its own key domain
 * (`designer-prodigi`) in an option without autoload, never shown again,
 * logged or audited. `merchboard_manage_designer`.
 */
class ProdigiAccount {

	public const KEY_OPTION = 'merchboard_designer_prodigi_key';

	/**
	 * A v5 key: `live_` or `sandbox_` before letters, digits, `-`, `_` and `:`.
	 */
	private const KEY_PATTERN = '/^(?:live|sandbox)_[A-Za-z0-9:_-]{16,200}$/';

	/**
	 * Constructor.
	 *
	 * @param AuditTrail      $audit       Audit trail.
	 * @param Transaction     $transaction Transaction.
	 * @param ChatCipher|null $cipher      Cipher (tests).
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction, private ?ChatCipher $cipher = null ) {
	}

	/**
	 * Whether a key is stored.
	 */
	public function configured(): bool {
		return null !== $this->key();
	}

	/**
	 * The key, or null.
	 */
	public function key(): ?string {
		$stored = get_option( self::KEY_OPTION, '' );

		return is_string( $stored ) && '' !== $stored ? $this->cipher()->decrypt( $stored ) : null;
	}

	/**
	 * Stores a new key ('' keeps the stored one).
	 *
	 * @param string $key Key.
	 * @return bool Whether something changed.
	 * @throws DesignerException When the user may not or the key is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set( string $key ): bool {
		self::authorize();
		$key = trim( $key );

		if ( '' === $key ) {
			return false;
		}

		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			self::refuse( DesignerException::PRODIGI_KEY );
		}

		$encrypted = $this->cipher()->encrypt( $key ) ?? self::refuse( DesignerException::PRODIGI_KEY );

		$this->transaction->run(
			function () use ( $encrypted ): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting' => 'prodigi_key',
						'value'   => 'set',
					)
				);
				update_option( self::KEY_OPTION, $encrypted, false );
			}
		);

		return true;
	}

	/**
	 * Removes the key.
	 *
	 * @throws DesignerException When the user may not.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function clear(): void {
		self::authorize();

		if ( false === get_option( self::KEY_OPTION, false ) ) {
			return;
		}

		$this->transaction->run(
			function (): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting' => 'prodigi_key',
						'value'   => 'removed',
					)
				);
				delete_option( self::KEY_OPTION );
			}
		);
	}

	/**
	 * The cipher of the key's domain.
	 */
	private function cipher(): ChatCipher {
		$this->cipher ??= new ChatCipher( 'designer-prodigi' );

		return $this->cipher;
	}

	/**
	 * Requires the designer capability.
	 *
	 * @throws DesignerException When the user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			self::refuse( DesignerException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Refuses with a reason.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
