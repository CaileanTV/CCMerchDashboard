<?php
/**
 * Everything waiting for the administrator's approval, on one page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin\Approvals;

use MerchBoard\Modules\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Proposals" (the owner's choice 2026-11-02): every kind of
 * thing the administrator approves is a tab, added by its module through the
 * filter FILTER. A tab and the menu item show a red count while something
 * waits. A user sees the tabs their capabilities allow; the menu item is
 * there while at least one is. The pages the tabs replaced keep their
 * addresses, as in e-mails already sent: they lead to their tabs.
 */
final class ApprovalsPage {

	public const SLUG = 'merchboard-approvals';

	/**
	 * The filter that collects the tabs: list<ApprovalTab> → list<ApprovalTab>.
	 */
	public const FILTER = 'merchboard_approval_tabs';

	/**
	 * `admin_menu` priority: where "Category proposals" was.
	 */
	public const PRIORITY = 11;

	/**
	 * Adds the menu item with the number waiting, and sends a replaced page's
	 * address to its tab.
	 */
	public function add_menu(): void {
		// Read-only parameter; the redirect changes nothing.
		$former = self::former_url( wp_unslash( $_GET ), self::all() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in former_url().

		if ( null !== $former ) {
			wp_safe_redirect( $former );
			exit;
		}

		// WordPress hides the item from a user who may open no tab; the count is of the tabs they may open.
		$tabs  = self::visible();
		$first = $tabs[0] ?? self::all()[0] ?? null;

		if ( null === $first ) {
			return;
		}

		$title = __( 'Proposals', 'merchboard' );

		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Proposals', 'merchboard' ),
			esc_html( $title ) . self::menu_count( array_sum( array_map( static fn( ApprovalTab $tab ): int => $tab->waiting(), $tabs ) ) ),
			$first->capability,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * A tab's URL.
	 *
	 * @param string                    $tab  The tab's key.
	 * @param array<string, int|string> $args Query arguments.
	 */
	public static function url( string $tab, array $args = array() ): string {
		$args['page'] = self::SLUG;
		$args['tab']  = $tab;

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Where a replaced page's address leads; null for any other address.
	 *
	 * @phpstan-param list<ApprovalTab> $tabs
	 *
	 * @param array<mixed>  $query Unslashed query.
	 * @param ApprovalTab[] $tabs  The tabs.
	 */
	public static function former_url( array $query, array $tabs ): ?string {
		$page = is_string( $query['page'] ?? null ) ? $query['page'] : '';

		foreach ( '' === $page ? array() : $tabs as $tab ) {
			if ( in_array( $page, $tab->former, true ) ) {
				$args = array();

				foreach ( $query as $key => $value ) {
					if ( is_string( $key ) && ! in_array( $key, array( 'page', 'tab' ), true ) && is_scalar( $value ) ) {
						$args[ sanitize_key( $key ) ] = rawurlencode( sanitize_text_field( (string) $value ) );
					}
				}

				return self::url( $tab->key, $args );
			}
		}

		return null;
	}

	/**
	 * Renders the page: the tabs with their counts and the open tab. A tab
	 * opens by its key; without one the first with something waiting does.
	 */
	public function render(): void {
		$tabs = self::visible();

		if ( array() === $tabs ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only parameter; nothing is changed by this request.
		$asked   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$waiting = array();

		foreach ( $tabs as $tab ) {
			$waiting[ $tab->key ] = $tab->waiting();
		}

		$keys = array_column( array_map( static fn( ApprovalTab $tab ): array => array( 'key' => $tab->key ), $tabs ), 'key' );
		$busy = array_keys( array_filter( $waiting ) );
		$open = in_array( $asked, $keys, true ) ? $asked : (string) ( $busy[0] ?? $keys[0] );

		echo '<div class="wrap merchboard-approvals"><h1>' . esc_html__( 'Proposals', 'merchboard' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Everything that waits for your approval. A red number shows how many are waiting.', 'merchboard' ) . '</p>';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Kinds of proposals', 'merchboard' ) . '">';

		foreach ( $tabs as $tab ) {
			$active = $tab->key === $open;

			echo '<a class="nav-tab' . ( $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $tab->key ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab->label ) . self::tab_count( $waiting[ $tab->key ] ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tab_count() escapes.
		}

		echo '</nav>';

		foreach ( $tabs as $tab ) {
			if ( $tab->key === $open ) {
				$tab->render();
			}
		}

		echo '</div>';
	}

	/**
	 * Every tab the modules added.
	 *
	 * @return list<ApprovalTab>
	 */
	public static function all(): array {
		$tabs = apply_filters( self::FILTER, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- FILTER starts with merchboard_.

		return is_array( $tabs ) ? array_values( array_filter( $tabs, static fn( mixed $tab ): bool => $tab instanceof ApprovalTab ) ) : array();
	}

	/**
	 * The tabs the current user may open.
	 *
	 * @return list<ApprovalTab>
	 */
	private static function visible(): array {
		return array_values( array_filter( self::all(), static fn( ApprovalTab $tab ): bool => current_user_can( $tab->capability ) ) );
	}

	/**
	 * WordPress's red count in the menu, or nothing while none wait.
	 *
	 * @param int $count Waiting.
	 */
	private static function menu_count( int $count ): string {
		return $count > 0 ? ' <span class="awaiting-mod count-' . esc_attr( (string) $count ) . '"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>' : '';
	}

	/**
	 * A red count on a tab, or nothing while none wait.
	 *
	 * @param int $count Waiting.
	 */
	private static function tab_count( int $count ): string {
		if ( $count < 1 ) {
			return '';
		}

		/* translators: %s: number waiting. */
		$label = sprintf( _n( '%s waiting', '%s waiting', $count, 'merchboard' ), number_format_i18n( $count ) );

		return ' <span class="merchboard-approval-count" title="' . esc_attr( $label ) . '" style="display:inline-block;box-sizing:border-box;min-width:18px;height:18px;margin-left:6px;padding:0 5px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;font-weight:600;line-height:18px;text-align:center;vertical-align:middle">' . esc_html( number_format_i18n( $count ) ) . '<span class="screen-reader-text"> ' . esc_html( $label ) . '</span></span>';
	}
}
