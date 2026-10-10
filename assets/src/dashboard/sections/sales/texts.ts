/**
 * Texts shared by the Overview's key figures and the Sales section
 * (ADR-040 pkt. 14), so both say the same thing.
 */
import { __ } from '@wordpress/i18n';

/** What the sales figures are, and are not (ADR-053: including VAT). */
export function salesExplanation(): string {
	return __(
		"The shop's sales of your products after discounts, including VAT, excluding shipping and fees. It is not your payout.",
		'merchboard'
	);
}

/** Shown for a currency whose period has sales without stored VAT. */
export function missingVatNote(): string {
	return __(
		'VAT is missing for some older sales in this period, so the amounts are shown excluding VAT.',
		'merchboard'
	);
}

/**
 * Label of the sales amount: including VAT, or the excl. VAT fallback.
 *
 * @param vatIncluded Whether the amounts include VAT.
 */
export function salesLabel( vatIncluded: boolean ): string {
	return vatIncluded
		? __( 'Sales (incl. VAT)', 'merchboard' )
		: __( 'Net sales (excl. VAT)', 'merchboard' );
}

/**
 * The amount to show: incl. VAT when known, otherwise excl. VAT (never a
 * mix: the whole currency uses one basis).
 *
 * @param amounts                 Amounts.
 * @param amounts.netSales        Excl. VAT.
 * @param amounts.netSalesInclVat Incl. VAT, or null.
 * @param vatIncluded             Whether the currency's amounts include VAT.
 */
export function shownSales(
	amounts: {
		readonly netSales: string;
		readonly netSalesInclVat: string | null;
	},
	vatIncluded: boolean
): string {
	return vatIncluded && amounts.netSalesInclVat !== null
		? amounts.netSalesInclVat
		: amounts.netSales;
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
