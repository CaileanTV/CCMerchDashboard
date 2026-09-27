<?php
/**
 * The full category reconciliation view on the Creators page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use LogicException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AuditLogPage;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationBatch;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationTrigger;
use MerchBoard\Modules\WooCommerce\CategorySyncReport;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\ManualLinkConflict;
use MerchBoard\Modules\WooCommerce\PreloadedMappings;
use MerchBoard\Modules\WooCommerce\ProductRecord;
use MerchBoard\Modules\WooCommerce\ProductStore;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-033's safety net for changes no hook sees, as a view of Merch
 * Dashboard → Creators (`action=category-sync`, linked from the list; no new
 * menu item): a dry run of the whole catalogue (CategoryReconciliationBatch::
 * preview(), nothing written), its counts, expected changes and the products
 * that need attention, and the explicit confirmation that starts the run in
 * the background (CategorySyncActions).
 *
 * "Needs attention" lists what the dry run found: ambiguous products
 * (categories of several creators; never linked by the reconciliation),
 * products that could not be evaluated, and products whose only mapped
 * creator is inactive (no new link). It also lists manual links that deviate
 * from the product's creator categories (ManualLinkConflict: another creator,
 * an inactive creator or ambiguous categories); the manual link wins and is
 * kept (ADR-033 point 3). Each row shows the current creator and the mapped
 * categories; nothing is ever fixed here. Nothing is stored.
 *
 * Only for `merchboard_manage_creators`. Products are shown with name, SKU
 * and, for users who may edit them, a link to their edit screen; no customer
 * data.
 */
final class CategorySyncScreen {

	/**
	 * Most rows listed per table (the report holds at most this many).
	 */
	private const LIST_LIMIT = CategoryReconciliationBatch::MAX_CHANGES;

	/**
	 * Creators read while rendering "Needs attention", by id (each at most once).
	 *
	 * @var array<int, Creator|null>
	 */
	private array $creator_cache = array();

	/**
	 * Constructor.
	 *
	 * @param CategoryReconciliationBatch $batch    Dry runs.
	 * @param ProductStore                $products Product reads.
	 * @param CreatorCategoryRepository   $mappings Mapping reads.
	 * @param CreatorRepository           $creators Creator names.
	 */
	public function __construct(
		private CategoryReconciliationBatch $batch,
		private ProductStore $products,
		private CreatorCategoryRepository $mappings,
		private CreatorRepository $creators
	) {
	}

