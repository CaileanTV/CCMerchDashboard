import { createElement, Fragment } from '@wordpress/element';
import type { ReactNode } from 'react';

/** The elements a message may contain (as the server's NewsHtml). */
const ALLOWED = new Set( [
	'p',
	'br',
	'strong',
	'b',
	'em',
	'i',
	'ul',
	'ol',
	'li',
	'a',
] );

const SAFE_LINK = /^(https?:\/\/|mailto:)/i;

/**
 * React elements from a node: allowed elements with their children, links
 * only with a full http(s) or mailto address (opened in a new tab without
 * the opener), text as text; everything else keeps only its text.
 *
 * @param node DOM node.
 * @param key  React key.
 */
function build( node: Node, key: string ): ReactNode {
	if ( node.nodeType === Node.TEXT_NODE ) {
		return node.textContent;
	}

	if ( ! ( node instanceof Element ) ) {
		return null;
	}

	const children = Array.from( node.childNodes ).map( ( child, index ) =>
		build( child, `${ key }-${ index }` )
	);
	const tag = node.tagName.toLowerCase();

	if ( ! ALLOWED.has( tag ) ) {
		return createElement( Fragment, { key }, ...children );
	}

	if ( tag === 'br' ) {
		return createElement( 'br', { key } );
	}

	if ( tag === 'a' ) {
		const href = node.getAttribute( 'href' ) ?? '';

		return SAFE_LINK.test( href )
			? createElement(
					'a',
					{
						key,
						href,
						target: '_blank',
						rel: 'noopener noreferrer',
					},
					...children
				)
			: createElement( Fragment, { key }, ...children );
	}

	return createElement( tag, { key }, ...children );
}

/**
 * A message's body (step 25.11): the server cleans it (NewsHtml), and this
 * rebuilds only the allowed elements as React elements from a parsed copy,
 * so no HTML string is ever inserted into the page.
 *
 * @param props      Props.
 * @param props.html Cleaned HTML from the server.
 */
export function NewsContent( { html }: { readonly html: string } ) {
	const document = new DOMParser().parseFromString(
		`<body>${ html }</body>`,
		'text/html'
	);

	return (
		<div className="merchboard-news__body">
			{ Array.from( document.body.childNodes ).map( ( node, index ) =>
				build( node, String( index ) )
			) }
		</div>
	);
}
