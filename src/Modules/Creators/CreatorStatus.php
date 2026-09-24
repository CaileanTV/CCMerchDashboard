<?php
/**
 * Creator status values.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

defined( 'ABSPATH' ) || exit;

/**
 * The only valid values of `merchboard_creators.status`.
 *
 * Deliberately minimal. New states (e.g. an archived state) are added here
 * when a phase needs them; the VARCHAR column needs no schema change for that.
 */
enum CreatorStatus: string {

	/**
	 * The creator is in business with the company.
	 */
	case Active = 'active';

	/**
	 * The creator is not active. Historical data is kept.
	 */
	case Inactive = 'inactive';
}
