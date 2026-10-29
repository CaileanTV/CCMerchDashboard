<?php
/**
 * Puts an approved design version in the shop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use Closure;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\DesignerPrices;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerContent;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerMedia;
use MerchBoard\Modules\ProductDesigner\Shop\GuardedProduct;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPrice;
use MerchBoard\Modules\WooCommerce\ProductCreatorLinkException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The apply pipeline (step 30.10, ADR-080 pkt. 6), run by Action Scheduler
 * (`merchboard_designer_apply`, one version) under a named lock per version:
 *
 * 1. `product`: the WooCommerce draft of the designer product, behind the
 *    legacy guard (WooCommerceDesignerWriter::create_draft()).
 * 2. `link`: the product is linked to the creator with the origin
 *    `designer` (before it gets a category, so the category reconciliation
 *    never links it first).
 * 3. `media`: the mockups are copied into the media library (once each).
 * 4. `content`: title, description, category (the creator's first, else the
 *    base product's), images, colour × size variations and the price from
 *    the base price now and the version's profit (step 30.11: the shop
 *    follows a new base price); a first approval publishes the product
 *    (WooCommerceDesignerWriter).
 * 5. `live`: the version becomes the live one (the previous is superseded),
 *    the shop's price and its base price are stored for the settlement, and
 *    the creator is notified. A base price that changed meanwhile reprices
 *    the product.
 *
 * Every step can be repeated: an interrupted or failed run continues where
 * it stopped when it runs again ("Try again" under Designs). No transaction
 * around WooCommerce saves (HPOS commits outer transactions). A failure
 * leaves the version `failed` with the step and a reason, and notifies the
 * administrator once per version, step and reason. It never throws.
 */
class DesignerApply {

	public const HOOK = 'merchboard_designer_apply';

	private const LOCK = 'merchboard_designer_apply_';

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb        Database (the named lock).
	 * @param DesignerProductRepository $products    Designer products.
	 * @param BaseProductRepository     $bases       Base products.
	 * @param CreatorRepository         $creators    Creators.
	 * @param CreatorCategoryRepository $categories  Creators' categories.
	 * @param WooCommerceDesignerWriter $writer      The only writer of designer products.
	 * @param DesignerMedia             $media       Mockups in the media library.
	 * @param Closure                   $link        Links the product to its creator (ProductCreatorLink::apply_designer_link()).
	 * @param AuditTrail                $audit       Audit trail.
	 * @param Transaction               $transaction Transaction.
	 * @param Logger                    $logger      Technical log.
	 * @param int                       $decimals    Store price decimals.
	 * @param Closure|null              $reprice     Reprices a product (DesignerRepricing::schedule()).
	 *
	 * @phpstan-param Closure(int, int, array<string, mixed>): mixed $link
	 * @phpstan-param (Closure(int): void)|null $reprice
	 */
	public function __construct(
		private wpdb $wpdb,
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private CreatorRepository $creators,
		private CreatorCategoryRepository $categories,
		private WooCommerceDesignerWriter $writer,
		private DesignerMedia $media,
		private Closure $link,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Logger $logger,
		private int $decimals,
		private ?Closure $reprice = null
	) {
	}

	/**
	 * Applies an approved version (the job); never throws.
	 *
	 * @param mixed $version_id Version.
	 */
	public function run( mixed $version_id ): void {
		$id = is_numeric( $version_id ) ? (int) $version_id : 0;

		if ( $id < 1 || ! $this->lock( $id ) ) {
			return;
		}

		$step = 'start';

		try {
			$this->apply( $id, $step );
		} catch ( Throwable $e ) {
			$this->failed( $id, $step, $e );
		} finally {
			$wpdb = $this->wpdb;
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
		}
	}

