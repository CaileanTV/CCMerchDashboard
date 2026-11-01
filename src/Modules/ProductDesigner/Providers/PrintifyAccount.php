<?php
/**
 * The Printify access token.
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
 * ADR-087: the personal access token is encrypted with sodium in its own key
 * domain (`designer-printify`) in an option without autoload, never shown
 * again, logged or audited. `merchboard_manage_designer`.
 */
class PrintifyAccount {

	public const TOKEN_OPTION = 'merchboard_designer_printify_token';

	/**
	 * A token: letters, digits, `.`, `_` and `-`, up to Printify's 3,000 characters.
	 */
	private const TOKEN_PATTERN = '/^[A-Za-z0-9._-]{20,3000}$/';

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
		return null !== $this->token();
	}

	/**
	 * The token, or null.
	 */
	public function token(): ?string {
		$stored = get_option( self::TOKEN_OPTION, '' );

		return is_string( $stored ) && '' !== $stored ? $this->cipher()->decrypt( $stored ) : null;
	}

	/**
	 * Stores a new token ('' keeps the stored one).
	 *
	 * @param string $token Token.
	 * @return bool Whether something changed.
	 * @throws DesignerException When the user may not or the token is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set( string $token ): bool {
		self::authorize();
		$token = trim( $token );

		if ( '' === $token ) {
			return false;
		}

		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			self::refuse( DesignerException::PRINTIFY_KEY );
		}

		$encrypted = $this->cipher()->encrypt( $token ) ?? self::refuse( DesignerException::PRINTIFY_KEY );

		$this->transaction->run(
			function () use ( $encrypted ): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting' => 'printify_key',
						'value'   => 'set',
					)
				);
				update_option( self::TOKEN_OPTION, $encrypted, false );
			}
		);

		return true;
	}

	/**
	 * Removes the token.
	 *
	 * @throws DesignerException When the user may not.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function clear(): void {
		self::authorize();

		if ( false === get_option( self::TOKEN_OPTION, false ) ) {
			return;
		}

		$this->transaction->run(
			function (): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting' => 'printify_key',
						'value'   => 'removed',
					)
				);
				delete_option( self::TOKEN_OPTION );
			}
		);
	}

	/**
	 * The cipher of the token's domain.
	 */
	private function cipher(): ChatCipher {
		$this->cipher ??= new ChatCipher( 'designer-printify' );

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
