/** One bounded worker job at a time, shared by all PSD fields. */
import type { PsdScene } from './psd-scene';
import { TemplateError } from './psd-template';
import type { PsdTemplate, TemplateErrorCode } from './psd-template';

let cancel: ( () => void ) | undefined;

/** A new selection cancels the previous import; slow imports expire. */
export function importPsd(
	buffer: ArrayBuffer,
	scene: false
): Promise< PsdTemplate >;
export function importPsd(
	buffer: ArrayBuffer,
	scene: true
): Promise< PsdScene >;
export function importPsd(
	buffer: ArrayBuffer,
	scene: boolean
): Promise< PsdTemplate | PsdScene > {
	cancel?.();

	return new Promise( ( resolve, reject ) => {
		const worker = new Worker(
			new URL( './psd-import.worker.ts', import.meta.url )
		);
		const finish = (): void => {
			worker.terminate();
			clearTimeout( timer );
			cancel = undefined;
		};
		const fail = (): void => {
			finish();
			reject( new TemplateError( 'unreadable' ) );
		};
		const timer = setTimeout( fail, 15_000 );
		cancel = fail;
		worker.onerror = fail;
		worker.onmessageerror = fail;
		worker.onmessage = (
			event: MessageEvent< {
				result?: PsdTemplate | PsdScene;
				code?: TemplateErrorCode;
				detail?: string;
			} >
		): void => {
			finish();
			if ( event.data.result !== undefined ) {
				resolve( event.data.result );
			} else {
				reject(
					new TemplateError(
						event.data.code ?? 'unreadable',
						event.data.detail
					)
				);
			}
		};
		try {
			worker.postMessage( { buffer, scene }, [ buffer ] );
		} catch {
			fail();
		}
	} );
}
