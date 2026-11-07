/** Decode and convert untrusted PSDs away from the administrator's UI. */
import { initializeCanvas } from 'ag-psd';
import { validatePsdCompression } from './psd-budget';
import { readScene } from './psd-scene';
import { readTemplate, TemplateError } from './psd-template';

initializeCanvas(
	() => {
		throw new Error( 'Canvas is unavailable in PSD imports.' );
	},
	( width, height ) => new ImageData( width, height )
);

self.onmessage = async (
	event: MessageEvent< { buffer: ArrayBuffer; scene: boolean } >
) => {
	try {
		await validatePsdCompression( event.data.buffer );
		const result = event.data.scene
			? readScene( event.data.buffer )
			: readTemplate( event.data.buffer );
		self.postMessage( { result } );
	} catch ( error ) {
		self.postMessage( {
			code: error instanceof TemplateError ? error.code : 'unreadable',
			detail: error instanceof TemplateError ? error.detail : '',
		} );
	}
};
