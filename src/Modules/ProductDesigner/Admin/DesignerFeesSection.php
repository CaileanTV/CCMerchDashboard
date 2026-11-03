<?php
/**
 * The digitization fee of the product designer.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Fees\DesignerFeeRepository;
use MerchBoard\Modules\ProductDesigner\Fees\DesignerFees;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * The tab Product designer → Fees (ADR-086): the amount and the list of fees,
 * where a charged one can be cancelled. Needs `merchboard_manage_payouts`,
 * because it changes what creators are paid. Post/Redirect/Get with a
 * whitelisted outcome code.
 */
final class DesignerFeesSection {

	public const SAVE_ACTION = 'merchboard_save_designer_fee';

	public const CANCEL_ACTION = 'merchboard_cancel_designer_fee';

	private const RESULT_ARG = 'merchboard_fee_result';

	/**
	 * Constructor.
	 *
	 * @param DesignerFees          $service Fees.
	 * @param DesignerFeeRepository $fees    The list.
	 * @param Logger                $logger  Technical log.
	 */
	public function __construct( private DesignerFees $service, private DesignerFeeRepository $fees, private Logger $logger ) {
	}

	/**
	 * Registers the admin-post handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::CANCEL_ACTION, array( $this, 'handle_cancel' ) );
	}

	/**
	 * Saves the amount.
	 */
	public function handle_save(): void {
		check_admin_referer( self::SAVE_ACTION );

		$amount = isset( $_POST['amount'] ) && is_string( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';

		$this->finish( fn() => $this->service->set_amount( $amount ), 'saved' );
	}

	/**
	 * Cancels a charged fee.
	 */
	public function handle_cancel(): void {
		$id = isset( $_POST['fee_id'] ) && is_string( $_POST['fee_id'] ) ? absint( $_POST['fee_id'] ) : 0;

		check_admin_referer( self::CANCEL_ACTION . '_' . $id );

		$this->finish( fn() => $this->service->cancel( $id ), 'cancelled' );
	}

	/**
	 * The tab.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			return;
		}

		$this->render_result();

		$amount = SalesAmount::format( Settings::digitization_fee_units() );

		echo '<h2>' . esc_html__( 'Digitization fee', 'merchboard' ) . '</h2>';
		echo '<p>' . esc_html__( 'A new embroidery file costs a digitization fee. Creators see this amount in the designer. It is drawn from the creator\'s account at the first sale of a product with the file, whichever variant it is, and also on the creator\'s own order. The account can go below zero, and the amount is then offset against coming profit. A file the creator has used before costs nothing. The amount includes VAT, like the other prices. 0 means no fee.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<p><label>' . esc_html__( 'Fee per new embroidery file incl. VAT', 'merchboard' ) . ' <input type="text" inputmode="decimal" name="amount" value="' . esc_attr( rtrim( rtrim( $amount, '0' ), '.' ) ) . '" style="width:8rem"></label> ';
		submit_button( __( 'Save', 'merchboard' ), 'secondary', 'submit', false );
		echo '</p></form>';

		$this->render_list();
	}

	/**
	 * The list of fees.
	 */
	private function render_list(): void {
		try {
			$rows = $this->fees->recent( DesignerFees::LIST_LIMIT );
		} catch ( DatabaseException ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The fees could not be read.', 'merchboard' ) . '</p></div>';

			return;
		}

		echo '<h2>' . esc_html__( 'Fees', 'merchboard' ) . '</h2>';

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No fees yet.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Creator', 'merchboard' ) . '</th><th>' . esc_html__( 'Product', 'merchboard' ) . '</th><th>' . esc_html__( 'Side', 'merchboard' ) . '</th><th>' . esc_html__( 'Amount', 'merchboard' ) . '</th><th>' . esc_html__( 'Status', 'merchboard' ) . '</th><th>' . esc_html__( 'Order', 'merchboard' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$charged = DesignerFeeRepository::CHARGED === $row['status'];

			echo '<tr><td>' . esc_html( (string) ( $row['display_name'] ?? '' ) ) . '</td><td>' . esc_html( (string) $row['title'] ) . '</td><td>' . esc_html( (string) $row['side'] ) . '</td>';
			echo '<td>' . esc_html( rtrim( rtrim( (string) $row['amount'], '0' ), '.' ) . ' ' . (string) $row['currency'] ) . '</td>';
			echo '<td>' . esc_html( $charged ? __( 'Charged', 'merchboard' ) : __( 'Waiting for the first sale', 'merchboard' ) ) . '</td>';
			// A waiting fee may remember the order it was charged on before it was cancelled; only a charged fee shows one.
			echo '<td>' . esc_html( null === $row['order_id'] || DesignerFeeRepository::CHARGED !== $row['status'] ? '' : '#' . (string) $row['order_id'] ) . '</td><td>';

			if ( $charged ) {
				$id = (int) $row['id'];

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr__( 'Cancel this fee? The creator gets the amount back, and the next sale charges it again.', 'merchboard' ) . '">';
				echo '<input type="hidden" name="action" value="' . esc_attr( self::CANCEL_ACTION ) . '"><input type="hidden" name="fee_id" value="' . esc_attr( (string) $id ) . '">';
				wp_nonce_field( self::CANCEL_ACTION . '_' . $id );
				echo '<button type="submit" class="button-link button-link-delete">' . esc_html__( 'Cancel the fee', 'merchboard' ) . '</button></form>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The outcome of the last action.
	 */
	private function render_result(): void {
		$result = isset( $_GET[ self::RESULT_ARG ] ) && is_string( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a whitelisted message.
		$known  = array(
			'saved'     => array( __( 'The fee was saved.', 'merchboard' ), 'success' ),
			'cancelled' => array( __( 'The fee was cancelled and paid back. The next sale charges it again.', 'merchboard' ), 'success' ),
			'refused'   => array( __( 'That was not possible: check the amount, or whether the fee is still charged.', 'merchboard' ), 'error' ),
			'failed'    => array( __( 'It could not be saved. Nothing was changed.', 'merchboard' ), 'error' ),
		);

		if ( isset( $known[ $result ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $known[ $result ][1] ) . ' inline"><p>' . esc_html( $known[ $result ][0] ) . '</p></div>';
		}
	}

	/**
	 * Runs an action and goes back to the tab with its outcome.
	 *
	 * @param callable $work   The action.
	 * @param string   $result Outcome when it works.
	 */
	private function finish( callable $work, string $result ): void {
		try {
			$work();
		} catch ( DesignerException ) {
			$result = 'refused';
		} catch ( Throwable $e ) {
			$this->logger->error( 'A digitization fee could not be changed.', array( 'exception' => $e::class ) );
			$result = 'failed';
		}

		wp_safe_redirect( DesignerMenu::url( DesignerMenu::FEES, array( self::RESULT_ARG => $result ) ) );
		exit;
	}
}
