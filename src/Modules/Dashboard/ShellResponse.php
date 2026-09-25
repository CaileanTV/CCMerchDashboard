<?php
/**
 * Dashboard shell response.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * What ShellController answers a dashboard request with: a redirect, or a
 * template with its status code, view data and (for the React shell) the CSP
 * nonce of its scripts. Plain value object, so the decision is testable
 * without sending headers or exiting.
 */
final class ShellResponse {

	public const TEMPLATE_SHELL     = 'shell';
	public const TEMPLATE_NO_ACCESS = 'no-access';

	/**
	 * Constructor.
	 *
	 * @param int                  $status       HTTP status.
	 * @param string|null          $redirect     Redirect target (only for 302).
	 * @param string|null          $template     Template name (one of the constants).
	 * @param array<string, mixed> $view         View data for the template.
	 * @param string|null          $script_nonce CSP nonce; null = the page runs no scripts.
	 */
	private function __construct(
		public readonly int $status,
		public readonly ?string $redirect,
		public readonly ?string $template,
		public readonly array $view,
		public readonly ?string $script_nonce
	) {
	}

	/**
	 * A redirect.
	 *
	 * @param string $url Target.
	 */
	public static function redirect( string $url ): self {
		return new self( 302, $url, null, array(), null );
	}

	/**
	 * A rendered page.
	 *
	 * @param int                  $status       HTTP status.
	 * @param string               $template     Template name.
	 * @param array<string, mixed> $view         View data.
	 * @param string|null          $script_nonce CSP nonce for the page's scripts.
	 */
	public static function page( int $status, string $template, array $view, ?string $script_nonce = null ): self {
		return new self( $status, null, $template, $view, $script_nonce );
	}
}
