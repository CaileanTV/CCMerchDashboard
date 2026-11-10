<?php
/**
 * The creators' discount campaigns for Twitch subscribers.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use Closure;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Creators\CreatorMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and saves a creator's campaigns (ADR-106 pkt. 3, status note
 * 2026-11-10) in creator meta. The creator saves them all at once through
 * `/me` (the caller resolved the creator with CreatorContext):
 *
 * - At most 20 campaigns, each with a name, whole percents 0–100 per tier,
 *   all products or a selection of the creator's own (at most 500), and an
 *   optional period (first and last day).
 * - Campaigns that are on and whose periods meet: a product is in at most
 *   one campaign of selected products, and there is at most one campaign
 *   of all products (a selection goes before it on its products).
 * - No percent above the creator's lowest profit percent among the
 *   campaign's products (all the creator's products for "all").
 * - A campaign can only be on while the creator's Twitch channel is
 *   connected.
 * - The audit lists the changed field names only.
 *
 * Field codes: `gifted`, `campaigns` and `campaigns.<n>.<field>` with
 * `name`, `tier_one`, `tier_two`, `tier_three`, `scope`, `product_ids`,
 * `starts`, `ends`, `enabled`.
 */
final class TwitchSubDiscountRepository {

	/**
	 * The form field of each tier (field codes are letters only).
	 */
	public const TIER_FIELDS = array(
		1 => 'tier_one',
		2 => 'tier_two',
		3 => 'tier_three',
	);

	/**
	 * Settings read in this request, by creator.
	 *
	 * @var array<int, TwitchSubDiscountSettings>
	 */
	private array $cache = array();

	/**
	 * Whether a creator owns a parent product.
	 *
	 * @var Closure(int, int): bool
	 */
	private Closure $owns;

	/**
	 * Whether a creator's Twitch channel is connected.
	 *
	 * @var Closure(int): bool
	 */
	private Closure $connected;

