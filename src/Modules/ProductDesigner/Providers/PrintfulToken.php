<?php
/**
 * The Printful API token.
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
 * ADR-081 pkt. 3: the private token is encrypted with sodium in its own key
 * domain (`designer-printful`) and stored in an option without autoload. It
 * is never shown again and never logged or audited (the audit records only
 * that it was set or removed). `merchboard_manage_designer`.
 */
class PrintfulToken {

	public const OPTION = 'merchboard_designer_printful_token';

	/**
	 * A token: letters, digits, `-` and `_`.
	 */
	private const PATTERN = '/^[A-Za-z0-9_-]{20,200}$/';

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
	 * Whether a token is stored.
	 */
	public function configured(): bool {
		return null !== $this->get();
	}

	/**
	 * The token, or null.
	 */
	public function get(): ?string {
		$stored = get_option( self::OPTION, '' );

		return is_string( $stored ) && '' !== $stored ? $this->cipher()->decrypt( $stored ) : null;
	}

	/**
	 * Stores a new token.
	 *
	 * @param string $token The token.
	 * @throws DesignerException When the user may not, the token is invalid or it cannot be encrypted.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set( string $token ): void {
		self::authorize();
		$token = trim( $token );

		if ( 1 !== preg_match( self::PATTERN, $token ) ) {
			self::refuse( DesignerException::PRINTFUL_TOKEN );
		}

		$encrypted = $this->cipher()->encrypt( $token ) ?? self::refuse( DesignerException::PRINTFUL_TOKEN );

		$this->transaction->run(
			function () use ( $encrypted ): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting' => 'printful_token',
						'value'   => 'set',
					)
				);
				update_option( self::OPTION, $encrypted, false );
			}
		);
	}

	/**
	 * Removes the token.
	 *
	 * @throws DesignerException When the user may not.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function clear(): void {
		self::authorize();

		if ( false === get_option( self::OPTION, false ) ) {
			return;
		}

		$this->transaction->run(
			function (): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting' => 'printful_token',
						'value'   => 'removed',
					)
				);
				delete_option( self::OPTION );
			}
		);
	}

	/**
	 * The cipher of the token's key domain.
	 */
	private function cipher(): ChatCipher {
		$this->cipher ??= new ChatCipher( 'designer-printful' );

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
