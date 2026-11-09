/**
 * Image pickers on Merch Dashboard → Settings → Branding (phase 41, ADR-103).
 *
 * Progressive enhancement: a button with `data-merchboard-image="<field id>"`
 * opens WordPress' Media Library modal (PNG, JPEG and WebP only) and puts the
 * chosen attachment id into the hidden field; `data-merchboard-image-clear`
 * empties it. The preview changes only through `src` and `style.display`,
 * never as HTML. The server validates every image again when the form is
 * saved. No build step and no dependencies beyond WordPress' media script.
 */
( function () {
	const config = window.merchboardImages;

	if ( ! config || ! window.wp || ! window.wp.media ) {
		return;
	}

	const frames = {};

	/**
	 * The hidden field and preview of a picker.
	 *
	 * @param {string} id Field id.
	 * @return {{field: HTMLInputElement|null, preview: HTMLImageElement|null}} Elements.
	 */
	function elements( id ) {
		return {
			field: document.getElementById( id ),
			preview: document.getElementById( id + '-preview' ),
		};
	}

	document
		.querySelectorAll( '[data-merchboard-image]' )
		.forEach( function ( button ) {
			const id = button.getAttribute( 'data-merchboard-image' );

			button.addEventListener( 'click', function () {
				if ( ! frames[ id ] ) {
					frames[ id ] = window.wp.media( {
						title: config.title,
						button: { text: config.button },
						library: { type: config.types },
						multiple: false,
					} );

					frames[ id ].on( 'select', function () {
						const attachment = frames[ id ]
							.state()
							.get( 'selection' )
							.first()
							.toJSON();
						const target = elements( id );

						if (
							! target.field ||
							! attachment ||
							! Number.isInteger( attachment.id ) ||
							config.types.indexOf( attachment.mime ) === -1
						) {
							return;
						}

						target.field.value = String( attachment.id );

						if (
							target.preview &&
							typeof attachment.url === 'string'
						) {
							target.preview.src = attachment.url;
							target.preview.style.display = '';
						}
					} );
				}

				frames[ id ].open();
			} );
		} );

	document
		.querySelectorAll( '[data-merchboard-image-clear]' )
		.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				const target = elements(
					button.getAttribute( 'data-merchboard-image-clear' )
				);

				if ( target.field ) {
					target.field.value = '0';
				}

				if ( target.preview ) {
					target.preview.removeAttribute( 'src' );
					target.preview.style.display = 'none';
				}
			} );
		} );
} )();
