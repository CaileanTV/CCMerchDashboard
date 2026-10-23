<?php
/**
 * A refused change of a news message.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Why a news message was not changed; the reason is the admin page's
 * outcome code.
 */
final class NewsException extends RuntimeException {

	public const NOT_AUTHORIZED = 'not_authorized';
	public const INVALID_TITLE  = 'invalid_title';
	public const INVALID_BODY   = 'invalid_body';
	public const UNKNOWN        = 'unknown_news';
	public const NOT_DRAFT      = 'not_draft';
	public const NOT_PUBLISHED  = 'not_published';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'The news message was not changed: ' . $reason . '.' );
	}

	/**
	 * The reason (outcome code).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
