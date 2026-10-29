<?php
/**
 * Creating, changing and deleting base products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Bases;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * The only write path of base products (ADR-080 pkt. 2), for
 * `merchboard_manage_designer`, each change audited in the same
 * transaction as the row.
 *
 * - **Template layers** are PNG files, stored before the transaction and
 *   recorded inside it; when the change is refused they are deleted again.
 *   All layers of a side have the same size, which is the template's size.
 *   A replaced or removed layer is deleted after the change is stored.
 * - **Version:** a change of a side's template or print area raises
 *   `version`; designs made on an earlier version must be saved and sent
 *   again. A new base price does not (step 30.11): it fires
 *   `merchboard_designer_base_price_changed` after the commit, and the
 *   designer products in the shop are repriced (DesignerRepricing).
 * - **Status:** a new base product is a draft; it is activated only with
 *   colours, sizes and sides. A base product with designs is never
 *   deleted; it is retired (step 30.11, final): `merchboard_designer_base_retired`
 *   takes its products out of the shop (DesignerRetirement). A retired base
 *   product cannot be changed or activated again.
 */
class BaseProductService {

	/**
	 * Fired after a base product's price changed: `( int $base_id )`.
	 */
	public const PRICE_CHANGED = 'merchboard_designer_base_price_changed';

	/**
	 * Fired after a base product was retired: `( int $base_id )`.
	 */
	public const RETIRED = 'merchboard_designer_base_retired';

	/**
	 * Constructor.
	 *
	 * @param BaseProductRepository   $bases       Base products.
	 * @param DesignerAssetRepository $assets      File records.
	 * @param DesignerFiles           $files       Files.
	 * @param AuditTrail              $audit       Audit trail.
	 * @param Transaction             $transaction Transaction.
	 * @param int                     $decimals    Store price decimals.
	 * @param string                  $currency    Store currency.
	 */
	public function __construct(
		private BaseProductRepository $bases,
		private DesignerAssetRepository $assets,
		private DesignerFiles $files,
		private AuditTrail $audit,
		private Transaction $transaction,
		private int $decimals,
		private string $currency
	) {
	}

