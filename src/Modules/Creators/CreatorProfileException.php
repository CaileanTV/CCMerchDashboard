<?php
/**
 * Invalid creator profile input.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * A profile update with invalid fields. Carries one fixed error code per
 * field (e.g. `bio` → `too_long`); never user input. The client maps codes to
 * messages.
 */
final class CreatorProfileException extends DomainException {

	public const INVALID     = 'invalid';
	public const REQUIRED    = 'required';
	public const TOO_LONG    = 'too_long';
	public const INVALID_URL = 'invalid_url';

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $errors Field (e.g. `social_links.x`) → code.
	 */
	public function __construct( private array $errors ) {
		parent::__construct( 'Invalid creator profile.' );
	}

	/**
	 * Field → error code.
	 *
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
