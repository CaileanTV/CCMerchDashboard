/**
 * Suggestions for the WordPress user field on the creator form (phase 10).
 *
 * Progressive enhancement: the field works without this script (type the
 * login). Suggestions come from admin-ajax.php (action merchboard_search_users,
 * nonce and capability checked server-side) and are rendered as <datalist>
 * options with textContent only, never as HTML. No build step and no
 * dependencies.
 */
( function () {
	const config = window.merchboardUserSearch;
	const input = document.getElementById( 'merchboard-user' );
	const list = document.getElementById( 'merchboard-user-options' );

	if ( ! config || ! input || ! list ) {
		return;
	}

	let timer = null;
	let controller = null;

	/**
	 * Replaces the suggestions.
	 *
	 * @param {Array<{login: string, label: string}>} users Suggested users.
	 */
	function render( users ) {
		list.replaceChildren(
			...users.map( ( user ) => {
				const option = document.createElement( 'option' );
				option.value = user.login;
				option.label = user.label;
				option.textContent = user.label;
				return option;
			} )
		);
	}

	/**
	 * Fetches suggestions for the current field value.
	 */
	function search() {
		const term = input.value.trim();

		if ( term.length < 2 ) {
			render( [] );
			return;
		}

		if ( controller ) {
			controller.abort();
		}

		controller = new window.AbortController();

		const url = new window.URL( config.url );
		url.searchParams.set( 'action', config.action );
		url.searchParams.set( 'nonce', config.nonce );
		url.searchParams.set( 'term', term );

		window
			.fetch( url.toString(), {
				credentials: 'same-origin',
				signal: controller.signal,
			} )
			.then( ( response ) => ( response.ok ? response.json() : null ) )
			.then( ( body ) => {
				render( body && body.success ? body.data : [] );
			} )
			.catch( () => {
				// Aborted or offline: keep typing the login manually.
			} );
	}

	input.addEventListener( 'input', () => {
		window.clearTimeout( timer );
		timer = window.setTimeout( search, 250 );
	} );
} )();