	/**
	 * The steps.
	 *
	 * @param int    $id   Version.
	 * @param string $step The running step (for the failure).
	 * @throws DesignerException When a step is refused.
	 * @throws DatabaseException When a step cannot be stored.
	 * @throws ProductCreatorLinkException When the link is refused.
	 */
	private function apply( int $id, string &$step ): void {
		$version = $this->products->version( $id );

		if ( null === $version || DesignerProductRepository::APPROVED !== $version['status'] || DesignerProductRepository::APPLY_DONE === $version['apply_state'] ) {
			return;
		}

		$product_id = (int) $version['design_product_id'];
		$creator_id = (int) $version['creator_id'];
		$base       = $this->bases->find( (int) $version['base_id'] ) ?? self::refuse( DesignerException::BASE_UNKNOWN );

		if ( BaseProduct::RETIRED === $base->status ) {
			self::refuse( DesignerException::BASE_RETIRED );
		}

		$price   = $this->price( $version, $base );
		$creator = $this->creators->find( $creator_id );

		if ( null === $creator || $creator->is_deleted() || CreatorStatus::Active !== $creator->status ) {
			self::refuse( 'creator' );
		}

		$title = (string) $version['title'];

		$step    = $this->step( $id, 'product' );
		$product = $this->writer->create_draft( $product_id, $title );

		$step = $this->step( $id, 'link' );
		( $this->link )( $product->wc_product_id, $creator_id, array( 'design_product_id' => $product_id ) );

		$step  = $this->step( $id, 'media' );
		$files = SubmissionFiles::decode( is_string( $version['files'] ) ? $version['files'] : null );
		$media = $this->media->import( $id, $files, $product->wc_product_id, $title );

		$step = $this->step( $id, 'content' );
		$this->writer->set_content( $product, $this->content( $version, $base, $files, $media, $creator_id, $price->price_units() ) );

		$step = $this->step( $id, 'live' );
		$this->go_live( $version, $product, $price );

		$now = $this->bases->find( $base->id ?? 0 );

		if ( null !== $now && $now->base_units !== $base->base_units && null !== $this->reprice ) {
			( $this->reprice )( $product_id );
		}
	}

	/**
	 * What the shop shows of a version.
	 *
	 * @param array<string, mixed>                                                                            $version Version.
	 * @param BaseProduct                                                                                     $base    Base product.
	 * @param array{stamp: string, print: array<string, mixed>, mockups: array<string, array<string, mixed>>} $files Files.
	 * @param array<string, int>                                                                              $media   Attachment by slot.
	 * @param int                                                                                             $creator_id Creator.
	 * @param int                                                                                             $price      Customer's price in units.
	 * @throws DatabaseException When the creator's categories cannot be read.
	 */
	private function content( array $version, BaseProduct $base, array $files, array $media, int $creator_id, int $price ): DesignerContent {
		$chosen = json_decode( (string) $version['colors'], true );
		$sizes  = json_decode( (string) $version['sizes'], true );
		$chosen = is_array( $chosen ) ? array_values( array_filter( $chosen, 'is_string' ) ) : array();
		$colors = array_values( array_filter( $base->colors, static fn( array $color ): bool => in_array( $color['key'], $chosen, true ) ) );
		$sides  = array_keys( $files['mockups'] );
		$images = array();
		$first  = array();

		// The first colour's sides first, then the other colours.
		foreach ( $colors as $color ) {
			foreach ( $sides as $side ) {
				$attachment = $media[ SubmissionFiles::MOCKUP . ':' . $side . ':' . $color['key'] ] ?? null;

				if ( null !== $attachment ) {
					$images[]                 = $attachment;
					$first[ $color['key'] ] ??= $attachment;
				}
			}
		}

		$category = array_map( static fn( $category ): int => $category->term_id, $this->categories->for_creator( $creator_id ) );
		$category = array() !== $category ? array( min( $category ) ) : ( null === $base->category_id ? array() : array( $base->category_id ) );

		return new DesignerContent(
			(string) $version['title'],
			(string) $version['description'],
			$category,
			array_map(
				static fn( array $color ): array => array(
					'key'  => $color['key'],
					'name' => $color['name'],
				),
				$colors
			),
			is_array( $sizes ) ? array_values( array_filter( $sizes, 'is_string' ) ) : array(),
			$images,
			$first,
			$price,
			$this->decimals,
			null === $version['live_version_id']
		);
	}

