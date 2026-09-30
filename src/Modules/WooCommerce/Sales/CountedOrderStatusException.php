<?php
/**
 * Rejected change of the counted order statuses.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * A change of the counted order statuses that violates a rule. The reason is
 * a fixed code that the admin UI maps to a message; no user input is part of
 * it (ADR-038 S12: the Settings module's SettingsException is not extended).
 */
final class CountedOrderStatusException extends DomainException {

	public const NOT_AUTHORIZED = 'not_authorized';
	public const UNAVAILABLE    = 'unavailable';
	public const EMPTY_LIST     = 'empty';
	public const NOT_ALLOWED    = 'not_allowed';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Counted order statuses rejected: ' . $reason . '.' );
	}

	/**
	 * Reason code (one of the class constants).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
