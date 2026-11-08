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
 * ADR-094: when a base product that creators design on gets a higher price,
 * only the prices that go up wait 14 days; everything else of the save
 * (also lower prices) applies at once. A planned increase is one of:
 * - `base`: the base price;
 * - `large` / `small`: the price of a large or small extra design area;
 * - `sizes`: a size's own price (or a size that gets a higher own price
 *   than the base price it had);
 * - `large_sides`: sides that become large areas.
 * The creators are told at once and the designer shows the coming change. On
 * the date only those components are set on the base product as it is then,
 * and the existing repricing runs (`merchboard_designer_base_price_changed`).
 * A later increase is added to the plan (its date moves 14 days from then,
 * and the creators are told again); the administrator can cancel the plan.
 * Stored in an option, keyed by base product.
 *
 * @phpstan-type Plan array{at: int, base: int|null, large: int|null, small: int|null, sizes: array<string, int>, large_sides: list<string>, scheduled_at: int}
 * @phpstan-type Increase array{base: int|null, large: int|null, small: int|null, sizes: array<string, int>, large_sides: list<string>}
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
	 * The prices a save raises, compared with the base product now; only
	 * sizes and sides it keeps count.
	 *
	 * @param BaseProduct         $base        The base product now.
	 * @param int                 $price       New base price (units).
	 * @param array<string, int>  $size_prices New own prices of sizes.
	 * @param string[]            $sizes       The sizes it keeps or gets.
	 * @param int                 $large       New price of a large extra area.
	 * @param int                 $small       New price of a small extra area.
	 * @param array<string, bool> $small_sides Whether each existing side is small, by key.
	 * @return Increase|null Null when nothing goes up.
	 */
	public static function increases( BaseProduct $base, int $price, array $size_prices, array $sizes, int $large, int $small, array $small_sides ): ?array {
		$increase = array(
			'base'        => $price > $base->base_units ? $price : null,
			'large'       => $large > $base->extra_side_units ? $large : null,
			'small'       => $small > $base->small_side_units ? $small : null,
			'sizes'       => array(),
			'large_sides' => array(),
		);

		foreach ( array_intersect( $base->sizes, $sizes ) as $size ) {
			$own = $size_prices[ $size ] ?? null;

			// A size without an own price follows the base price (and its increase).
			if ( null !== $own && $own > ( $base->size_prices[ $size ] ?? $base->base_units ) ) {
				$increase['sizes'][ (string) $size ] = $own;
			}
		}

		foreach ( $base->views as $view ) {
			if ( $view->small && false === ( $small_sides[ $view->key ] ?? true ) ) {
				$increase['large_sides'][] = $view->key;
			}
		}

		$raised = null !== $increase['base'] || null !== $increase['large'] || null !== $increase['small'] || array() !== $increase['sizes'] || array() !== $increase['large_sides'];

		return $raised ? $increase : null;
	}

	/**
	 * The planned change of a base product, or null.
	 *
	 * @param int $base_id Base product.
	 * @return Plan|null
	 * @phpstan-impure Reads the option.
	 */
	public static function scheduled( int $base_id ): ?array {
		$entry = self::stored()[ $base_id ] ?? null;

		return is_array( $entry ) ? self::entry( $entry ) : null;
	}

	/**
	 * Plans the increases 14 days from now (added to a plan there is) and
	 * tells the creators.
	 *
	 * @param BaseProduct  $base     The base product now.
	 * @param array<mixed> $increase What goes up.
	 * @phpstan-param Increase $increase
	 * @return int When the prices apply (Unix time).
	 * @throws DatabaseException When the creators or the audit cannot be read or written.
	 */
	public function schedule( BaseProduct $base, array $increase ): int {
		$id     = (int) $base->id;
		$now    = ( $this->clock )();
		$at     = $now->add( new DateInterval( 'P' . self::NOTICE_DAYS . 'D' ) )->getTimestamp();
		$before = self::scheduled( $id );
		$stored = self::stored();

		$stored[ $id ] = array(
			'at'           => $at,
			'base'         => $increase['base'] ?? $before['base'] ?? null,
			'large'        => $increase['large'] ?? $before['large'] ?? null,
			'small'        => $increase['small'] ?? $before['small'] ?? null,
			'sizes'        => array_merge( $before['sizes'] ?? array(), $increase['sizes'] ),
			'large_sides'  => array_values( array_unique( array_merge( $before['large_sides'] ?? array(), $increase['large_sides'] ) ) ),
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

		$planned = self::entry( $stored[ $id ] );

		foreach ( $this->products->creators_of_base( $id ) as $creator_id ) {
			do_action(
				'merchboard_notify',
				self::NOTIFICATION,
				$creator_id,
				array(
					'title'       => $base->name,
					'date'        => gmdate( 'Y-m-d', $at ),
					'base_raised' => null !== ( $planned['base'] ?? null ),
					'price'       => SalesAmount::format( $planned['base'] ?? $base->base_units ),
					'before'      => SalesAmount::format( $base->base_units ),
					'currency'    => $base->currency,
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
	 * Sets a planned increase now: the administrator's "Change the price now"
	 * (owner's wish 2026-11-06). After the date it sets what the automatic
	 * check has not set yet (no page view since the date); before the date it
	 * overrides the 14 days' notice, which is audited as `early`.
	 *
	 * @param int $base_id Base product.
	 * @return bool Whether a planned increase was set (false: none is planned, or the base product is retired or gone).
	 * @throws DesignerException When the user may not.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function apply_now( int $base_id ): bool {
		self::authorize();

		$entry = self::scheduled( $base_id );

		if ( null === $entry ) {
			return false;
		}

		return $this->apply( $base_id, $entry, true, ! $this->due( $entry ) );
	}

	/**
	 * Sets the increases whose date has come (hourly); never throws.
	 *
	 * @return int How many base products got their new prices.
	 */
	public function apply_due(): int {
		$now     = ( $this->clock )()->getTimestamp();
		$applied = 0;

		foreach ( self::stored() as $base_id => $raw ) {
			$entry = is_array( $raw ) ? self::entry( $raw ) : null;

			if ( null === $entry || ! $this->due( $entry, $now ) ) {
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
	 * Whether the date of a planned change has come.
	 *
	 * @param array<mixed> $entry The change.
	 * @param int|null     $now   Now (timestamp; null: the clock).
	 * @phpstan-param Plan $entry
	 */
	private function due( array $entry, ?int $now = null ): bool {
		return $entry['at'] <= ( $now ?? ( $this->clock )()->getTimestamp() );
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

		$date = (string) wp_date( (string) get_option( 'date_format' ), $entry['at'] );

		return null === $entry['base']
			/* translators: %s: date. */
			? sprintf( __( 'Prices of this product go up on %s. Products in the shop follow on that date; your profit setting stays the same.', 'merchboard' ), $date )
			: sprintf(
				/* translators: 1: date, 2: new base price, 3: currency. */
				__( 'The price of this product goes up on %1$s (base price %2$s %3$s). Products in the shop follow on that date; your profit setting stays the same.', 'merchboard' ),
				$date,
				SalesAmount::format( $entry['base'] ),
				$base->currency
			);
	}

	/**
	 * Sets the planned increases on the base product as it is now.
	 *
	 * @param int          $base_id Base product.
	 * @param array<mixed> $entry   The change.
	 * @param bool         $manual  Whether the administrator set it ("Change the price now").
	 * @param bool         $early   Whether that was before the date (the notice overridden).
	 * @phpstan-param Plan $entry
	 * @return bool Whether the prices were set (false: the base product is retired or gone; the plan is removed).
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function apply( int $base_id, array $entry, bool $manual = false, bool $early = false ): bool {
		$base   = $this->bases->find( $base_id );
		$stored = self::stored();
		unset( $stored[ $base_id ] );

		if ( null === $base || BaseProduct::RETIRED === $base->status ) {
			update_option( self::OPTION, $stored, false );

			return false;
		}

		$views = array_map(
			static fn( BaseView $view ): BaseView => new BaseView( $view->key, $view->name, $view->width, $view->height, $view->layers, $view->area, $view->width_mm, $view->height_mm, $view->dpi, $view->thread_pack, $view->flat, $view->scene, $view->sizes, $view->small && ! in_array( $view->key, $entry['large_sides'], true ) ),
			$base->views
		);
		$sizes = array_merge( $base->size_prices, array_intersect_key( $entry['sizes'], array_flip( $base->sizes ) ) );

		$this->bases->update( $base_id, new BaseProduct( $base->id, $base->status, $base->source, $base->name, $base->description, $entry['base'] ?? $base->base_units, $base->currency, $base->category_id, $base->colors, $base->sizes, $views, $base->version, $entry['large'] ?? $base->extra_side_units, $base->note, $sizes, $entry['small'] ?? $base->small_side_units, $base->background_color, $base->tag_ids ) );
		update_option( self::OPTION, $stored, false );
		$context = array(
			'base_id'   => $base_id,
			'base_name' => $base->name,
		);

		if ( $manual ) {
			$context['manual'] = true;
			$context['early']  = $early;
			$this->audit->record( AuditAction::DesignerPriceApplied, null, $context );
		} else {
			$this->audit->record_system( AuditAction::DesignerPriceApplied, null, $context );
		}

		do_action( 'merchboard_designer_base_price_changed', $base_id );

		return true;
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
	 * @return Plan|null
	 */
	private static function entry( array $raw ): ?array {
		if ( ! is_int( $raw['at'] ?? null ) ) {
			return null;
		}

		$units = static fn( mixed $value ): ?int => is_int( $value ) && $value >= 0 ? $value : null;
		$sizes = array();

		foreach ( is_array( $raw['sizes'] ?? null ) ? $raw['sizes'] : array() as $size => $value ) {
			if ( is_int( $value ) && $value > 0 ) {
				$sizes[ (string) $size ] = $value;
			}
		}

		$base = $units( $raw['base'] ?? null );

		return array(
			'at'           => $raw['at'],
			'base'         => null !== $base && $base > 0 ? $base : null,
			'large'        => $units( $raw['large'] ?? null ),
			'small'        => $units( $raw['small'] ?? null ),
			'sizes'        => $sizes,
			'large_sides'  => array_values( array_filter( is_array( $raw['large_sides'] ?? null ) ? $raw['large_sides'] : array(), 'is_string' ) ),
			'scheduled_at' => is_int( $raw['scheduled_at'] ?? null ) ? $raw['scheduled_at'] : 0,
		);
	}
}
