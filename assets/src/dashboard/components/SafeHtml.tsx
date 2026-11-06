/**
 * Shows a short HTML text from the server (e.g. the agreement) as React
 * elements, without injecting HTML: only headings, paragraphs, line breaks,
 * lists, bold and italic text and https links are kept; anything else
 * becomes its text. The server sanitizes the text as well (wp_kses_post).
 */
import { createElement, Fragment } from '@wordpress/element';
import type { ReactNode } from 'react';

const TAGS: Readonly< Record< string, string > > = {
	H2: 'h3',
	H3: 'h4',
	H4: 'h5',
	P: 'p',
	BR: 'br',
	UL: 'ul',
	OL: 'ol',
	LI: 'li',
	STRONG: 'strong',
	B: 'strong',
	EM: 'em',
	I: 'em',
	A: 'a',
};

/**
 * React nodes of a DOM node's children.
 *
 * @param node The DOM node.
 */
function nodes( node: Node ): ReactNode[] {
	return Array.from( node.childNodes ).map( ( child, index ) =>
		element( child, index )
	);
}

/**
 * One DOM node as React.
 *
 * @param node  The DOM node.
 * @param index Its position (the React key).
 */
function element( node: Node, index: number ): ReactNode {
	if ( node.nodeType === Node.TEXT_NODE ) {
		return node.textContent ?? '';
	}

	if ( ! ( node instanceof Element ) ) {
		return null;
	}

	const tag = TAGS[ node.tagName ];

	if ( tag === undefined ) {
		return createElement( Fragment, { key: index }, ...nodes( node ) );
	}

	if ( tag === 'br' ) {
		return createElement( 'br', { key: index } );
	}

	if ( tag === 'a' ) {
		const href = node.getAttribute( 'href' ) ?? '';

		return href.startsWith( 'https://' )
			? createElement(
					'a',
					{
						key: index,
						href,
						target: '_blank',
						rel: 'noopener noreferrer',
					},
					...nodes( node )
				)
			: createElement( Fragment, { key: index }, ...nodes( node ) );
	}

	return createElement( tag, { key: index }, ...nodes( node ) );
}

export interface SafeHtmlProps {
	/** The HTML text. */
	readonly html: string;
	readonly className?: string;
}

/**
 * The text as React elements in a `<div>`.
 *
 * @param props           Props.
 * @param props.html      The HTML text.
 * @param props.className Class of the wrapper.
 */
export function SafeHtml( { html, className }: SafeHtmlProps ) {
	const parsed = new DOMParser().parseFromString( html, 'text/html' );

	return createElement(
		'div',
		className === undefined ? {} : { className },
		...nodes( parsed.body )
	);
}