	/**
	 * Makes the version live and notifies the creator.
	 *
	 * @param array<string, mixed> $version Version.
	 * @param GuardedProduct       $product The product in the shop.
	 * @param DesignerPrice        $price   The price written to the shop.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function go_live( array $version, GuardedProduct $product, DesignerPrice $price ): void {
		$id     = (int) $version['id'];
		$change = null !== $version['live_version_id'];

		$this->transaction->run(
			function () use ( $version, $id, $product, $price ): void {
				$this->products->go_live( (int) $version['design_product_id'], $id );
				$this->products->set_shop_price( (int) $version['design_product_id'], SalesAmount::format( $price->price_units() ), SalesAmount::format( $price->base_units ) );
				$this->audit->record_system(
					AuditAction::DesignerVersionApplied,
					(int) $version['creator_id'],
					array(
						'design_product_id' => (int) $version['design_product_id'],
						'version_id'        => $id,
						'number'            => (int) $version['number'],
						'product_id'        => $product->wc_product_id,
					)
				);
			}
		);

		do_action(
			'merchboard_notify',
			$change ? DesignerNotifications::CHANGE_APPROVED : DesignerNotifications::APPROVED,
			(int) $version['creator_id'],
			array(
				'title'    => (string) $version['title'],
				'price'    => SalesAmount::format( $price->price_units() ),
				'currency' => $this->currency( (int) $version['base_id'] ),
			),
			'designer_version:' . $id
		);
	}

	/**
	 * The customer's price from the base price now and the version's profit.
	 *
	 * @param array<string, mixed> $version Version.
	 * @param BaseProduct          $base    Base product.
	 * @throws DesignerException When it cannot be priced.
	 */
	private function price( array $version, BaseProduct $base ): DesignerPrice {
		try {
			return DesignerPrices::compute( (int) $version['creator_id'], SalesAmount::format( $base->base_units ), (string) $version['profit_kind'], SalesAmount::format( SalesAmount::parse( (string) $version['profit_value'] ) ), $base->currency, $this->decimals );
		} catch ( InvalidArgumentException | OverflowException ) {
			self::refuse( DesignerException::DESIGN_PROFIT );
		}
	}

	/**
	 * Records the step that runs now.
	 *
	 * @param int    $id   Version.
	 * @param string $step Step.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function step( int $id, string $step ): string {
		$this->products->set_apply( $id, DesignerProductRepository::APPLY_RUNNING, $step, null );

		return $step;
	}

	/**
	 * Records a failure, logs it and notifies the administrator.
	 *
	 * @param int       $id   Version.
	 * @param string    $step Step.
	 * @param Throwable $e    Cause.
	 */
	private function failed( int $id, string $step, Throwable $e ): void {
		$reason = $e instanceof DesignerException ? $e->reason() : ( $e instanceof ProductCreatorLinkException ? 'link' : 'error' );

		$this->logger->error(
			'A designer product could not be put in the shop.',
			array_merge(
				$e instanceof DatabaseException ? $e->context() : array(),
				array(
					'version_id' => $id,
					'step'       => $step,
					'reason'     => $reason,
					'exception'  => $e::class,
				)
			)
		);

		try {
			$this->products->set_apply( $id, DesignerProductRepository::APPLY_FAILED, $step, $reason );
			$version = $this->products->version( $id );
		} catch ( Throwable ) {
			return;
		}

		if ( null !== $version ) {
			do_action(
				'merchboard_notify',
				DesignerNotifications::APPLY_FAILED,
				(int) $version['creator_id'],
				array(
					'title'  => (string) $version['title'],
					'reason' => $reason,
				),
				'designer_apply:' . $id . ':' . $step . ':' . $reason
			);
		}
	}

	/**
	 * Takes the version's lock (one run at a time).
	 *
	 * @param int $id Version.
	 */
	private function lock( int $id ): bool {
		$wpdb     = $this->wpdb;
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK . $id, 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		return is_numeric( $acquired ) && 1 === (int) $acquired;
	}

	/**
	 * The currency of a base product ('' when it is gone).
	 *
	 * @param int $base_id Base product.
	 */
	private function currency( int $base_id ): string {
		try {
			return $this->bases->find( $base_id )->currency ?? '';
		} catch ( DatabaseException ) {
			return '';
		}
	}

	/**
	 * Refuses with a reason.
	 *
	 * @param string $reason DesignerException reason (or "creator").
	 * @throws DesignerException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
