<?php
/**
 * A refused import from MerchAlert.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Import;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Why the import did not run; the reason is the admin page's outcome code.
 */
final class MerchAlertImportException extends RuntimeException {

	public const MERCHALERT_ACTIVE = 'import_merchalert_active';
	public const NO_DATA           = 'import_no_data';
	public const PREVIEW_OUTDATED  = 'import_preview_outdated';
	public const BACKUP_REQUIRED   = 'import_backup_required';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'The import from MerchAlert was refused: ' . $reason . '.' );
	}

	/**
	 * The reason (outcome code).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
