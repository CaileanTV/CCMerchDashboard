<?php
/**
 * The creator's designer drafts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerPrices;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerImages;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's side of the designer before submission (step 30.8,
 * ADR-080 pkt. 3–4): start a designer product on an active base product,
 * save its draft and delete a product that never left the draft. The
 * creator is the one the caller resolved through CreatorContext (ADR-007);
 * every product is looked up with the creator, so another creator's product
 * is "not found". An administrator viewing as a creator (ADR-075) may edit
 * drafts; submitting (DesignerSubmissions, step 30.9) is refused to them.
 *
 * A draft holds the title, description, chosen colours and sizes, the
 * profit (a percent of the base price or a fixed amount, incl. VAT, never
 * below 0), the design (DesignValidator) and the price it gives now.
 */
class DesignerDrafts {

	/**
	 * Largest profit value (a percent or an amount).
	 */
	public const MAX_PROFIT = 100000;

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products    Designer products.
	 * @param BaseProductRepository     $bases       Base products.
	 * @param AuditTrail                $audit       Audit trail.
	 * @param Transaction               $transaction Transaction.
	 * @param int                       $decimals    Store price decimals.
	 * @param DesignerFiles             $files       Files (a deleted product's submission files).
	 */
	public function __construct(
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private AuditTrail $audit,
		private Transaction $transaction,
		private int $decimals,
		private DesignerFiles $files
	) {
	}

	/**
	 * Starts a designer product on an active base product.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $base_id Base product.
	 * @return int The designer product's id.
	 * @throws DesignerException When the base product is not active.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( Creator $creator, int $base_id ): int {
		$base = $this->active_base( $base_id );

		return $this->transaction->run(
			function () use ( $creator, $base ): int {
				[ $product ] = $this->products->create(
					$creator->id,
					(int) $base->id,
					$this->fields(
						$base,
						DesignerNames::prefix( $creator->display_name, $base->name ),
						$base->description,
						array( $base->colors[0]['key'] ?? '' ),
						$base->sizes,
						'fixed',
						0,
						array(
							'version' => DesignValidator::VERSION,
							'sides'   => array(),
						)
					)
				);
				$this->audit->record(
					AuditAction::DesignerProductCreated,
					$creator->id,
					array(
						'design_product_id' => $product,
						'base_id'           => (int) $base->id,
					)
				);

				return $product;
			}
		);
	}

	/**
	 * "Copy design" (ADR-096 pkt. 7): a new designer product whose first
	 * draft has the setup of the newest version of one of the creator's
	 * products, in any status. The design is checked again against the base
	 * product as it is now (its sides, colours and sizes); files are not
	 * copied, so the copy renders its own at submission.
	 *
	 * @param Creator         $creator   The creator.
	 * @param int             $product   The designer product to copy.
	 * @param DesignValidator $validator The design check for this creator.
	 * @return int The new designer product's id.
	 * @throws DesignerException When the product is unknown, its base product is not active or its setup is refused.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function duplicate( Creator $creator, int $product, DesignValidator $validator ): int {
		$row     = $this->products->find( $creator->id, $product ) ?? self::refuse( DesignerException::DESIGN_UNKNOWN );
		$version = $this->products->newest( $product ) ?? self::refuse( DesignerException::DESIGN_UNKNOWN );
		$base    = $this->active_base( (int) $row['base_id'] );
		$prefix  = DesignerNames::prefix( $creator->display_name, $base->name );
		$design  = json_decode( (string) $version['design'], true );
		$offered = array_column( $base->colors, 'key' );
		$colors  = self::in_chosen_order( json_decode( (string) $version['colors'], true ), $offered );
		$sizes   = self::subset( json_decode( (string) $version['sizes'], true ), $base->sizes );
		$kind    = in_array( $version['profit_kind'], array( 'percent', 'fixed' ), true ) ? (string) $version['profit_kind'] : 'fixed';
		$colors  = array() === $colors ? array( $offered[0] ?? '' ) : $colors;

		$fields = $this->fields(
			$base,
			$this->copy_title( $prefix, DesignerNames::postfix( (string) $version['title'], $prefix, $base->name ) ),
			(string) $version['description'],
			$colors,
			array() === $sizes ? $base->sizes : $sizes,
			$kind,
			$this->profit_units( $version['profit_value'] ),
			$validator->validate( self::known_sides( is_array( $design ) ? $design : array(), $base ), $base, $this->bases->provider( (int) $base->id )['stitches'] ?? array(), $colors )
		);

		$fields['primary_image'] = DesignerImages::valid( $version['primary_image'] ?? null, $fields['design'], $colors ) ? $version['primary_image'] : null;

		return $this->transaction->run(
			function () use ( $creator, $base, $fields, $product ): int {
				[ $copy ] = $this->products->create( $creator->id, (int) $base->id, $fields );
				$this->audit->record(
					AuditAction::DesignerProductCreated,
					$creator->id,
					array(
						'design_product_id' => $copy,
						'base_id'           => (int) $base->id,
						'copied_from'       => $product,
					)
				);

				return $copy;
			}
		);
	}

	/**
	 * Saves the draft of a creator's designer product.
	 *
	 * @param Creator              $creator   The creator.
	 * @param int                  $product   Designer product.
	 * @param array<string, mixed> $input     `title` (the creator's addition to the name), `colors`, `sizes`, `profit_kind`, `profit_value` (decimal string), `design`.
	 * @param DesignValidator      $validator The design check for this creator.
	 * @throws DesignerException When the product or its draft is unknown, or a value is refused.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save( Creator $creator, int $product, array $input, DesignValidator $validator ): void {
		$row   = $this->products->find( $creator->id, $product ) ?? self::refuse( DesignerException::DESIGN_UNKNOWN );
		$draft = $this->products->draft( $product ) ?? self::refuse( DesignerException::DESIGN_NOT_DRAFT );
		$base  = $this->bases->find( (int) $row['base_id'] ) ?? self::refuse( DesignerException::BASE_UNKNOWN );
		$title = DesignerNames::full(
			DesignerNames::prefix( $creator->display_name, $base->name ),
			sanitize_text_field( is_string( $input['title'] ?? null ) ? $input['title'] : '' )
		);

		if ( mb_strlen( $title ) > 120 ) {
			self::refuse( DesignerException::DESIGN_TITLE );
		}

		if ( $this->products->name_taken( $title, $product ) ) {
			self::refuse( DesignerException::DESIGN_NAME_TAKEN );
		}

		$kind  = $input['profit_kind'] ?? null;
		$value = $this->profit_units( $input['profit_value'] ?? null );

		if ( ! in_array( $kind, array( 'percent', 'fixed' ), true ) ) {
			self::refuse( DesignerException::DESIGN_PROFIT );
		}

		$colors = self::in_chosen_order( $input['colors'] ?? null, array_column( $base->colors, 'key' ) );
		$fields = $this->fields(
			$base,
			$title,
			$base->description,
			$colors,
			self::subset( $input['sizes'] ?? null, $base->sizes ),
			$kind,
			$value,
			$validator->validate( $input['design'] ?? null, $base, $this->bases->provider( (int) $row['base_id'] )['stitches'] ?? array(), $colors )
		);

		// The image the creator chose, kept only while it names a mockup of the design (ADR-096).
		$fields['primary_image'] = DesignerImages::valid( $input['primary_image'] ?? null, $fields['design'], $colors ) ? $input['primary_image'] : null;

		$this->products->save_draft( (int) $draft['id'], $fields );
	}

	/**
	 * Deletes a designer product that never reached the shop, for good.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @throws DesignerException When it is unknown or has been submitted.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( Creator $creator, int $product ): void {
		$row = $this->products->product( $product );

		if ( null === $row || $creator->id !== (int) $row['creator_id'] ) {
			self::refuse( DesignerException::DESIGN_UNKNOWN );
		}

		$files = $this->transaction->run(
			function () use ( $creator, $product ): array {
				// Locked first, so a submission or an attached file cannot slip in between (review 30.15).
				$this->products->lock_versions( $product );
				$files = $this->products->files_of( $product );

				if ( ! $this->products->delete_draft_product( $product ) ) {
					throw new DesignerException( DesignerException::DESIGN_NOT_DRAFT );
				}

				$this->audit->record( AuditAction::DesignerProductDeleted, $creator->id, array( 'design_product_id' => $product ) );

				return $files;
			}
		);

		// The submission files of its drafts and withdrawn versions (step 30.9), once the rows are gone.
		foreach ( $files as $name ) {
			$this->files->delete( $name );
		}
	}

	/**
	 * The draft fields with the price they give now.
	 *
	 * @phpstan-param list<string> $colors
	 * @phpstan-param list<string> $sizes
	 *
	 * @param BaseProduct                                                           $base        Base product.
	 * @param string                                                                $title       Title.
	 * @param string                                                                $description Description.
	 * @param string[]                                                              $colors      Colour keys.
	 * @param string[]                                                              $sizes       Sizes.
	 * @param string                                                                $kind        `percent` or `fixed`.
	 * @param int                                                                   $value       Profit value in units (a percent × 10 000 or an amount).
	 * @param array{version: int, sides: array<string, list<array<string, mixed>>>} $design Design.
	 * @return array<string, mixed>
	 * @throws DesignerException When the colours, sizes or price are invalid.
	 */
	private function fields( BaseProduct $base, string $title, string $description, array $colors, array $sizes, string $kind, int $value, array $design ): array {
		if ( array() === $colors || in_array( '', $colors, true ) || array() === $sizes ) {
			self::refuse( DesignerException::DESIGN_OPTIONS );
		}

		try {
			$price = DesignerPrices::compute( 1, SalesAmount::format( DesignerPrices::secured_base( $base, $design, $sizes, null, $colors ) ), $kind, SalesAmount::format( $value ), $base->currency, $this->decimals );
		} catch ( InvalidArgumentException | OverflowException ) {
			self::refuse( DesignerException::DESIGN_PROFIT );
		}

		return array(
			'base_version' => $base->version,
			'title'        => $title,
			'description'  => $description,
			'colors'       => $colors,
			'sizes'        => $sizes,
			'sides'        => self::all_sides( $design ),
			'design'       => $design,
			'profit_kind'  => $kind,
			'profit_value' => SalesAmount::format( $value ),
			'quoted_price' => SalesAmount::format( $price->price_units() ),
		);
	}

	/**
	 * A free name for a copy: the original's addition followed by "(copy)",
	 * "(copy 2)" … within the name's length.
	 *
	 * @param string $prefix  The fixed part of the name.
	 * @param string $postfix The original's addition.
	 * @throws DatabaseException When the names cannot be read.
	 */
	private function copy_title( string $prefix, string $postfix ): string {
		$title = '';

		for ( $number = 1; $number <= 20; $number++ ) {
			$mark = __( '(copy)', 'merchboard' );

			if ( $number > 1 ) {
				/* translators: %d: the number of the copy (2 or more). */
				$mark = sprintf( __( '(copy %d)', 'merchboard' ), $number );
			}

			$room  = max( 0, 120 - mb_strlen( $prefix ) - mb_strlen( $mark ) - 2 );
			$title = DesignerNames::full( $prefix, trim( mb_substr( $postfix, 0, $room ) . ' ' . $mark ) );

			if ( ! $this->products->name_taken( $title, 0 ) ) {
				break;
			}
		}

		return $title;
	}

	/**
	 * A design without the sides, colours and threads of sides the base
	 * product no longer has, so a copy of an old design is not refused.
	 *
	 * @param array<mixed, mixed> $design The stored design.
	 * @param BaseProduct         $base   The base product as it is now.
	 * @return array<mixed, mixed>
	 */
	private static function known_sides( array $design, BaseProduct $base ): array {
		$keys = array_flip( array_map( static fn( $view ): string => $view->key, $base->views ) );
		$keep = static function ( array $part ) use ( $keys ): array {
			foreach ( array( 'sides', 'background', 'threads' ) as $field ) {
				if ( isset( $part[ $field ] ) && is_array( $part[ $field ] ) ) {
					$part[ $field ] = array_intersect_key( $part[ $field ], $keys );
				}
			}

			return $part;
		};

		$design = $keep( $design );

		if ( isset( $design['designs'] ) && is_array( $design['designs'] ) ) {
			$design['designs'] = array_map( static fn( mixed $item ): mixed => is_array( $item ) ? $keep( $item ) : $item, $design['designs'] );
		}

		return $design;
	}

	/**
	 * The sides of every design of a product (the shared one and the
	 * colour designs, ADR-096), in the order first met.
	 *
	 * @param array<string, mixed> $design Validated design.
	 * @return list<string>
	 */
	private static function all_sides( array $design ): array {
		$sides = array_keys( (array) ( $design['sides'] ?? array() ) );

		foreach ( (array) ( $design['designs'] ?? array() ) as $item ) {
			$sides = array_merge( $sides, array_keys( (array) ( is_array( $item ) ? ( $item['sides'] ?? array() ) : array() ) ) );
		}

		return array_values( array_unique( array_map( 'strval', $sides ) ) );
	}

	/**
	 * An active base product.
	 *
	 * @param int $base_id Base product.
	 * @throws DesignerException When it is unknown or not active.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function active_base( int $base_id ): BaseProduct {
		$base = $this->bases->find( $base_id );

		if ( null === $base || BaseProduct::ACTIVE !== $base->status ) {
			self::refuse( DesignerException::BASE_UNKNOWN );
		}

		return $base;
	}

	/**
	 * A profit value in units: a decimal, at least 0.
	 *
	 * @param mixed $value Decimal string or number.
	 * @throws DesignerException When it is invalid.
	 */
	private function profit_units( mixed $value ): int {
		$text = is_int( $value ) ? (string) $value : ( is_string( $value ) ? str_replace( ',', '.', trim( $value ) ) : '' );

		try {
			$units = SalesAmount::parse( $text );
		} catch ( InvalidArgumentException | OverflowException ) {
			self::refuse( DesignerException::DESIGN_PROFIT );
		}

		if ( $units < 0 || $units > self::MAX_PROFIT * 10000 ) {
			self::refuse( DesignerException::DESIGN_PROFIT );
		}

		return $units;
	}

	/**
	 * The chosen values that the base product offers, in its order.
	 *
	 * @phpstan-param list<string> $offered
	 *
	 * @param mixed    $chosen  Chosen values.
	 * @param string[] $offered Offered values.
	 * @return list<string>
	 */
	private static function subset( mixed $chosen, array $offered ): array {
		$chosen = array_filter( is_array( $chosen ) ? $chosen : array(), 'is_string' );

		return array_values( array_intersect( $offered, $chosen ) );
	}

	/**
	 * The chosen values that the base product offers, in the creator's order.
	 *
	 * @phpstan-param list<string> $offered
	 *
	 * @param mixed    $chosen  Chosen values.
	 * @param string[] $offered Offered values.
	 * @return list<string>
	 */
	private static function in_chosen_order( mixed $chosen, array $offered ): array {
		$chosen = array_filter( is_array( $chosen ) ? $chosen : array(), 'is_string' );

		return array_values( array_unique( array_intersect( $chosen, $offered ) ) );
	}

	/**
	 * Refuses with a reason.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
