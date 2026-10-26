<?php
/**
 * State of a category proposal.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

defined( 'ABSPATH' ) || exit;

/**
 * A proposal waits (pending) until an administrator approves or rejects it,
 * the creator withdraws it, or a newer proposal for the same field replaces
 * it. Each state after pending is final.
 */
enum ProposalStatus: string {

	case Pending   = 'pending';
	case Approved  = 'approved';
	case Rejected  = 'rejected';
	case Replaced  = 'replaced';
	case Withdrawn = 'withdrawn';
}
