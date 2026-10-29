<?php
/**
 * The Gelato API key and store.
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
 * ADR-082 pkt. 5: the API key is encrypted with sodium in its own key
 * domain (`designer-gelato`) in an option without autoload, never shown
 * again, logged or audited. The store id (an API-only store without a
 * sales channel, pkt. 4) is not secret. `merchboard_manage_designer`.
 */
class GelatoAccount {

	public const KEY_OPTION = 'merchboard_designer_gelato_key';

	public const STORE_OPTION = 'merchboard_designer_gelato_store';

	/**
	 * A key: letters, digits, `-`, `_` and `:`.
	 */
	private const KEY_PATTERN = '/^[A-Za-z0-9:_-]{20,200}$/';

	/**
	 * A store id (a UUID).
	 */
	private const STORE_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

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
	 * Whether a key and a store are stored.
	 */
	public function configured(): bool {
		return null !== $this->key() && null !== $this->store();
	}

	/**
	 * The key, or null.
	 */
	public function key(): ?string {
		$stored = get_option( self::KEY_OPTION, '' );

		return is_string( $stored ) && '' !== $stored ? $this->cipher()->decrypt( $stored ) : null;
	}

	/**
	 * The store id, or null.
	 */
	public function store(): ?string {
		$store = get_option( self::STORE_OPTION, '' );

		return is_string( $store ) && 1 === preg_match( self::STORE_PATTERN, $store ) ? strtolower( $store ) : null;
	}

	/**
	 * Stores a new key and/or store id ('' keeps the stored one).
	 *
	 * @param string $key   Key.
	 * @param string $store Store id.
	 * @return bool Whether something changed.
	 * @throws DesignerException When the user may not or a value is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set( string $key, string $store ): bool {
		self::authorize();
		$key   = trim( $key );
		$store = strtolower( trim( $store ) );

		if ( ( '' !== $key && 1 !== preg_match( self::KEY_PATTERN, $key ) ) || ( '' !== $store && 1 !== preg_match( self::STORE_PATTERN, $store ) ) ) {
			self::refuse( DesignerException::GELATO_ACCOUNT );
		}

		$encrypted = '' === $key ? null : ( $this->cipher()->encrypt( $key ) ?? self::refuse( DesignerException::GELATO_ACCOUNT ) );

		if ( null === $encrypted && ( '' === $store || $this->store() === $store ) ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $encrypted, $store ): void {
				if ( null !== $encrypted ) {
					$this->audit->record(
						AuditAction::SettingsUpdated,
						null,
						array(
							'setting' => 'gelato_key',
							'value'   => 'set',
						)
					);
					update_option( self::KEY_OPTION, $encrypted, false );
				}

				if ( '' !== $store && $this->store() !== $store ) {
					$this->audit->record(
						AuditAction::SettingsUpdated,
						null,
						array(
							'setting'        => 'gelato_store',
							'previous_value' => $this->store(),
							'value'          => $store,
						)
					);
					update_option( self::STORE_OPTION, $store, false );
				}
			}
		);

		return true;
	}

	/**
	 * Removes the key (the store id stays).
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
						'setting' => 'gelato_key',
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
		$this->cipher ??= new ChatCipher( 'designer-gelato' );

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
