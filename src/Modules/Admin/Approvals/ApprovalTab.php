<?php
/**
 * A kind of thing that waits for the administrator's approval.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin\Approvals;

use Closure;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * One tab of "Merch Dashboard → Proposals": its key in the address, its name,
 * the capability that may see it, how many are waiting and how it renders.
 * A module adds one through the filter ApprovalsPage::FILTER (the owner's
 * choice 2026-11-02: everything the administrator approves, also what comes
 * later). `former` names the admin pages it replaced, whose addresses lead to
 * it.
 */
final class ApprovalTab {

	/**
	 * Constructor.
	 *
	 * @param string   $key        Key in the address (a-z, 0-9, -).
	 * @param string   $label      Name of the tab.
	 * @param string   $capability Capability that may open it.
	 * @param Closure  $waiting    How many are waiting: () → int.
	 * @param Closure  $render     Renders the tab's content: () → void.
	 * @param string[] $former     Slugs of the admin pages it replaced.
	 *
	 * @phpstan-param Closure(): int $waiting
	 * @phpstan-param Closure(): void $render
	 * @phpstan-param list<string> $former
	 */
	public function __construct(
		public string $key,
		public string $label,
		public string $capability,
		private Closure $waiting,
		private Closure $render,
		public array $former = array()
	) {
	}

	/**
	 * How many are waiting; 0 when they cannot be counted, so the menu
	 * always shows.
	 */
	public function waiting(): int {
		try {
			return max( 0, ( $this->waiting )() );
		} catch ( Throwable ) {
			return 0;
		}
	}

	/**
	 * Renders the tab's content.
	 */
	public function render(): void {
		( $this->render )();
	}
}
