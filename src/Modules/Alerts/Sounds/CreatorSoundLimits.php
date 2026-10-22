<?php
/**
 * A creator's own limits of uploaded alert sounds.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Sounds;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator can give a creator other limits of uploaded sounds
 * than the site-wide ones (step 25.9.2, the project owner's wish
 * 2026-10-22), e.g. for special needs. Each limit is optional (none = the
 * site-wide limit) and has the same range as the site-wide one; the site's
 * upload limit still applies. They apply to the creator's own uploads only
 * (not the administrator's library), checked at upload, so stored sounds
 * stay.
 *
 * Stored as creator meta `alert_sound_limits`; changed only with
 * `merchboard_manage_alerts`, with one audit record per changed limit
 * (`alert_creator_sound_limits_changed`) in the same transaction.
 */
final class CreatorSoundLimits {

	/**
	 * Stored key of the largest file in bytes.
	 */
	private const BYTES = 'max_bytes';

	/**
	 * Stored key of the longest sound in seconds.
	 */
	private const SECONDS = 'max_seconds';

	/**
	 * Constructor.
	 *
	 * @param CreatorMetaStore  $meta        Creator meta.
	 * @param CreatorRepository $creators    Creators.
	 * @param AuditTrail        $audit       Audit log.
	 * @param Transaction       $transaction Transactions.
	 */
	public function __construct( private CreatorMetaStore $meta, private CreatorRepository $creators, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * The creator's own limits; null where the site-wide limit applies (also
	 * for a stored value out of range).
	 *
	 * @param int $creator_id Creator id.
	 * @return array{max_bytes: int|null, max_seconds: int|null}
	 */
	public function own( int $creator_id ): array {
		$stored  = $this->meta->get( $creator_id, CreatorMetaStore::ALERT_SOUND_LIMITS );
		$bytes   = is_array( $stored ) ? ( $stored[ self::BYTES ] ?? null ) : null;
		$seconds = is_array( $stored ) ? ( $stored[ self::SECONDS ] ?? null ) : null;

		return array(
			'max_bytes'   => is_int( $bytes ) && Settings::valid_sound_max_bytes( $bytes ) ? $bytes : null,
			'max_seconds' => is_int( $seconds ) && Settings::valid_sound_max_seconds( $seconds ) ? $seconds : null,
		);
	}

	/**
	 * The limits of the creator's uploads: their own, else the site-wide
	 * ones; the largest file within the site's upload limit.
	 *
	 * @param int $creator_id Creator id.
	 */
	public function for_creator( int $creator_id ): SoundLimits {
		$own = $this->own( $creator_id );

		return new SoundLimits(
			null === $own['max_bytes'] ? SoundFiles::max_bytes() : SoundFiles::within_site_limit( $own['max_bytes'] ),
			$own['max_seconds'] ?? SoundFiles::max_seconds()
		);
	}

	/**
	 * Sets the creator's own limits (null = the site-wide limit).
	 *
	 * @param int      $creator_id  Creator id.
	 * @param int|null $max_bytes   Largest file in bytes, or null.
	 * @param int|null $max_seconds Longest sound in seconds, or null.
	 * @return bool Whether anything changed.
	 * @throws AlertSettingsException When not allowed, the creator does not exist or a limit is out of range.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function set( int $creator_id, ?int $max_bytes, ?int $max_seconds ): bool {
		$this->check( $creator_id, $max_bytes, $max_seconds );

		$before  = $this->own( $creator_id );
		$changes = array_filter(
			array(
				Settings::SOUND_MAX_BYTES   => array( $before['max_bytes'], $max_bytes ),
				Settings::SOUND_MAX_SECONDS => array( $before['max_seconds'], $max_seconds ),
			),
			static fn( array $change ): bool => $change[0] !== $change[1]
		);

		if ( array() === $changes ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $creator_id, $changes, $max_bytes, $max_seconds ): void {
				foreach ( $changes as $setting => [ $previous, $value ] ) {
					$this->audit->record(
						AuditAction::AlertCreatorSoundLimitsChanged,
						$creator_id,
						array(
							'setting'        => $setting,
							'previous_value' => $previous,
							'value'          => $value,
						)
					);
				}

				$this->meta->set(
					$creator_id,
					CreatorMetaStore::ALERT_SOUND_LIMITS,
					array_filter(
						array(
							self::BYTES   => $max_bytes,
							self::SECONDS => $max_seconds,
						),
						static fn( ?int $limit ): bool => null !== $limit
					)
				);
			}
		);

		return true;
	}

	/**
	 * Requires the alerts capability, limits in range and an existing creator.
	 *
	 * @param int      $creator_id  Creator id.
	 * @param int|null $max_bytes   Largest file in bytes, or null.
	 * @param int|null $max_seconds Longest sound in seconds, or null.
	 * @throws AlertSettingsException When one of them is not met.
	 */
	private function check( int $creator_id, ?int $max_bytes, ?int $max_seconds ): void {
		$reason = match ( true ) {
			! current_user_can( Capabilities::MANAGE_ALERTS ) => AlertSettingsException::NOT_AUTHORIZED,
			null !== $max_bytes && ! Settings::valid_sound_max_bytes( $max_bytes ) => AlertSettingsException::INVALID_SOUND_SIZE,
			null !== $max_seconds && ! Settings::valid_sound_max_seconds( $max_seconds ) => AlertSettingsException::INVALID_SOUND_LENGTH,
			null === $this->creators->find( $creator_id ) => AlertSettingsException::CREATOR_NOT_FOUND,
			default => null,
		};

		if ( null !== $reason ) {
			throw new AlertSettingsException( $reason );
		}
	}
}
