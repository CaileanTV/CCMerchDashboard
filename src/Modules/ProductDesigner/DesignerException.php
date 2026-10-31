<?php
/**
 * A refused product designer operation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A refused operation of the product designer (ADR-080), with a stable
 * reason code for the UI. The message is never shown.
 */
final class DesignerException extends RuntimeException {

	public const NOT_AUTHORIZED = 'not_authorized';

	public const CAMPAIGN_NAME = 'campaign_name';

	public const CAMPAIGN_PERIOD = 'campaign_period';

	public const CAMPAIGN_SCOPE = 'campaign_scope';

	public const CAMPAIGN_CODES = 'campaign_codes';

	public const CAMPAIGN_CREATOR_CODE = 'campaign_creator_code';

	public const CAMPAIGN_UNKNOWN = 'campaign_unknown';

	public const CAMPAIGN_IN_USE = 'campaign_in_use';

	public const NOT_DESIGNER_PRODUCT = 'not_designer_product';

	public const PRICES_EXCL_TAX = 'prices_excl_tax';

	public const FILE_MISSING = 'file_missing';

	public const FILE_TOO_LARGE = 'file_too_large';

	public const FILE_TYPE = 'file_type';

	public const BASE_NAME = 'base_name';

	public const BASE_PRICE = 'base_price';

	public const BASE_EXTRA = 'base_extra';

	public const BASE_CATEGORY = 'base_category';

	public const BASE_COLORS = 'base_colors';

	public const BASE_SIZES = 'base_sizes';

	public const BASE_VIEW = 'base_view';

	public const BASE_LAYER_SIZE = 'base_layer_size';

	public const BASE_INCOMPLETE = 'base_incomplete';

	public const BASE_UNKNOWN = 'base_unknown';

	public const BASE_IN_USE = 'base_in_use';

	public const CLIPART_NAME = 'clipart_name';

	public const CLIPART_UNKNOWN = 'clipart_unknown';

	public const DESIGNER_DISABLED = 'designer_disabled';

	public const DESIGN_INVALID = 'design_invalid';

	public const DESIGN_UNKNOWN = 'design_unknown';

	public const DESIGN_NOT_DRAFT = 'design_not_draft';

	public const DESIGN_TITLE = 'design_title';

	public const DESIGN_PROFIT = 'design_profit';

	public const DESIGN_OPTIONS = 'design_options';

	public const UPLOAD_QUOTA = 'upload_quota';

	public const UPLOAD_UNKNOWN = 'upload_unknown';

	public const DESIGN_EMPTY = 'design_empty';

	public const DESIGN_STALE = 'design_stale';

	public const SUBMISSION_FILE = 'submission_file';

	public const SUBMISSION_FILES = 'submission_files';

	public const SUBMISSION_PENDING = 'submission_pending';

	public const NOT_PENDING = 'not_pending';

	public const REVIEW_NOTE = 'review_note';

	public const NOT_LIVE = 'not_live';

	public const BASE_RETIRED = 'base_retired';

	public const PRINTFUL_TOKEN = 'printful_token';

	public const GELATO_ACCOUNT = 'gelato_account';

	public const PRODIGI_KEY = 'prodigi_key';

	public const PREVIEW_UNAVAILABLE = 'preview_unavailable';

	public const PREVIEW_BUSY = 'preview_busy';

	public const PREVIEW_FAILED = 'preview_failed';

	public const MOCKUPS_PENDING = 'mockups_pending';

	/** The catalog import (review 30.15): no colour, size or side was ticked. */
	public const BASE_PICK = 'base_pick';

	/** The base product is being imported and cannot be changed meanwhile. */
	public const IMPORT_RUNNING = 'import_running';

	/** A render of the provider failed. */
	public const IMPORT_RENDER = 'import_render';

	/** The renders took too long. */
	public const IMPORT_TIMEOUT = 'import_timeout';

	/** No side's marker was found in the renders. */
	public const IMPORT_NO_AREA = 'import_no_area';

	/** The renders gave no pair of photos to compare. */
	public const IMPORT_NO_PHOTOS = 'import_no_photos';

	/** A render could not be read as an image. */
	public const IMPORT_IMAGE = 'import_image';

	/** The server has no GD to measure the renders. */
	public const IMPORT_GD = 'import_gd';

	/** The import could not be queued. */
	public const IMPORT_QUEUE = 'import_queue';

	/** Another product has the same name. */
	public const DESIGN_NAME_TAKEN = 'design_name_taken';

	/** A design is on its way to the shop and cannot be deleted now. */
	public const DESIGN_APPLYING = 'design_applying';

	/** An embroidery pack is unknown or its values are refused. */
	public const THREAD_PACK = 'thread_pack';

	/** An embroidery pack is used by a base product. */
	public const THREAD_PACK_IN_USE = 'thread_pack_in_use';

	/** A print file does not keep to its side's embroidery pack. */
	public const PRINT_THREADS = 'print_threads';

	/**
	 * Constructor.
	 *
	 * @param string $reason Reason code.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Product designer operation refused: ' . $reason );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
