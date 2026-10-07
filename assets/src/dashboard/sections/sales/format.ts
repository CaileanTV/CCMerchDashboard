/**
 * Display formatting of sales figures in the site's locale. Amounts are
 * decimal strings from the API and are formatted as strings: no float in
 * between (ADR-040 pkt. 9).
 */

/**
 * A WordPress locale as a BCP 47 tag, e.g. da_DK → da-DK.
 *
 * @param locale WordPress locale.
 */
function tag( locale: string ): string {
	return locale.replace( '_', '-' );
}

/**
 * A decimal string as money in the locale, e.g. "1234.5000" DKK → "1.234,50 kr.".
 * Intl formats the string itself (no float in between); the API value is
 * shown as it is when Intl cannot format it.
 *
 * @param amount   Decimal string with four decimals.
 * @param currency ISO 4217 code.
 * @param locale   WordPress locale, e.g. da_DK.
 */
export function moneyText(
	amount: string,
	currency: string,
	locale: string
): string {
	try {
		// Intl.NumberFormat formats a decimal string exactly (ES2023); the
		// ES2022 typings only declare number | bigint. `format` is a bound getter.
		const format = new Intl.NumberFormat( tag( locale ), {
			style: 'currency',
			currency,
		} ).format as unknown as ( value: string ) => string;

		return format( amount );
	} catch {
		return `${ amount } ${ currency }`;
	}
}

/**
 * A count in the locale, e.g. 1234 → "1.234".
 *
 * @param value  Integer.
 * @param locale WordPress locale.
 */
export function countText( value: number, locale: string ): string {
	try {
		return new Intl.NumberFormat( tag( locale ) ).format( value );
	} catch {
		return String( value );
	}
}

/**
 * A `YYYY-MM-DD` date of the site's calendar in the locale. The date is not a
 * moment in time, so it is formatted in UTC to keep the day.
 *
 * @param date   Date.
 * @param locale WordPress locale.
 */
export function dayText( date: string, locale: string ): string {
	try {
		return new Intl.DateTimeFormat( tag( locale ), {
			dateStyle: 'medium',
			timeZone: 'UTC',
		} ).format( new Date( `${ date }T00:00:00Z` ) );
	} catch {
		return date;
	}
}

/**
 * A period of the series: one day as that day, a longer period as
 * "first – last" (both included).
 *
 * @param start  First day.
 * @param end    Last day.
 * @param locale WordPress locale.
 */
export function periodText(
	start: string,
	end: string,
	locale: string
): string {
	return start === end
		? dayText( start, locale )
		: `${ dayText( start, locale ) } – ${ dayText( end, locale ) }`;
}
