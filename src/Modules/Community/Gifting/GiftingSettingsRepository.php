<?php
/**
 * The creators' Twitch gifting settings.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use Closure;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Creators\CreatorMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and saves a creator's gifting settings (ADR-107 pkt. 2) in creator
 * meta `twitch_gifting`. The creator saves them through `/me`; switching on
 * needs a connected Twitch channel. Pausing and resuming also come from the
 * chat (`!gift pause`/`!gift resume` by the creator or a moderator). An
 * administrator sets the test mode on the creator's edit page; the creator's
 * own form keeps it. The audit lists changed field names only.
 */
final class GiftingSettingsRepository {

	/**
	 * Settings read in this request, by creator.
	 *
	 * @var array<int, GiftingSettings>
	 */
	private array $cache = array();

	/**
	 * Whether a creator's Twitch channel is connected.
	 *
	 * @var Closure(int): bool
	 */
	private Closure $connected;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): bool $connected
	 *
	 * @param CreatorMetaStore $meta        Creator meta.
	 * @param AuditTrail       $audit       Audit trail.
	 * @param Transaction      $transaction Transactions.
	 * @param Closure          $connected   Whether a creator's Twitch channel is connected.
	 */
	public function __construct( private CreatorMetaStore $meta, private AuditTrail $audit, private Transaction $transaction, Closure $connected ) {
		$this->connected = $connected;
	}

	/**
	 * A creator's settings.
	 *
	 * @param int $creator_id Creator.
	 */
	public function find( int $creator_id ): GiftingSettings {
		if ( ! isset( $this->cache[ $creator_id ] ) ) {
			$this->cache[ $creator_id ] = GiftingSettings::from_stored( $this->meta->get( $creator_id, CreatorMetaStore::TWITCH_GIFTING ) );
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
	 * Validates and saves the creator's settings.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $values     `enabled`, `paused`, `window`, `language`.
	 * @throws TwitchSubDiscountInvalid With a code per invalid field (a failed write throws DatabaseException).
	 */
	public function save( int $creator_id, array $values ): GiftingSettings {
		$errors   = array();
		$enabled  = $values['enabled'] ?? null;
		$paused   = $values['paused'] ?? null;
		$window   = $values['window'] ?? null;
		$language = $values['language'] ?? null;

		if ( ! is_bool( $enabled ) ) {
			$errors['enabled'] = 'required';
		} elseif ( $enabled && ! $this->connected( $creator_id ) ) {
			$errors['enabled'] = 'not_connected';
		}

		if ( ! is_bool( $paused ) ) {
			$errors['paused'] = 'required';
		}

		if ( ! is_int( $window ) || $window < GiftingSettings::MIN_WINDOW || $window > GiftingSettings::MAX_WINDOW ) {
			$errors['window'] = 'invalid';
		}

		if ( ! in_array( $language, GiftingSettings::LANGUAGES, true ) ) {
			$errors['language'] = 'required';
		}

		if ( array() !== $errors ) {
			throw new TwitchSubDiscountInvalid( $errors );
		}

		return $this->store( $creator_id, new GiftingSettings( true === $enabled, true === $paused, (int) $window, (string) $language, $this->find( $creator_id )->test_mode ), true );
	}

	/**
	 * Pauses or resumes gifting (the chat or the dashboard's button).
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $paused     Paused.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_paused( int $creator_id, bool $paused ): GiftingSettings {
		return $this->store( $creator_id, $this->find( $creator_id )->with_paused( $paused ), false );
	}

	/**
	 * Turns the test mode on or off (an administrator on the creator's edit
	 * page; audited).
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $test_mode  Test mode.
	 * @return bool Whether it changed.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_test_mode( int $creator_id, bool $test_mode ): bool {
		if ( $this->find( $creator_id )->test_mode === $test_mode ) {
			return false;
		}

		$this->store( $creator_id, $this->find( $creator_id )->with_test_mode( $test_mode ), true );

		return true;
	}

	/**
	 * Stores settings when they changed.
	 *
	 * @param int             $creator_id Creator.
	 * @param GiftingSettings $settings   New settings.
	 * @param bool            $audit      Whether to audit (the creator's form).
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function store( int $creator_id, GiftingSettings $settings, bool $audit ): GiftingSettings {
		$before  = $this->find( $creator_id )->to_stored();
		$stored  = $settings->to_stored();
		$changed = array_keys( array_filter( $stored, static fn( mixed $value, string $field ): bool => $value !== $before[ $field ], ARRAY_FILTER_USE_BOTH ) );

		if ( array() === $changed ) {
			return $settings;
		}

		$this->transaction->run(
			function () use ( $creator_id, $stored, $changed, $audit ): void {
				$this->meta->set( $creator_id, CreatorMetaStore::TWITCH_GIFTING, $stored );

				if ( $audit ) {
					$this->audit->record( AuditAction::TwitchGiftingChanged, $creator_id, array( 'changed_fields' => $changed ) );
				}
			}
		);

		$this->cache[ $creator_id ] = $settings;

		return $settings;
	}
}