	/**
	 * Creates or changes a base product.
	 *
	 * Fields: `name`, `description`, `base_price` (decimal, comma or point),
	 * `category_id`, `colors` (one per line: name and #rrggbb), `sizes`
	 * (separated by commas or new lines). Each side: `key` (empty for a new
	 * one), `name`, `x`, `y`, `width`, `height` (print area in template px),
	 * `width_mm`, `height_mm`, `dpi`, `remove` (bool), `uploads` (upload
	 * arrays by layer) and `clear` (layers to remove).
	 *
	 * @param int|null                   $id    Base product; null for a new one.
	 * @param array<string, string>      $fields General fields.
	 * @param list<array<string, mixed>> $views  Sides.
	 * @return int The base product's id.
	 * @throws DesignerException When the user may not, or a value or file is refused (nothing is kept).
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function save( ?int $id, array $fields, array $views ): int {
		self::authorize();

		$existing = null === $id ? null : $this->existing( $id );

		if ( BaseProduct::RETIRED === ( $existing->status ?? '' ) ) {
			self::reject( DesignerException::BASE_RETIRED );
		}

		$name     = trim( sanitize_text_field( $fields['name'] ?? '' ) );
		$price    = $this->price( $fields['base_price'] ?? '' );
		$category = self::category( $fields['category_id'] ?? '' );
		$colors   = self::colors( $fields['colors'] ?? '' );
		$sizes    = self::sizes( $fields['sizes'] ?? '' );

		if ( '' === $name || mb_strlen( $name ) > 191 ) {
			self::reject( DesignerException::BASE_NAME );
		}

		$stored = array();

		try {
			$plans = $this->plan_views( $existing, $views, $stored, array_column( $colors, 'key' ) );

			$saved = $this->transaction->run(
				function () use ( $id, $existing, $name, $fields, $price, $category, $colors, $sizes, $plans ): array {
					$sides = array();

					foreach ( $plans as $plan ) {
						$layers = $plan['layers'];

						foreach ( $plan['new'] as $layer => $file ) {
							$layers[ $layer ] = $this->assets->insert( DesignerAssetRepository::LAYER, $name . ' – ' . $plan['name'] . ' – ' . $layer, $file );
						}

						$sides[] = $this->view( $plan, $layers );
					}

					$base = $this->base( $existing, $name, $fields, $price, $category, $colors, $sizes, $sides );

					if ( null === $existing ) {
						$new_id = $this->bases->insert( $base );
						$this->audit->record(
							AuditAction::DesignerBaseCreated,
							null,
							array(
								'base_id'   => $new_id,
								'base_name' => $base->name,
								'version'   => $base->version,
							)
						);

						return array( $new_id, array() );
					}

					$this->bases->update( (int) $id, $base );
					$this->audit->record(
						AuditAction::DesignerBaseChanged,
						null,
						array(
							'base_id'        => (int) $id,
							'base_name'      => $base->name,
							'version'        => $base->version,
							'changed_fields' => self::changes( $existing, $base ),
							'status'         => $base->status,
						)
					);

					return array( (int) $id, array_values( array_diff( $existing->layer_assets(), $base->layer_assets() ) ) );
				}
			);
		} catch ( DesignerException | DatabaseException $e ) {
			foreach ( $stored as $file ) {
				$this->files->delete( $file['file'] );
			}

			throw $e;
		}

		$this->forget_layers( $saved[1] );

		if ( null !== $existing && $existing->base_units !== $price ) {
			do_action( 'merchboard_designer_base_price_changed', $saved[0] );
		}

		return $saved[0];
	}

	/**
	 * Activates a base product for designs, or takes it back to draft.
	 *
	 * @param int  $id     Base product.
	 * @param bool $active Whether creators may design on it.
	 * @throws DesignerException When the user may not, it is unknown or incomplete.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_active( int $id, bool $active ): void {
		self::authorize();

		$base   = $this->existing( $id );
		$status = $active ? BaseProduct::ACTIVE : BaseProduct::DRAFT;

		if ( BaseProduct::RETIRED === $base->status ) {
			self::reject( DesignerException::BASE_RETIRED );
		}

		if ( $status === $base->status ) {
			return;
		}

		$changed = $this->with_status( $base, $status );

		$this->transaction->run(
			function () use ( $id, $changed ): void {
				$this->bases->update( $id, $changed );
				$this->audit->record(
					AuditAction::DesignerBaseChanged,
					null,
					array(
						'base_id'        => $id,
						'base_name'      => $changed->name,
						'version'        => $changed->version,
						'changed_fields' => array( 'status' ),
						'status'         => $changed->status,
					)
				);
			}
		);
	}

	/**
	 * Retires a base product for good: no new designs, its products are
	 * taken out of the shop and its waiting designs rejected (after the
	 * commit, `merchboard_designer_base_retired`).
	 *
	 * @param int $id Base product.
	 * @throws DesignerException When the user may not or it is unknown.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function retire( int $id ): void {
		self::authorize();

		$base = $this->existing( $id );

		if ( BaseProduct::RETIRED === $base->status ) {
			return;
		}

		$changed = $this->with_status( $base, BaseProduct::RETIRED );

		$this->transaction->run(
			function () use ( $id, $changed ): void {
				$this->bases->update( $id, $changed );
				$this->audit->record(
					AuditAction::DesignerBaseRetired,
					null,
					array(
						'base_id'   => $id,
						'base_name' => $changed->name,
					)
				);
			}
		);

		do_action( 'merchboard_designer_base_retired', $id );
	}

	/**
	 * Deletes a base product without designs, with its template files.
	 *
	 * @param int $id Base product.
	 * @throws DesignerException When the user may not, it is unknown or has designs.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $id ): void {
		self::authorize();

		$base = $this->existing( $id );

		$this->transaction->run(
			function () use ( $id, $base ): void {
				if ( $this->bases->products( $id ) > 0 ) {
					throw new DesignerException( DesignerException::BASE_IN_USE );
				}

				$this->bases->delete( $id );
				$this->audit->record(
					AuditAction::DesignerBaseDeleted,
					null,
					array(
						'base_id'   => $id,
						'base_name' => $base->name,
					)
				);
			}
		);

		$this->forget_layers( $base->layer_assets() );
	}

	/**
	 * The colours of a text: one per line, a name and a #rrggbb colour.
	 *
	 * @param string $text Text.
	 * @return list<array{key: string, name: string, hex: string}>
	 * @throws DesignerException When a line is not a name and a colour, or names repeat.
	 */
	public static function colors( string $text ): array {
		$colors = array();

		$lines = preg_split( '/\R/u', $text );

		foreach ( false === $lines ? array() : $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( 1 !== preg_match( '/^(.+?)[\s,;]+#?([0-9a-fA-F]{6})$/u', $line, $match ) ) {
				self::reject( DesignerException::BASE_COLORS );
			}

			$name = trim( sanitize_text_field( $match[1] ) );
			$key  = substr( sanitize_title( $name ), 0, 32 );

			if ( '' === $name || '' === $key || isset( $colors[ $key ] ) ) {
				self::reject( DesignerException::BASE_COLORS );
			}

			$colors[ $key ] = array(
				'key'  => $key,
				'name' => $name,
				'hex'  => '#' . strtolower( $match[2] ),
			);
		}

		return array_values( $colors );
	}

