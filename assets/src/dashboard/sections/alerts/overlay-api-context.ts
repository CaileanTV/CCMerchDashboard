/**
 * Which overlay the alert cards change. The creator dashboard uses the
 * creator's own overlay (the default); the admin page's global overlay
 * (step 25.9.4) provides its own calls, so the same cards never touch a
 * creator's overlay there.
 */
import { createContext, useContext } from '@wordpress/element';

import { creatorOverlayApi } from '../../api/alerts';
import type { OverlayApi } from '../../api/alerts';

export const OverlayApiContext =
	createContext< OverlayApi >( creatorOverlayApi );

/**
 * The overlay calls of the cards' owner.
 */
export function useOverlayApi(): OverlayApi {
	return useContext( OverlayApiContext );
}
