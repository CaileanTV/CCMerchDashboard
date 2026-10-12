<?php
/**
 * Creators list table.
 *
 * Only instantiated after WordPress' WP_List_Table has been loaded
 * (see CreatorsAdminPage::render_list()).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\UserAccounts;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

/**
 * Native WordPress list of creators: display name (with row actions), linked
 * user, status, created, updated. Status views, sorting and pagination.
 *
 * Other modules may add read-only columns without the Creators module knowing
 * them (e.g. the WooCommerce module's product categories), through three
 * hooks: `merchboard_creators_list_columns` (add column key => label),
 * `merchboard_creators_list_items` (the creators of the page, once, so a
 * column can load its data for all rows together) and
 * `merchboard_creators_list_column` (the escaped cell HTML). They run only on
 * this screen, which requires `merchboard_manage_creators`.
 */
final class CreatorsListTable extends WP_List_Table {

	/**
	 * Items per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Labels of the users linked to the creators on this page.
	 *
	 * @var array<int, string>
	 */
	private array $user_labels = array();

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository $creators Creator storage.
	 * @param UserAccounts      $users    User labels.
	 */
	public function __construct(
		private CreatorRepository $creators,
		private UserAccounts $users
	) {
		parent::__construct(
			array(
				'singular' => 'creator',
				'plural'   => 'creators',
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
		$columns = array(
			'display_name' => __( 'Display name', 'merchboard' ),
			'user'         => __( 'WordPress user', 'merchboard' ),
			'status'       => __( 'Status', 'merchboard' ),
			'created_at'   => __( 'Created', 'merchboard' ),
			'updated_at'   => __( 'Last updated', 'merchboard' ),
		);

		/**
		 * Filters the columns added to the Creators list. Added columns are
		 * read-only and rendered through `merchboard_creators_list_column`; the
		 * built-in columns cannot be removed or relabelled.
		 *
		 * @param mixed $added Column key => label to add (default an empty array).
		 */
		$added = apply_filters( 'merchboard_creators_list_columns', array() );

		foreach ( is_array( $added ) ? $added : array() as $key => $label ) {
			if ( is_string( $key ) && is_string( $label ) && ! isset( $columns[ $key ] ) ) {
				$columns[ sanitize_key( $key ) ] = $label;
			}
		}

		return $columns;
	}

	/**
	 * Sortable columns (whitelisted again in the repository).
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'display_name' => array( 'display_name', false ),
			'status'       => array( 'status', false ),
			'created_at'   => array( 'created_at', true ),
			'updated_at'   => array( 'updated_at', false ),
		);
	}

	/**
	 * Primary column (receives row actions).
	 */
	protected function get_default_primary_column_name() {
		return 'display_name';
	}

	/**
	 * Status filter links.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$current = $this->status_filter();
		$views   = array(
			'all' => sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( CreatorsAdminPage::url() ),
				null === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'merchboard' )
			),
		);

		foreach ( CreatorStatus::cases() as $status ) {
			$views[ $status->value ] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( CreatorsAdminPage::url( array( 'status' => $status->value ) ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( CreatorsAdminPage::status_label( $status ) )
			);
		}

		return $views;
	}

	/**
	 * Loads the current page.
	 */
	public function prepare_items(): void {
		// Read-only list parameters; values are whitelisted in the repository.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$page = $this->creators->paginate( $this->get_pagenum(), self::PER_PAGE, $this->status_filter(), $orderby, $order );

		$this->items           = $page['items'];
		$this->user_labels     = $this->users->labels(
			array_values( array_filter( array_map( static fn( Creator $c ): ?int => $c->user_id, $page['items'] ) ) )
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'display_name' );

		/**
		 * Fires once with the creators shown on this page, so an added column can
		 * load its data for all rows in one go (no query per row).
		 *
		 * @param list<Creator> $creators Creators of the page.
		 */
		do_action( 'merchboard_creators_list_items', $page['items'] );
		$this->set_pagination_args(
			array(
				'total_items' => $page['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Message when there are no creators.
	 */
	public function no_items(): void {
		esc_html_e( 'No creators found.', 'merchboard' );
	}

	/**
	 * Display name with row actions.
	 *
	 * @param Creator $item Creator.
	 */
	protected function column_display_name( $item ): string {
		$edit    = CreatorsAdminPage::edit_url( $item->id );
		$target  = CreatorStatus::Active === $item->status ? CreatorStatus::Inactive : CreatorStatus::Active;
		$toggle  = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => CreatorAdminActions::STATUS_ACTION,
					'creator' => $item->id,
					'status'  => $target->value,
				),
				admin_url( 'admin-post.php' )
			),
			CreatorAdminActions::status_nonce_action( $item->id )
		);
		$actions = array(
			'edit'   => sprintf( '<a href="%1$s">%2$s</a>', esc_url( $edit ), esc_html__( 'Edit', 'merchboard' ) ),
			'status' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $toggle ),
				esc_html( CreatorStatus::Active === $target ? __( 'Activate', 'merchboard' ) : __( 'Deactivate', 'merchboard' ) )
			),
		);

		return sprintf( '<strong><a class="row-title" href="%1$s">%2$s</a></strong>', esc_url( $edit ), esc_html( $item->display_name ) )
			. $this->row_actions( $actions );
	}

	/**
	 * Linked WordPress user.
	 *
	 * @param Creator $item Creator.
	 */
	protected function column_user( $item ): string {
		if ( null === $item->user_id ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No WordPress user', 'merchboard' ) . '</span>';
		}

		$label = $this->user_labels[ $item->user_id ] ?? sprintf(
			/* translators: %d: WordPress user id. */
			__( 'Unknown user #%d', 'merchboard' ),
			$item->user_id
		);

		return current_user_can( 'edit_user', $item->user_id )
			? sprintf( '<a href="%1$s">%2$s</a>', esc_url( get_edit_user_link( $item->user_id ) ), esc_html( $label ) )
			: esc_html( $label );
	}

	/**
	 * Status.
	 *
	 * @param Creator $item Creator.
	 */
	protected function column_status( $item ): string {
		return esc_html( CreatorsAdminPage::list_status_label( $item ) );
	}

	/**
	 * Creation time (site time zone).
	 *
	 * @param Creator $item Creator.
	 */
	protected function column_created_at( $item ): string {
		return esc_html( CreatorsAdminPage::local_time( $item->created_at ) );
	}

	/**
	 * Last update (site time zone).
	 *
	 * @param Creator $item Creator.
	 */
	protected function column_updated_at( $item ): string {
		return esc_html( CreatorsAdminPage::local_time( $item->updated_at ) );
	}

	/**
	 * A column added through `merchboard_creators_list_columns`.
	 *
	 * @param Creator $item        Creator.
	 * @param string  $column_name Column key.
	 */
	protected function column_default( $item, $column_name ): string {
		/**
		 * Filters the cell of an added column. The callback returns escaped
		 * HTML and must not change anything.
		 *
		 * @param mixed   $html        Cell HTML (default an empty string).
		 * @param string  $column_name Column key.
		 * @param Creator $item        Creator of the row.
		 */
		$html = apply_filters( 'merchboard_creators_list_column', '', $column_name, $item );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Status from the view links, or null for all.
	 */
	private function status_filter(): ?CreatorStatus {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, whitelisted via the enum.
		$value = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return CreatorStatus::tryFrom( $value );
	}
}
