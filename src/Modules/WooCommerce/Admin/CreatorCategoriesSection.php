<?php
/**
 * "Product category" section on the creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use LogicException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CategoryMappingPreview;
use MerchBoard\Modules\WooCommerce\CategoryMappingRemoval;
use MerchBoard\Modules\WooCommerce\CategorySyncReport;
use MerchBoard\Modules\WooCommerce\CreatorCategory;
use MerchBoard\Modules\WooCommerce\CreatorCategoryException;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\MappingRemovalChoice;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the creator's product category mapping and lets an administrator map
 * a category or remove the mapping (ADR-033 point 10). Read-only rendering:
 * every change goes through CreatorCategoriesActions and the services.
 *
 * - Mapping: choose a category → dry run of the first reconciliation
 *   (CategoryMappingPreview, nothing written) → explicit confirmation.
 * - Removal: dry run of both choices → explicit choice and confirmation.
 * - An orphaned mapping (its category was deleted) is marked; it creates no
 *   ownership and is removed with the same explicit choice.
 *
 * Only for `merchboard_manage_creators`; creators never see this.
 */
final class CreatorCategoriesSection {

	/**
	 * Constructor.
	 *
	 * @param CreatorCategoryService    $mappings Mapping reads and rules.
	 * @param CreatorCategoryRepository $stored   All mappings (for the category list).
	 * @param CategoryMappingPreview    $preview  Dry runs.
	 * @param CreatorRepository         $creators Creator names.
	 */
	public function __construct(
		private CreatorCategoryService $mappings,
		private CreatorCategoryRepository $stored,
		private CategoryMappingPreview $preview,
		private CreatorRepository $creators
	) {
	}

