<?php
/**
 * Admin page of the migration from Product Vendors.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\VendorImport;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Import from Product Vendors" (steps 26.1–26.2,
 * ADR-074): what is migrated and what is not, the last migration, a dry run
 * with the chosen user per vendor (read-only, `preview=1`, the choices as
 * `user[term_id]`) and the confirmation form with the dry run's
 * fingerprint, the chosen users and the backup checkbox
 * (VendorImportActions). Shown while Product Vendors is active or after a
 * migration. The page itself changes nothing.
 */
final class VendorImportPage {

	public const SLUG = 'merchboard-vendor-import';

	/**
	 * Menu priority: before "Audit log".
	 */
	public const PRIORITY = 29;

	/**
	 * Query argument: show the dry run.
	 */
	public const PREVIEW_ARG = 'preview';

	/**
	 * Query argument: the chosen user per vendor.
	 */
	public const USER_ARG = 'user';

	/**
	 * Query argument: the outcome code after a migration.
	 */
	public const RESULT_ARG = 'result';

	/**
	 * Constructor.
	 *
	 * @param VendorImportPlan       $plan   The dry run.
	 * @param ProductVendorsSource   $source Product Vendors.
	 * @param VendorRoleCleanup|null $roles  Removal of Product Vendors' roles (step 26.3); null = no section.
	 */
	public function __construct( private VendorImportPlan $plan, private ProductVendorsSource $source, private ?VendorRoleCleanup $roles = null ) {
	}

