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
	stackOffset,
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
export function pill(
	note: string,
	size = 'medium',
	over = false
): HTMLElement {
	const node = el(
		'div',
		`mb-ad__pill mb-ad__pill--${ size }${ over ? ' mb-ad__pill--over' : '' }`
	);

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
function item( product: AdProduct, className = '', name = true ): HTMLElement {
	const node = el( 'div', ( 'mb-ad__item ' + className ).trim() );
	node.append( image( product ) );

	if ( name ) {
		node.append( el( 'div', 'mb-ad__name', product.name ) );
	}

	return node;
}

/** How many products one round of the carousel holds at least, so the strip is always wider than the screen. */
const CAROUSEL_ROUND = 12;

/**
 * One view of a showing for a design.
 *
 * @param ad   The ad.
 * @param view The products of the view.
 */
export function renderView( ad: Ad, view: readonly AdProduct[] ): HTMLElement {
	const body = el( 'div', 'mb-ad__view' );
	const over = ad.pillPosition === 'over';
	const note = ad.note === '' ? null : pill( ad.note, ad.pillSize, over );
	const names = ad.showNames;
	// Where the pill goes when it is over the product image.
	let anchor: HTMLElement | null = null;

	switch ( ad.design ) {
		case 'shelf': {
			const row = el( 'div', 'mb-ad__row' );
			view.forEach( ( product ) =>
				row.append( item( product, '', names ) )
			);
			body.append( row );
			anchor = row;
			break;
		}
		case 'carousel': {
			const track = el( 'div', 'mb-ad__track' );
			// One round holds the products again and again until it is wider than the
			// screen; two rounds run on, so the strip never shows a gap.
			const round: AdProduct[] = [];

			while ( view.length > 0 && round.length < CAROUSEL_ROUND ) {
				round.push( ...view );
			}

			[ ...round, ...round ].forEach( ( product ) =>
				track.append( item( product, '', names ) )
			);
			track.style.animationDuration = `${ round.length * 3 }s`;
			const strip = el( 'div', 'mb-ad__strip' );
			strip.append( track );
			body.append( strip );
			anchor = strip;
			break;
		}
		case 'showcase':
		case 'stack': {
			// Stack: the middle product in front, the two others right behind it, turned.
			const stage = el(
				'div',
				ad.design === 'stack' ? 'mb-ad__stack' : 'mb-ad__three'
			);
			const [ left, middle, right ] = view;

			if ( left !== undefined && view.length > 1 ) {
				stage.append( item( left, 'mb-ad__item--left', names ) );
			}

			if ( middle !== undefined ) {
				const front = item( middle, 'mb-ad__item--middle', names );
				stage.append( front );
				anchor = front.querySelector( '.mb-ad__img' );
			}

			if ( right !== undefined && view.length > 2 ) {
				stage.append( item( right, 'mb-ad__item--right', names ) );
			}

			body.append( stage );
			break;
		}
		default: {
			const [ product ] = view;

			if ( product !== undefined ) {
				const card = el( 'div', 'mb-ad__card' );
				const info = el( 'div', 'mb-ad__info' );
				const frame = image( product );

				if ( names ) {
					info.append( el( 'div', 'mb-ad__name', product.name ) );
				}

				if ( note !== null && ad.design === 'corner' && ! over ) {
					info.append( note );
				}

				card.append( frame );

				if ( info.childElementCount > 0 ) {
					card.append( info );
				}

				body.append( card );
				anchor = frame;
			}
		}
	}

	if ( note !== null && over && anchor !== null ) {
		// In the lower third of the product image (or the shelf, or the strip).
		if ( ad.design === 'shelf' || ad.design === 'carousel' ) {
			anchor.classList.add( 'has-pill' );
		}

		anchor.append( note );
	} else if ( note !== null && ad.design !== 'corner' ) {
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
	root.style.setProperty(
		'--mb-stack-spread',
		stackOffset( ad.stackSpread )
	);

	// "Show now" and the preview show the ad also while it is switched off.
	const show = ( now = false ): void => {
		const views = viewsOf( ad );

		if (
			showing ||
			( ! ad.enabled && ! config.preview && ! now ) ||
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
		window.setInterval( () => show(), intervalMs( config ) );
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
					show( true );
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
