<?php
/**
 * The daily number of previews per creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\NamedLock;

defined( 'ABSPATH' ) || exit;

/**
 * At most PER_DAY previews a creator starts each day (ADR-116, security
 * review 2026-11-21): each one is a mockup task at a provider, which uses
 * the shop's quota there. The day is Copenhagen's; the count is a transient
 * per creator and day, read and raised under a named lock so parallel
 * requests cannot pass it together. Polling a started preview is not
 * counted.
 */
final class PreviewQuota {

	/**
	 * Previews a creator may start a day (the owner's choice 2026-11-21).
	 */
	public const PER_DAY = 100;

	/**
	 * Transient prefix.
	 */
	private const PREFIX = 'merchboard_previews_';

	/**
	 * Current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 *
	 * @param NamedLock|null $lock  The counter's lock; null counts without one (tests).
	 * @param Closure|null   $clock Current time; defaults to now.
	 */
	public function __construct( private ?NamedLock $lock = null, ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Counts a preview and tells whether the creator may start it.
	 *
	 * @param int $creator_id Creator.
	 */
	public function take( int $creator_id ): bool {
		$now  = ( $this->clock )()->setTimezone( new DateTimeZone( 'Europe/Copenhagen' ) );
		$key  = self::PREFIX . $now->format( 'Ymd' ) . '_' . $creator_id;
		$left = max( 60, (int) $now->setTime( 0, 0 )->modify( '+1 day' )->format( 'U' ) - (int) $now->format( 'U' ) );
		$work = static function () use ( $key, $left ): bool {
			$count = (int) get_transient( $key );

			if ( $count >= self::PER_DAY ) {
				return false;
			}

			set_transient( $key, $count + 1, $left );

			return true;
		};

		return true === ( null === $this->lock ? $work() : $this->lock->run( 'merchboard_previews_' . $creator_id, 5, $work ) );
	}
}
