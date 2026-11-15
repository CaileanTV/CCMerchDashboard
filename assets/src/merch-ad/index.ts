/**
 * The merch ad overlay in OBS (ADR-113): shows the creator's products in one
 * of five designs every few minutes for a few seconds, with the pill and the
 * creator's animations; checks every 10 seconds for changes and "show now".
 * Everything is built with textContent and validated configuration.
 */
import './merch-ad.css';

import {
	firstDelayMs,
	intervalMs,
	parseConfig,
	pillParts,
	stepMs,
	viewsOf,
} from './ad';
import type { Ad, AdProduct, Config } from './ad';

const OUT_MS = 700;
const CHECK_MS = 10000;

/**
 * An element with a class and optional text.
 *
 * @param tag       Tag.
 * @param className Class.
 * @param text      Text.
 */
function el< K extends keyof HTMLElementTagNameMap >(
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
 * The pill with chat commands in bold.
 *
 * @param note The text.
 */
export function pill( note: string ): HTMLElement {
	const node = el( 'div', 'mb-ad__pill' );

	for ( const part of pillParts( note ) ) {
		node.append(
			part.command
				? el( 'strong', 'mb-ad__command', part.text )
				: document.createTextNode( part.text )
		);
	}

	return node;
}

/**
 * A product's image: background-free, or the shop image in a tile.
 *
 * @param product The product.
 */
function image( product: AdProduct ): HTMLElement {
	const frame = el(
		'div',
		product.cutout
			? 'mb-ad__img mb-ad__img--cutout'
			: 'mb-ad__img mb-ad__img--tile'
	);
	const img = el( 'img', '' );
	img.src = product.image;
	img.alt = '';
	img.decoding = 'async';
	frame.append( img );

	return frame;
}

/**
 * A product with its name.
 *
 * @param product   The product.
 * @param className Extra class.
 */
function item( product: AdProduct, className = '' ): HTMLElement {
	const node = el( 'div', ( 'mb-ad__item ' + className ).trim() );
	node.append( image( product ), el( 'div', 'mb-ad__name', product.name ) );

	return node;
}

/**
 * One view of a showing for a design.
 *
 * @param ad   The ad.
 * @param view The products of the view.
 */
export function renderView( ad: Ad, view: readonly AdProduct[] ): HTMLElement {
	const body = el( 'div', 'mb-ad__view' );
	const note = ad.note === '' ? null : pill( ad.note );

	switch ( ad.design ) {
		case 'shelf': {
			const row = el( 'div', 'mb-ad__row' );
			view.forEach( ( product ) => row.append( item( product ) ) );
			body.append( row );
			break;
		}
		case 'carousel': {
			const track = el( 'div', 'mb-ad__track' );
			// Twice, so the strip runs on without a gap.
			[ ...view, ...view ].forEach( ( product ) =>
				track.append( item( product ) )
			);
			track.style.animationDuration = `${ Math.max( 8, view.length * 3 ) }s`;
			const strip = el( 'div', 'mb-ad__strip' );
			strip.append( track );
			body.append( strip );
			break;
		}
		case 'showcase': {
			const stage = el( 'div', 'mb-ad__three' );
			const [ left, middle, right ] = view;

			if ( left !== undefined && view.length > 1 ) {
				stage.append( item( left, 'mb-ad__item--left' ) );
			}

			if ( middle !== undefined ) {
				stage.append( item( middle, 'mb-ad__item--middle' ) );
			}

			if ( right !== undefined && view.length > 2 ) {
				stage.append( item( right, 'mb-ad__item--right' ) );
			}

			body.append( stage );
			break;
		}
		default: {
			const [ product ] = view;

			if ( product !== undefined ) {
				const card = el( 'div', 'mb-ad__card' );
				const info = el( 'div', 'mb-ad__info' );
				info.append( el( 'div', 'mb-ad__name', product.name ) );

				if ( note !== null && ad.design === 'corner' ) {
					info.append( note );
				}

				card.append( image( product ), info );
				body.append( card );
			}
		}
	}

	if ( note !== null && ad.design !== 'corner' ) {
		body.append( note );
	}

	return body;
}

/**
 * Runs the overlay.
 *
 * @param root   The ad's element.
 * @param config The page's configuration.
 */
export function start( root: HTMLElement, config: Config ): void {
	const { ad } = config;
	let showing = false;
	let lastShowAt = config.showAt;

	root.className = `mb-ad mb-ad--${ ad.design } mb-ad--${ ad.theme }`;
	document.documentElement.classList.toggle(
		'mb-ad-preview',
		config.preview
	);
	root.style.setProperty( '--mb-accent', ad.accent );

	const show = (): void => {
		const views = viewsOf( ad );

		// The preview shows the design also before the creator switches the ad on.
		if (
			showing ||
			( ! ad.enabled && ! config.preview ) ||
			views.length === 0
		) {
			return;
		}

		showing = true;

		const stage = el(
			'div',
			`mb-ad__stage mb-ad__stage--in-${ ad.enter }`
		);
		let index = 0;
		stage.append( renderView( ad, views[ 0 ] ?? [] ) );
		root.replaceChildren( stage );

		const step = stepMs( ad );
		const turn = window.setInterval( () => {
			if ( views.length < 2 ) {
				return;
			}

			index = ( index + 1 ) % views.length;
			stage.replaceChildren( renderView( ad, views[ index ] ?? [] ) );
		}, step );

		window.setTimeout( () => {
			window.clearInterval( turn );
			stage.className = `mb-ad__stage mb-ad__stage--out-${ ad.leave }`;
			window.setTimeout( () => {
				root.replaceChildren();
				showing = false;
			}, OUT_MS );
		}, ad.seconds * 1000 );
	};

	window.setTimeout( () => {
		show();
		window.setInterval( show, intervalMs( config ) );
	}, firstDelayMs( config ) );

	// Changes and "show now".
	window.setInterval( () => {
		void fetch( config.status, { cache: 'no-store', credentials: 'omit' } )
			.then( ( response ) => ( response.ok ? response.json() : null ) )
			.then( ( body: unknown ) => {
				if ( typeof body !== 'object' || body === null ) {
					return;
				}

				const { version, show_at: showAt } = body as Record<
					string,
					unknown
				>;

				if (
					typeof version === 'string' &&
					version !== config.version
				) {
					window.location.reload();
				} else if (
					typeof showAt === 'number' &&
					showAt > lastShowAt
				) {
					lastShowAt = showAt;
					show();
				}
			} )
			.catch( () => undefined );
	}, CHECK_MS );
}

const rootNode =
	typeof document === 'undefined' ? null : document.getElementById( 'mb-ad' );
const configNode =
	typeof document === 'undefined'
		? null
		: document.getElementById( 'mb-ad-config' );

if ( rootNode !== null && configNode !== null ) {
	let parsed: unknown = null;

	try {
		parsed = JSON.parse( configNode.textContent ?? '' );
	} catch {
		parsed = null;
	}

	const config = parseConfig( parsed );

	if ( config !== null ) {
		start( rootNode, config );
	}
}
