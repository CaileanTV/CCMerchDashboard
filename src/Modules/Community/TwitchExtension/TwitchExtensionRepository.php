<?php
/**
 * Reads and saves a creator's Twitch Extension panel.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchExtension;

use Closure;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Creators\CreatorMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's panel settings in creator meta `twitch_extension` (ADR-112
 * pkt. 4). Only the creator's own products can be chosen, 12 at most. The
 * audit lists changed field names only. Saving clears the panel's cache.
 */
final class TwitchExtensionRepository {

	/**
	 * The panel's cache (a transient per creator).
	 */
	public const CACHE_PREFIX = 'merchboard_twitch_ext_';

	/**
	 * Settings read in this request, by creator.
	 *
	 * @var array<int, TwitchExtensionSettings>
	 */
	private array $cache = array();

	/**
	 * Whether a product is one of the creator's.
	 *
	 * @var Closure(int, int): bool
	 */
	private Closure $owns;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int, int): bool $owns
	 *
	 * @param CreatorMetaStore $meta        Creator meta.
	 * @param AuditTrail       $audit       Audit trail.
	 * @param Transaction      $transaction Transactions.
	 * @param Closure          $owns        Whether a product (2nd) is one of the creator's (1st).
	 */
	public function __construct( private CreatorMetaStore $meta, private AuditTrail $audit, private Transaction $transaction, Closure $owns ) {
		$this->owns = $owns;
	}

	/**
	 * A creator's settings.
	 *
	 * @param int $creator_id Creator.
	 */
	public function find( int $creator_id ): TwitchExtensionSettings {
		if ( ! isset( $this->cache[ $creator_id ] ) ) {
			$this->cache[ $creator_id ] = TwitchExtensionSettings::from_stored( $this->meta->get( $creator_id, CreatorMetaStore::TWITCH_EXTENSION ) );
		}

		return $this->cache[ $creator_id ];
	}

	/**
	 * Validates and saves the creator's settings.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $values     `enabled` (bool) and `product_ids` (list of the creator's product ids).
	 * @throws TwitchSubDiscountInvalid With a code per invalid field (a failed write throws DatabaseException).
	 */
	public function save( int $creator_id, array $values ): TwitchExtensionSettings {
		$errors  = array();
		$enabled = $values['enabled'] ?? null;
		$ids     = $values['product_ids'] ?? null;

		if ( ! is_bool( $enabled ) ) {
			$errors['enabled'] = 'required';
		}

		if ( ! is_array( $ids ) || ! array_is_list( $ids ) ) {
			$errors['product_ids'] = 'invalid';
		} elseif ( count( $ids ) > TwitchExtensionSettings::MAX_PRODUCTS ) {
			$errors['product_ids'] = 'too_many';
		} else {
			foreach ( $ids as $id ) {
				if ( ! is_int( $id ) || $id < 1 || ! ( $this->owns )( $creator_id, $id ) ) {
					$errors['product_ids'] = 'not_owned';
					break;
				}
			}
		}

		if ( array() !== $errors || ! is_array( $ids ) ) {
			throw new TwitchSubDiscountInvalid( $errors );
		}

		$settings = new TwitchExtensionSettings( true === $enabled, array_values( array_unique( array_map( 'intval', $ids ) ) ) );
		$before   = $this->find( $creator_id )->to_stored();
		$stored   = $settings->to_stored();
		$changed  = array_keys( array_filter( $stored, static fn( mixed $value, string $field ): bool => $value !== $before[ $field ], ARRAY_FILTER_USE_BOTH ) );

		if ( array() === $changed ) {
			return $settings;
		}

		$this->transaction->run(
			function () use ( $creator_id, $stored, $changed ): void {
				$this->meta->set( $creator_id, CreatorMetaStore::TWITCH_EXTENSION, $stored );
				$this->audit->record( AuditAction::TwitchExtensionChanged, $creator_id, array( 'changed_fields' => $changed ) );
			}
		);

		$this->cache[ $creator_id ] = $settings;
		delete_transient( self::CACHE_PREFIX . $creator_id );

		return $settings;
	}
}
