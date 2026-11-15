/**
 * The shop's Twitch Extension panel (ADR-112): shows the creator's products
 * (image and name) under the stream. No price, no link, no buying: Twitch
 * does not allow them in an extension. Twitch hosts this file; it reads the
 * products from the shop with the extension's JWT and never sends anything
 * else. Built into a ZIP by `npm run twitch-extension -- --site <url>`.
 */
import './panel.css';

export interface PanelProduct {
	readonly id: number;
	readonly name: string;
	readonly image: string | null;
}

export interface Panel {
	readonly enabled: boolean;
	readonly creator: string;
	readonly products: readonly PanelProduct[];
}

export type Language = 'da' | 'en';

interface Texts {
	readonly title: ( creator: string ) => string;
	readonly titleNoName: string;
	readonly empty: string;
	readonly failed: string;
	readonly loading: string;
}

const TEXTS: Readonly< Record< Language, Texts > > = {
	da: {
		title: ( creator ) => `Merch fra ${ creator }`,
		titleNoName: 'Merch',
		empty: 'Ingen produkter at vise endnu.',
		failed: 'Produkterne kunne ikke hentes lige nu.',
		loading: 'Henter produkter …',
	},
	en: {
		title: ( creator ) => `Merch by ${ creator }`,
		titleNoName: 'Merch',
		empty: 'No products to show yet.',
		failed: 'The products could not be loaded right now.',
		loading: 'Loading products …',
	},
};

/** The most products shown (the shop sends 12 at most). */
const MAX_PRODUCTS = 12;

/**
 * The panel's language from Twitch's query (`language=da`), English otherwise.
 *
 * @param search `location.search`.
 */
export function languageOf( search: string ): Language {
	const language = new URLSearchParams( search ).get( 'language' ) ?? '';

	return language.toLowerCase().startsWith( 'da' ) ? 'da' : 'en';
}

/**
 * Validates the shop's answer; null when it is not a panel.
 *
 * @param body Response body.
 */
export function parsePanel( body: unknown ): Panel | null {
	if ( typeof body !== 'object' || body === null ) {
		return null;
	}

	const { enabled, creator, products } = body as Record< string, unknown >;

	if (
		typeof enabled !== 'boolean' ||
		typeof creator !== 'string' ||
		! Array.isArray( products )
	) {
		return null;
	}

	const parsed: PanelProduct[] = [];

	for ( const product of products.slice( 0, MAX_PRODUCTS ) ) {
		if ( typeof product !== 'object' || product === null ) {
			return null;
		}

		const { id, name, image } = product as Record< string, unknown >;

		if (
			typeof id !== 'number' ||
			! Number.isInteger( id ) ||
			typeof name !== 'string' ||
			( image !== null &&
				( typeof image !== 'string' || ! /^https:\/\//.test( image ) ) )
		) {
			return null;
		}

		parsed.push( { id, name, image } );
	}

	return { enabled, creator, products: parsed };
}

/**
 * An element with a class and optional text (never HTML).
 *
 * @param tag       Tag.
 * @param className Class.
 * @param text      Text.
 */
function element< K extends keyof HTMLElementTagNameMap >(
	tag: K,
	className: string,
	text?: string
): HTMLElementTagNameMap[ K ] {
	const node = document.createElement( tag );
	node.className = className;

	if ( text !== undefined ) {
		node.textContent = text;
	}

	return node;
}

/**
 * Shows a message instead of the products.
 *
 * @param root    The panel's root.
 * @param message Message.
 */
export function renderMessage( root: HTMLElement, message: string ): void {
	root.replaceChildren( element( 'p', 'mb-panel__message', message ) );
}

/**
 * Shows the panel.
 *
 * @param root     The panel's root.
 * @param panel    The shop's answer.
 * @param language Language.
 */
export function renderPanel(
	root: HTMLElement,
	panel: Panel,
	language: Language
): void {
	const texts = TEXTS[ language ];

	if ( ! panel.enabled || panel.products.length === 0 ) {
		renderMessage( root, texts.empty );
		return;
	}

	const title = element(
		'h1',
		'mb-panel__title',
		panel.creator === '' ? texts.titleNoName : texts.title( panel.creator )
	);
	const list = element( 'ul', 'mb-panel__grid' );

	for ( const product of panel.products ) {
		const item = element( 'li', 'mb-panel__item' );

		if ( product.image !== null ) {
			const image = element( 'img', 'mb-panel__image' );
			image.src = product.image;
			image.alt = '';
			image.loading = 'lazy';
			image.decoding = 'async';
			item.append( image );
		} else {
			item.append(
				element( 'span', 'mb-panel__image mb-panel__image--blank' )
			);
		}

		item.append( element( 'span', 'mb-panel__name', product.name ) );
		list.append( item );
	}

	root.replaceChildren( title, list );
}

/**
 * Fetches the panel from the shop.
 *
 * @param api   The panel's REST URL.
 * @param token The extension's JWT.
 */
export async function fetchPanel(
	api: string,
	token: string
): Promise< Panel | null > {
	try {
		const response = await fetch( api, {
			headers: { Authorization: `Bearer ${ token }` },
			credentials: 'omit',
		} );

		return response.ok ? parsePanel( await response.json() ) : null;
	} catch {
		return null;
	}
}

interface TwitchAuth {
	readonly channelId: string;
	readonly token: string;
}

interface TwitchContext {
	readonly theme?: string;
}

interface TwitchExt {
	onAuthorized: ( callback: ( auth: TwitchAuth ) => void ) => void;
	onContext: ( callback: ( context: TwitchContext ) => void ) => void;
}

/**
 * Starts the panel inside Twitch.
 *
 * @param root The panel's root (`data-api` on the body holds the REST URL).
 * @param ext  Twitch's Extension Helper.
 */
export function start( root: HTMLElement, ext: TwitchExt ): void {
	const api = document.body.dataset.api ?? '';
	const language = languageOf( window.location.search );
	let channel = '';

	renderMessage( root, TEXTS[ language ].loading );

	ext.onContext( ( context ) => {
		document.body.classList.toggle( 'mb-dark', context.theme === 'dark' );
	} );

	// Twitch calls this again when the token is renewed; the panel loads once per channel.
	ext.onAuthorized( ( auth ) => {
		if ( auth.channelId === channel || api === '' ) {
			return;
		}

		channel = auth.channelId;
		void fetchPanel( api, auth.token ).then( ( panel ) => {
			if ( panel === null ) {
				renderMessage( root, TEXTS[ language ].failed );
			} else {
				renderPanel( root, panel, language );
			}
		} );
	} );
}

declare global {
	interface Window {
		Twitch?: { readonly ext?: TwitchExt };
	}
}

const panelRoot =
	typeof document === 'undefined'
		? null
		: document.getElementById( 'mb-panel' );

if ( panelRoot !== null && window.Twitch?.ext !== undefined ) {
	start( panelRoot, window.Twitch.ext );
}