	/**
	 * The creator's profit percent (whole, rounded down) per parent product:
	 * the given ones, or all the creator's products for null.
	 *
	 * @var Closure(int, list<int>|null): array<int, int>
	 */
	private Closure $profit_percents;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int, int): bool $owns
	 * @phpstan-param Closure(int): bool $connected
	 * @phpstan-param Closure(int, list<int>|null): array<int, int> $profit_percents
	 *
	 * @param CreatorMetaStore $meta            Creator meta.
	 * @param AuditTrail       $audit           Audit trail.
	 * @param Transaction      $transaction     Transactions.
	 * @param Closure          $owns            Whether a creator (1st) owns a parent product (2nd).
	 * @param Closure          $connected       Whether a creator's Twitch channel is connected.
	 * @param Closure          $profit_percents The creator's profit percent per product (the given ones, or all for null).
	 */
	public function __construct( private CreatorMetaStore $meta, private AuditTrail $audit, private Transaction $transaction, Closure $owns, Closure $connected, Closure $profit_percents ) {
		$this->owns            = $owns;
		$this->connected       = $connected;
		$this->profit_percents = $profit_percents;
	}

	/**
	 * A creator's settings (no campaigns when none are saved).
	 *
	 * @param int $creator_id Creator.
	 */
	public function find( int $creator_id ): TwitchSubDiscountSettings {
		if ( ! isset( $this->cache[ $creator_id ] ) ) {
			$this->cache[ $creator_id ] = TwitchSubDiscountSettings::from_stored( $this->meta->get( $creator_id, CreatorMetaStore::TWITCH_SUB_DISCOUNT ) );
		}

		return $this->cache[ $creator_id ];
	}

	/**
	 * Whether a creator's Twitch channel is connected.
	 *
	 * @param int $creator_id Creator.
	 */
	public function connected( int $creator_id ): bool {
		return ( $this->connected )( $creator_id );
	}

	/**
	 * The creator's profit percent per own product (for the dashboard).
	 *
	 * @param int $creator_id Creator.
	 * @return array<int, int>
	 */
	public function profit_percents( int $creator_id ): array {
		return ( $this->profit_percents )( $creator_id, null );
	}

	/**
	 * Validates and saves a creator's settings.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $values     `gifted` and `campaigns` (each: `id`, `name`, `enabled`, `tiers` 1–3 => percent, `scope`, `product_ids`, `starts`, `ends`).
	 * @return TwitchSubDiscountSettings The saved settings.
	 * @throws TwitchSubDiscountInvalid With a code per invalid field.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save( int $creator_id, array $values ): TwitchSubDiscountSettings {
		$previous = $this->find( $creator_id );
		$settings = $this->validate( $creator_id, $values, $previous );
		$before   = $previous->to_stored();
		$stored   = $settings->to_stored();
		$changed  = array_keys(
			array_filter(
				$stored,
				static fn( mixed $value, string $field ): bool => $value !== $before[ $field ],
				ARRAY_FILTER_USE_BOTH
			)
		);

		if ( array() === $changed ) {
			return $settings;
		}

		$this->transaction->run(
			function () use ( $creator_id, $stored, $changed ): void {
				$this->meta->set( $creator_id, CreatorMetaStore::TWITCH_SUB_DISCOUNT, $stored );
				$this->audit->record( AuditAction::TwitchSubDiscountChanged, $creator_id, array( 'changed_fields' => $changed ) );
			}
		);

		$this->cache[ $creator_id ] = $settings;

		return $settings;
	}

	/**
	 * Validated settings from the creator's form.
	 *
	 * @param int                       $creator_id Creator.
	 * @param array<mixed>              $values     Submitted values.
	 * @param TwitchSubDiscountSettings $previous   The saved settings (their ids are kept).
	 * @throws TwitchSubDiscountInvalid With a code per invalid field.
	 */
	private function validate( int $creator_id, array $values, TwitchSubDiscountSettings $previous ): TwitchSubDiscountSettings {
		$errors = array();
		$gifted = $values['gifted'] ?? null;
		$input  = $values['campaigns'] ?? null;

		if ( ! is_bool( $gifted ) ) {
			$errors['gifted'] = 'required';
		}

		if ( ! is_array( $input ) || ! array_is_list( $input ) ) {
			throw new TwitchSubDiscountInvalid( $errors + array( 'campaigns' => 'invalid' ) );
		}

		if ( count( $input ) > TwitchSubDiscountSettings::MAX_CAMPAIGNS ) {
			throw new TwitchSubDiscountInvalid( $errors + array( 'campaigns' => 'too_many' ) );
		}

		$known     = array_map( static fn( TwitchSubCampaign $campaign ): string => $campaign->id, $previous->campaigns );
		$campaigns = array();

		foreach ( $input as $index => $campaign ) {
			$read = $this->campaign( $creator_id, is_array( $campaign ) ? $campaign : array(), $known, $errors, 'campaigns.' . $index . '.' );

			if ( null !== $read ) {
				$campaigns[ $index ] = $read;
				$known[]             = $read->id;
			}
		}

		$this->check_overlaps( $campaigns, $errors );

		if ( array() !== $errors ) {
			throw new TwitchSubDiscountInvalid( $errors );
		}

		return new TwitchSubDiscountSettings( true === $gifted, array_values( $campaigns ) );
	}

	/**
	 * One validated campaign, or null when it has errors (added to $errors).
	 *
	 * @phpstan-param list<string> $known
	 *
	 * @param int                   $creator_id Creator.
	 * @param array<mixed>          $values     Submitted campaign.
	 * @param string[]              $known      Ids in use (the saved campaigns' and those read before).
	 * @param array<string, string> $errors     Errors so far.
	 * @param string                $prefix     Field prefix (`campaigns.<n>.`).
	 */
	private function campaign( int $creator_id, array $values, array $known, array &$errors, string $prefix ): ?TwitchSubCampaign {
		$count   = count( $errors );
		$id      = $values['id'] ?? '';
		$name    = is_string( $values['name'] ?? null ) ? trim( $values['name'] ) : null;
		$enabled = $values['enabled'] ?? null;
		$scope   = $values['scope'] ?? null;
		$ids     = $values['product_ids'] ?? array();
		$tiers   = array(
			1 => 0,
			2 => 0,
			3 => 0,
		);
		$input   = is_array( $values['tiers'] ?? null ) ? $values['tiers'] : array();

		if ( null === $name || '' === $name || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			$errors[ $prefix . 'name' ] = 'required';
		} elseif ( mb_strlen( $name ) > TwitchSubCampaign::MAX_NAME ) {
			$errors[ $prefix . 'name' ] = 'too_long';
		}

		if ( ! is_bool( $enabled ) ) {
			$errors[ $prefix . 'enabled' ] = 'required';
		}

		foreach ( self::TIER_FIELDS as $tier => $field ) {
			$percent = $input[ $tier ] ?? ( $input[ (string) $tier ] ?? null );

			if ( ! is_int( $percent ) || $percent < 0 || $percent > 100 ) {
				$errors[ $prefix . $field ] = 'invalid';
				continue;
			}

			$tiers[ $tier ] = $percent * 100;
		}

		if ( ! in_array( $scope, array( TwitchSubCampaign::ALL, TwitchSubCampaign::SELECTED ), true ) ) {
			$errors[ $prefix . 'scope' ] = 'required';
		}

		$products = array();

		if ( TwitchSubCampaign::SELECTED === $scope ) {
			if ( ! is_array( $ids ) ) {
				$errors[ $prefix . 'product_ids' ] = 'invalid';
			} elseif ( count( $ids ) > TwitchSubCampaign::MAX_PRODUCTS ) {
				$errors[ $prefix . 'product_ids' ] = 'too_many';
			} else {
				foreach ( $ids as $product ) {
					if ( ! is_int( $product ) || $product < 1 || ! ( $this->owns )( $creator_id, $product ) ) {
						$errors[ $prefix . 'product_ids' ] = 'invalid';
						break;
					}

					$products[ $product ] = $product;
				}

				if ( ! isset( $errors[ $prefix . 'product_ids' ] ) && array() === $products ) {
					$errors[ $prefix . 'product_ids' ] = 'required';
				}
			}
		}

		$starts = self::optional_day( $values['starts'] ?? null );
		$ends   = self::optional_day( $values['ends'] ?? null );

		if ( false === $starts ) {
			$errors[ $prefix . 'starts' ] = 'invalid';
		}

		if ( false === $ends ) {
			$errors[ $prefix . 'ends' ] = 'invalid';
		} elseif ( is_string( $starts ) && is_string( $ends ) && $ends < $starts ) {
			$errors[ $prefix . 'ends' ] = 'before_start';
		}

		if ( true === $enabled && 0 === max( $tiers ) && ! isset( $errors[ $prefix . 'tier_one' ] ) ) {
			$errors[ $prefix . 'tier_one' ] = 'required';
		}

		if ( true === $enabled && ! $this->connected( $creator_id ) ) {
			$errors[ $prefix . 'enabled' ] = 'not_connected';
		}

		if ( count( $errors ) > $count ) {
			return null;
		}

		// Never above the creator's lowest profit percent among the campaign's products.
		$percents = ( $this->profit_percents )( $creator_id, TwitchSubCampaign::SELECTED === $scope ? array_values( $products ) : null );
		$lowest   = array() === $percents ? null : min( $percents );

		foreach ( self::TIER_FIELDS as $tier => $field ) {
			if ( null !== $lowest && $tiers[ $tier ] > $lowest * 100 ) {
				$errors[ $prefix . $field ] = 'too_high';
			}
		}

		if ( count( $errors ) > $count ) {
			return null;
		}

		return new TwitchSubCampaign(
			is_string( $id ) && in_array( $id, $known, true ) && 1 === preg_match( '/^[0-9a-f]{16}$/', $id ) ? $id : bin2hex( random_bytes( 8 ) ),
			(string) $name,
			true === $enabled,
			$tiers,
			(string) $scope,
			array_values( $products ),
			is_string( $starts ) ? $starts : null,
			is_string( $ends ) ? $ends : null
		);
	}

	/**
	 * Campaigns that are on and meet in time: a product in at most one
	 * selection, at most one campaign of all products.
	 *
	 * @param array<int, TwitchSubCampaign> $campaigns Validated campaigns by index.
	 * @param array<string, string>         $errors    Errors so far.
	 */
	private function check_overlaps( array $campaigns, array &$errors ): void {
		foreach ( $campaigns as $index => $campaign ) {
			foreach ( $campaigns as $other_index => $other ) {
				if ( $other_index >= $index || ! $campaign->overlaps( $other ) || $campaign->scope !== $other->scope ) {
					continue;
				}

				if ( TwitchSubCampaign::ALL === $campaign->scope ) {
					$errors[ 'campaigns.' . $index . '.scope' ] = 'taken';
				} elseif ( array() !== array_intersect( $campaign->product_ids, $other->product_ids ) ) {
					$errors[ 'campaigns.' . $index . '.product_ids' ] = 'taken';
				}
			}
		}
	}

	/**
	 * An optional day: null for none, false when invalid.
	 *
	 * @param mixed $value Submitted value (`Y-m-d`, '' or null).
	 */
	private static function optional_day( mixed $value ): string|false|null {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return TwitchSubCampaign::day( $value ) ?? false;
	}
}
