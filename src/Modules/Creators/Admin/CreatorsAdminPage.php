<?php
/**
 * Creators admin screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorException;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\UserAccounts;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Creators": list (WP_List_Table), add and edit form.
 * Read-only rendering; all changes go through CreatorAdminActions. Every
 * value from the database is escaped at output.
 */
final class CreatorsAdminPage {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'merchboard-creators';

	/**
	 * Query argument carrying a whitelisted notice code after a redirect.
	 */
	public const NOTICE_ARG = 'merchboard_notice';

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository  $creators     Creator storage (reads only).
	 * @param UserAccounts       $users        User labels.
	 * @param TransactionSupport $transactions Whether changes can be saved at all.
	 */
	public function __construct(
		private CreatorRepository $creators,
		private UserAccounts $users,
		private TransactionSupport $transactions
	) {
	}

	/**
	 * URL of the creators screen.
	 *
	 * @param array<string, int|string> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL of a creator's edit form.
	 *
	 * @param int $creator_id Creator id.
	 */
	public static function edit_url( int $creator_id ): string {
		return self::url(
			array(
				'action'  => 'edit',
				'creator' => $creator_id,
			)
		);
	}

	/**
	 * Adds "Creators" to the Merch Dashboard menu (owned by the Admin module)
	 * and loads the user search script on this screen only.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Creators', 'merchboard' ),
			__( 'Creators', 'merchboard' ),
			Capabilities::MANAGE_CREATORS,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'enqueue_assets_on_load' ) );
		}
	}

	/**
	 * Hooks the asset loading for this screen.
	 */
	public function enqueue_assets_on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Progressive enhancement for the user field: suggestions from
	 * CreatorUserSearch. The form works without it (type the login).
	 */
	public function enqueue_assets(): void {
		// src/Modules/Creators/Admin → plugin root.
		wp_enqueue_script(
			'merchboard-creator-user-search',
			plugins_url( 'assets/js/creator-user-search.js', dirname( __DIR__, 4 ) . '/merchboard.php' ),
			array(),
			defined( 'MERCHBOARD_VERSION' ) ? (string) constant( 'MERCHBOARD_VERSION' ) : false,
			true
		);
		wp_add_inline_script(
			'merchboard-creator-user-search',
			'window.merchboardUserSearch = ' . (string) wp_json_encode(
				array(
					'url'    => admin_url( 'admin-ajax.php' ),
					'action' => CreatorUserSearch::ACTION,
					'nonce'  => wp_create_nonce( CreatorUserSearch::ACTION ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Renders the list or the form.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only navigation parameters; nothing is changed by this request.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action     = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$creator_id = isset( $_GET['creator'] ) ? absint( $_GET['creator'] ) : 0;
		$notice     = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		$this->render_notice( $notice );

		// Changes are refused while a table cannot roll back (ADR-024); say why up front.
		if ( ! $this->transactions->available() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Creator changes are disabled: the Merch Dashboard database tables use a storage engine without transaction support (for example MyISAM), so a change and its audit record could not be saved together. Convert the tables to InnoDB. Details are in the log.', 'merchboard' ) . '</p></div>';
		}

		if ( 'new' === $action ) {
			$this->render_form( null );
		} elseif ( 'edit' === $action ) {
			$creator = $creator_id > 0 ? $this->creators->find( $creator_id ) : null;

			if ( null === $creator ) {
				echo '<h1>' . esc_html__( 'Creator not found', 'merchboard' ) . '</h1>';
				echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to creators', 'merchboard' ) . '</a></p>';
			} else {
				$this->render_form( $creator );
			}
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Message and type for a notice code; null for unknown codes.
	 *
	 * @param string $code Notice code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function notice_message( string $code ): ?array {
		return match ( $code ) {
			'created'                                  => array( __( 'Creator created.', 'merchboard' ), 'success' ),
			'updated'                                  => array( __( 'Creator updated.', 'merchboard' ), 'success' ),
			'activated'                                => array( __( 'Creator activated.', 'merchboard' ), 'success' ),
			'deactivated'                              => array( __( 'Creator deactivated.', 'merchboard' ), 'success' ),
			'no_changes'                               => array( __( 'No changes were made.', 'merchboard' ), 'info' ),
			CreatorException::NOT_FOUND                => array( __( 'The creator does not exist.', 'merchboard' ), 'error' ),
			CreatorException::NOT_AUTHORIZED           => array( __( 'You are not allowed to manage creators.', 'merchboard' ), 'error' ),
			CreatorException::INVALID_DISPLAY_NAME     => array( __( 'Enter a display name of 1 to 191 characters.', 'merchboard' ), 'error' ),
			CreatorException::INVALID_STATUS           => array( __( 'Choose a valid status.', 'merchboard' ), 'error' ),
			CreatorException::USER_NOT_FOUND           => array( __( 'The selected WordPress user does not exist.', 'merchboard' ), 'error' ),
			CreatorException::USER_ALREADY_LINKED      => array( __( 'The selected WordPress user is already linked to another creator.', 'merchboard' ), 'error' ),
			'error'                                    => array( __( 'A technical error occurred. Please check the creator and try again later.', 'merchboard' ), 'error' ),
			default                                    => null,
		};
	}

	/**
	 * Prints a notice for a whitelisted code.
	 *
	 * @param string $code Notice code.
	 */
	private function render_notice( string $code ): void {
		$notice = self::notice_message( $code );

		if ( null === $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notice[1] ),
			esc_html( $notice[0] )
		);
	}

	/**
	 * Renders the list screen.
	 */
	private function render_list(): void {
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$table = new CreatorsListTable( $this->creators, $this->users );
		$table->prepare_items();

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Creators', 'merchboard' ) . '</h1> ';
		echo '<a href="' . esc_url( self::url( array( 'action' => 'new' ) ) ) . '" class="page-title-action">' . esc_html__( 'Add New Creator', 'merchboard' ) . '</a>';
		echo '<hr class="wp-header-end">';
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		$table->display();
		echo '</form>';
	}

	/**
	 * Renders the add/edit form.
	 *
	 * @param Creator|null $creator Creator to edit, or null to add.
	 */
	private function render_form( ?Creator $creator ): void {
		$creator_id = null === $creator ? 0 : $creator->id;
		// Safe default: a new creator is inactive until an administrator activates it.
		$status     = null === $creator ? CreatorStatus::Inactive : $creator->status;
		$user_value = $this->user_field_value( $creator );

		echo '<h1>' . esc_html( null === $creator ? __( 'Add New Creator', 'merchboard' ) : __( 'Edit Creator', 'merchboard' ) ) . '</h1>';
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← Back to creators', 'merchboard' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CreatorAdminActions::SAVE_ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator_id ) . '">';
		wp_nonce_field( CreatorAdminActions::save_nonce_action( $creator_id ) );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="merchboard-display-name">' . esc_html__( 'Display name', 'merchboard' ) . '</label></th><td>';
		echo '<input type="text" id="merchboard-display-name" name="display_name" class="regular-text" required maxlength="' . esc_attr( (string) CreatorRepository::DISPLAY_NAME_MAX_LENGTH ) . '" value="' . esc_attr( null === $creator ? '' : $creator->display_name ) . '">';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="merchboard-status">' . esc_html__( 'Status', 'merchboard' ) . '</label></th><td>';
		echo '<select id="merchboard-status" name="status">';
		foreach ( CreatorStatus::cases() as $case ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $case->value ), selected( $status->value, $case->value, false ), esc_html( self::status_label( $case ) ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Inactive creators keep their data but will not get dashboard access.', 'merchboard' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="merchboard-user">' . esc_html__( 'WordPress user', 'merchboard' ) . '</label></th><td>';
		printf(
			'<input type="text" id="merchboard-user" name="%1$s" class="regular-text" list="merchboard-user-options" autocomplete="off" value="%2$s">',
			esc_attr( CreatorAdminActions::USER_FIELD ),
			esc_attr( $user_value )
		);
		echo '<datalist id="merchboard-user-options"></datalist>';

		if ( null !== $creator?->user_id && null === $this->users->login( $creator->user_id ) ) {
			/* translators: %d: WordPress user id. */
			echo '<p class="description"><strong>' . esc_html( sprintf( __( 'Linked to user #%d, which no longer exists. Clear the field to unlink.', 'merchboard' ), $creator->user_id ) ) . '</strong></p>';
		} elseif ( null !== $creator?->user_id && current_user_can( 'edit_user', $creator->user_id ) ) {
			echo '<p><a href="' . esc_url( get_edit_user_link( $creator->user_id ) ) . '">' . esc_html__( 'Open WordPress user profile', 'merchboard' ) . '</a></p>';
		}

		echo '<p class="description">' . esc_html__( 'Type the login of the account that may use this creator (suggestions appear after two characters), or leave the field empty for no WordPress user. Linking adds the "Merch Dashboard Creator" role; unlinking removes it. Neither deletes the user.', 'merchboard' ) . '</p>';
		echo '</td></tr>';

		if ( null !== $creator ) {
			echo '<tr><th scope="row">' . esc_html__( 'Created', 'merchboard' ) . '</th><td>' . esc_html( self::local_time( $creator->created_at ) ) . '</td></tr>';
			echo '<tr><th scope="row">' . esc_html__( 'Last updated', 'merchboard' ) . '</th><td>' . esc_html( self::local_time( $creator->updated_at ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
		submit_button( null === $creator ? __( 'Create creator', 'merchboard' ) : __( 'Save changes', 'merchboard' ) );
		echo '</form>';
	}

	/**
	 * Initial value of the user field: the linked user's login, "#id" for a
	 * link to a user that no longer exists (saving unchanged keeps it), or for a
	 * new creator the login of a user preselected from the profile screen
	 * (`user_id` query argument; only an existing user is used).
	 *
	 * @param Creator|null $creator Creator being edited.
	 */
	private function user_field_value( ?Creator $creator ): string {
		if ( null !== $creator ) {
			if ( null === $creator->user_id ) {
				return '';
			}

			return $this->users->login( $creator->user_id ) ?? '#' . $creator->user_id;
		}

		// Read-only prefill; the choice is validated again when the form is saved.
		$preselected = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		return $preselected > 0 ? (string) $this->users->login( $preselected ) : '';
	}

	/**
	 * Translated status label.
	 *
	 * @param CreatorStatus $status Status.
	 */
	public static function status_label( CreatorStatus $status ): string {
		return CreatorStatus::Active === $status ? __( 'Active', 'merchboard' ) : __( 'Inactive', 'merchboard' );
	}

	/**
	 * A UTC timestamp in the site's time zone and format.
	 *
	 * @param \DateTimeImmutable $time UTC time.
	 */
	public static function local_time( \DateTimeImmutable $time ): string {
		$formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time->getTimestamp() );

		return false === $formatted ? '' : $formatted;
	}
}
