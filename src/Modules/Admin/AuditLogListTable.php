<?php
/**
 * Audit log list table.
 *
 * Only instantiated after WordPress' WP_List_Table has been loaded
 * (see AuditLogPage::render()).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin;

use MerchBoard\Core\Audit\AuditEntry;
use MerchBoard\Core\Audit\AuditFilter;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\UserAccounts;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only list of audit records, newest first: time, event, creator, actor
 * and details. Paginated in the database (20 per page). No bulk actions, no
 * row actions that change anything.
 */
final class AuditLogListTable extends WP_List_Table {

	/**
	 * Records per page.
	 */
	public const PER_PAGE = 20;

	/**
	 * "Display Name (login)" of existing users referenced on this page.
	 *
	 * @var array<int, string>
	 */
	private array $user_labels = array();

	/**
	 * Display names of existing creators on this page (null: not found).
	 *
	 * @var array<int, string|null>
	 */
	private array $creator_names = array();

	/**
	 * Constructor.
	 *
	 * @param AuditRepository   $audit    Audit storage (reads only).
	 * @param CreatorRepository $creators Creator names.
	 * @param UserAccounts      $users    User labels.
	 * @param AuditFilter       $filter   Validated filter.
	 */
	public function __construct(
		private AuditRepository $audit,
		private CreatorRepository $creators,
		private UserAccounts $users,
		private AuditFilter $filter
	) {
		parent::__construct(
			array(
				'singular' => 'audit_record',
				'plural'   => 'audit_records',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'created_at' => __( 'Time', 'merchboard' ),
			'action'     => __( 'Event', 'merchboard' ),
			'creator'    => __( 'Creator', 'merchboard' ),
			'actor'      => __( 'By', 'merchboard' ),
			'details'    => __( 'Details', 'merchboard' ),
		);
	}

	/**
	 * Primary column.
	 */
	protected function get_default_primary_column_name() {
		return 'created_at';
	}

	/**
	 * Loads the current page.
	 *
	 * @throws DatabaseException When a stored row is invalid (the page reports it).
	 */
	public function prepare_items(): void {
		$page = $this->audit->paginate( $this->filter, $this->get_pagenum(), self::PER_PAGE );

		$this->items           = $page['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'created_at' );
		$this->set_pagination_args(
			array(
				'total_items' => $page['total'],
				'per_page'    => self::PER_PAGE,
			)
		);

		$user_ids = array();

		foreach ( $page['items'] as $entry ) {
			$user_ids = array_merge( $user_ids, AuditLogFormatter::context_user_ids( $entry ), null === $entry->actor_user_id ? array() : array( $entry->actor_user_id ) );

			if ( null !== $entry->creator_id && ! array_key_exists( $entry->creator_id, $this->creator_names ) ) {
				$this->creator_names[ $entry->creator_id ] = $this->creators->find( $entry->creator_id )?->display_name;
			}
		}

		$this->user_labels = $this->users->labels( array_values( array_unique( $user_ids ) ) );
	}

	/**
	 * Message when nothing matches.
	 */
	public function no_items(): void {
		echo esc_html(
			$this->filter->is_empty()
				? __( 'No audit records yet.', 'merchboard' )
				: __( 'No audit records match the filter.', 'merchboard' )
		);
	}

	/**
	 * Time in the site's time zone.
	 *
	 * @param AuditEntry $item Record.
	 */
	protected function column_created_at( $item ): string {
		return esc_html( CreatorsAdminPage::local_time( $item->created_at ) );
	}

	/**
	 * Event, linking to the same filter.
	 *
	 * @param AuditEntry $item Record.
	 */
	protected function column_action( $item ): string {
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( AuditLogPage::url( array( AuditLogPage::ARG_EVENT => $item->action->value ) ) ),
			esc_html( AuditLogFormatter::action_label( $item->action ) )
		);
	}

	/**
	 * Creator: name (edit link for creator managers) and a filter link, "not
	 * found" for a missing record, a dash for records without creator.
	 *
	 * @param AuditEntry $item Record.
	 */
	protected function column_creator( $item ): string {
		if ( null === $item->creator_id ) {
			return '—';
		}

		$name = $this->creator_names[ $item->creator_id ] ?? null;

		if ( null === $name ) {
			/* translators: %d: creator id. */
			return esc_html( sprintf( __( 'Creator #%d (not found)', 'merchboard' ), $item->creator_id ) );
		}

		$label = current_user_can( Capabilities::MANAGE_CREATORS )
			? sprintf( '<a href="%1$s">%2$s</a>', esc_url( CreatorsAdminPage::edit_url( $item->creator_id ) ), esc_html( $name ) )
			: esc_html( $name );

		return $label . sprintf(
			' <a class="merchboard-audit-filter" href="%1$s">%2$s</a>',
			esc_url( AuditLogPage::url( array( AuditLogPage::ARG_CREATOR => $item->creator_id ) ) ),
			esc_html__( '(filter)', 'merchboard' )
		);
	}

	/**
	 * Actor: "System" for NULL, the user's label (profile link when allowed)
	 * while the user exists, "#id (deleted user)" otherwise.
	 *
	 * @param AuditEntry $item Record.
	 */
	protected function column_actor( $item ): string {
		if ( null === $item->actor_user_id ) {
			return esc_html__( 'System', 'merchboard' );
		}

		$label = $this->user_labels[ $item->actor_user_id ] ?? null;

		if ( null === $label ) {
			/* translators: %d: user id. */
			return esc_html( sprintf( __( 'User #%d (no longer exists)', 'merchboard' ), $item->actor_user_id ) );
		}

		return current_user_can( 'edit_user', $item->actor_user_id )
			? sprintf( '<a href="%1$s">%2$s</a>', esc_url( get_edit_user_link( $item->actor_user_id ) ), esc_html( $label ) )
			: esc_html( $label );
	}

	/**
	 * Context as readable lines.
	 *
	 * @param AuditEntry $item Record.
	 */
	protected function column_details( $item ): string {
		$lines = AuditLogFormatter::details( $item, $this->user_labels );

		return array() === $lines ? '—' : implode( '<br>', array_map( 'esc_html', $lines ) );
	}
}