	/**
	 * Registers the section on the creator's edit page, above the products.
	 */
	public function register(): void {
		add_action( 'merchboard_creators_edit_sections', array( $this, 'render' ), 5 );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'mapped'                                               => array( __( 'The category is mapped. Its products are linked to the creator in the background; the links appear under Products shortly.', 'merchboard' ), 'success' ),
			'removed'                                              => array( __( 'The mapping is removed. Its category-managed links are removed in the background; manual links stay.', 'merchboard' ), 'success' ),
			'kept_removed'                                         => array( __( 'The category-managed links are now manual links and the mapping is removed.', 'merchboard' ), 'success' ),
			'kept_partial'                                         => array( __( 'Not all category-managed links could be converted yet, so the mapping is still in place. Review the result below and run "Keep as manual links" again.', 'merchboard' ), 'error' ),
			'unchanged'                                            => array( __( 'Nothing changed.', 'merchboard' ), 'info' ),
			CreatorCategoriesActions::CONFIRMATION_REQUIRED        => array( __( 'Nothing changed: confirm that you reviewed the preview.', 'merchboard' ), 'error' ),
			CreatorCategoriesActions::CHOICE_REQUIRED              => array( __( 'Nothing changed: choose what happens to the category-managed links.', 'merchboard' ), 'error' ),
			CreatorCategoryException::NOT_AUTHORIZED               => array( __( 'You are not allowed to manage creators.', 'merchboard' ), 'error' ),
			CreatorCategoryException::CREATOR_NOT_FOUND            => array( __( 'The creator does not exist.', 'merchboard' ), 'error' ),
			CreatorCategoryException::CREATOR_INACTIVE             => array( __( 'Only active creators can get a product category. Activate the creator first.', 'merchboard' ), 'error' ),
			CreatorCategoryException::TERM_NOT_FOUND               => array( __( 'The product category does not exist.', 'merchboard' ), 'error' ),
			CreatorCategoryException::NOT_PRODUCT_CATEGORY         => array( __( 'Only WooCommerce product categories can be mapped.', 'merchboard' ), 'error' ),
			CreatorCategoryException::DEFAULT_CATEGORY             => array( __( 'The default product category cannot be mapped.', 'merchboard' ), 'error' ),
			CreatorCategoryException::CATEGORY_MAPPED_ELSEWHERE    => array( __( 'The category is already mapped to another creator. Remove that mapping first; a category is never moved silently.', 'merchboard' ), 'error' ),
			CreatorCategoryException::CREATOR_HAS_CATEGORY         => array( __( 'The creator already has a product category. Remove it first.', 'merchboard' ), 'error' ),
			CreatorCategoryException::ALREADY_MAPPED               => array( __( 'The category is already mapped to this creator.', 'merchboard' ), 'info' ),
			'error'                                                => array( __( 'A technical error occurred. Nothing may have been changed; check the mapping and try again later.', 'merchboard' ), 'error' ),
			default                                                => null,
		};
	}

	/**
	 * Label of a reconciliation outcome in a dry run.
	 *
	 * @param ReconciliationOutcome $outcome Outcome.
	 */
	public static function outcome_label( ReconciliationOutcome $outcome ): string {
		return match ( $outcome ) {
			ReconciliationOutcome::Linked          => __( 'Linked to the creator', 'merchboard' ),
			ReconciliationOutcome::Moved           => __( 'Moved from another creator\'s category link', 'merchboard' ),
			ReconciliationOutcome::Unlinked        => __( 'Category link removed', 'merchboard' ),
			ReconciliationOutcome::Unchanged       => __( 'Unchanged', 'merchboard' ),
			ReconciliationOutcome::SkippedManual   => __( 'Manual link, never changed', 'merchboard' ),
			ReconciliationOutcome::Ambiguous       => __( 'Ambiguous: in categories of several creators, no category link', 'merchboard' ),
			ReconciliationOutcome::SkippedInactive => __( 'Creator inactive, no new link', 'merchboard' ),
			ReconciliationOutcome::Error           => __( 'Could not be evaluated', 'merchboard' ),
		};
	}

	/**
	 * Renders the section below the creator form.
	 *
	 * @param Creator $creator Creator being edited.
	 */
	public function render( Creator $creator ): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return;
		}

		echo '<hr><h2 id="merchboard-categories">' . esc_html__( 'Product category', 'merchboard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Products directly in the creator\'s WooCommerce product category are linked to the creator automatically, and lose that link when they leave the category. The mapping itself is not ownership: the links are created and removed in the background and appear under Products. Manual links always win and are never changed by a category. Products only in a subcategory are not linked.', 'merchboard' ) . '</p>';

		// Read-only display parameters; nothing is changed by this request.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$result  = isset( $_GET[ CreatorCategoriesActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ CreatorCategoriesActions::RESULT_ARG ] ) ) : '';
		$counts  = isset( $_GET[ CreatorCategoriesActions::COUNTS_ARG ] ) ? CreatorCategoriesActions::parse_counts( sanitize_text_field( wp_unslash( $_GET[ CreatorCategoriesActions::COUNTS_ARG ] ) ) ) : null;
		$preview = isset( $_GET[ CreatorCategoriesActions::PREVIEW_ARG ] ) ? absint( $_GET[ CreatorCategoriesActions::PREVIEW_ARG ] ) : 0;
		$remove  = isset( $_GET[ CreatorCategoriesActions::REMOVE_ARG ] ) ? absint( $_GET[ CreatorCategoriesActions::REMOVE_ARG ] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->render_result( $result, $counts );

		try {
			$mappings = $this->mappings->for_creator( $creator->id );
		} catch ( DatabaseException ) {
			self::notice( __( 'The product category could not be loaded.', 'merchboard' ), 'error' );
			return;
		}

		$this->render_list( $creator, $mappings );

		foreach ( $mappings as $mapping ) {
			if ( $mapping->term_id === $remove ) {
				$this->render_removal( $creator, $mapping );
				return;
			}
		}

		if ( array() === $mappings ) {
			$this->render_add( $creator, $preview );
		}
	}

	/**
	 * Outcome of the previous request.
	 *
	 * @param string                     $code   Outcome code.
	 * @param array{0: int, 1: int}|null $counts Converted and failed links of a "Keep as manual".
	 */
	private function render_result( string $code, ?array $counts ): void {
		$message = '' === $code ? null : self::result_message( $code );

		if ( null === $message ) {
			return;
		}

		$text = $message[0];

		if ( null !== $counts && in_array( $code, array( 'kept_removed', 'kept_partial' ), true ) ) {
			/* translators: 1: outcome message, 2: links converted, 3: links not converted. */
			$text = sprintf( __( '%1$s Converted: %2$d. Not converted: %3$d.', 'merchboard' ), $text, $counts[0], $counts[1] );
		}

		self::notice( $text, $message[1] );
	}

	/**
	 * The creator's mappings.
	 *
	 * @phpstan-param list<CreatorCategory> $mappings
	 *
	 * @param Creator           $creator  Creator.
	 * @param CreatorCategory[] $mappings Mappings.
	 */
	private function render_list( Creator $creator, array $mappings ): void {
		if ( array() === $mappings ) {
			echo '<p>' . esc_html__( 'No product category is mapped to this creator.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped merchboard-creator-categories"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Category', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'ID', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Creator', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Mapped', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Published products directly in the category', 'merchboard' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'merchboard' ) . '</span></th>';
		echo '</tr></thead><tbody>';

		foreach ( $mappings as $mapping ) {
			$term = self::term( $mapping->term_id );

			echo '<tr><td>';

			if ( null === $term ) {
				echo '<strong>' . esc_html__( 'Deleted category', 'merchboard' ) . '</strong><br><span class="description">' . esc_html__( 'The category no longer exists, so this mapping links no products. Remove it to free the creator\'s category.', 'merchboard' ) . '</span>';
			} else {
				echo esc_html( $term->name );
			}

			echo '</td><td>' . esc_html( (string) $mapping->term_id ) . '</td>';
			echo '<td>' . esc_html( $creator->display_name ) . '</td>';
			echo '<td>' . esc_html( CreatorsAdminPage::local_time( $mapping->created_at ) ) . '</td>';
			echo '<td>' . esc_html( null === $term ? '–' : (string) $term->count ) . '</td>';
			echo '<td><a class="button button-link-delete" href="' . esc_url( self::url( $creator, CreatorCategoriesActions::REMOVE_ARG, $mapping->term_id ) ) . '">' . esc_html__( 'Remove…', 'merchboard' ) . '</a></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Choosing a category, and for a chosen one its dry run and confirmation.
	 *
	 * @param Creator $creator Creator.
	 * @param int     $term_id Category to preview (0: none).
	 */
	private function render_add( Creator $creator, int $term_id ): void {
		echo '<h3>' . esc_html__( 'Map a product category', 'merchboard' ) . '</h3>';

		if ( CreatorStatus::Active !== $creator->status ) {
			echo '<p>' . esc_html__( 'Only active creators can get a product category.', 'merchboard' ) . '</p>';
			return;
		}

		$this->render_category_select( $creator, $term_id );

		if ( $term_id < 1 ) {
			return;
		}

		try {
			$report = $this->preview->for_new_mapping( $creator->id, $term_id );
		} catch ( CreatorCategoryException $e ) {
			$message = self::result_message( $e->reason() );
			self::notice( null === $message ? __( 'The category cannot be mapped.', 'merchboard' ) : $message[0], 'error' );
			return;
		} catch ( DatabaseException | LogicException ) {
			self::notice( self::result_message( 'error' )[0] ?? '', 'error' );
			return;
		}

		/* translators: %s: category name. */
		echo '<h4>' . esc_html( sprintf( __( 'Preview: mapping "%s"', 'merchboard' ), self::term( $term_id )->name ?? '#' . $term_id ) ) . '</h4>';
		self::render_report( $report );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CreatorCategoriesActions::MAP_ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		echo '<input type="hidden" name="term_id" value="' . esc_attr( (string) $term_id ) . '">';
		wp_nonce_field( CreatorCategoriesActions::map_nonce_action( $creator->id ) );
		echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I have reviewed the preview. Map the category; its products are linked to the creator in the background, and future changes to the category follow automatically.', 'merchboard' ) . '</label></p>';
		submit_button( __( 'Map category', 'merchboard' ), 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( CreatorsAdminPage::edit_url( $creator->id ) . '#merchboard-categories' ) . '">' . esc_html__( 'Cancel', 'merchboard' ) . '</a>';
		echo '</form>';
	}

	/**
	 * The category choice (GET: showing a preview changes nothing).
	 *
	 * @param Creator $creator  Creator.
	 * @param int     $selected Preselected category.
	 */
	private function render_category_select( Creator $creator, int $selected ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => CreatorCategoryService::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( ! is_array( $terms ) || array() === $terms ) {
			echo '<p>' . esc_html__( 'There are no product categories.', 'merchboard' ) . '</p>';
			return;
		}

		try {
			$owners = array();

			foreach ( $this->stored->all() as $mapping ) {
				$owners[ $mapping->term_id ] = $mapping->creator_id;
			}
		} catch ( DatabaseException ) {
			self::notice( __( 'The product categories could not be loaded.', 'merchboard' ), 'error' );
			return;
		}

		$default = (int) get_option( 'default_product_cat', 0 );

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#merchboard-categories">';
		echo '<input type="hidden" name="page" value="' . esc_attr( CreatorsAdminPage::SLUG ) . '">';
		echo '<input type="hidden" name="action" value="edit">';
		echo '<input type="hidden" name="creator" value="' . esc_attr( (string) $creator->id ) . '">';
		echo '<label for="merchboard-category">' . esc_html__( 'Product category', 'merchboard' ) . '</label> ';
		echo '<select id="merchboard-category" name="' . esc_attr( CreatorCategoriesActions::PREVIEW_ARG ) . '" required>';
		echo '<option value="">' . esc_html__( 'Choose a category', 'merchboard' ) . '</option>';

		foreach ( $terms as $term ) {
			if ( $term->term_id === $default ) {
				continue;
			}

			$owner = $owners[ $term->term_id ] ?? null;
			$label = $term->name;

			if ( null !== $owner ) {
				$name = $this->creators->find( $owner )->display_name ?? '#' . $owner;
				/* translators: 1: category name, 2: creator name. */
				$label = sprintf( __( '%1$s (mapped to %2$s)', 'merchboard' ), $term->name, $name );
			}

			printf(
				'<option value="%1$s"%2$s%3$s>%4$s</option>',
				esc_attr( (string) $term->term_id ),
				selected( $selected, $term->term_id, false ),
				disabled( null !== $owner, true, false ),
				esc_html( $label )
			);
		}

		echo '</select> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Preview', 'merchboard' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'The preview shows what the mapping would do. Nothing is changed until you confirm. The default product category cannot be mapped, and a category can only belong to one creator.', 'merchboard' ) . '</p>';
		echo '</form>';
	}

	/**
	 * Dry runs of both removal choices and the explicit choice.
	 *
	 * @param Creator         $creator Creator.
	 * @param CreatorCategory $mapping Mapping to remove.
	 */
	private function render_removal( Creator $creator, CreatorCategory $mapping ): void {
		$name = self::term( $mapping->term_id )->name ?? __( 'Deleted category', 'merchboard' );

		/* translators: %s: category name. */
		echo '<h3>' . esc_html( sprintf( __( 'Remove the mapping of "%s"', 'merchboard' ), $name ) ) . '</h3>';

		try {
			$report = $this->preview->for_removal( $mapping );
			$keep   = $this->preview->conversion_count( $mapping );
		} catch ( CreatorCategoryException | DatabaseException | LogicException ) {
			self::notice( self::result_message( 'error' )[0] ?? '', 'error' );
			return;
		}

		echo '<p>' . esc_html__( 'Choose what happens to the links this mapping created. Manual links are never changed. Nothing is changed until you confirm.', 'merchboard' ) . '</p>';

		echo '<h4>' . esc_html__( 'Preview: remove category-managed links', 'merchboard' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Includes products that have left the category but still hold a category link to this creator.', 'merchboard' ) . '</p>';
		self::render_report( $report );

		echo '<h4>' . esc_html__( 'Preview: keep as manual links', 'merchboard' ) . '</h4>';
		/* translators: %d: number of links. */
		echo '<p>' . esc_html( sprintf( _n( '%d category-managed link becomes a manual link to this creator. It keeps the creator and is no longer changed by categories.', '%d category-managed links become manual links to this creator. They keep the creator and are no longer changed by categories.', $keep, 'merchboard' ), $keep ) ) . '</p>';
		/* translators: %d: most links converted per run. */
		echo '<p class="description">' . esc_html( sprintf( __( 'At most %d links are converted per run, and you need permission to edit each product. If links are left or fail, the mapping stays in place and you can run this again; links already converted are not converted twice.', 'merchboard' ), CategoryMappingRemoval::MAX_CONVERSIONS ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CreatorCategoriesActions::UNMAP_ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		echo '<input type="hidden" name="term_id" value="' . esc_attr( (string) $mapping->term_id ) . '">';
		wp_nonce_field( CreatorCategoriesActions::unmap_nonce_action( $creator->id ) );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'What happens to the category-managed links', 'merchboard' ) . '</legend>';
		echo '<p><label><input type="radio" name="choice" value="' . esc_attr( MappingRemovalChoice::RemoveLinks->value ) . '" required> ' . esc_html__( 'Remove category-managed links', 'merchboard' ) . '</label></p>';
		echo '<p><label><input type="radio" name="choice" value="' . esc_attr( MappingRemovalChoice::KeepAsManual->value ) . '" required> ' . esc_html__( 'Keep as manual links', 'merchboard' ) . '</label></p>';
		echo '</fieldset>';
		echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I have reviewed the preview and want to remove the mapping with the chosen option.', 'merchboard' ) . '</label></p>';
		submit_button( __( 'Remove mapping', 'merchboard' ), 'delete', 'submit', false );
		echo ' <a class="button" href="' . esc_url( CreatorsAdminPage::edit_url( $creator->id ) . '#merchboard-categories' ) . '">' . esc_html__( 'Cancel', 'merchboard' ) . '</a>';
		echo '</form>';
	}

	/**
	 * Counts of a dry run, one row per outcome.
	 *
	 * @param CategorySyncReport $report Dry run.
	 */
	private static function render_report( CategorySyncReport $report ): void {
		echo '<table class="widefat striped merchboard-category-preview"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Outcome', 'merchboard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Products', 'merchboard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( ReconciliationOutcome::cases() as $outcome ) {
			echo '<tr><td>' . esc_html( self::outcome_label( $outcome ) ) . '</td><td>' . esc_html( (string) $report->result->count( $outcome ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'This is a preview. Nothing has been changed.', 'merchboard' ) . '</p>';
	}

	/**
	 * An admin notice.
	 *
	 * @param string $text Text.
	 * @param string $type success|info|error.
	 */
	private static function notice( string $text, string $type ): void {
		printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	/**
	 * An existing `product_cat` term, or null.
	 *
	 * @param int $term_id Term id.
	 */
	private static function term( int $term_id ): ?WP_Term {
		$term = get_term( $term_id, CreatorCategoryService::TAXONOMY );

		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * The edit page with a query argument.
	 *
	 * @param Creator $creator Creator.
	 * @param string  $arg     Query argument.
	 * @param int     $value   Value.
	 */
	private static function url( Creator $creator, string $arg, int $value ): string {
		return add_query_arg( array( $arg => $value ), CreatorsAdminPage::edit_url( $creator->id ) ) . '#merchboard-categories';
	}
}
