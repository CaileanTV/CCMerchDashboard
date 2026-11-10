<?php
/**
 * A creator's discount campaigns for Twitch subscribers.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-106 pkt. 3 (status note 2026-11-10): whether gifted subscriptions
 * count (for all the creator's campaigns) and up to MAX_CAMPAIGNS
 * campaigns. On a product, an active campaign of selected products goes
 * before an active campaign of all products; a product is in at most one
 * active campaign of each kind (the repository checks it when saving).
 */
final readonly class TwitchSubDiscountSettings {

	/**
	 * Most campaigns.
	 */
	public const MAX_CAMPAIGNS = 20;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<TwitchSubCampaign> $campaigns
	 *
	 * @param bool                $gifted    Whether gifted subscriptions count.
	 * @param TwitchSubCampaign[] $campaigns Campaigns.
	 */
	public function __construct( public bool $gifted, public array $campaigns ) {
	}

	/**
	 * Nothing set: gifted subscriptions count, no campaigns.
	 */
	public static function defaults(): self {
		return new self( true, array() );
	}

	/**
	 * The stored settings; unreadable campaigns are left out.
	 *
	 * @param mixed $stored Stored creator meta.
	 */
	public static function from_stored( mixed $stored ): self {
		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}

		$campaigns = array();

		foreach ( is_array( $stored['campaigns'] ?? null ) ? $stored['campaigns'] : array() as $campaign ) {
			$read = TwitchSubCampaign::from_stored( $campaign );

			if ( null !== $read ) {
				$campaigns[] = $read;
			}
		}

		return new self( true === ( $stored['gifted'] ?? true ), array_slice( $campaigns, 0, self::MAX_CAMPAIGNS ) );
	}

	/**
	 * The value to store.
	 *
	 * @return array{gifted: bool, campaigns: list<array<string, mixed>>}
	 */
	public function to_stored(): array {
		return array(
			'gifted'    => $this->gifted,
			'campaigns' => array_map( static fn( TwitchSubCampaign $campaign ): array => $campaign->to_stored(), $this->campaigns ),
		);
	}

	/**
	 * The campaign that applies to a parent product on a day: an active
	 * campaign of selected products before one of all products; null for
	 * none.
	 *
	 * @param int    $product_id Parent product.
	 * @param string $today      The day (Y-m-d, site time zone).
	 */
	public function campaign_for( int $product_id, string $today ): ?TwitchSubCampaign {
		$all = null;

		foreach ( $this->campaigns as $campaign ) {
			if ( ! $campaign->active( $today ) || ! $campaign->covers( $product_id ) ) {
				continue;
			}

			if ( TwitchSubCampaign::SELECTED === $campaign->scope ) {
				return $campaign;
			}

			$all ??= $campaign;
		}

		return $all;
	}

	/**
	 * Whether a subscription counts: gifted ones as the creator chose.
	 *
	 * @param bool $gifted Whether it was a gift.
	 */
	public function counts( bool $gifted ): bool {
		return ! $gifted || $this->gifted;
	}
}
