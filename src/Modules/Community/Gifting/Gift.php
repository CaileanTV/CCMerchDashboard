<?php
/**
 * A gift a viewer bought in a creator's Twitch stream.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `merchboard_gifts` (ADR-107 pkt. 4). Statuses:
 *
 * - `waiting`: paid, waiting for a draw (also after an unclaimed win);
 * - `entering`: the `!enter` window is open;
 * - `won`: a winner was drawn and has 24 hours to claim;
 * - `claimed`: the winner got the gift code;
 * - `returned`: nobody claimed it after 3 draws, the buyer got the code;
 * - `cancelled`: the gift order was cancelled or refunded before a draw.
 */
final readonly class Gift {

	public const WAITING   = 'waiting';
	public const ENTERING  = 'entering';
	public const WON       = 'won';
	public const CLAIMED   = 'claimed';
	public const RETURNED  = 'returned';
	public const CANCELLED = 'cancelled';

	/**
	 * Most draws before the code goes to the buyer.
	 */
	public const MAX_DRAWS = 3;

	/**
	 * Hours a winner has to claim.
	 */
	public const CLAIM_HOURS = 24;

	/**
	 * Constructor.
	 *
	 * @param int                  $id               Id.
	 * @param int                  $creator_id       Creator.
	 * @param int                  $order_id         The gift order.
	 * @param int                  $product_id       Parent product.
	 * @param string               $unit_price       Paid unit price incl. VAT (decimal).
	 * @param string               $shipping_credit  Shipping the gift covers (decimal).
	 * @param string               $currency         Currency.
	 * @param string|null          $buyer_name       The name to announce; null for anonymous.
	 * @param string               $status           Status.
	 * @param int                  $draws            Draws so far.
	 * @param array<string,string> $entrants         Twitch id => login while the window is open.
	 * @param string|null          $window_ends      UTC (Y-m-d H:i:s) while entering.
	 * @param string|null          $winner_twitch_id The winner's Twitch id.
	 * @param string|null          $winner_login     The winner's Twitch login.
	 * @param string|null          $claim_deadline   UTC (Y-m-d H:i:s) while won.
	 * @param string|null          $claim_token      The claim link's token while won.
	 * @param int|null             $coupon_id        The gift code's coupon.
	 * @param string               $created_at       UTC.
	 */
	public function __construct(
		public int $id,
		public int $creator_id,
		public int $order_id,
		public int $product_id,
		public string $unit_price,
		public string $shipping_credit,
		public string $currency,
		public ?string $buyer_name,
		public string $status,
		public int $draws,
		public array $entrants,
		public ?string $window_ends,
		public ?string $winner_twitch_id,
		public ?string $winner_login,
		public ?string $claim_deadline,
		public ?string $claim_token,
		public ?int $coupon_id,
		public string $created_at
	) {
	}

	/**
	 * A gift from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function from_row( array $row ): self {
		$entrants = json_decode( (string) ( $row['entrants'] ?? '' ), true );
		$clean    = array();

		foreach ( is_array( $entrants ) ? $entrants : array() as $id => $login ) {
			if ( 1 === preg_match( '/^\d{1,20}$/', (string) $id ) && is_string( $login ) ) {
				$clean[ (string) $id ] = $login;
			}
		}

		$text = static fn( mixed $value ): ?string => null === $value || '' === $value ? null : (string) $value;

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['creator_id'] ?? 0 ),
			(int) ( $row['order_id'] ?? 0 ),
			(int) ( $row['product_id'] ?? 0 ),
			(string) ( $row['unit_price'] ?? '0' ),
			(string) ( $row['shipping_credit'] ?? '0' ),
			(string) ( $row['currency'] ?? '' ),
			$text( $row['buyer_name'] ?? null ),
			(string) ( $row['status'] ?? self::WAITING ),
			(int) ( $row['draws'] ?? 0 ),
			$clean,
			$text( $row['window_ends'] ?? null ),
			$text( $row['winner_twitch_id'] ?? null ),
			$text( $row['winner_login'] ?? null ),
			$text( $row['claim_deadline'] ?? null ),
			$text( $row['claim_token'] ?? null ),
			is_numeric( $row['coupon_id'] ?? null ) ? (int) $row['coupon_id'] : null,
			(string) ( $row['created_at'] ?? '' )
		);
	}
}
