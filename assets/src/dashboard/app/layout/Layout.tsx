import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { useConfig } from '../config-context';
import { WhatsNew } from '../../sections/news/WhatsNew';
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
 * The logo and/or the site name (ADR-042). An admin logo replaces the site
 * name (alt = site name); the default logo is a mark next to the site name
 * (alt = ""). Without a logo, or when the image fails to load, only the site
 * name is shown, so there is never a broken image.
 */
function Brand() {
	const { logo, siteName } = useConfig();
	const [ failed, setFailed ] = useState( false );
	const showLogo = logo !== null && ! failed;

	return (
		<div className="merchboard-topbar__brand">
			{ showLogo && (
				<span className="merchboard-logo">
					<img
						className="merchboard-logo__image"
						src={ logo.url }
						width={ logo.width }
						height={ logo.height }
						alt={ logo.alt }
						onError={ () => setFailed( true ) }
					/>
				</span>
			) }
			{ ( ! showLogo || ! logo.custom ) && (
				<p className="merchboard-topbar__site">{ siteName }</p>
			) }
		</div>
	);
}

/**
 * App frame: skip link, top bar (logo and/or site name, creator name, menu button below lg, log out),
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
				<Brand />

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
			{ config.impersonating === true && (
				<div className="merchboard-impersonation" role="status">
					<p className="merchboard-impersonation__text">
						{ creatorName !== undefined
							? sprintf(
									/* translators: %s: creator name. */
									__(
										'You view and act as %s. What you change is changed for the creator.',
										'merchboard'
									),
									creatorName
								)
							: __(
									'You view and act as the creator. What you change is changed for the creator.',
									'merchboard'
								) }
					</p>
					{ config.impersonationEndUrl !== undefined && (
						<a
							className="merchboard-impersonation__end"
							href={ config.impersonationEndUrl }
						>
							{ __(
								'Stop viewing as the creator',
								'merchboard'
							) }
						</a>
					) }
				</div>
			) }
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
			{ /* "What's new" once the creator is known (step 25.11). */ }
			{ creatorName !== undefined && <WhatsNew /> }
		</div>
	);
}
