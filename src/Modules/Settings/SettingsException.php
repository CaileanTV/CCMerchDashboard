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
