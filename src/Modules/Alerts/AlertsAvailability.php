<?php
/**
 * Whether purchase alerts are sent.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

use Closure;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Kill switch and double guard (ADR-065 pkt. 6): alerts are sent only when
 * an administrator has switched them on and MerchAlert is not active, so
 * the same purchase never gets two alerts. MerchAlert is recognised by the
 * constant it defines when it loads, independent of its folder name.
 */
final class AlertsAvailability {

	/**
	 * The constant MerchAlert defines when it is loaded.
	 */
	public const MERCHALERT_CONSTANT = 'CCMERCH_ALERT_VERSION';

	/**
	 * Whether MerchAlert is loaded.
	 *
	 * @var Closure(): bool
	 */
	private Closure $merchalert;

	/**
	 * Constructor.
	 *
	 * @param (Closure(): bool)|null $merchalert Whether MerchAlert is loaded; defaults to its constant.
	 */
	public function __construct( ?Closure $merchalert = null ) {
		$this->merchalert = $merchalert ?? static fn(): bool => defined( self::MERCHALERT_CONSTANT );
	}

	/**
	 * Whether an administrator has switched alerts on.
	 */
	public function enabled(): bool {
		return Settings::alerts_enabled();
	}

	/**
	 * Whether MerchAlert is active on this site.
	 */
	public function merchalert_active(): bool {
		return ( $this->merchalert )();
	}

	/**
	 * Whether alerts are sent now.
	 */
	public function sending(): bool {
		return $this->enabled() && ! $this->merchalert_active();
	}
}
