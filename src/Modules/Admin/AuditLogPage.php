<?php
/**
 * Audit log admin screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditFilter;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\UserAccounts;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Audit log" (phase 11): the read-only business audit
 * trail (ADR-023), capability `merchboard_view_logs`.
 *
 * - Filters (documented scope): event, creator and period. They are GET
 *   parameters of a read-only screen (no nonce needed, nothing changes);
 *   every value is validated here and again in AuditFilter, and reaches SQL
 *   only as a prepare() argument.
 * - There is no way to create, edit or delete records here.
 * - Technical logs are not shown; the screen links to WooCommerce → Status →
 *   Logs (source merchboard) for users who may see them (ADR-021).
 */
final class AuditLogPage {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'merchboard-audit-log';

	/**
	 * Hook priority on `admin_menu`: after Creators (10) and Settings (20).
	 */
	public const PRIORITY = 30;

	/**
	 * Query argument: event (an AuditAction value).
	 */
	public const ARG_EVENT = 'event';

	/**
	 * Query argument: creator id.
	 */
	public const ARG_CREATOR = 'creator';

	/**
	 * Query argument: first day (Y-m-d, site time zone).
	 */
	public const ARG_FROM = 'from';

	/**
	 * Query argument: last day (Y-m-d, site time zone).
	 */
	public const ARG_TO = 'to';

	/**
	 * Constructor.
	 *
	 * @param AuditRepository   $audit    Audit storage (reads only).
	 * @param CreatorRepository $creators Creator names.
	 * @param UserAccounts      $users    User labels.
	 * @param Logger            $logger   Technical log.
	 */
	public function __construct(
		private AuditRepository $audit,
		private CreatorRepository $creators,
		private UserAccounts $users,
		private Logger $logger
	) {
	}

	/**
	 * URL of the screen with optional filter arguments.
	 *
	 * @param array<string, int|string> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Adds "Audit log" to the Merch Dashboard menu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Audit Log', 'merchboard' ),
			__( 'Audit log', 'merchboard' ),
			Capabilities::VIEW_LOGS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Builds the filter from request values. Invalid values are dropped and
	 * reported, never passed on.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @param DateTimeZone $zone  Site time zone (for the day boundaries).
	 * @return array{filter: AuditFilter, invalid: list<string>, values: array<string, string>}
	 */
	public static function filter_from_request( array $input, DateTimeZone $zone ): array {
		$invalid = array();
		$values  = array();

		$event  = sanitize_key( is_scalar( $input[ self::ARG_EVENT ] ?? null ) ? (string) $input[ self::ARG_EVENT ] : '' );
		$action = '' === $event ? null : AuditAction::tryFrom( $event );

		if ( '' !== $event && null === $action ) {
			$invalid[] = self::ARG_EVENT;
		} elseif ( null !== $action ) {
			$values[ self::ARG_EVENT ] = $action->value;
		}

		$raw_creator = is_scalar( $input[ self::ARG_CREATOR ] ?? null ) ? trim( (string) $input[ self::ARG_CREATOR ] ) : '';
		$creator_id  = null;

		if ( '' !== $raw_creator ) {
			if ( 1 === preg_match( '/^[1-9]\d{0,18}$/', $raw_creator ) ) {
				$creator_id                  = (int) $raw_creator;
				$values[ self::ARG_CREATOR ] = (string) $creator_id;
			} else {
				$invalid[] = self::ARG_CREATOR;
			}
		}

		$from  = self::day( $input[ self::ARG_FROM ] ?? null, $zone );
		$until = self::day( $input[ self::ARG_TO ] ?? null, $zone );

		foreach ( array(
			self::ARG_FROM => $from,
			self::ARG_TO   => $until,
		) as $arg => $day ) {
			if ( false === $day ) {
				$invalid[] = $arg;
			} elseif ( null !== $day ) {
				$values[ $arg ] = $day->format( 'Y-m-d' );
			}
		}

		$from  = $from instanceof DateTimeImmutable ? $from : null;
		$until = $until instanceof DateTimeImmutable ? $until->modify( '+1 day' ) : null;

		if ( null !== $from && null !== $until && $from >= $until ) {
			$invalid[] = 'period';
			$from      = null;
			$until     = null;
			unset( $values[ self::ARG_FROM ], $values[ self::ARG_TO ] );
		}

		$utc = new DateTimeZone( 'UTC' );

		return array(
			'filter'  => new AuditFilter(
				$action,
				$creator_id,
				$from?->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				$until?->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
			),
			'invalid' => $invalid,
			'values'  => $values,
		);
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_LOGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view the Merch Dashboard audit log.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only filter parameters; validated in filter_from_request().
		$request = self::filter_from_request( wp_unslash( $_GET ), wp_timezone() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only screen; every value is validated.

		echo '<div class="wrap merchboard-audit-log">';
		echo '<h1>' . esc_html__( 'Audit log', 'merchboard' ) . '</h1>';
		echo '<p>' . esc_html__( 'Business changes to creators and settings: who changed what, and when. Records cannot be changed or deleted here.', 'merchboard' ) . '</p>';

		if ( array() !== $request['invalid'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Some filter values were invalid and have been ignored.', 'merchboard' ) . '</p></div>';
		}

		$this->render_filters( $request['values'] );

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$table = new AuditLogListTable( $this->audit, $this->creators, $this->users, $request['filter'] );

		try {
			$table->prepare_items();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Reading the audit log failed.', array_merge( $e->context(), array( 'reason' => $e->getMessage() ) ) );
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The audit log could not be read. Details are in the technical log.', 'merchboard' ) . '</p></div></div>';
			return;
		}

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';

		foreach ( $request['values'] as $arg => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $arg ) . '" value="' . esc_attr( $value ) . '">';
		}

		$table->display();
		echo '</form>';

		$this->render_technical_log_note();
		echo '</div>';
	}

	/**
	 * Filter form (GET).
	 *
	 * @param array<string, string> $values Valid current values.
	 */
	private function render_filters( array $values ): void {
		echo '<form method="get" class="merchboard-audit-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';

		echo '<label for="merchboard-audit-event">' . esc_html__( 'Event', 'merchboard' ) . '</label> ';
		echo '<select id="merchboard-audit-event" name="' . esc_attr( self::ARG_EVENT ) . '">';
		echo '<option value="">' . esc_html__( 'All events', 'merchboard' ) . '</option>';
		foreach ( AuditAction::cases() as $action ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $action->value ),
				selected( $values[ self::ARG_EVENT ] ?? '', $action->value, false ),
				esc_html( AuditLogFormatter::action_label( $action ) )
			);
		}
		echo '</select> ';

