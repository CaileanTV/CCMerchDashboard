<?php
/**
 * The creators' discounts for Twitch subscribers.
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
 * Reads and saves a creator's settings (ADR-106 pkt. 3) in creator meta.
 * The creator saves them through `/me` (the caller resolved the creator
 * with CreatorContext):
 *
 * - Percents are whole numbers 0–100 per tier.
 * - Selected products must be the creator's own (at most 500).
 * - The discount can only be switched on while the creator's Twitch channel
 *   is connected.
 * - The audit lists the changed field names only.
 */
final class TwitchSubDiscountRepository {

	/**
	 * Settings read in this request, by creator.
	 *
	 * @var array<int, TwitchSubDiscountSettings>
	 */
	private array $cache = array();

	/**
	 * The form field of each tier (field codes are letters only).
	 */
	public const TIER_FIELDS = array(
		1 => 'tier_one',
		2 => 'tier_two',
		3 => 'tier_three',
	);

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
	 * Constructor.
	 *
	 * @phpstan-param Closure(int, int): bool $owns
	 * @phpstan-param Closure(int): bool $connected
	 *
	 * @param CreatorMetaStore $meta        Creator meta.
	 * @param AuditTrail       $audit       Audit trail.
	 * @param Transaction      $transaction Transactions.
	 * @param Closure          $owns        Whether a creator (1st) owns a parent product (2nd).
	 * @param Closure          $connected   Whether a creator's Twitch channel is connected.
	 */
	public function __construct( private CreatorMetaStore $meta, private AuditTrail $audit, private Transaction $transaction, Closure $owns, Closure $connected ) {
		$this->owns      = $owns;
		$this->connected = $connected;
	}

	/**
	 * A creator's settings (the defaults, off, when none are saved).
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
	 * Validates and saves a creator's settings.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $values     `enabled`, `tiers` (1, 2, 3 => percent), `gifted`, `scope`, `product_ids`.
	 * @return TwitchSubDiscountSettings The saved settings.
	 * @throws TwitchSubDiscountInvalid With a code per invalid field.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save( int $creator_id, array $values ): TwitchSubDiscountSettings {
		$settings = $this->validate( $creator_id, $values );
		$previous = $this->find( $creator_id )->to_stored();
		$stored   = $settings->to_stored();
		$changed  = array_keys(
			array_filter(
				$stored,
				static fn( mixed $value, string $field ): bool => $value !== $previous[ $field ],
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
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $values     Submitted values.
	 * @throws TwitchSubDiscountInvalid With a code per invalid field.
	 */
	private function validate( int $creator_id, array $values ): TwitchSubDiscountSettings {
		$errors  = array();
		$enabled = $values['enabled'] ?? null;
		$gifted  = $values['gifted'] ?? null;
		$scope   = $values['scope'] ?? null;
		$ids     = $values['product_ids'] ?? array();
		$input   = is_array( $values['tiers'] ?? null ) ? $values['tiers'] : array();
		$tiers   = array(
			1 => 0,
			2 => 0,
			3 => 0,
		);

		if ( ! is_bool( $enabled ) ) {
			$errors['enabled'] = 'required';
		}

		if ( ! is_bool( $gifted ) ) {
			$errors['gifted'] = 'required';
		}

		foreach ( array( 1, 2, 3 ) as $tier ) {
			$percent = $input[ $tier ] ?? ( $input[ (string) $tier ] ?? null );

			if ( ! is_int( $percent ) || $percent < 0 || $percent > 100 ) {
				$errors[ self::TIER_FIELDS[ $tier ] ] = 'invalid';
				continue;
			}

			$tiers[ $tier ] = $percent * 100;
		}

		if ( ! in_array( $scope, array( TwitchSubDiscountSettings::ALL, TwitchSubDiscountSettings::SELECTED ), true ) ) {
			$errors['scope'] = 'required';
		}

		$products = array();

		if ( TwitchSubDiscountSettings::SELECTED === $scope ) {
			if ( ! is_array( $ids ) ) {
				$errors['product_ids'] = 'invalid';
			} elseif ( count( $ids ) > TwitchSubDiscountSettings::MAX_PRODUCTS ) {
				$errors['product_ids'] = 'too_many';
			} else {
				foreach ( $ids as $id ) {
					if ( ! is_int( $id ) || $id < 1 || ! ( $this->owns )( $creator_id, $id ) ) {
						$errors['product_ids'] = 'invalid';
						break;
					}

					$products[ $id ] = $id;
				}

				if ( ! isset( $errors['product_ids'] ) && true === $enabled && array() === $products ) {
					$errors['product_ids'] = 'required';
				}
			}
		}

		if ( true === $enabled && array() === array_intersect_key( $errors, array_flip( self::TIER_FIELDS ) ) && 0 === max( $tiers ) ) {
			$errors['tier_one'] = 'required';
		}

		if ( true === $enabled && ! $this->connected( $creator_id ) ) {
			$errors['enabled'] = 'not_connected';
		}

		if ( array() !== $errors ) {
			throw new TwitchSubDiscountInvalid( $errors );
		}

		return new TwitchSubDiscountSettings(
			true === $enabled,
			$tiers,
			true === $gifted,
			(string) $scope,
			array_values( $products )
		);
	}
}
