/**
 * The admin's chat window of a channel (Merch Dashboard → Alerts → Chatbot,
 * phase 25 step 8): polls
 * `GET merchboard/v1/alerts/chat/{platform}/{creator}` every few seconds and shows the bot's latest chat messages. Messages are
 * inserted as text, never as HTML; a chatter's colour is used only when it
 * is a plain #rrggbb value.
 */

/** Seconds between two polls. */
const POLL_SECONDS = 3;

interface ChatMessage {
	readonly id: number;
	readonly name: string;
	readonly login: string;
	readonly color: string;
	readonly text: string;
	readonly at: string;
}

function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null;
}

/**
 * The messages of an answer; invalid entries are skipped.
 *
 * @param body Response body.
 */
export function parseMessages( body: unknown ): ChatMessage[] {
	const list =
		isRecord( body ) && Array.isArray( body.messages )
			? ( body.messages as unknown[] )
			: [];

	return list.flatMap( ( item ) =>
		isRecord( item ) &&
		Number.isSafeInteger( item.id ) &&
		typeof item.name === 'string' &&
		typeof item.login === 'string' &&
		typeof item.color === 'string' &&
		typeof item.text === 'string' &&
		typeof item.at === 'string'
			? [
					{
						id: item.id as number,
						name: item.name,
						login: item.login,
						color: item.color,
						text: item.text,
						at: item.at,
					},
				]
			: []
	);
}

/**
 * One message as a line of the window.
 *
 * @param message Message.
 */
export function renderMessage( message: ChatMessage ): HTMLElement {
	const line = document.createElement( 'p' );
	const time = document.createElement( 'time' );
	const name = document.createElement( 'strong' );
	const at = new Date( message.at );

	line.style.margin = '0 0 4px';
	time.dateTime = message.at;
	time.textContent = Number.isNaN( at.getTime() )
		? ''
		: at.toLocaleTimeString( undefined, {
				hour: '2-digit',
				minute: '2-digit',
			} );
	time.style.color = '#646970';
	name.textContent = message.name !== '' ? message.name : message.login;

	if ( /^#[0-9a-f]{6}$/i.test( message.color ) ) {
		name.style.color = message.color;
	}

	line.append( time, ' ', name, ': ', message.text );

	return line;
}

/**
 * Starts polling for a window.
 *
 * @param box The window (`data-merchboard-chat`).
 */
export function startChat( box: HTMLElement ): () => void {
	const endpoint = box.dataset.endpoint ?? '';
	const nonce = box.dataset.nonce ?? '';
	let after = 0;
	let stopped = false;
	let timer = 0;

	const poll = async () => {
		try {
			const url = new URL( endpoint );
			url.searchParams.set( 'after', String( after ) );

			const response = await fetch( url.href, {
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { 'X-WP-Nonce': nonce },
			} );

			if ( ! response.ok ) {
				throw new Error( 'Chat request failed.' );
			}

			const messages = parseMessages( await response.json() );
			const atBottom =
				box.scrollHeight - box.scrollTop - box.clientHeight < 40;

			// "No messages" stays only until the first message arrives.
			if ( after === 0 ) {
				box.replaceChildren();

				if ( messages.length === 0 ) {
					const empty = document.createElement( 'p' );
					empty.dataset.empty = '1';
					empty.textContent = box.dataset.empty ?? '';
					box.append( empty );
				}
			}

			if ( messages.length > 0 ) {
				box.querySelector( '[data-empty]' )?.remove();
			}

			for ( const message of messages ) {
				box.append( renderMessage( message ) );
				after = Math.max( after, message.id );
			}

			if ( atBottom ) {
				box.scrollTop = box.scrollHeight;
			}
		} catch {
			box.setAttribute( 'title', box.dataset.error ?? '' );
		}

		if ( ! stopped ) {
			timer = window.setTimeout( poll, POLL_SECONDS * 1000 );
		}
	};

	void poll();

	return () => {
		stopped = true;
		window.clearTimeout( timer );
	};
}

document
	.querySelectorAll< HTMLElement >( '[data-merchboard-chat]' )
	.forEach( ( box ) => {
		startChat( box );
	} );
