/**
 * Alert overlay for OBS (ADR-065 pkt. 4): reads the page configuration,
 * long-polls the feed and shows the alerts. Falls back to a request every
 * five seconds when long-polls time out (a proxy that buffers them), backs
 * off after errors, retries at once when OBS shows the scene again, and
 * reloads the page when the overlay settings change.
 */
import './overlay.css';
import {
	REQUEST_TIMEOUT,
	SHORT_POLL_INTERVAL,
	backoff,
	feedUrl,
	parseAnswer,
	parseConfig,
	type OverlayConfig,
} from './feed';
import { AlertPlayer } from './player';
import { fitText, followText } from './fit';
import { playSound } from './sound';

/** Wait after a 404 (the id was regenerated or the creator deactivated). */
const GONE_RETRY = 60000;

let wake: ( () => void ) | null = null;

function sleep( ms: number ): Promise< void > {
	return new Promise( ( resolve ) => {
		const timer = window.setTimeout( done, ms );

		function done(): void {
			window.clearTimeout( timer );
			wake = null;
			resolve();
		}

		wake = done;
	} );
}

function request( url: string ): Promise< Response > {
	return fetch( url, {
		cache: 'no-store',
		credentials: 'omit',
		signal: AbortSignal.timeout( REQUEST_TIMEOUT ),
	} );
}

async function run(
	config: OverlayConfig,
	player: AlertPlayer
): Promise< void > {
	let cursor: number | null = null;
	let attempt = 0;
	let longPoll = true;

	for (;;) {
		try {
			const response = await request(
				feedUrl( config.feed, cursor, longPoll )
			);

			if ( response.status === 404 ) {
				await sleep( GONE_RETRY );
				continue;
			}

			const answer = response.ok
				? parseAnswer( await response.json() )
				: null;

			if ( answer === null ) {
				throw new Error( 'Invalid feed answer.' );
			}

			if ( answer.version !== config.version ) {
				window.location.reload();
				return;
			}

			if ( cursor !== null ) {
				player.enqueue( answer.alerts );
			}

			cursor = answer.cursor;
			attempt = 0;

			if ( ! longPoll ) {
				await sleep( SHORT_POLL_INTERVAL );
			}
		} catch ( error ) {
			if (
				error instanceof DOMException &&
				( error.name === 'TimeoutError' || error.name === 'AbortError' )
			) {
				longPoll = false;
			}

			await sleep( backoff( attempt ) );
			attempt += 1;
		}
	}
}

function start(): void {
	const source = document.getElementById( 'merchboard-overlay-config' );
	const image = document.getElementById( 'merchboard-overlay-image' );
	const text = document.getElementById( 'merchboard-overlay-text' );
	const secondary = document.getElementById( 'merchboard-overlay-secondary' );

	if (
		source === null ||
		image === null ||
		text === null ||
		secondary === null
	) {
		return;
	}

	let config: OverlayConfig | null = null;

	try {
		config = parseConfig( JSON.parse( source.textContent ?? '' ) );
	} catch {
		config = null;
	}

	if ( config === null ) {
		return;
	}

	document.addEventListener( 'visibilitychange', () => {
		if ( document.visibilityState === 'visible' && wake !== null ) {
			wake();
		}
	} );

	void run(
		config,
		new AlertPlayer(
			{ image, text, secondary },
			config,
			undefined,
			undefined,
			( url ) => playSound( url ),
			config.textFit === 'shrink' ? fitText : undefined,
			config.imageFollows
				? ( elements ) => followText( elements.image, elements.text )
				: undefined
		)
	);
}

start();
