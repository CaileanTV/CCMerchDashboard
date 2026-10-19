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
	 * @throws AlertSoundException When not authorized, the library is full or the file is refused (nothing is kept).
	 * @throws Throwable When the audit record cannot be written (DatabaseException; the file is deleted).
	 */
	public function add( array $file ): AlertSound {
		$this->authorize();
		$this->check_room();

		$id = $this->files->store( $file, self::LIBRARY_META, 1 );
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
