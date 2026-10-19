<?php
/**
 * The administrator's alert sound library.
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
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Sounds an administrator offers to every creator (phase 25, step 6.3):
 * creators who do not want to upload their own sound choose one of these.
 *
 * - **Files:** attachments marked with LIBRARY_META, validated like the
 *   creators' uploads (SoundFiles). At most MAX_SOUNDS.
 * - **Names:** each sound has a name creators see (step 6.7): the
 *   administrator's, at most NAME_MAX characters, or the file name.
 * - **Changes:** only `merchboard_manage_alerts`; each is audited (the file
 *   name, never the file). A removed sound is no longer played anywhere:
 *   overlays that chose it fall back to their other sounds.
 */
final class AlertSoundLibrary {

	/**
	 * Attachment meta: marks a library sound (value 1).
	 */
	public const LIBRARY_META = '_merchboard_alert_sound_library';

	/**
	 * Most sounds in the library.
	 */
	public const MAX_SOUNDS = 50;

	/**
	 * Longest name in characters.
	 */
	public const NAME_MAX = 60;

	/**
	 * Constructor.
	 *
	 * @param SoundFiles  $files       Sound files.
	 * @param AuditTrail  $audit       Audit trail.
	 * @param Transaction $transaction Database transaction.
	 */
	public function __construct( private SoundFiles $files, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * The library, by name.
	 *
	 * @return list<AlertSound>
	 */
	public function all(): array {
		$sounds = array();

		foreach ( $this->ids() as $id ) {
			$sound = $this->files->sound( $id, true );

			if ( null !== $sound ) {
				$sounds[] = $sound;
			}
		}

		usort( $sounds, static fn( AlertSound $a, AlertSound $b ): int => strnatcasecmp( $a->name, $b->name ) );

		return $sounds;
	}

	/**
	 * A library sound, or null when the attachment is not one.
	 *
	 * @param int $attachment Attachment id.
	 */
	public function find( int $attachment ): ?AlertSound {
		return $attachment > 0 && '1' === (string) get_post_meta( $attachment, self::LIBRARY_META, true ) ? $this->files->sound( $attachment, true ) : null;
	}

	/**
	 * Adds an uploaded file to the library.
	 *
	 * @param array<mixed> $file Upload (one entry of $_FILES).
	 * @param string       $name Name creators see; '' for the file name.
	 * @throws AlertSoundException When not authorized, the library is full or the file is refused (nothing is kept).
	 * @throws Throwable When the audit record cannot be written (DatabaseException; the file is deleted).
	 */
	public function add( array $file, string $name = '' ): AlertSound {
		$this->authorize();
		$this->check_room();

		$id    = $this->files->store( $file, self::LIBRARY_META, 1 );
		$clean = self::clean_name( $name );

		if ( '' !== $clean ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $clean,
				)
			);
		}

		$this->audit_added( $id );

		return $this->files->sound( $id, true ) ?? new AlertSound( $id, '', '', 0, true );
	}

	/**
	 * Audits a new library sound; deletes it when that fails.
	 *
	 * @param int $id Attachment id.
	 * @throws Throwable When the audit record cannot be written (DatabaseException; the file is deleted).
	 */
	private function audit_added( int $id ): void {
		try {
			$this->transaction->run(
				function () use ( $id ): void {
					$this->audit->record( AuditAction::AlertSoundLibraryAdded, null, array( 'name' => wp_basename( (string) get_attached_file( $id ) ) ) );
				}
			);
		} catch ( Throwable $e ) {
			wp_delete_attachment( $id, true );

			throw $e;
		}
	}

	/**
	 * Gives a library sound a new name ('' gives it its file name again).
	 *
	 * @param int    $attachment Attachment id.
	 * @param string $name       New name.
	 * @return bool Whether the name changed.
	 * @throws AlertSoundException When not authorized or it is not a library sound.
	 * @throws DatabaseException When it cannot be stored (nothing is changed).
	 */
	public function rename( int $attachment, string $name ): bool {
		$this->authorize();
		$sound = $this->library_sound( $attachment );
		$clean = self::clean_name( $name );
		$clean = '' === $clean ? sanitize_text_field( pathinfo( (string) get_attached_file( $attachment ), PATHINFO_FILENAME ) ) : $clean;

		if ( $clean === $sound->name ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $attachment, $clean, $sound ): void {
				$this->audit->record(
					AuditAction::AlertSoundLibraryRenamed,
					null,
					array(
						'name'          => $clean,
						'previous_name' => $sound->name,
					)
				);

				$updated = wp_update_post(
					array(
						'ID'         => $attachment,
						'post_title' => $clean,
					),
					true
				);

				if ( ! is_int( $updated ) ) {
					throw new DatabaseException( 'Could not rename the library sound.' );
				}
			}
		);

		return true;
	}

	/**
	 * A sound of the library.
	 *
	 * @param int $attachment Attachment id.
	 * @throws AlertSoundException When it is not a library sound.
	 */
	private function library_sound( int $attachment ): AlertSound {
		$sound = $this->find( $attachment );

		if ( null === $sound ) {
			throw new AlertSoundException( AlertSoundException::UNKNOWN_SOUND );
		}

		return $sound;
	}

	/**
	 * A name as plain text of at most NAME_MAX characters.
	 *
	 * @param string $name Submitted name.
	 */
	private static function clean_name( string $name ): string {
		return trim( mb_substr( sanitize_text_field( $name ), 0, self::NAME_MAX ) );
	}

	/**
	 * Removes a sound from the library and deletes its file.
	 *
	 * @param int $attachment Attachment id.
	 * @return bool Whether it was a library sound.
	 * @throws AlertSoundException When not authorized.
	 * @throws DatabaseException When the audit record cannot be written (the file is kept).
	 */
	public function remove( int $attachment ): bool {
		$this->authorize();

		if ( '1' !== (string) get_post_meta( $attachment, self::LIBRARY_META, true ) ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $attachment ): void {
				$this->audit->record( AuditAction::AlertSoundLibraryRemoved, null, array( 'name' => wp_basename( (string) get_attached_file( $attachment ) ) ) );
			}
		);
		wp_delete_attachment( $attachment, true );

		return true;
	}

	/**
	 * The library's attachment ids.
	 *
	 * @return list<int>
	 */
	private function ids(): array {
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => self::MAX_SOUNDS,
				'fields'      => 'ids',
				'meta_key'    => self::LIBRARY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- At most MAX_SOUNDS rows, admin and dashboard only.
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Requires room for one more sound.
	 *
	 * @throws AlertSoundException When the library is full.
	 */
	private function check_room(): void {
		if ( count( $this->ids() ) >= self::MAX_SOUNDS ) {
			throw new AlertSoundException( AlertSoundException::LIBRARY_FULL );
		}
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws AlertSoundException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new AlertSoundException( AlertSoundException::NOT_AUTHORIZED );
		}
	}
}