		printf(
			'<label for="merchboard-audit-from">%1$s</label> <input type="date" id="merchboard-audit-from" name="%2$s" value="%3$s"> ',
			esc_html__( 'From', 'merchboard' ),
			esc_attr( self::ARG_FROM ),
			esc_attr( $values[ self::ARG_FROM ] ?? '' )
		);
		printf(
			'<label for="merchboard-audit-to">%1$s</label> <input type="date" id="merchboard-audit-to" name="%2$s" value="%3$s"> ',
			esc_html__( 'To', 'merchboard' ),
			esc_attr( self::ARG_TO ),
			esc_attr( $values[ self::ARG_TO ] ?? '' )
		);

		if ( isset( $values[ self::ARG_CREATOR ] ) ) {
			echo '<input type="hidden" name="' . esc_attr( self::ARG_CREATOR ) . '" value="' . esc_attr( $values[ self::ARG_CREATOR ] ) . '">';
			/* translators: %s: creator id. */
			echo '<span class="merchboard-audit-creator-filter">' . esc_html( sprintf( __( 'Creator #%s', 'merchboard' ), $values[ self::ARG_CREATOR ] ) ) . '</span> ';
		}

		submit_button( __( 'Filter', 'merchboard' ), 'secondary', '', false );

		if ( array() !== $values ) {
			echo ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Clear filters', 'merchboard' ) . '</a>';
		}

		echo '</form>';
	}

	/**
	 * Where technical logs are (they are not part of the audit trail).
	 */
	private function render_technical_log_note(): void {
		echo '<p class="description">' . esc_html__( 'Technical logs (errors and diagnostics) are not part of the audit log.', 'merchboard' ) . ' ';

		if ( defined( 'WC_VERSION' ) && current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's capability for its own log screen.
			echo '<a href="' . esc_url(
				add_query_arg(
					array(
						'page'   => 'wc-status',
						'tab'    => 'logs',
						'source' => 'merchboard',
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'View them under WooCommerce → Status → Logs (source "merchboard").', 'merchboard' ) . '</a>';
		} elseif ( ! defined( 'WC_VERSION' ) ) {
			echo esc_html__( 'Without WooCommerce they are written to the PHP error log (debug.log).', 'merchboard' );
		}

		echo '</p>';
	}

	/**
	 * Parses a Y-m-d day in the site time zone.
	 *
	 * @param mixed        $value Raw value.
	 * @param DateTimeZone $zone  Site time zone.
	 * @return DateTimeImmutable|false|null Start of the day, false when invalid, null when empty.
	 */
	private static function day( mixed $value, DateTimeZone $zone ): DateTimeImmutable|false|null {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return null;
		}

		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $zone );

		// Reject overflow such as 2026-02-31 and anything that is not exactly Y-m-d.
		return false !== $day && $day->format( 'Y-m-d' ) === $value ? $day : false;
	}
}