	/**
	 * Adds the link on the Creators list and the view.
	 */
	public function register(): void {
		add_action( 'merchboard_creators_list_tools', array( $this, 'render_link' ) );
		add_action( 'merchboard_creators_view_' . CategorySyncActions::VIEW, array( $this, 'render' ) );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'sync_started'          => array( __( 'The full reconciliation has started in the background. It runs in batches of 100 products; when the last batch is done, "Category reconciliation completed" with its run id and counts appears in the audit log.', 'merchboard' ), 'success' ),
			'confirmation_required' => array( __( 'Nothing started: confirm that you reviewed the dry run.', 'merchboard' ), 'error' ),
			'preview_outdated'      => array( __( 'Nothing started: the products or mappings changed since your dry run. Review the new dry run below and confirm again.', 'merchboard' ), 'error' ),
			'queue_unavailable'     => array( __( 'Nothing started: the background queue (Action Scheduler) is not available. Nothing was changed.', 'merchboard' ), 'error' ),
			'error'                 => array( __( 'A technical error occurred. Nothing was started.', 'merchboard' ), 'error' ),
			default                 => null,
		};
	}

	/**
	 * "Category reconciliation" link next to "Add New Creator".
	 */
	public function render_link(): void {
		if ( current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			echo ' <a href="' . esc_url( CategorySyncActions::url() ) . '" class="page-title-action">' . esc_html__( 'Category reconciliation', 'merchboard' ) . '</a>';
		}
	}

	/**
	 * The view.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return;
		}

		// Read-only display parameters; nothing is changed by this request.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$result  = isset( $_GET[ CategorySyncActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ CategorySyncActions::RESULT_ARG ] ) ) : '';
		$preview = isset( $_GET[ CategorySyncActions::PREVIEW_ARG ] ) && '1' === $_GET[ CategorySyncActions::PREVIEW_ARG ];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<h1>' . esc_html__( 'Category reconciliation', 'merchboard' ) . '</h1>';
		echo '<p><a href="' . esc_url( CreatorsAdminPage::url() ) . '">' . esc_html__( 'Back to creators', 'merchboard' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Brings every product\'s category-managed creator link in line with the product category mappings, for changes no automatic update saw. Manual links are never changed, ambiguous products get no link, and inactive creators get no new links. A category mapping is not ownership: only the product\'s creator link is.', 'merchboard' ) . '</p>';

		$message = '' === $result ? null : self::result_message( $result );

		if ( null !== $message ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $message[1] ), esc_html( $message[0] ) );
		}

		if ( 'sync_started' === $result ) {
			echo '<p><a href="' . esc_url( AuditLogPage::url() ) . '">' . esc_html__( 'Open the audit log', 'merchboard' ) . '</a></p>';
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( CreatorsAdminPage::SLUG ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CategorySyncActions::VIEW ) . '">';
		echo '<input type="hidden" name="' . esc_attr( CategorySyncActions::PREVIEW_ARG ) . '" value="1">';
		echo '<button type="submit" class="button">' . esc_html__( 'Run a dry run', 'merchboard' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Evaluates every relevant product now and changes nothing.', 'merchboard' ) . '</span>';
		echo '</form>';

		if ( ! $preview ) {
			return;
		}

		$user_id = get_current_user_id();

		try {
			$report = $this->batch->preview( CategoryReconciliationTrigger::FullSync, $user_id > 0 ? $user_id : null );
		} catch ( DatabaseException | LogicException ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html__( 'The dry run could not be completed. Nothing was changed.', 'merchboard' ) );
			return;
		}

		$this->render_report( $report );
		$this->render_attention( $report );
		$this->render_changes( $report );
		self::render_confirmation( $report );
	}

	/**
	 * Counts per outcome and the total.
	 *
	 * @param CategorySyncReport $report Dry run.
	 */
	private function render_report( CategorySyncReport $report ): void {
		echo '<h2>' . esc_html__( 'Dry run', 'merchboard' ) . '</h2>';
		echo '<table class="widefat striped merchboard-sync-counts"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Outcome', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Products', 'merchboard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( ReconciliationOutcome::cases() as $outcome ) {
			echo '<tr><td>' . esc_html( CreatorCategoriesSection::outcome_label( $outcome ) ) . '</td><td>' . esc_html( (string) $report->result->count( $outcome ) ) . '</td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'Total', 'merchboard' ) . '</th><td>' . esc_html( (string) $report->result->total() ) . '</td></tr>';
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'This is a dry run. Nothing has been changed.', 'merchboard' ) . '</p>';
	}

	/**
	 * Products that need an administrator's attention.
	 *
	 * @param CategorySyncReport $report Dry run.
	 */
	private function render_attention( CategorySyncReport $report ): void {
		echo '<h2 id="merchboard-needs-attention">' . esc_html__( 'Needs attention', 'merchboard' ) . '</h2>';

		if ( array() === $report->attention ) {
			echo '<p>' . esc_html__( 'Nothing needs attention.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'The reconciliation does not decide these products. Fix them in WooCommerce (product categories) or on the creator\'s page (manual link, mapping, status). Nothing here is changed automatically.', 'merchboard' ) . '</p>';

		try {
			$mappings = new PreloadedMappings( $this->mappings );
		} catch ( DatabaseException ) {
			$mappings = null;
		}

		$rows                = array();
		$this->creator_cache = array();

		foreach ( $report->attention as $entry ) {
			$product = $this->products->find( $entry['product_id'] );
			$rows[]  = array(
				'entry'    => $entry,
				'product'  => $product,
				'term_ids' => null === $product || null === $mappings ? array() : array_values( array_filter( $product->category_ids, static fn( int $id ): bool => null !== $mappings->find( $id ) ) ),
			);
		}

		$names = self::term_names( array_values( array_unique( array_merge( array(), ...array_column( $rows, 'term_ids' ) ) ) ) );

		echo '<table class="widefat striped merchboard-sync-attention"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Product', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Why', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Current creator', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Mapped categories', 'merchboard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$categories = array();

			foreach ( $row['term_ids'] as $term_id ) {
				$mapping      = $mappings?->find( $term_id );
				$categories[] = sprintf(
					/* translators: 1: category name, 2: creator name. */
					__( '%1$s → %2$s', 'merchboard' ),
					/* translators: %d: term id. */
					$names[ $term_id ] ?? sprintf( __( 'Deleted category (#%d)', 'merchboard' ), $term_id ),
					null === $mapping ? '–' : $this->creator_label( $mapping->creator_id )
				);
			}

			$entry = $row['entry'];

			echo '<tr><td>' . $this->product_cell( $entry['product_id'], $row['product'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in product_cell().
			echo '<td>' . esc_html( self::attention_reason( $entry ) ) . '</td>';
			echo '<td>' . $this->current_creator_cell( $entry, $row['product'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in current_creator_cell().
			echo '<td>' . esc_html( array() === $categories ? '–' : implode( '; ', $categories ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $report->attention_truncated ) {
			/* translators: %d: number of products shown. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Only the first %d products are shown.', 'merchboard' ), self::LIST_LIMIT ) ) . '</p>';
		}
	}

	/**
	 * The expected link changes.
	 *
	 * @param CategorySyncReport $report Dry run.
	 */
	private function render_changes( CategorySyncReport $report ): void {
		echo '<h2>' . esc_html__( 'Expected changes', 'merchboard' ) . '</h2>';

		if ( array() === $report->changes ) {
			echo '<p>' . esc_html__( 'No product creator link would change.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped merchboard-sync-changes"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Product', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Outcome', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Current creator', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'New creator', 'merchboard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $report->changes as $change ) {
			$outcome = ReconciliationOutcome::tryFrom( $change['outcome'] );

			echo '<tr><td>' . $this->product_cell( $change['product_id'], $this->products->find( $change['product_id'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in product_cell().
			echo '<td>' . esc_html( null === $outcome ? $change['outcome'] : CreatorCategoriesSection::outcome_label( $outcome ) ) . '</td>';
			echo '<td>' . esc_html( $this->creator_name( $change['from_creator_id'] ) ) . '</td>';
			echo '<td>' . esc_html( $this->creator_name( $change['to_creator_id'] ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $report->changes_truncated ) {
			/* translators: %d: number of products shown. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Only the first %d products are shown.', 'merchboard' ), self::LIST_LIMIT ) ) . '</p>';
		}
	}

	/**
	 * The explicit confirmation that starts the run.
	 *
	 * @param CategorySyncReport $report Dry run.
	 */
	private static function render_confirmation( CategorySyncReport $report ): void {
		echo '<h2>' . esc_html__( 'Run the reconciliation', 'merchboard' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CategorySyncActions::ACTION ) . '">';
		echo '<input type="hidden" name="fingerprint" value="' . esc_attr( $report->fingerprint() ) . '">';
		wp_nonce_field( CategorySyncActions::ACTION );
		echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I have reviewed the dry run. Run the reconciliation now: it can create, move and remove category-managed product creator links as shown above. It runs in the background; if the products or mappings change first, nothing starts and a new dry run is shown.', 'merchboard' ) . '</label></p>';
		submit_button( __( 'Start the reconciliation', 'merchboard' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Product name, SKU and, when the user may edit it, a link. Escaped HTML.
	 *
	 * @param int                $product_id Product id.
	 * @param ProductRecord|null $product    Product, if found.
	 */
	private function product_cell( int $product_id, ?ProductRecord $product ): string {
		if ( null === $product ) {
			return esc_html( '#' . $product_id );
		}

		$name = '' === $product->name ? __( '(no name)', 'merchboard' ) : $product->name;
		/* translators: 1: product name, 2: product id. */
		$label = esc_html( sprintf( __( '%1$s (#%2$d)', 'merchboard' ), $name, $product->id ) );
		$link  = current_user_can( Capabilities::EDIT_PRODUCT, $product->id ) ? get_edit_post_link( $product->id, 'raw' ) : null;
		$html  = is_string( $link ) && '' !== $link ? '<a href="' . esc_url( $link ) . '">' . $label . '</a>' : $label;

		return '' === $product->sku ? $html : $html . '<br><span class="description">' . esc_html( $product->sku ) . '</span>';
	}

	/**
	 * The product's current creator link in a "Needs attention" row, marked
	 * "Manual" for a deviating manual link. Escaped HTML.
	 *
	 * @param array{product_id: int, outcome: string, conflict?: string, creator_id?: int|null, mapped_creator_ids?: list<int>} $entry   Attention entry.
	 * @param ProductRecord|null                                                                                                $product Product, if found.
	 */
	private function current_creator_cell( array $entry, ?ProductRecord $product ): string {
		if ( ! isset( $entry['conflict'] ) ) {
			$creator_id = $product?->creator_id();

			return esc_html( null === $creator_id ? '–' : $this->creator_label( $creator_id ) );
		}

		$creator_id = $entry['creator_id'] ?? null;
		$label      = null === $creator_id ? __( 'Invalid link', 'merchboard' ) : $this->creator_label( $creator_id );

		return esc_html( $label ) . '<br><span class="description">' . esc_html__( 'Manual', 'merchboard' ) . '</span>';
	}

	/**
	 * A creator's name, marked when inactive; "#id" when not found. Each
	 * creator is read at most once per rendering.
	 *
	 * @param int $creator_id Creator id.
	 */
	private function creator_label( int $creator_id ): string {
		if ( ! array_key_exists( $creator_id, $this->creator_cache ) ) {
			$this->creator_cache[ $creator_id ] = $creator_id > 0 ? $this->creators->find( $creator_id ) : null;
		}

		$creator = $this->creator_cache[ $creator_id ];

		if ( null === $creator ) {
			return '#' . $creator_id;
		}

		return $creator->display_name . ( CreatorStatus::Inactive === $creator->status ? ' ' . __( '(inactive)', 'merchboard' ) : '' );
	}

	/**
	 * Explanation of an attention entry.
	 *
	 * @param array{product_id: int, outcome: string, conflict?: string, creator_id?: int|null, mapped_creator_ids?: list<int>} $entry Attention entry.
	 */
	private static function attention_reason( array $entry ): string {
		if ( isset( $entry['conflict'] ) ) {
			return match ( ManualLinkConflict::tryFrom( $entry['conflict'] ) ) {
				ManualLinkConflict::OtherCreator    => __( 'Manual link to another creator than the product\'s mapped category. The manual link wins and is not changed.', 'merchboard' ),
				ManualLinkConflict::InactiveCreator => __( 'Manual link to another creator than the product\'s mapped category, whose creator is inactive. The manual link wins and is not changed.', 'merchboard' ),
				ManualLinkConflict::Ambiguous       => __( 'Manual link, but the product is directly in categories mapped to several creators. The manual link wins and is not changed.', 'merchboard' ),
				default                             => $entry['conflict'],
			};
		}

		$outcome = $entry['outcome'];

		return match ( ReconciliationOutcome::tryFrom( $outcome ) ) {
			ReconciliationOutcome::Ambiguous       => __( 'Ambiguous: the product is directly in categories mapped to several creators, so it gets no category link.', 'merchboard' ),
			ReconciliationOutcome::SkippedInactive => __( 'The only mapped creator is inactive, so the product gets no new link.', 'merchboard' ),
			ReconciliationOutcome::Error           => __( 'Could not be evaluated. Check the product; details are in the log.', 'merchboard' ),
			default                                => $outcome,
		};
	}

	/**
	 * A creator's name, "—" for none.
	 *
	 * @param int|null $creator_id Creator id.
	 */
	private function creator_name( ?int $creator_id ): string {
		if ( null === $creator_id ) {
			return '—';
		}

		return $this->creators->find( $creator_id )->display_name ?? '#' . $creator_id;
	}

	/**
	 * Names of existing `product_cat` terms, by id (one query).
	 *
	 * @phpstan-param list<int> $term_ids
	 *
	 * @param int[] $term_ids Term ids.
	 * @return array<int, string>
	 */
	private static function term_names( array $term_ids ): array {
		if ( array() === $term_ids ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => CreatorCategoryService::TAXONOMY,
				'include'    => $term_ids,
				'hide_empty' => false,
			)
		);
		$names = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$names[ $term->term_id ] = $term->name;
		}

		return $names;
	}
}
