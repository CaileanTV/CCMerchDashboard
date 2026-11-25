<?php
/**
 * Reads and saves a creator's thank-you note.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\ThankYouNote;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Creators\CreatorMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's note in creator meta `thank_you_note` (phase 58). The text is
 * plain text: tags are removed, line breaks kept, and a link is refused,
 * because the packing slip is printed and goes to the customer. The audit
 * lists changed field names only, never the text.
 */
final class ThankYouNoteRepository {

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
	 * A creator's note.
	 *
	 * @param int $creator_id Creator.
	 * @throws \MerchBoard\Core\Database\DatabaseException When creator meta cannot be read.
	 */
	public function find( int $creator_id ): ThankYouNoteSettings {
		return ThankYouNoteSettings::from_stored( $this->meta->get( $creator_id, CreatorMetaStore::THANK_YOU_NOTE ) );
	}

	/**
	 * Validates and saves the creator's note.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $values     `enabled` (bool) and `text` (string).
	 * @throws TwitchSubDiscountInvalid With a code per invalid field (a failed write throws DatabaseException).
	 */
	public function save( int $creator_id, array $values ): ThankYouNoteSettings {
		$errors  = array();
		$enabled = $values['enabled'] ?? null;
		$text    = $values['text'] ?? '';

		if ( ! is_bool( $enabled ) ) {
			$errors['enabled'] = 'required';
		}

		if ( ! is_string( $text ) ) {
			$errors['text'] = 'invalid';
			$text           = '';
		} else {
			$text = self::clean( $text );

			if ( mb_strlen( $text ) > ThankYouNoteSettings::MAX_TEXT ) {
				$errors['text'] = 'too_long';
			} elseif ( substr_count( $text, "\n" ) >= ThankYouNoteSettings::MAX_LINES ) {
				$errors['text'] = 'too_many_lines';
			} elseif ( 1 === preg_match( '~https?://|www\.~i', $text ) ) {
				$errors['text'] = 'has_link';
			} elseif ( true === $enabled && '' === $text ) {
				$errors['text'] = 'required';
			}
		}

		if ( array() !== $errors ) {
			throw new TwitchSubDiscountInvalid( $errors );
		}

		$note    = new ThankYouNoteSettings( true === $enabled, $text );
		$before  = $this->find( $creator_id )->to_stored();
		$stored  = $note->to_stored();
		$changed = array_keys( array_filter( $stored, static fn( mixed $value, string $field ): bool => $value !== $before[ $field ], ARRAY_FILTER_USE_BOTH ) );

		if ( array() !== $changed ) {
			$this->transaction->run(
				function () use ( $creator_id, $stored, $changed ): void {
					$this->meta->set( $creator_id, CreatorMetaStore::THANK_YOU_NOTE, $stored );
					$this->audit->record( AuditAction::ThankYouNoteChanged, $creator_id, array( 'changed_fields' => $changed ) );
				}
			);
		}

		return $note;
	}

	/**
	 * Plain text with `\n` line breaks, without tags, trailing spaces or
	 * empty lines at the ends.
	 *
	 * @param string $text The creator's text.
	 */
	private static function clean( string $text ): string {
		$lines = explode( "\n", sanitize_textarea_field( str_replace( array( "\r\n", "\r" ), "\n", $text ) ) );

		return trim( implode( "\n", array_map( 'rtrim', $lines ) ) );
	}
}
