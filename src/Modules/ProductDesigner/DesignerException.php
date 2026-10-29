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

	public const CAMPAIGN_NAME = 'campaign_name';

	public const CAMPAIGN_PERIOD = 'campaign_period';

	public const CAMPAIGN_SCOPE = 'campaign_scope';

	public const CAMPAIGN_CODES = 'campaign_codes';

	public const CAMPAIGN_CREATOR_CODE = 'campaign_creator_code';

	public const CAMPAIGN_UNKNOWN = 'campaign_unknown';

	public const CAMPAIGN_IN_USE = 'campaign_in_use';

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
