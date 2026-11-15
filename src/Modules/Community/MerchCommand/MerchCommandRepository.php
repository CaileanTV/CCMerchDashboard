<?php
/**
 * Reads and saves a creator's !merch command.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchCommand;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Creators\CreatorMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's !merch in creator meta `merch_command` (ADR-113 pkt. 5); the
 * audit lists changed field names only.
 */
final class MerchCommandRepository {

	/**
	 * Constructor.
	 *
	 * @param CreatorMetaStore $meta        Creator meta.
	 * @param AuditTrail       $audit       Audit trail.
	 * @param Transaction      $transaction Transactions.
	 */
	public function __construct( private CreatorMetaStore $meta, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * A creator's settings.
	 *
	 * @param int $creator_id Creator.
	 * @throws \MerchBoard\Core\Database\DatabaseException When creator meta cannot be read.
	 */
	public function find( int $creator_id ): MerchCommandSettings {
		return MerchCommandSettings::from_stored( $this->meta->get( $creator_id, CreatorMetaStore::MERCH_COMMAND ) );
	}

	/**
	 * Validates and saves the creator's settings.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $values     `enabled` (bool), `text` (string, '' for the default) and `cooldown` (seconds).
	 * @throws TwitchSubDiscountInvalid With a code per invalid field (a failed write throws DatabaseException).
	 */
	public function save( int $creator_id, array $values ): MerchCommandSettings {
		$errors   = array();
		$enabled  = $values['enabled'] ?? null;
		$text     = $values['text'] ?? '';
		$cooldown = $values['cooldown'] ?? null;

		if ( ! is_bool( $enabled ) ) {
			$errors['enabled'] = 'required';
		}

		if ( ! is_string( $text ) ) {
			$errors['text'] = 'invalid';
		} else {
			$text = trim( sanitize_text_field( $text ) );

			if ( mb_strlen( $text ) > MerchCommandSettings::MAX_TEXT ) {
				$errors['text'] = 'too_long';
			} elseif ( str_starts_with( strtolower( $text ), MerchCommandSettings::COMMAND ) ) {
				// The bot's own answer must never be the command again.
				$errors['text'] = 'is_command';
			}
		}

		if ( ! is_int( $cooldown ) || $cooldown < MerchCommandSettings::MIN_COOLDOWN || $cooldown > MerchCommandSettings::MAX_COOLDOWN ) {
			$errors['cooldown'] = 'invalid';
		}

		if ( array() !== $errors || ! is_int( $cooldown ) ) {
			throw new TwitchSubDiscountInvalid( $errors );
		}

		$settings = new MerchCommandSettings( true === $enabled, $text, $cooldown );
		$before   = $this->find( $creator_id )->to_stored();
		$stored   = $settings->to_stored();
		$changed  = array_keys( array_filter( $stored, static fn( mixed $value, string $field ): bool => $value !== $before[ $field ], ARRAY_FILTER_USE_BOTH ) );

		if ( array() !== $changed ) {
			$this->transaction->run(
				function () use ( $creator_id, $stored, $changed ): void {
					$this->meta->set( $creator_id, CreatorMetaStore::MERCH_COMMAND, $stored );
					$this->audit->record( AuditAction::MerchCommandChanged, $creator_id, array( 'changed_fields' => $changed ) );
				}
			);
		}

		return $settings;
	}
}
