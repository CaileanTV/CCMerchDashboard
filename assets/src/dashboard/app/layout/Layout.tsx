import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { useConfig } from '../config-context';
import { Navigation } from './Navigation';

export interface LayoutProps {
	/** Page title, rendered as the page's only h1. */
	readonly title: string;
	/** The creator's display name from `GET /me`, once known. */
	readonly creatorName?: string;
	readonly children: ReactNode;
}

const MOBILE_NAV_ID = 'merchboard-mobile-nav';
const MAIN_ID = 'merchboard-main';

/**
 * App frame: skip link, top bar (site name, creator name, menu button below lg, log out),
 * sidebar (lg and up), off-canvas navigation (below lg) and the main region.
 */
export function Layout( { title, creatorName, children }: LayoutProps ) {
	const config = useConfig();
	const [ menuOpen, setMenuOpen ] = useState( false );
	const menuButton = useRef< HTMLButtonElement >( null );

	useEffect( () => {
		if ( ! menuOpen ) {
			return undefined;
		}

		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				setMenuOpen( false );
				menuButton.current?.focus();
			}
		};
		document.addEventListener( 'keydown', onKeyDown );

		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ menuOpen ] );

	const closeMenu = () => setMenuOpen( false );

	return (
		<div className="merchboard-layout">
			<a className="merchboard-skip-link" href={ `#${ MAIN_ID }` }>
				{ __( 'Skip to content', 'merchboard' ) }
			</a>
			<header className="merchboard-topbar">
				<button
					ref={ menuButton }
					type="button"
					className="merchboard-topbar__menu-button"
					aria-expanded={ menuOpen }
					aria-controls={ MOBILE_NAV_ID }
					onClick={ () => setMenuOpen( ( open ) => ! open ) }
				>
					{ menuOpen
						? __( 'Close menu', 'merchboard' )
						: __( 'Menu', 'merchboard' ) }
				</button>
				<p className="merchboard-topbar__site">{ config.siteName }</p>
				{ creatorName !== undefined && (
					<p className="merchboard-topbar__creator">
						{ creatorName }
					</p>
				) }
				<a
					className="merchboard-topbar__logout"
					href={ config.logoutUrl }
				>
					{ __( 'Log out', 'merchboard' ) }
				</a>
			</header>
			<nav
				id={ MOBILE_NAV_ID }
				className="merchboard-mobile-nav"
				aria-label={ __( 'Dashboard', 'merchboard' ) }
				hidden={ ! menuOpen }
			>
				<Navigation
					sections={ config.sections }
					onNavigate={ closeMenu }
				/>
			</nav>
			<div className="merchboard-layout__body">
				<nav
					className="merchboard-sidebar"
					aria-label={ __( 'Dashboard', 'merchboard' ) }
				>
					<Navigation sections={ config.sections } />
				</nav>
				<main
					id={ MAIN_ID }
					className="merchboard-main"
					tabIndex={ -1 }
				>
					<h1 className="merchboard-main__title">{ title }</h1>
					{ children }
				</main>
			</div>
		</div>
	);
}
