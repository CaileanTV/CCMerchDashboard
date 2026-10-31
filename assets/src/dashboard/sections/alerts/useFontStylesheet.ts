import { useEffect } from '@wordpress/element';

/**
 * Adds a font's stylesheet to the page once (ADR-067; the dashboard's CSP
 * allows fonts.googleapis.com and fonts.gstatic.com). Only URLs from the
 * server's validated font list reach this.
 *
 * @param url Stylesheet URL.
 */
export function addFontStylesheet( url: string ): void {
	const exists = Array.from(
		document.head.querySelectorAll< HTMLLinkElement >(
			'link[data-merchboard-font]'
		)
	).some( ( link ) => link.href === url );

	if ( ! exists ) {
		const link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.href = url;
		link.dataset.merchboardFont = '';
		document.head.append( link );
	}
}

/**
 * Loads an approved Google font's stylesheet once per page.
 *
 * @param url Stylesheet URL, or null for the default font.
 */
export function useFontStylesheet( url: string | null ): void {
	useEffect( () => {
		if ( url !== null ) {
			addFontStylesheet( url );
		}
	}, [ url ] );
}