	/**
	 * The sizes of a text, separated by commas or new lines.
	 *
	 * @param string $text Text.
	 * @return list<string>
	 * @throws DesignerException When sizes repeat.
	 */
	public static function sizes( string $text ): array {
		$sizes = array();

		$parts = preg_split( '/[\r\n,]+/u', $text );

		foreach ( false === $parts ? array() : $parts as $part ) {
			$size = trim( sanitize_text_field( $part ) );

			if ( '' === $size ) {
				continue;
			}

			if ( in_array( $size, $sizes, true ) || mb_strlen( $size ) > 20 ) {
				self::reject( DesignerException::BASE_SIZES );
			}

			$sizes[] = $size;
		}

		return $sizes;
	}

	/**
	 * The sides to store: the existing layers kept, the new files stored.
	 *
	 * @param BaseProduct|null                                                             $existing Stored base product.
	 * @param list<array<string, mixed>>                                                   $views    Sides as entered.
	 * @param list<array{file: string, mime: string, width: int, height: int, bytes: int}> $stored   Files stored so far (for clean-up).
	 * @param string[]                                                                     $colors   Colour keys of the base product (a colour's own background).
	 * @param-out list<array{file: string, mime: string, width: int, height: int, bytes: int}> $stored
	 * @return list<array{key: string, name: string, width: int, height: int, layers: array<string, int>, new: array<string, array{file: string, mime: string, width: int, height: int, bytes: int}>, input: array<string, mixed>}>
	 * @throws DesignerException When a side or file is refused.
	 */
	private function plan_views( ?BaseProduct $existing, array $views, array &$stored, array $colors = array() ): array {
		$current = array();

		foreach ( $existing->views ?? array() as $view ) {
			$current[ $view->key ] = $view;
		}

		$plans = array();
		$keys  = array();

		foreach ( $views as $input ) {
			$key  = is_string( $input['key'] ?? null ) ? $input['key'] : '';
			$old  = '' === $key ? null : ( $current[ $key ] ?? self::reject( DesignerException::BASE_VIEW ) );
			$name = trim( sanitize_text_field( is_string( $input['name'] ?? null ) ? $input['name'] : '' ) );

			if ( true === ( $input['remove'] ?? false ) ) {
				continue;
			}

			if ( null === $old ) {
				$key = substr( sanitize_title( $name ), 0, 32 );
			}

			if ( '' === $name || '' === $key || isset( $keys[ $key ] ) ) {
				self::reject( DesignerException::BASE_VIEW );
			}

			$keys[ $key ] = true;
			$layers       = $old->layers ?? array();
			$clear        = is_array( $input['clear'] ?? null ) ? $input['clear'] : array();

			foreach ( $clear as $layer ) {
				unset( $layers[ (string) $layer ] );
			}

			$new     = array();
			$uploads = is_array( $input['uploads'] ?? null ) ? $input['uploads'] : array();

			// A colour's own background (`background:<key>`) only for a colour of the base product.
			foreach ( array_keys( $layers ) as $layer ) {
				if ( str_starts_with( $layer, BaseView::COLOR_BACKGROUND ) && ! in_array( substr( $layer, strlen( BaseView::COLOR_BACKGROUND ) ), $colors, true ) ) {
					unset( $layers[ $layer ] );
				}
			}

			foreach ( array_merge( BaseView::LAYERS, array_map( static fn( string $color ): string => BaseView::color_layer( $color ), $colors ) ) as $layer ) {
				$upload = $uploads[ $layer ] ?? null;

				if ( is_array( $upload ) && UPLOAD_ERR_NO_FILE !== ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
					$file          = $this->files->store( $upload, true );
					$stored[]      = $file;
					$new[ $layer ] = $file;
					unset( $layers[ $layer ] );
				}
			}

			[ $width, $height ] = $this->template_size( $old, $layers, $new );

			$plans[] = array(
				'key'    => $key,
				'name'   => $name,
				'width'  => $width,
				'height' => $height,
				'layers' => $layers,
				'new'    => $new,
				'input'  => $input,
			);
		}

		return $plans;
	}

