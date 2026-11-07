/** Resource limits shared by the two browser PSD importers. */
import { readPsd } from 'ag-psd';
import type { Layer, Psd } from 'ag-psd';

// Include hidden layers and masks: ag-psd decodes those too.
const MAX_LAYER_COUNT = 256;
const MAX_DECODED_PIXELS = 16_000_000;
const MAX_WORK_PIXELS = 32_000_000;
const MEMORY_LIMIT = 128 * 1024 * 1024;

const options = {
	useImageData: true,
	skipCompositeImageData: true,
	skipThumbnail: true,
	skipLinkedFilesData: true,
	totalMemoryLimit: MEMORY_LIMIT,
};

/** Read metadata before allowing ag-psd to allocate pixel buffers. */
function metadataOf( buffer: ArrayBuffer ): Psd {
	const metadata = readPsd( buffer, { ...options, useRawData: true } );
	const documentPixels = pixels( metadata.width, metadata.height );

	if ( documentPixels === 0 || documentPixels * 5 > MAX_WORK_PIXELS ) {
		throw new Error( 'PSD conversion budget exceeded.' );
	}

	if ( metadata.bitsPerChannel !== 8 ) {
		throw new Error( 'Unsupported PSD depth.' );
	}

	let decoded = 0;
	let count = 0;
	const pending = [ ...( metadata.children ?? [] ) ];

	while ( pending.length > 0 ) {
		const layer = pending.pop() as Layer;
		count++;

		if ( count > MAX_LAYER_COUNT ) {
			throw new Error( 'PSD layer limit exceeded.' );
		}

		for ( const box of [ layer, layer.mask, layer.realMask ] ) {
			if ( box !== undefined ) {
				decoded += pixels(
					( box.right ?? 0 ) - ( box.left ?? 0 ),
					( box.bottom ?? 0 ) - ( box.top ?? 0 )
				);
			}
		}

		// Conversion may produce a full document image for each layer.
		if (
			decoded > MAX_DECODED_PIXELS ||
			documentPixels * Math.max( 5, count ) > MAX_WORK_PIXELS
		) {
			throw new Error( 'PSD pixel budget exceeded.' );
		}

		pending.push( ...( layer.children ?? [] ) );
	}

	return metadata;
}

/** Validate a document, layer or mask rectangle before pixel allocation. */
function pixels( width: number, height: number ): number {
	if (
		! Number.isInteger( width ) ||
		! Number.isInteger( height ) ||
		width < 0 ||
		height < 0 ||
		width > 8000 ||
		height > 8000 ||
		width * height > 4_000_000
	) {
		throw new Error( 'PSD dimensions exceeded.' );
	}

	return width * height;
}

/** Validate ZIP output as a stream, before the decoder's eager inflate. */
export async function validatePsdCompression(
	buffer: ArrayBuffer
): Promise< void > {
	const metadata = metadataOf( buffer );
	const pending = [ ...( metadata.children ?? [] ) ];
	while ( pending.length > 0 ) {
		const layer = pending.pop() as Layer;
		const channels = layer.rawData?.channels ?? [];
		if ( channels.length > 8 ) {
			throw new Error( 'Too many PSD channels.' );
		}
		for ( const channel of channels ) {
			if ( channel.compression < 2 || channel.data === undefined ) {
				continue;
			}
			let box = channel.id === -2 ? layer.mask : layer;
			if ( channel.id === -3 ) {
				box = layer.realMask;
			}
			if ( box === undefined ) {
				throw new Error( 'Missing PSD channel rectangle.' );
			}
			const limit = pixels(
				( box.right ?? 0 ) - ( box.left ?? 0 ),
				( box.bottom ?? 0 ) - ( box.top ?? 0 )
			);
			const stream = new Blob( [ new Uint8Array( channel.data ) ] )
				.stream()
				.pipeThrough( new DecompressionStream( 'deflate' ) );
			const reader = stream.getReader();
			let length = 0;
			try {
				while ( true ) {
					const chunk = await reader.read();
					if ( chunk.done ) {
						break;
					}
					length += chunk.value.byteLength;
					if ( length > limit ) {
						throw new Error(
							'PSD compressed channel exceeds dimensions.'
						);
					}
				}
			} finally {
				await reader.cancel();
			}
			if ( length !== limit ) {
				throw new Error( 'Invalid PSD compressed channel length.' );
			}
		}
		pending.push( ...( layer.children ?? [] ) );
	}
}

/** Decode only after metadata has passed the shared limits. */
export function readBoundedPsd( buffer: ArrayBuffer ): Psd {
	metadataOf( buffer );
	return readPsd( buffer, options );
}
