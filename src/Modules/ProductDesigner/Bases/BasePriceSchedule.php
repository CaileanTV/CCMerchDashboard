<?php
/**
 * Price increases of base products, announced 14 days before.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Bases;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-094: when a base product that creators design on gets a higher price
 * (the base price, a size's own price, the price of a large or small extra
 * area, or a side that becomes large), the whole new set of prices waits
 * 14 days. The creators are told at once and the designer shows the coming
 * price; on the date the prices are set and the existing repricing runs
 * (`merchboard_designer_base_price_changed`). One change per base product:
 * a new one replaces it, and the administrator can cancel it. Stored in an
 * option, keyed by base product.
 */
class BasePriceSchedule {

	public const OPTION = 'merchboard_designer_price_schedule';

	public const NOTICE_DAYS = 14;

	public const NOTIFICATION = 'designer_price_increase';

	/**
	 * Current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param BaseProductRepository     $bases    Base products.
	 * @param DesignerProductRepository $products Designer products (who to tell).
	 * @param AuditTrail                $audit    Audit trail.
	 * @param Logger                    $logger   Technical log.
	 * @param Closure|null              $clock    Current time (tests).
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 */
	public function __construct( private BaseProductRepository $bases, private DesignerProductRepository $products, private AuditTrail $audit, private Logger $logger, ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Whether creators design on a base product, so a higher price waits.
	 *
	 * @param int $base_id Base product.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function applies( int $base_id ): bool {
		return array() !== $this->products->creators_of_base( $base_id );
	}

	/**
	 * Whether new prices raise any price of a base product.
	 *
	 * @param BaseProduct         $base        The base product now.
	 * @param int                 $price       New base price (units).
	 * @param array<string, int>  $size_prices New prices of sizes with their own.
	 * @param int                 $large       New price of a large extra area.
	 * @param int                 $small       New price of a small extra area.
	 * @param array<string, bool> $small_sides Whether each existing side is small, by key.
	 */
	public static function raises( BaseProduct $base, int $price, array $size_prices, int $large, int $small, array $small_sides ): bool {
		if ( $price > $base->base_units || $large > $base->extra_side_units || $small > $base->small_side_units ) {
			return true;
		}

		foreach ( $base->sizes as $size ) {
			if ( ( $size_prices[ $size ] ?? $price ) > ( $base->size_prices[ $size ] ?? $base->base_units ) ) {
				return true;
			}
		}

		foreach ( $base->views as $view ) {
			if ( $view->small && false === ( $small_sides[ $view->key ] ?? true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The planned change of a base product, or null.
	 *
	 * @param int $base_id Base product.
	 * @return array{at: int, base: int, sizes: array<string, int>, large: int, small: int, small_sides: array<string, bool>, scheduled_at: int}|null
	 * @phpstan-impure Reads the option.
	 */
	public static function scheduled( int $base_id ): ?array {
		$entry = self::stored()[ $base_id ] ?? null;

		return is_array( $entry ) ? self::entry( $entry ) : null;
	}

	/**
	 * Plans new prices 14 days from now and tells the creators.
	 *
	 * @param BaseProduct         $base        The base product now.
	 * @param int                 $price       New base price.
	 * @param array<string, int>  $size_prices New prices of sizes.
	 * @param int                 $large       New large area price.
	 * @param int                 $small       New small area price.
	 * @param array<string, bool> $small_sides Whether each side is small.
	 * @return int When the prices apply (Unix time).
	 * @throws DatabaseException When the creators or the audit cannot be read or written.
	 */
	public function schedule( BaseProduct $base, int $price, array $size_prices, int $large, int $small, array $small_sides ): int {
		$id     = (int) $base->id;
		$now    = ( $this->clock )();
		$at     = $now->add( new DateInterval( 'P' . self::NOTICE_DAYS . 'D' ) )->getTimestamp();
		$stored = self::stored();

		$stored[ $id ] = array(
			'at'           => $at,
			'base'         => $price,
			'sizes'        => $size_prices,
			'large'        => $large,
			'small'        => $small,
			'small_sides'  => $small_sides,
			'scheduled_at' => $now->getTimestamp(),
		);

		update_option( self::OPTION, $stored, false );
		$this->audit->record(
			AuditAction::DesignerPriceScheduled,
			null,
			array(
				'base_id'    => $id,
				'base_name'  => $base->name,
				'applies_at' => gmdate( 'Y-m-d H:i:s', $at ),
			)
		);

		foreach ( $this->products->creators_of_base( $id ) as $creator_id ) {
			do_action(
				'merchboard_notify',
				self::NOTIFICATION,
				$creator_id,
				array(
					'title'    => $base->name,
					'date'     => gmdate( 'Y-m-d', $at ),
					'price'    => SalesAmount::format( $price ),
					'before'   => SalesAmount::format( $base->base_units ),
					'currency' => $base->currency,
				),
				'designer_price:' . $id . ':' . $now->getTimestamp()
			);
		}

		return $at;
	}

	/**
	 * Cancels the planned change of a base product.
	 *
	 * @param int $base_id Base product.
	 * @throws DesignerException When the user may not.
	 * @throws DatabaseException When it cannot be audited.
	 */
	public function cancel( int $base_id ): void {
		self::authorize();

		$stored = self::stored();

		if ( ! isset( $stored[ $base_id ] ) ) {
			return;
		}

		unset( $stored[ $base_id ] );
		update_option( self::OPTION, $stored, false );
		$this->audit->record( AuditAction::DesignerPriceScheduleCancelled, null, array( 'base_id' => $base_id ) );
	}

	/**
	 * Sets the prices whose date has come (hourly); never throws.
	 *
	 * @return int How many base products got their new prices.
	 */
	public function apply_due(): int {
		$now     = ( $this->clock )()->getTimestamp();
		$applied = 0;

		foreach ( self::stored() as $base_id => $raw ) {
			$entry = is_array( $raw ) ? self::entry( $raw ) : null;

			if ( null === $entry || $entry['at'] > $now ) {
				continue;
			}

			try {
				$this->apply( (int) $base_id, $entry );
				++$applied;
			} catch ( Throwable $e ) {
				$this->logger->error( 'A planned base price could not be set.', array( 'base_id' => (int) $base_id, 'error' => $e::class ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short.
			}
		}

		return $applied;
	}

	/**
	 * The text the designer shows of a planned change ('' for none).
	 *
	 * @param BaseProduct $base Base product.
	 */
	public static function notice( BaseProduct $base ): string {
		$entry = null === $base->id ? null : self::scheduled( $base->id );

		if ( null === $entry ) {
			return '';
		}

		return sprintf(
			/* translators: 1: date, 2: new base price, 3: currency. */
			__( 'The price of this product goes up on %1$s (base price %2$s %3$s). Products in the shop follow on that date; your profit setting stays the same.', 'merchboard' ),
			wp_date( (string) get_option( 'date_format' ), $entry['at'] ),
			SalesAmount::format( $entry['base'] ),
			$base->currency
		);
	}

	/**
	 * Sets a planned change.
	 *
	 * @param int                                                                                                                               $base_id Base product.
	 * @param array{at: int, base: int, sizes: array<string, int>, large: int, small: int, small_sides: array<string, bool>, scheduled_at: int} $entry   The change.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function apply( int $base_id, array $entry ): void {
		$base   = $this->bases->find( $base_id );
		$stored = self::stored();
		unset( $stored[ $base_id ] );

		if ( null === $base || BaseProduct::RETIRED === $base->status ) {
			update_option( self::OPTION, $stored, false );

			return;
		}

		$views = array_map(
			static fn( BaseView $view ): BaseView => new BaseView( $view->key, $view->name, $view->width, $view->height, $view->layers, $view->area, $view->width_mm, $view->height_mm, $view->dpi, $view->thread_pack, $view->flat, $view->scene, $view->sizes, $entry['small_sides'][ $view->key ] ?? $view->small ),
			$base->views
		);
		$sizes = array_intersect_key( $entry['sizes'], array_flip( $base->sizes ) );

		$this->bases->update( $base_id, new BaseProduct( $base->id, $base->status, $base->source, $base->name, $base->description, $entry['base'], $base->currency, $base->category_id, $base->colors, $base->sizes, $views, $base->version, $entry['large'], $base->note, $sizes, $entry['small'] ) );
		update_option( self::OPTION, $stored, false );
		$this->audit->record_system(
			AuditAction::DesignerPriceApplied,
			null,
			array(
				'base_id'   => $base_id,
				'base_name' => $base->name,
			)
		);

		do_action( 'merchboard_designer_base_price_changed', $base_id );
	}

	/**
	 * Requires the capability to manage the designer.
	 *
	 * @throws DesignerException When the user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			throw new DesignerException( DesignerException::NOT_AUTHORIZED );
		}
	}

	/**
	 * The stored changes.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * A stored change, checked.
	 *
	 * @param array<mixed> $raw Stored value.
	 * @return array{at: int, base: int, sizes: array<string, int>, large: int, small: int, small_sides: array<string, bool>, scheduled_at: int}|null
	 */
	private static function entry( array $raw ): ?array {
		if ( ! is_int( $raw['at'] ?? null ) || ! is_int( $raw['base'] ?? null ) || $raw['base'] < 1 || ! is_int( $raw['large'] ?? null ) || ! is_int( $raw['small'] ?? null ) ) {
			return null;
		}

		$sizes = array();

		foreach ( is_array( $raw['sizes'] ?? null ) ? $raw['sizes'] : array() as $size => $units ) {
			if ( is_int( $units ) && $units > 0 ) {
				$sizes[ (string) $size ] = $units;
			}
		}

		$small_sides = array();

		foreach ( is_array( $raw['small_sides'] ?? null ) ? $raw['small_sides'] : array() as $key => $small ) {
			$small_sides[ (string) $key ] = true === $small;
		}

		return array(
			'at'           => $raw['at'],
			'base'         => $raw['base'],
			'sizes'        => $sizes,
			'large'        => max( 0, $raw['large'] ),
			'small'        => max( 0, $raw['small'] ),
			'small_sides'  => $small_sides,
			'scheduled_at' => is_int( $raw['scheduled_at'] ?? null ) ? $raw['scheduled_at'] : 0,
		);
	}
}
