/**
 * Texts shared by the Overview's key figures and the Sales section
 * (ADR-040 pkt. 14), so both say the same thing.
 */
import { __ } from '@wordpress/i18n';

/** What net sales are, and are not. */
export function netSalesExplanation(): string {
	return __(
		"The shop's net sales of your products after discounts, excluding VAT, shipping and fees. It is not your payout.",
		'merchboard'
	);
}

/** Shown when the figures come in more than one currency. */
export function perCurrencyNote(): string {
	return __(
		'The figures are shown per currency and never added together.',
		'merchboard'
	);
}

/** Shown when the shop has no sales route (no WooCommerce). */
export function notAvailableText(): string {
	return __( 'Sales figures are not available.', 'merchboard' );
}
