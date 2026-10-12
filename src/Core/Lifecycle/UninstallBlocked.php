<?php
/**
 * An opt-in uninstall that must not delete anything yet.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Lifecycle;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Uninstaller::run() before it changes anything, when payouts are
 * pending or cannot be counted (ADR-059, fail closed). uninstall.php turns it
 * into UninstallHalt, which ends the deletion request so WordPress keeps the
 * plugin's files.
 */
final class UninstallBlocked extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int|null $pending Pending payouts, or null when they could not be counted.
	 */
	public function __construct( public readonly ?int $pending ) {
		parent::__construct( null === $pending ? 'Pending payouts could not be counted.' : 'Payouts are pending.' );
	}
}
