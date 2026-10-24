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
 * "Merch Dashboard → Import from Product Vendors" (step 26.1, ADR-074): what
 * is migrated and what is not, and a dry run with the chosen user per
 * vendor (read-only, `preview=1`, the choices as `user[term_id]`). Shown
 * while Product Vendors is active or after a migration. The page itself
 * changes nothing.
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
	 * Constructor.
	 *
	 * @param VendorImportPlan     $plan   The dry run.
	 * @param ProductVendorsSource $source Product Vendors.
	 */
	public function __construct( private VendorImportPlan $plan, private ProductVendorsSource $source ) {
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap"><h1>' . esc_html__( 'Import from Product Vendors', 'merchboard' ) . '</h1>';
		echo '<p>' . esc_html__( 'Moves the vendors from WooCommerce Product Vendors to Merch Dashboard once: a creator per vendor, the vendor\'s WordPress user linked to it, and the vendor\'s products linked to the creator. A vendor whose user is linked to a creator already uses that creator. Product Vendors\' data is only read, never changed.', 'merchboard' ) . '</p>';
		echo '<ul class="ul-disc"><li>' . esc_html__( 'Not moved: commissions, payouts, PayPal and other payment details.', 'merchboard' ) . '</li><li>' . esc_html__( 'New creators get their settlement start at the time of the migration, so sales Product Vendors has paid commission for are never paid out again.', 'merchboard' ) . '</li><li>' . esc_html__( 'A creator has one WordPress user. When a vendor has several, choose the one to link; the others are not moved.', 'merchboard' ) . '</li><li>' . esc_html__( 'Vendors waiting for approval are skipped.', 'merchboard' ) . '</li><li>' . esc_html__( 'Keep Product Vendors active until the migration is done: it is read through Product Vendors itself.', 'merchboard' ) . '</li></ul>';

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
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This is a dry run: nothing was changed. The migration itself comes in the next step (26.2).', 'merchboard' ) . '</p></div>';
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
