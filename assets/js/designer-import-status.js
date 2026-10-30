/**
 * Keeps the status of a base product's catalog import up to date (review 30.15).
 *
 * Progressive enhancement: without this script the status shows when the
 * page is reloaded. The status comes from admin-ajax.php (action
 * merchboard_base_import_status, nonce and capability checked server-side)
 * as HTML the server has escaped. When the import has ended, the page is
 * reloaded to show the sides. No build step and no dependencies.
 */
( function () {
	const config = window.merchboardImportStatus;
	const panel = document.getElementById( 'merchboard-import-status' );
	const INTERVAL = 10000;

	if ( ! config || ! panel ) {
		return;
	}

	/**
	 * Fetches the status and looks again unless the import has ended.
	 */
	async function update() {
		const query = new URLSearchParams( {
			action: config.action,
			nonce: config.nonce,
			base: config.base,
		} );

		try {
			const response = await fetch( `${ config.url }?${ query }`, {
				credentials: 'same-origin',
			} );
			const answer = await response.json();

			if ( answer.success ) {
				panel.innerHTML = answer.data.html;

				if ( 'running' !== answer.data.state ) {
					window.location.reload();

					return;
				}
			}
		} catch {
			// A failed look is tried again at the next interval.
		}

		window.setTimeout( update, INTERVAL );
	}

	window.setTimeout( update, INTERVAL );
} )();
