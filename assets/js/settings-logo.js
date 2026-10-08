/**
 * Logo picker on Merch Dashboard → Settings (phase 21, ADR-042).
 *
 * Progressive enhancement: opens WordPress' Media Library modal, limited to
 * PNG, JPEG and WebP, and puts the chosen attachment id into the hidden form
 * field. The preview is updated through `src` only and the status through
 * textContent, never as HTML. The server validates the attachment again when
 * the form is saved. No build step and no dependencies beyond WordPress' own
 * media script.
 */
( function () {
	const config = window.merchboardLogo;
	const button = document.getElementById( 'merchboard-logo-select' );
	const field = document.getElementById( 'merchboard-logo-id' );
	const status = document.getElementById( 'merchboard-logo-status' );

	if (
		! config ||
		! button ||
		! field ||
		! status ||
		! window.wp ||
		! window.wp.media
	) {
		return;
	}

	let frame = null;

	/**
	 * Shows the chosen image in the preview.
	 *
	 * @param {string} url Image URL from the Media Library.
	 */
	function preview( url ) {
		let image = document.getElementById( 'merchboard-logo-preview' );

		if ( ! image ) {
			image = document.createElement( 'img' );
			image.id = 'merchboard-logo-preview';
			image.height = 64;
			image.alt = '';
			const wrapper = document.createElement( 'p' );
			wrapper.appendChild( image );
			status.parentNode.insertBefore( wrapper, status );
		}

		image.src = url;
	}

	button.addEventListener( 'click', function () {
		if ( ! frame ) {
			frame = window.wp.media( {
				title: config.title,
				button: { text: config.button },
				library: { type: config.types },
				multiple: false,
			} );

			frame.on( 'select', function () {
				const attachment = frame
					.state()
					.get( 'selection' )
					.first()
					.toJSON();

				if (
					! attachment ||
					! Number.isInteger( attachment.id ) ||
					config.types.indexOf( attachment.mime ) === -1
				) {
					return;
				}

				field.value = String( attachment.id );

				if ( typeof attachment.url === 'string' ) {
					preview( attachment.url );
				}

				status.textContent = config.selected;
			} );
		}

		frame.open();
	} );
} )();
