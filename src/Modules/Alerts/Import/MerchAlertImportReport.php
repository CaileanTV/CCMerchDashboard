<?php
/**
 * What the import from MerchAlert does.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Import;

defined( 'ABSPATH' ) || exit;

/**
 * The dry run of the import (step 25.10.1): per MerchAlert creator what
 * happens, the global overlay and the settings. The fingerprint ties the
 * administrator's confirmation to exactly this dry run.
 */
final readonly class MerchAlertImportReport {

	/**
	 * Creator statuses. NEW, REPLACE and IMPORTED are imported; the others
	 * are shown and skipped.
	 */
	public const NEW         = 'new';
	public const REPLACE     = 'replace';
	public const IMPORTED    = 'imported';
	public const NO_CATEGORY = 'no_category';
	public const NO_MATCH    = 'no_match';
	public const NO_OVERLAY  = 'no_overlay';
	public const DUPLICATE   = 'duplicate';
	public const ID_TAKEN    = 'id_taken';

	/**
	 * Global overlay status without one in MerchAlert.
	 */
	public const NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<array{id: int, name: string, term_id: int, creator_id: int, creator_name: string, status: string, events: int, messages: int, sounds: int, chatbot: bool}> $creators
	 * @phpstan-param array{status: string, sounds: int} $global_overlay
	 * @phpstan-param array{sound_max_bytes: int|null, sound_max_seconds: int|null, username_enabled: bool, username_label: string, fonts: int, api_key: bool, done: bool} $settings
	 *
	 * @param array[] $creators       Per MerchAlert creator.
	 * @param array   $global_overlay The global overlay.
	 * @param array   $settings       Settings, fonts and whether they were imported already.
	 */
	public function __construct( public array $creators, public array $global_overlay, public array $settings ) {
	}

	/**
	 * Whether a creator status is imported.
	 *
	 * @param string $status Status.
	 */
	public static function imports( string $status ): bool {
		return in_array( $status, array( self::NEW, self::REPLACE, self::IMPORTED ), true );
	}

	/**
	 * Identifies this dry run (sha256 of its content).
	 */
	public function fingerprint(): string {
		return hash( 'sha256', (string) wp_json_encode( array( $this->creators, $this->global_overlay, $this->settings ) ) );
	}

	/**
	 * Counts for the audit log (no names).
	 *
	 * @return array<string, int>
	 */
	public function audit_context(): array {
		$context = array(
			'creators' => 0,
			'skipped'  => 0,
			'events'   => 0,
			'messages' => 0,
		);

		foreach ( $this->creators as $creator ) {
			if ( self::imports( $creator['status'] ) ) {
				++$context['creators'];
				$context['events']   += $creator['events'];
				$context['messages'] += $creator['messages'];
			} else {
				++$context['skipped'];
			}
		}

		return $context;
	}
}
