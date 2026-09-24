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
 * template with its status code and view data. Plain value object, so the
 * decision is testable without sending headers or exiting.
 */
final class ShellResponse {

	public const TEMPLATE_SHELL     = 'shell';
	public const TEMPLATE_NO_ACCESS = 'no-access';

	/**
	 * Constructor.
	 *
	 * @param int                  $status   HTTP status.
	 * @param string|null          $redirect Redirect target (only for 302).
	 * @param string|null          $template Template name (one of the constants).
	 * @param array<string, mixed> $view     View data for the template.
	 */
	private function __construct(
		public readonly int $status,
		public readonly ?string $redirect,
		public readonly ?string $template,
		public readonly array $view
	) {
	}

	/**
	 * A redirect.
	 *
	 * @param string $url Target.
	 */
	public static function redirect( string $url ): self {
		return new self( 302, $url, null, array() );
	}

	/**
	 * A rendered page.
	 *
	 * @param int                  $status   HTTP status.
	 * @param string               $template Template name.
	 * @param array<string, mixed> $view     View data.
	 */
	public static function page( int $status, string $template, array $view ): self {
		return new self( $status, null, $template, $view );
	}
}
