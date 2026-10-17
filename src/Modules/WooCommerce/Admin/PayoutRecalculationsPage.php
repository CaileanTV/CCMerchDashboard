<?php
/**
 * Admin page of payout recalculations (ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculationExportRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculationExports;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculationRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\Recalculation;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\RecalculationItem;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Recalculations" (`merchboard_manage_payouts`): the
 * recalculations of settled payouts without the 20 % deduction (ADR-063),
 * newest first, and one recalculation with its items, rows, decisions and
 * the forms of the step its status allows. Reading never changes anything.
 */
final class PayoutRecalculationsPage {

	public const SLUG = 'merchboard-payout-recalculations';

	public const PER_PAGE = 20;

	/**
	 * Menu priority: after "Payouts".
	 */
	public const PRIORITY = 26;

	/**
	 * Constructor.
	 *
	 * @param PayoutRecalculationRepository            $recalculations Recalculations (read only).
	 * @param CreatorRepository                        $creators       Creator names.
	 * @param Logger                                   $logger         Technical log.
	 * @param PayoutRecalculationExportRepository|null $exports        Exports (read only); null = no exports section.
	 */
	public function __construct( private PayoutRecalculationRepository $recalculations, private CreatorRepository $creators, private Logger $logger, private ?PayoutRecalculationExportRepository $exports = null ) {
	}

