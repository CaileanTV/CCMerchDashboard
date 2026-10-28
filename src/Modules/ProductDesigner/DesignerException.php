<?php
/**
 * A refused product designer operation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A refused operation of the product designer (ADR-080), with a stable
 * reason code for the UI. The message is never shown.
 */
final class DesignerException extends RuntimeException {

	public const NOT_AUTHORIZED = 'not_authorized';

	/**
	 * Constructor.
	 *
	 * @param string $reason Reason code.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Product designer operation refused: ' . $reason );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