	/**
	 * The template size of a side: every layer has the same size.
	 *
	 * @param BaseView|null                                                                         $old    Stored side.
	 * @param array<string, int>                                                                    $layers Kept layers (asset ids).
	 * @param array<string, array{file: string, mime: string, width: int, height: int, bytes: int}> $added  New layers.
	 * @return array{0: int, 1: int}
	 * @throws DesignerException When the sizes differ or the side has no layer.
	 * @throws DatabaseException When a kept layer cannot be read.
	 */
	private function template_size( ?BaseView $old, array $layers, array $added ): array {
		$sizes = array();

		foreach ( $added as $file ) {
			$sizes[] = $file['width'] . 'x' . $file['height'];
		}

		if ( array() !== $layers && null !== $old ) {
			$sizes[] = $old->width . 'x' . $old->height;
		}

		if ( array() === $sizes ) {
			self::reject( DesignerException::BASE_VIEW );
		}

		if ( 1 !== count( array_unique( $sizes ) ) ) {
			self::reject( DesignerException::BASE_LAYER_SIZE );
		}

		[ $width, $height ] = array_map( 'intval', explode( 'x', $sizes[0] ) );

		return array( $width, $height );
	}

	/**
	 * A side from its plan.
	 *
	 * @param array{key: string, name: string, width: int, height: int, layers: array<string, int>, new: array<string, mixed>, input: array<string, mixed>} $plan   Plan.
	 * @param array<string, int>                                                                                                                            $layers Asset id by layer.
	 * @throws DesignerException When the side is invalid.
	 */
	private function view( array $plan, array $layers ): BaseView {
		$number = static fn( string $field ): int => is_scalar( $plan['input'][ $field ] ?? null ) && 1 === preg_match( '/^\d{1,5}$/', trim( (string) $plan['input'][ $field ] ) ) ? (int) trim( (string) $plan['input'][ $field ] ) : -1;
		$sorted = array();

		foreach ( BaseView::LAYERS as $layer ) {
			if ( isset( $layers[ $layer ] ) ) {
				$sorted[ $layer ] = $layers[ $layer ];
			}
		}

		// The colours' own backgrounds after the shared layers.
		foreach ( $layers as $layer => $asset ) {
			if ( str_starts_with( $layer, BaseView::COLOR_BACKGROUND ) ) {
				$sorted[ $layer ] = $asset;
			}
		}

		try {
			$view = new BaseView(
				$plan['key'],
				$plan['name'],
				$plan['width'],
				$plan['height'],
				$sorted,
				array(
					'x'      => $number( 'x' ),
					'y'      => $number( 'y' ),
					'width'  => $number( 'width' ),
					'height' => $number( 'height' ),
				),
				$number( 'width_mm' ),
				$number( 'height_mm' ),
				$number( 'dpi' )
			);
		} catch ( InvalidArgumentException ) {
			self::reject( DesignerException::BASE_VIEW );
		}

		// The creator's browser renders the print file, which is stored like any designer file (step 30.9).
		if ( max( $view->print_pixels() ) > DesignerFiles::MAX_PIXELS ) {
			self::reject( DesignerException::BASE_VIEW );
		}

		return $view;
	}

	/**
	 * The base product to store.
	 *
	 * @param BaseProduct|null      $existing Stored base product.
	 * @param string                $name     Name.
	 * @param array<string, string> $fields   General fields.
	 * @param int                   $price    Base price in units.
	 * @param int|null              $category Category.
	 * @phpstan-param list<array{key: string, name: string, hex: string}> $colors
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<BaseView> $views
	 *
	 * @param array[]               $colors   Colours.
	 * @param string[]              $sizes    Sizes.
	 * @param BaseView[]            $views    Sides.
	 * @throws DesignerException When the base product is invalid (an active one must stay complete).
	 */
	private function base( ?BaseProduct $existing, string $name, array $fields, int $price, ?int $category, array $colors, array $sizes, array $views ): BaseProduct {
		$version = $existing->version ?? 1;

		// A changed template, or a colour or size that is renamed or removed (the owner's choice
		// 2026-10-29, review 30.15), makes waiting designs outdated. A new colour or size does not.
		if ( null !== $existing && ( self::templates( $existing->views ) !== self::templates( $views ) || array() !== array_diff( array_map( static fn( array $color ): string => $color['key'] . '|' . $color['name'], $existing->colors ), array_map( static fn( array $color ): string => $color['key'] . '|' . $color['name'], $colors ) ) || array() !== array_diff( $existing->sizes, $sizes ) ) ) {
			++$version;
		}

		try {
			return new BaseProduct(
				$existing->id ?? null,
				$existing->status ?? BaseProduct::DRAFT,
				$existing->source ?? 'own',
				$name,
				sanitize_textarea_field( $fields['description'] ?? '' ),
				$price,
				$this->currency,
				$category,
				$colors,
				$sizes,
				$views,
				$version
			);
		} catch ( InvalidArgumentException ) {
			self::reject( BaseProduct::ACTIVE === ( $existing->status ?? '' ) ? DesignerException::BASE_INCOMPLETE : DesignerException::BASE_VIEW );
		}
	}