	/**
	 * Adds the submenu page (hooked on `admin_menu` with PRIORITY).
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Recalculations', 'merchboard' ),
			__( 'Recalculations', 'merchboard' ),
			Capabilities::MANAGE_PAYOUTS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * URL of the page.
	 *
	 * @param array<string, int|string> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Message and type of an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'error'|'warning']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			PayoutRecalculationActions::PREVIEW_ACTION => array( __( 'The preview was created. Nothing was paid, offset or changed: review it, then approve or reject it.', 'merchboard' ), 'success' ),
			PayoutRecalculationActions::APPROVE_ACTION => array( __( 'The recalculation was approved. Nothing was executed yet: execute it as a separate step.', 'merchboard' ), 'success' ),
			PayoutRecalculationActions::REJECT_ACTION  => array( __( 'The recalculation was rejected. It stays in the history and is never reopened automatically.', 'merchboard' ), 'success' ),
			PayoutRecalculationActions::REOPEN_ACTION  => array( __( 'A new preview was created from the current data. The rejected recalculation is unchanged.', 'merchboard' ), 'success' ),
			PayoutRecalculationActions::EXECUTE_ACTION => array( __( 'The approved adjustments were executed. They count in the balances and are settled by each creator\'s next settlement document.', 'merchboard' ), 'success' ),
			PayoutRecalculationActions::CLOSE_ACTION   => array( __( 'The recalculation was closed.', 'merchboard' ), 'success' ),
			PayoutRecalculationActions::EXPORT_ACTION  => array( __( 'The export was created and stored. Earlier exports are kept unchanged.', 'merchboard' ), 'success' ),
			PayoutException::INVALID_FILTER            => array( __( 'No export was created: choose a valid group or enter a creator ID.', 'merchboard' ), 'error' ),
			PayoutException::EXPORT_INTEGRITY          => array( __( 'The export does not match its manifest and checksums. It is not delivered; nothing was changed.', 'merchboard' ), 'error' ),
			PayoutRecalculationActions::NOT_CONFIRMED  => array( __( 'Nothing was done: tick the confirmation first.', 'merchboard' ), 'error' ),
			PayoutException::RECALCULATION_OPEN        => array( __( 'Another recalculation is still open. Reject or close it first.', 'merchboard' ), 'error' ),
			PayoutException::RECALCULATION_STALE       => array( __( 'Nothing was done: the data changed after the preview or approval (amounts, rows, documents or groups). Reject this recalculation and create a new preview.', 'merchboard' ), 'error' ),
			PayoutException::RECALCULATION_STATE       => array( __( 'This step does not fit the recalculation\'s status; nothing was changed.', 'merchboard' ), 'warning' ),
			PayoutException::RECALCULATION_ITEM_FAILED => array( __( 'An item could not be executed and was marked as failed; the execution stopped. Items executed before it are kept. Check the cause, then execute again or close the recalculation with a note.', 'merchboard' ), 'error' ),
			PayoutException::INVALID_NOTE              => array( __( 'Nothing was done: enter a note of at most 500 characters.', 'merchboard' ), 'error' ),
			PayoutException::NOT_FOUND                 => array( __( 'The recalculation does not exist.', 'merchboard' ), 'error' ),
			PayoutException::NOT_AUTHORIZED            => array( __( 'You are not allowed to do this.', 'merchboard' ), 'error' ),
			PayoutException::BUSY                      => array( __( 'Another step is running. Please try again in a moment.', 'merchboard' ), 'error' ),
			'error'                                    => array( __( 'A technical error occurred. Nothing was changed; try again later.', 'merchboard' ), 'error' ),
			default                                    => null,
		};
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage payouts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only parameters, validated here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$input  = wp_unslash( $_GET );
		$id     = self::id( $input['recalculation'] ?? null );
		$page   = self::id( $input['paged'] ?? null ) ?? 1;
		$result = is_string( $input['result'] ?? null ) ? sanitize_key( $input['result'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap merchboard-recalculations"><h1>' . esc_html__( 'Recalculations', 'merchboard' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Settled payouts of creators who were not VAT registered are recalculated without the 20 % deduction (ADR-063). Only the deduction is removed; every other value stays as the settlement document stored it. Each step is separate: preview, approve or reject, execute, close.', 'merchboard' ) . '</p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Executing adds the approved differences to the creators\' balances; they appear on each creator\'s next settlement document. A negative difference is offset against what is available, and the rest is carried forward as outstanding. The legal right to offset has not been validated. Former creators are only reported: nothing is paid or offset for them automatically. Settlement documents and payouts are never changed.', 'merchboard' ) . '</p></div>';

		$notice = self::result_message( $result );

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		try {
			if ( null !== $id ) {
				$this->render_recalculation( $id );
			} else {
				$this->render_list( min( $page, 1000000 ) );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The recalculations could not be read.', array( 'error' => $e::class ) );
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The recalculations could not be read. Please try again later.', 'merchboard' ) . '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * The recalculations, newest first, and the form for a new preview.
	 *
	 * @param int $page Requested page.
	 * @throws DatabaseException When a read fails.
	 */
	private function render_list( int $page ): void {
		if ( null === $this->recalculations->open() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::hidden( PayoutRecalculationActions::PREVIEW_ACTION, 0 );
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create preview', 'merchboard' ) . '</button></p></form>';
		}

		$total = $this->recalculations->count();
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$runs  = $total > 0 && $page <= $pages ? $this->recalculations->page( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) : array();

		if ( array() === $runs ) {
			echo '<p>' . esc_html__( 'No recalculations yet.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'Reference', 'merchboard' ), __( 'Status', 'merchboard' ), __( 'Created', 'merchboard' ), __( 'Decided', 'merchboard' ), __( 'Closed', 'merchboard' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $runs as $run ) {
			echo '<tr><td><a href="' . esc_url( self::url( array( 'recalculation' => $run->id ) ) ) . '">' . esc_html( $run->reference() ) . '</a></td>';
			echo '<td>' . esc_html( self::status_label( $run->status ) ) . '</td>';
			echo '<td>' . esc_html( self::date( $run->created_at ) ) . '</td>';
			echo '<td>' . esc_html( null === $run->decided_at ? '–' : self::date( $run->decided_at ) ) . '</td>';
			echo '<td>' . esc_html( null === $run->closed_at ? '–' : self::date( $run->closed_at ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One recalculation with its items and the forms its status allows.
	 *
	 * @param int $id Recalculation.
	 * @throws DatabaseException When a read fails.
	 */
	private function render_recalculation( int $id ): void {
		$run = $this->recalculations->find( $id );

		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← All recalculations', 'merchboard' ) . '</a></p>';

		if ( null === $run ) {
			echo '<p>' . esc_html__( 'The recalculation does not exist.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<h2>' . esc_html( $run->reference() ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$fields = array(
			__( 'Status', 'merchboard' )               => self::status_label( $run->status ),
			__( 'Calculation', 'merchboard' )          => $run->calculation_version,
			__( 'Created', 'merchboard' )              => self::date( $run->created_at ),
			__( 'Reopens', 'merchboard' )              => null === $run->reopens_id ? '–' : Recalculation::reference_of( $run->reopens_id ),
			__( 'Decided', 'merchboard' )              => null === $run->decided_at ? '–' : self::date( $run->decided_at ),
			__( 'Note of the decision', 'merchboard' ) => '' === $run->decision_note ? '–' : $run->decision_note,
			__( 'Closed', 'merchboard' )               => null === $run->closed_at ? '–' : self::date( $run->closed_at ),
			__( 'Note of the closing', 'merchboard' )  => '' === $run->close_note ? '–' : $run->close_note,
		);

		foreach ( $fields as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';

		$items = $this->recalculations->items( $run->id );

		if ( array() === $items ) {
			echo '<p>' . esc_html__( 'Nothing to recalculate: no settled row of a creator who was not VAT registered is left.', 'merchboard' ) . '</p>';
		}

		foreach ( array( RecalculationItem::ACTIVE, RecalculationItem::FORMER, RecalculationItem::MANUAL ) as $category ) {
			$group = array_values( array_filter( $items, static fn( RecalculationItem $item ): bool => $category === $item->category ) );

			if ( array() !== $group ) {
				$this->render_group( $category, $group );
			}
		}

		$this->render_forms( $run );

		if ( null !== $this->exports ) {
			$this->render_exports( $run, $this->exports );
		}
	}

	/**
	 * The form for a new export and the stored exports with scope,
	 * integrity, comparison and downloads.
	 *
	 * @param Recalculation                       $run     Recalculation.
	 * @param PayoutRecalculationExportRepository $exports Exports.
	 * @throws DatabaseException When the exports cannot be read.
	 */
	private function render_exports( Recalculation $run, PayoutRecalculationExportRepository $exports ): void {
		echo '<h3>' . esc_html__( 'Exports', 'merchboard' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'An export is stored unchanged with a manifest and SHA-256 checksums; a new export never replaces an earlier one. A filtered export contains only the chosen items and is not the complete recalculation.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="merchboard-recalculation-export">';
		self::hidden( PayoutRecalculationActions::EXPORT_ACTION, $run->id );
		echo '<p><label for="merchboard-export-group">' . esc_html__( 'Group', 'merchboard' ) . '</label> <select id="merchboard-export-group" name="group">';
		echo '<option value="">' . esc_html__( 'All groups (complete)', 'merchboard' ) . '</option>';

		foreach ( array( RecalculationItem::ACTIVE, RecalculationItem::FORMER, RecalculationItem::MANUAL ) as $category ) {
			echo '<option value="' . esc_attr( $category ) . '">' . esc_html( self::category_label( $category ) ) . '</option>';
		}

		echo '</select> <label for="merchboard-export-creator">' . esc_html__( 'Creator ID (optional)', 'merchboard' ) . '</label> <input type="text" id="merchboard-export-creator" name="creator_id" inputmode="numeric" pattern="[1-9][0-9]*" size="8"></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Create export', 'merchboard' ) . '</button></p></form>';

		$list = $exports->for_recalculation( $run->id );

		if ( array() === $list ) {
			echo '<p>' . esc_html__( 'No exports yet.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'Export', 'merchboard' ), __( 'Created', 'merchboard' ), __( 'Scope', 'merchboard' ), __( 'Integrity', 'merchboard' ), __( 'Since the previous export', 'merchboard' ), __( 'Files', 'merchboard' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $list as $export ) {
			$files    = $export['files'] ?? array();
			$status   = PayoutRecalculationExports::check( $files, $export['manifest_sha256'], $export['sums_sha256'] );
			$intact   = array( 'ok' ) === array_values( array_unique( $status ) );
			$manifest = json_decode( $files[ PayoutRecalculationExports::MANIFEST ] ?? '', true );
			$scope    = is_array( $manifest ) && is_array( $manifest['scope'] ?? null ) ? $manifest['scope'] : array();
			$changes  = is_array( $manifest ) && is_array( $manifest['changes_since_previous'] ?? null ) ? $manifest['changes_since_previous'] : null;

			echo '<tr><td>' . esc_html( PayoutRecalculationExports::reference( $export['recalculation_id'], $export['id'] ) ) . '</td>';
			echo '<td>' . esc_html( $export['created_at'] ) . ' UTC</td>';
			/* translators: 1: exported items, 2: all items, 3: exported rows. */
			$counts = sprintf( __( '%1$s of %2$s items, %3$s rows', 'merchboard' ), number_format_i18n( (int) ( $scope['items_exported'] ?? 0 ) ), number_format_i18n( (int) ( $scope['items_total'] ?? 0 ) ), number_format_i18n( (int) ( $scope['rows_exported'] ?? 0 ) ) );
			echo '<td>' . esc_html( true === ( $scope['complete'] ?? null ) ? __( 'Complete', 'merchboard' ) : __( 'Filtered: not the complete recalculation', 'merchboard' ) ) . '<br><span class="description">' . esc_html( $counts . ' · ' . $export['filters'] ) . '</span></td>';
			echo '<td>' . ( $intact ? esc_html__( 'Verified', 'merchboard' ) : '<strong>' . esc_html( __( 'Not intact', 'merchboard' ) . ': ' . implode( ', ', array_map( static fn( string $name, string $state ): string => $name . ' ' . $state, array_keys( $status ), $status ) ) ) . '</strong>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			echo '<td>' . esc_html( null === $changes ? __( 'No earlier export with the same filters', 'merchboard' ) : implode( ', ', array_map( static fn( mixed $name, mixed $state ): string => (string) $name . ' ' . ( is_scalar( $state ) ? (string) $state : '' ), array_keys( $changes ), $changes ) ) ) . '</td><td>';

			if ( $intact ) {
				foreach ( array_merge( PayoutRecalculationExports::DATA_FILES, array( PayoutRecalculationExports::MANIFEST, PayoutRecalculationExports::SUMS ) ) as $name ) {
					echo '<a href="' . esc_url( PayoutRecalculationExportDownload::url( $export['id'], $name ) ) . '">' . esc_html( $name ) . '</a><br>';
				}
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Check an export: put all five files in one folder. Linux and Git Bash: sha256sum -c SHA256SUMS. macOS: shasum -a 256 -c SHA256SUMS. Windows PowerShell: Get-FileHash -Algorithm SHA256 <file> and compare with the line of the file in SHA256SUMS.', 'merchboard' ) . '</p>';
	}

	/**
	 * The items of one group.
	 *
	 * @phpstan-param list<RecalculationItem> $items
	 *
	 * @param string              $category Group.
	 * @param RecalculationItem[] $items    Items.
	 */
	private function render_group( string $category, array $items ): void {
		echo '<h3>' . esc_html( self::category_label( $category ) ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'Creator', 'merchboard' ), __( 'Currency', 'merchboard' ), __( 'Status', 'merchboard' ), __( 'Rows', 'merchboard' ), __( 'Original', 'merchboard' ), __( 'Recalculated', 'merchboard' ), __( 'Difference', 'merchboard' ), __( 'Details', 'merchboard' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $items as $item ) {
			$money = static fn( ?int $units ): string => null === $units ? '–' : CreatorSalesSection::money( SalesAmount::format( $units ), $item->currency );
			echo '<tr><td>' . esc_html( $this->creator_name( $item->creator_id ) ) . '</td>';
			echo '<td>' . esc_html( $item->currency ) . '</td>';
			echo '<td>' . esc_html( self::item_status_label( $item ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $item->row_count ) ) . '</td>';
			echo '<td>' . esc_html( $money( $item->original_units ) ) . '</td>';
			echo '<td>' . esc_html( $money( $item->recalculated_units ) ) . '</td>';
			echo '<td><strong>' . esc_html( $money( $item->difference_units ) ) . '</strong></td><td>';
			$this->render_detail( $item );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The rows and documents of an item, collapsed.
	 *
	 * @param RecalculationItem $item Item.
	 */
	private function render_detail( RecalculationItem $item ): void {
		$rows       = is_array( $item->detail['rows'] ?? null ) ? $item->detail['rows'] : array();
		$documents  = is_array( $item->detail['documents'] ?? null ) ? $item->detail['documents'] : array();
		$undocument = $item->detail['undocumented_payouts'] ?? null;

		if ( RecalculationItem::REASON_INCONSISTENT_ROW === $item->reason ) {
			echo '<p>' . esc_html__( 'A settled row does not reproduce from its stored values. Nothing is recalculated for this creator and currency; assess it manually.', 'merchboard' ) . '</p>';
		}

		if ( is_numeric( $undocument ) ) {
			/* translators: %s: number of payouts. */
			echo '<p>' . esc_html( sprintf( __( '%s completed payouts have no settlement document (completed before settlement documents were introduced): their rows are not known. Assess them manually.', 'merchboard' ), number_format_i18n( (int) $undocument ) ) ) . '</p>';
		}

		if ( array() === $rows ) {
			return;
		}

		echo '<details><summary>' . esc_html( implode( ', ', array_map( static fn( mixed $number ): string => is_scalar( $number ) ? (string) $number : '', $documents ) ) ) . '</summary>';
		echo '<table class="widefat"><thead><tr>';

		foreach ( array( __( 'Document', 'merchboard' ), __( 'Order', 'merchboard' ), __( 'Type', 'merchboard' ), __( 'Profit', 'merchboard' ), __( 'Original', 'merchboard' ), __( 'Recalculated', 'merchboard' ), __( 'Difference', 'merchboard' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			echo '<tr>';

			foreach ( array( 'document_number', 'order_id', 'type', 'profit', 'original', 'recalculated', 'difference' ) as $key ) {
				$value = $row[ $key ] ?? null;
				echo '<td>' . esc_html( is_scalar( $value ) ? (string) $value : '–' ) . '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table></details>';
	}

	/**
	 * The forms the status allows.
	 *
	 * @param Recalculation $run Recalculation.
	 */
	private function render_forms( Recalculation $run ): void {
		echo '<h3>' . esc_html__( 'Next step', 'merchboard' ) . '</h3>';

		switch ( $run->status ) {
			case Recalculation::PREVIEW:
				self::form( $run->id, PayoutRecalculationActions::APPROVE_ACTION, __( 'Approve', 'merchboard' ), __( 'I have reviewed the preview. Approving executes nothing.', 'merchboard' ), false );
				self::form( $run->id, PayoutRecalculationActions::REJECT_ACTION, __( 'Reject', 'merchboard' ), null, true );
				break;
			case Recalculation::APPROVED:
				self::form( $run->id, PayoutRecalculationActions::EXECUTE_ACTION, __( 'Execute the approved adjustments', 'merchboard' ), __( 'Execute the approved differences of the active creators now. The basis is checked again first.', 'merchboard' ), null );
				self::form( $run->id, PayoutRecalculationActions::REJECT_ACTION, __( 'Reject', 'merchboard' ), null, true );
				break;
			case Recalculation::PARTIALLY_EXECUTED:
				self::form( $run->id, PayoutRecalculationActions::EXECUTE_ACTION, __( 'Execute the remaining items', 'merchboard' ), __( 'Execute the items that are not executed yet. Executed items are never executed again.', 'merchboard' ), null );
				self::form( $run->id, PayoutRecalculationActions::CLOSE_ACTION, __( 'Close', 'merchboard' ), null, true );
				break;
			case Recalculation::EXECUTED:
				self::form( $run->id, PayoutRecalculationActions::CLOSE_ACTION, __( 'Close', 'merchboard' ), null, false );
				break;
			case Recalculation::REJECTED:
				self::form( $run->id, PayoutRecalculationActions::REOPEN_ACTION, __( 'Reopen as a new preview', 'merchboard' ), null, null );
				break;
			default:
				echo '<p>' . esc_html__( 'This recalculation is closed.', 'merchboard' ) . '</p>';
		}
	}

	/**
	 * A form for one step.
	 *
	 * @param int         $id      Recalculation.
	 * @param string      $action  Action.
	 * @param string      $label   Button label.
	 * @param string|null $confirm Confirmation text; null = none.
	 * @param bool|null   $note    Note field: true required, false optional, null none.
	 */
	private static function form( int $id, string $action, string $label, ?string $confirm, ?bool $note ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="merchboard-recalculation-step">';
		self::hidden( $action, $id );

		if ( null !== $note ) {
			$field = 'merchboard-note-' . $action;
			echo '<p><label for="' . esc_attr( $field ) . '">' . esc_html( $note ? __( 'Note (required, at most 500 characters)', 'merchboard' ) : __( 'Note (optional, at most 500 characters)', 'merchboard' ) ) . '</label><br>';
			echo '<textarea id="' . esc_attr( $field ) . '" name="note" rows="2" cols="60" maxlength="500"' . ( $note ? ' required' : '' ) . '></textarea></p>';
		}

		if ( null !== $confirm ) {
			echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html( $confirm ) . '</label></p>';
		}

		echo '<p><button type="submit" class="button' . ( PayoutRecalculationActions::REJECT_ACTION === $action ? '' : ' button-primary' ) . '">' . esc_html( $label ) . '</button></p></form>';
	}

	/**
	 * Hidden fields of a form.
	 *
	 * @param string $action Action.
	 * @param int    $id     Recalculation (0 for a new preview).
	 */
	private static function hidden( string $action, int $id ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="recalculation_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( PayoutRecalculationActions::nonce_action( $action, $id ) );
	}

	/**
	 * A creator's name, or the id when unknown.
	 *
	 * @param int $creator_id Creator.
	 */
	private function creator_name( int $creator_id ): string {
		try {
			$creator = $this->creators->find( $creator_id );
		} catch ( DatabaseException ) {
			$creator = null;
		}

		/* translators: %d: creator id. */
		return null === $creator ? sprintf( __( 'Creator #%d', 'merchboard' ), $creator_id ) : $creator->display_name;
	}

	/**
	 * Label of a recalculation status.
	 *
	 * @param string $status Status.
	 */
	private static function status_label( string $status ): string {
		return match ( $status ) {
			Recalculation::PREVIEW            => __( 'Preview', 'merchboard' ),
			Recalculation::APPROVED           => __( 'Approved', 'merchboard' ),
			Recalculation::REJECTED           => __( 'Rejected', 'merchboard' ),
			Recalculation::PARTIALLY_EXECUTED => __( 'Partly executed', 'merchboard' ),
			Recalculation::EXECUTED           => __( 'Executed', 'merchboard' ),
			Recalculation::CLOSED             => __( 'Closed', 'merchboard' ),
			default                           => $status,
		};
	}

	/**
	 * Label of a group.
	 *
	 * @param string $category Group.
	 */
	private static function category_label( string $category ): string {
		return match ( $category ) {
			RecalculationItem::ACTIVE => __( 'Active creators: can be approved and executed', 'merchboard' ),
			RecalculationItem::FORMER => __( 'Former creators: reported only, never paid or offset automatically', 'merchboard' ),
			default                   => __( 'Manual assessment: cannot be recalculated safely', 'merchboard' ),
		};
	}

	/**
	 * Label of an item's status, with the failure of a failed one.
	 *
	 * @param RecalculationItem $item Item.
	 */
	private static function item_status_label( RecalculationItem $item ): string {
		return match ( $item->status ) {
			RecalculationItem::PROPOSED     => __( 'Proposed', 'merchboard' ),
			RecalculationItem::EXECUTED     => __( 'Executed', 'merchboard' ),
			/* translators: %s: failure code. */
			RecalculationItem::FAILED       => sprintf( __( 'Failed (%s)', 'merchboard' ), $item->failure ),
			RecalculationItem::REPORTED     => __( 'Reported', 'merchboard' ),
			RecalculationItem::NEEDS_REVIEW => __( 'Manual assessment', 'merchboard' ),
			default                         => $item->status,
		};
	}

	/**
	 * A UTC time in the site's format and time zone.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function date( DateTimeImmutable $time ): string {
		$text = wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $time->getTimestamp() );

		return is_string( $text ) ? $text : $time->format( 'Y-m-d H:i' );
	}

	/**
	 * A positive id from a request value.
	 *
	 * @param mixed $value Value.
	 */
	private static function id( mixed $value ): ?int {
		return is_string( $value ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', $value ) ? (int) $value : null;
	}
}
