/**
 * Fixed, translated texts of the payout section. Server messages are never
 * shown; a refusal is mapped from its reason code only.
 */
import { __, sprintf } from '@wordpress/i18n';

import type { ApiError } from '../../api/client';
import type { PayoutStatus } from '../../types/payouts';

/** The server's limits (ADR-055), for the explanations only. */
export const MAX_PENDING = 3;
export const MAX_PER_DAY = 10;

/** The REST error code of a refused payout operation. */
export const REFUSED = 'merchboard_payout_refused';

export function statusLabel( status: PayoutStatus ): string {
	switch ( status ) {
		case 'pending':
			return __( 'Pending', 'merchboard' );
		case 'completed':
			return __( 'Paid out', 'merchboard' );
		case 'rejected':
			return __( 'Rejected', 'merchboard' );
		default:
			return __( 'Cancelled', 'merchboard' );
	}
}

export function methodLabel( method: string ): string {
	switch ( method ) {
		case 'bank':
			return __( 'Bank transfer', 'merchboard' );
		case 'paypal':
			return __( 'PayPal', 'merchboard' );
		case 'gift_card':
			return __( 'Gift card', 'merchboard' );
		default:
			return method;
	}
}

/**
 * The message of a refused request or cancellation, or null when the error
 * is not a refusal (then the general error state applies).
 *
 * @param error API error.
 */
export function refusalMessage( error: ApiError ): string | null {
	if ( error.code === 'merchboard_invalid_payout' ) {
		return __( 'Fill in all fields of the request.', 'merchboard' );
	}

	if ( error.code !== REFUSED ) {
		return null;
	}

	switch ( error.reason ) {
		case 'invalid_amount':
			return __(
				'Enter an amount greater than 0 with at most as many decimals as the shop uses.',
				'merchboard'
			);
		case 'insufficient_balance':
			return __(
				'The amount is more than you can request right now.',
				'merchboard'
			);
		case 'balance_unknown':
			return __(
				'Your balance cannot be calculated right now, because some sales lack the data needed. No payout can be requested until it is known.',
				'merchboard'
			);
		case 'too_many_pending':
			return sprintf(
				/* translators: %d: maximum number of pending requests. */
				__(
					'You already have %d pending requests. Wait until one is handled, or cancel one.',
					'merchboard'
				),
				MAX_PENDING
			);
		case 'too_many_requests':
			return sprintf(
				/* translators: %d: maximum number of requests per 24 hours. */
				__(
					'You have made %d requests in the last 24 hours. Please try again later.',
					'merchboard'
				),
				MAX_PER_DAY
			);
		case 'method_unavailable':
			return __(
				'This payout method is not available right now.',
				'merchboard'
			);
		case 'invalid_details':
			return __(
				'Check the payment details: a registration number has 4 digits, an account number 1–10 digits, and PayPal needs a valid email address.',
				'merchboard'
			);
		case 'busy':
			return __(
				'Another payout of yours is being processed. Please try again in a moment.',
				'merchboard'
			);
		case 'not_pending':
			return __(
				'The request has already been handled, so it can no longer be cancelled.',
				'merchboard'
			);
		default:
			return __( 'The request was not accepted.', 'merchboard' );
	}
}