	/**
	 * Adds the submenu page (hooked on `admin_menu` with PRIORITY) while
	 * Product Vendors is active or after a migration.
	 */
	public function add_menu(): void {
		if ( ! $this->source->active() && array() === VendorImportPlan::mapping() ) {
			return;
		}

		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Import from Product Vendors', 'merchboard' ),
			__( 'Import from Product Vendors', 'merchboard' ),
			Capabilities::MANAGE_CREATORS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * URL of the page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			VendorImportException::NOT_AUTHORIZED => array( __( 'Sorry, you are not allowed to migrate creators. It takes the rights to manage creators and payouts.', 'merchboard' ), 'error' ),
			VendorImportException::INACTIVE       => array( __( 'Activate WooCommerce Product Vendors first: the migration reads the vendors through Product Vendors itself.', 'merchboard' ), 'error' ),
			VendorImportException::NO_DATA        => array( __( 'Product Vendors has no vendors.', 'merchboard' ), 'error' ),
			VendorImportException::UNREADABLE     => array( __( 'Product Vendors\' vendors could not be read. Please try again later.', 'merchboard' ), 'error' ),
			VendorImportException::OUTDATED       => array( __( 'The data changed since the dry run. Check the new dry run and migrate again.', 'merchboard' ), 'warning' ),
			VendorImportException::NO_BACKUP      => array( __( 'Confirm that you have a backup before you migrate.', 'merchboard' ), 'error' ),
			VendorImportActions::DONE             => array( __( 'The migration is done. Check the creators and their products, then remove the Product Vendors roles when you are ready.', 'merchboard' ), 'success' ),
			VendorRoleActions::DONE               => array( __( 'Product Vendors\' roles were removed. The users keep their other roles and their creator access.', 'merchboard' ), 'success' ),
			VendorRoleActions::NOT_CONFIRMED      => array( __( 'Nothing was changed: tick the confirmation first.', 'merchboard' ), 'error' ),
			VendorRoleActions::ERROR              => array( __( 'The roles could not be removed because of a technical error. Users changed before it keep the change; try again.', 'merchboard' ), 'error' ),
			VendorImportActions::ERROR            => array( __( 'The migration stopped because of a technical error. What was migrated is kept; run the dry run again to migrate the rest.', 'merchboard' ), 'error' ),
			default                               => null,
		};
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only parameters of the dry run, validated here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$preview = '1' === ( isset( $_GET[ self::PREVIEW_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::PREVIEW_ARG ] ) ) : '' );
		$choices = self::choices( isset( $_GET[ self::USER_ARG ] ) ? wp_unslash( $_GET[ self::USER_ARG ] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized to positive integers in choices().
		$result  = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap"><h1>' . esc_html__( 'Import from Product Vendors', 'merchboard' ) . '</h1>';
		echo '<p>' . esc_html__( 'Moves the vendors from WooCommerce Product Vendors to Merch Dashboard once: a creator per vendor, the vendor\'s WordPress user linked to it, and the vendor\'s products linked to the creator. A vendor whose user is linked to a creator already uses that creator. Product Vendors\' data is only read, never changed.', 'merchboard' ) . '</p>';
		$notice = '' === $result ? null : self::result_message( $result );

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<ul class="ul-disc"><li>' . esc_html__( 'Not moved: commissions, payouts, PayPal and other payment details.', 'merchboard' ) . '</li><li>' . esc_html__( 'New creators get their settlement start at the time of the migration, so sales Product Vendors has paid commission for are never paid out again.', 'merchboard' ) . '</li><li>' . esc_html__( 'A creator has one WordPress user. When a vendor has several, choose the one to link; the others are not moved.', 'merchboard' ) . '</li><li>' . esc_html__( 'Vendors waiting for approval are skipped.', 'merchboard' ) . '</li><li>' . esc_html__( 'Keep Product Vendors active until the migration is done: it is read through Product Vendors itself.', 'merchboard' ) . '</li></ul>';

		$this->render_last();
		$this->render_roles();

		try {
			$this->plan->check();

			if ( ! $preview ) {
				echo '<p><a class="button button-primary" href="' . esc_url( self::url( array( self::PREVIEW_ARG => '1' ) ) ) . '">' . esc_html__( 'Dry run', 'merchboard' ) . '</a></p></div>';

				return;
			}

			$report = $this->plan->build( $choices );
		} catch ( VendorImportException $e ) {
			$message = self::result_message( $e->reason() );
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div></div>', esc_attr( $message[1] ?? 'error' ), esc_html( $message[0] ?? '' ) );

			return;
		} catch ( DatabaseException ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The dry run could not be made because of a technical error. Please try again later.', 'merchboard' ) . '</p></div></div>';

			return;
		}

		$this->render_report( $report );
		echo '</div>';
	}

	/**
	 * The chosen users from the query: term id → user id, positive only.
	 *
	 * @param mixed $raw The query value.
	 * @return array<int, int>
	 */
	public static function choices( mixed $raw ): array {
		$choices = array();

		foreach ( is_array( $raw ) ? $raw : array() as $term_id => $user_id ) {
			// Numeric keys are integers already; digits only, so "-1" or "2.5" never count.
			$user_id = is_int( $user_id ) || ( is_string( $user_id ) && ctype_digit( $user_id ) ) ? (int) $user_id : 0;

			if ( is_int( $term_id ) && $term_id > 0 && $user_id > 0 ) {
				$choices[ $term_id ] = $user_id;
			}
		}

		return $choices;
	}

	/**
	 * The dry run, with a choice of user where a vendor has several.
	 *
	 * @param VendorImportReport $report The dry run.
	 */
	private function render_report( VendorImportReport $report ): void {
		echo '<h2>' . esc_html__( 'Dry run', 'merchboard' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				'' === $report->version
					? __( 'Product Vendors\' version is unknown.', 'merchboard' )
					/* translators: %s: version number. */
					: sprintf( __( 'Read through Product Vendors %s.', 'merchboard' ), $report->version )
			)
		);

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="' . esc_attr( self::PREVIEW_ARG ) . '" value="1">';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Vendor', 'merchboard' ) . '</th><th>' . esc_html__( 'User', 'merchboard' ) . '</th><th>' . esc_html__( 'What happens', 'merchboard' ) . '</th><th>' . esc_html__( 'Products', 'merchboard' ) . '</th><th>' . esc_html__( 'Settlement start', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $report->vendors as $vendor ) {
			echo '<tr><td>' . esc_html( $vendor['name'] );

			if ( $vendor['same_name'] ) {
				echo '<br><em>' . esc_html__( 'A creator with the same name exists. It is not used: creators are matched by their user.', 'merchboard' ) . '</em>';
			}

			echo '</td><td>';
			$this->render_users( $vendor );
			echo '</td><td>' . esc_html( self::status_label( $vendor['status'], $vendor['creator_name'] ) ) . '</td><td>' . esc_html( self::products_label( $vendor ) ) . '</td><td>' . esc_html( self::settlement_label( $vendor['settlement'] ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( array() !== array_filter( $report->vendors, static fn( array $vendor ): bool => count( $vendor['users'] ) > 1 ) ) {
			echo '<p>' . get_submit_button( __( 'Update the dry run', 'merchboard' ), 'secondary', '', false ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress' escaped button markup.
		}

		echo '</form>';

		$context = $report->audit_context();
		printf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: 1: vendors migrated, 2: vendors skipped, 3: products linked, 4: products linked to another creator. */
					__( 'In all: %1$d vendors migrated, %2$d skipped, %3$d products linked, %4$d products left because they belong to another creator.', 'merchboard' ),
					$context['vendors'],
					$context['skipped'],
					$context['products'],
					$context['conflict']
				)
			)
		);
		if ( 0 === $context['vendors'] ) {
			echo '<p>' . esc_html__( 'There is nothing to migrate.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( VendorImportActions::ACTION ) . '">';
		echo '<input type="hidden" name="fingerprint" value="' . esc_attr( $report->fingerprint() ) . '">';

		foreach ( $report->choices() as $term_id => $user_id ) {
			echo '<input type="hidden" name="' . esc_attr( self::USER_ARG . '[' . $term_id . ']' ) . '" value="' . esc_attr( (string) $user_id ) . '">';
		}

		wp_nonce_field( VendorImportActions::ACTION );
		echo '<p><label><input type="checkbox" name="backup" value="1" required> ' . esc_html__( 'I have taken a backup of the site, and Product Vendors is active.', 'merchboard' ) . '</label></p>';
		submit_button( __( 'Migrate vendors', 'merchboard' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Step 26.3: the migrated users that still hold a Product Vendors role,
	 * with the form that removes those roles (VendorRoleActions).
	 */
	private function render_roles(): void {
		try {
			$users = null === $this->roles ? array() : $this->roles->users();
		} catch ( DatabaseException ) {
			$users = array();
		}

		if ( array() === $users ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Remove the Product Vendors roles', 'merchboard' ) . '</h2>';
		echo '<p>' . esc_html__( 'These migrated users still have a Product Vendors role, which keeps their Product Vendors access and wp-admin. Removing it leaves only their Merch Dashboard access; their other roles stay. Check the creators and their products first.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( VendorRoleActions::ACTION ) . '">';
		wp_nonce_field( VendorRoleActions::ACTION );
		echo '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>' . esc_html__( 'User', 'merchboard' ) . '</th><th>' . esc_html__( 'Creator', 'merchboard' ) . '</th><th>' . esc_html__( 'Product Vendors roles', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $users as $user ) {
			$id = 'merchboard-vendor-role-' . $user['user_id'];
			echo '<tr><th scope="row" class="check-column"><input type="checkbox" id="' . esc_attr( $id ) . '" name="users[]" value="' . esc_attr( (string) $user['user_id'] ) . '" checked></th><td><label for="' . esc_attr( $id ) . '">' . esc_html( $user['login'] ) . '</label>';

			if ( array() !== $user['other_vendors'] ) {
				/* translators: %s: vendor names. */
				echo '<br><em>' . esc_html( sprintf( __( 'Also administers vendors that were not migrated: %s. Removing the role ends that access too.', 'merchboard' ), implode( ', ', $user['other_vendors'] ) ) ) . '</em>';
			}

			echo '</td><td>' . esc_html( $user['creator_name'] ) . '</td><td>' . esc_html( implode( ', ', array_map( array( self::class, 'pv_role_label' ), $user['roles'] ) ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I have checked the migrated creators and their products.', 'merchboard' ) . '</label></p>';
		submit_button( __( 'Remove the Product Vendors roles', 'merchboard' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * One of Product Vendors' roles in words.
	 *
	 * @param string $role Role.
	 */
	private static function pv_role_label( string $role ): string {
		return match ( $role ) {
			ProductVendorsSource::ADMIN_ROLE   => __( 'Vendor Admin', 'merchboard' ),
			ProductVendorsSource::MANAGER_ROLE => __( 'Vendor Manager', 'merchboard' ),
			default                            => __( 'Pending Vendor', 'merchboard' ),
		};
	}

	/**
	 * The last migration.
	 */
	private function render_last(): void {
		wp_cache_delete( VendorImportPlan::OPTION, 'options' );
		$stored = get_option( VendorImportPlan::OPTION, null );

		if ( ! is_array( $stored ) || ! is_string( $stored['completed_at'] ?? null ) ) {
			return;
		}

		$last = is_array( $stored['last'] ?? null ) ? $stored['last'] : array();

		printf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: 1: date and time, 2: creators created, 3: creators used, 4: products linked, 5: products left, 6: failures. */
					__( 'Last migration: %1$s (%2$d creators created, %3$d existing creators used, %4$d products linked, %5$d products left at another creator, %6$d failed).', 'merchboard' ),
					get_date_from_gmt( $stored['completed_at'], (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) ),
					(int) ( $last['created'] ?? 0 ),
					(int) ( $last['matched'] ?? 0 ),
					(int) ( $last['linked'] ?? 0 ),
					(int) ( $last['conflict'] ?? 0 ),
					(int) ( $last['failed'] ?? 0 )
				)
			)
		);
	}

	/**
	 * The vendor's user: a choice when it has several approved
	 * administrators.
	 *
	 * @phpstan-param array{term_id: int, status: string, users: list<array{id: int, name: string, role: string, creator_id: int}>, user_id: int} $vendor
	 *
	 * @param array $vendor The vendor's row.
	 */
	private function render_users( array $vendor ): void {
		if ( array() === $vendor['users'] ) {
			echo '—';

			return;
		}

		if ( 1 === count( $vendor['users'] ) || VendorImportReport::IMPORTED === $vendor['status'] ) {
			$user = array_values( array_filter( $vendor['users'], static fn( array $user ): bool => $user['id'] === $vendor['user_id'] ) )[0] ?? $vendor['users'][0];
			echo esc_html( $user['name'] . ' – ' . self::role_label( $user['role'] ) );

			return;
		}

		$id = 'merchboard-vendor-user-' . $vendor['term_id'];
		echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'User to link', 'merchboard' ) . '</label>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( self::USER_ARG . '[' . $vendor['term_id'] . ']' ) . '">';

		foreach ( $vendor['users'] as $user ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $user['id'], selected( $user['id'], $vendor['user_id'], false ), esc_html( $user['name'] . ' – ' . self::role_label( $user['role'] ) ) );
		}

		echo '</select>';
		/* translators: %d: number of other users. */
		echo '<br><small>' . esc_html( sprintf( _n( '%d other user is not moved.', '%d other users are not moved.', count( $vendor['users'] ) - 1, 'merchboard' ), count( $vendor['users'] ) - 1 ) ) . '</small>';
	}

	/**
	 * What happens to a vendor.
	 *
	 * @param string $status       Status.
	 * @param string $creator_name The creator here.
	 */
	private static function status_label( string $status, string $creator_name ): string {
		return match ( $status ) {
			VendorImportReport::NEW        => __( 'New creator with the vendor\'s name and the user', 'merchboard' ),
			/* translators: %s: creator name. */
			VendorImportReport::MATCHED    => sprintf( __( 'Uses the user\'s creator "%s"', 'merchboard' ), $creator_name ),
			/* translators: %s: creator name. */
			VendorImportReport::IMPORTED   => sprintf( __( 'Migrated already to "%s": only what is missing is added', 'merchboard' ), $creator_name ),
			VendorImportReport::NO_ADMIN   => __( 'New creator without a user (the vendor has no administrator)', 'merchboard' ),
			VendorImportReport::PENDING    => __( 'Skipped: the vendor is waiting for approval', 'merchboard' ),
			default                        => __( 'Skipped: the user is chosen for another vendor. Choose another user or link it later.', 'merchboard' ),
		};
	}

	/**
	 * A Product Vendors role in words.
	 *
	 * @param string $role 'admin' or 'manager'.
	 */
	private static function role_label( string $role ): string {
		return 'admin' === $role ? __( 'Vendor Admin', 'merchboard' ) : __( 'Vendor Manager', 'merchboard' );
	}

	/**
	 * The vendor's products in words.
	 *
	 * @phpstan-param array{status: string, products: array{link: int, same: int, conflict: int}} $vendor
	 *
	 * @param array $vendor The vendor's row.
	 */
	private static function products_label( array $vendor ): string {
		$products = $vendor['products'];

		if ( ! VendorImportReport::migrates( $vendor['status'] ) ) {
			return '—';
		}

		return sprintf(
			/* translators: 1: products to link, 2: products linked already, 3: products linked to another creator. */
			__( '%1$d linked, %2$d linked already, %3$d left (another creator)', 'merchboard' ),
			$products['link'],
			$products['same'],
			$products['conflict']
		);
	}

	/**
	 * The settlement start in words.
	 *
	 * @param string|null $settlement 'new', a UTC time, '' (none) or null (skipped).
	 */
	private static function settlement_label( ?string $settlement ): string {
		return match ( true ) {
			null === $settlement => '—',
			'new' === $settlement => __( 'Set to the time of the migration', 'merchboard' ),
			'' === $settlement   => __( 'None: the whole history counts. Set it under Payouts if Product Vendors has paid for it.', 'merchboard' ),
			default              => get_date_from_gmt( $settlement, (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) ),
		};
	}
}