	/**
	 * The same base product with another status.
	 *
	 * @param BaseProduct $base   Base product.
	 * @param string      $status New status.
	 * @throws DesignerException When it is incomplete for the status.
	 */
	private function with_status( BaseProduct $base, string $status ): BaseProduct {
		try {
			return new BaseProduct( $base->id, $status, $base->source, $base->name, $base->description, $base->base_units, $base->currency, $base->category_id, $base->colors, $base->sizes, $base->views, $base->version );
		} catch ( InvalidArgumentException ) {
			self::reject( DesignerException::BASE_INCOMPLETE );
		}
	}

	/**
	 * The base price in units at the store precision.
	 *
	 * @param string $text Decimal as entered.
	 * @throws DesignerException When it is not a positive price at the store precision.
	 */
	private function price( string $text ): int {
		$text = str_replace( ',', '.', trim( $text ) );

		try {
			$units = SalesAmount::parse( $text );
		} catch ( InvalidArgumentException | OverflowException ) {
			self::reject( DesignerException::BASE_PRICE );
		}

		if ( $units < 1 || ! PricingMath::fits_precision( $units, $this->decimals ) ) {
			self::reject( DesignerException::BASE_PRICE );
		}

		return $units;
	}

	/**
	 * The default product category, or null.
	 *
	 * @param string $value Term id as entered.
	 * @throws DesignerException When it is not a product category.
	 */
	private static function category( string $value ): ?int {
		$id = absint( $value );

		if ( 0 === $id ) {
			return null;
		}

		$term = get_term( $id, 'product_cat' );

		if ( ! $term instanceof WP_Term ) {
			self::reject( DesignerException::BASE_CATEGORY );
		}

		return $id;
	}

	/**
	 * A stored base product.
	 *
	 * @param int $id Id.
	 * @throws DesignerException When it does not exist.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function existing( int $id ): BaseProduct {
		$base = $this->bases->find( $id );

		if ( null === $base ) {
			self::reject( DesignerException::BASE_UNKNOWN );
		}

		return $base;
	}

	/**
	 * Deletes layer records and files no side uses any more. A failure is
	 * left as an orphan file (never a broken template).
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @param int[] $ids Asset ids.
	 */
	private function forget_layers( array $ids ): void {
		foreach ( $ids as $id ) {
			try {
				$asset = $this->assets->find( $id );

				if ( null !== $asset && DesignerAssetRepository::LAYER === $asset['kind'] ) {
					$this->assets->delete( $id );
					$this->files->delete( $asset['file'] );
				}
			} catch ( DatabaseException ) {
				continue;
			}
		}
	}

	/**
	 * What changed, for the audit (field names only).
	 *
	 * @param BaseProduct $before Before.
	 * @param BaseProduct $after  After.
	 * @return list<string>
	 */
	private static function changes( BaseProduct $before, BaseProduct $after ): array {
		$fields = array(
			'name'        => array( $before->name, $after->name ),
			'description' => array( $before->description, $after->description ),
			'base_price'  => array( $before->base_units, $after->base_units ),
			'category_id' => array( $before->category_id, $after->category_id ),
			'colors'      => array( $before->colors, $after->colors ),
			'sizes'       => array( $before->sizes, $after->sizes ),
			'views'       => array( self::templates( $before->views ), self::templates( $after->views ) ),
		);

		return array_keys( array_filter( $fields, static fn( array $pair ): bool => $pair[0] !== $pair[1] ) );
	}

	/**
	 * The sides as comparable arrays.
	 *
	 * @phpstan-param list<BaseView> $views
	 *
	 * @param BaseView[] $views Sides.
	 * @return list<array<string, mixed>>
	 */
	private static function templates( array $views ): array {
		return array_map( static fn( BaseView $view ): array => $view->to_array(), $views );
	}

	/**
	 * Refuses with a reason.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function reject( string $reason ): never {
		throw new DesignerException( $reason );
	}

	/**
	 * Requires `merchboard_manage_designer`.
	 *
	 * @throws DesignerException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			throw new DesignerException( DesignerException::NOT_AUTHORIZED );
		}
	}
}
