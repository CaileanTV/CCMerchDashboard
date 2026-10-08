<?php
/**
 * Settings business rule violation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Settings;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * A settings request that violates a rule. The reason is a fixed code that
 * the admin UI maps to a message; no user input is part of it.
 */
final class SettingsException extends DomainException {

	public const NOT_AUTHORIZED        = 'not_authorized';
	public const CONFIRMATION_REQUIRED = 'confirmation_required';
	public const INVALID_SLUG          = 'invalid_slug';
	public const SLUG_IN_USE           = 'slug_in_use';
	public const LOGO_NOT_FOUND        = 'logo_not_found';
	public const LOGO_WRONG_TYPE       = 'logo_wrong_type';
	public const LOGO_WRONG_SIZE       = 'logo_wrong_size';
	public const LOGO_FOREIGN_URL      = 'logo_foreign_url';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Settings request rejected: ' . $reason . '.' );
	}

	/**
	 * Reason code (one of the class constants).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
