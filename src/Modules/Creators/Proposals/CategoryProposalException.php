<?php
/**
 * Refused category proposals and decisions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Why a proposal, upload or decision was refused (nothing changed). Reasons
 * are stable codes for REST errors and admin notices, never shown as text.
 */
final class CategoryProposalException extends DomainException {

	public const NOT_AUTHORIZED = 'proposal_not_authorized';
	public const INVALID        = 'proposal_invalid';
	public const REQUIRED       = 'proposal_required';
	public const TOO_LONG       = 'proposal_too_long';
	public const INVALID_URL    = 'proposal_invalid_url';
	public const UNAVAILABLE    = 'proposal_unavailable';
	public const NOT_FOUND      = 'proposal_not_found';
	public const NOT_PENDING    = 'proposal_not_pending';
	public const NO_CATEGORY    = 'proposal_no_category';
	public const NO_FILE        = 'proposal_no_file';
	public const TOO_LARGE      = 'proposal_too_large';
	public const INVALID_TYPE   = 'proposal_invalid_type';
	public const UNREADABLE     = 'proposal_unreadable';
	public const TOO_SMALL      = 'proposal_too_small';
	public const TOO_BIG        = 'proposal_too_big';
	public const NOT_SQUARE     = 'proposal_not_square';

	/**
	 * Constructor.
	 *
	 * @param string                $reason One of the constants.
	 * @param array<string, string> $errors Per field (ProposalField value): one of the constants (INVALID only).
	 */
	public function __construct( private string $reason, private array $errors = array() ) {
		parent::__construct( 'Category proposal refused: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * The invalid fields and why.
	 *
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
