import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { getNotifications, markNotificationsRead } from '../api/notifications';
import type { NotificationItem } from '../api/notifications';
import { useConfig } from '../app/config-context';
import { Link } from '../app/router';

/** Notifications per page of the panel. */
const PER_PAGE = 10;

/** How often the unread count is refreshed while the tab is visible. */
const POLL_MS = 60_000;

const PANEL_ID = 'merchboard-notifications-panel';

/**
 * Date and time of a notification in the dashboard's language.
 *
 * @param iso    ISO 8601 time.
 * @param locale WordPress locale, e.g. "da_DK".
 */
export function notificationTime( iso: string, locale: string ): string {
	try {
		return new Intl.DateTimeFormat( locale.replace( '_', '-' ), {
			dateStyle: 'medium',
			timeStyle: 'short',
		} ).format( new Date( iso ) );
	} catch {
		return iso.slice( 0, 16 ).replace( 'T', ' ' );
	}
}

/**
 * The bell in the top bar (phase 29, ADR-079): a red bubble with the number
 * of unread notifications (9+ above 9), refreshed every minute while the tab
 * is visible. Opening it lists the notifications as plain text lines, newest
 * first, each linking to the page it is about; the ones shown are marked as
 * read (the server leaves them unread while an administrator views as the
 * creator). Any failure is quiet: the dashboard works without the bell.
 */
export function NotificationBell() {
	const { locale } = useConfig();
	const [ unread, setUnread ] = useState( 0 );
	const [ open, setOpen ] = useState( false );
	const [ items, setItems ] = useState< readonly NotificationItem[] >( [] );
	const [ page, setPage ] = useState( 1 );
	const [ pages, setPages ] = useState( 1 );
	const [ state, setState ] = useState< 'idle' | 'loading' | 'error' >(
		'idle'
	);
	const root = useRef< HTMLDivElement >( null );
	const button = useRef< HTMLButtonElement >( null );

	const refreshCount = useCallback( ( signal?: AbortSignal ) => {
		void getNotifications( 1, 1, signal ).then( ( result ) => {
			if ( result.ok ) {
				setUnread( result.data.unread );
			}
		} );
	}, [] );

	useEffect( () => {
		const controller = new AbortController();
		refreshCount( controller.signal );

		const timer = window.setInterval( () => {
			if ( document.visibilityState === 'visible' ) {
				refreshCount( controller.signal );
			}
		}, POLL_MS );
		const onVisible = () => {
			if ( document.visibilityState === 'visible' ) {
				refreshCount( controller.signal );
			}
		};
		document.addEventListener( 'visibilitychange', onVisible );

		return () => {
			controller.abort();
			window.clearInterval( timer );
			document.removeEventListener( 'visibilitychange', onVisible );
		};
	}, [ refreshCount ] );

	/**
	 * Loads a page into the panel and marks its unread lines as read. The
	 * lines keep their unread look until the panel closes.
	 */
	const load = useCallback( async ( next: number ) => {
		setState( 'loading' );
		const result = await getNotifications( next, PER_PAGE );

		if ( ! result.ok ) {
			setState( 'error' );
			return;
		}

		setItems( ( current ) =>
			next === 1
				? result.data.items
				: [ ...current, ...result.data.items ]
		);
		setPage( next );
		setPages( result.data.pages );
		setUnread( result.data.unread );
		setState( 'idle' );

		const fresh = result.data.items
			.filter( ( item ) => ! item.read )
			.map( ( item ) => item.id );

		if ( fresh.length > 0 ) {
			const marked = await markNotificationsRead( fresh );

			if ( marked.ok ) {
				setUnread( marked.data );
			}
		}
	}, [] );

	const close = useCallback( ( focus: boolean ) => {
		setOpen( false );

		if ( focus ) {
			button.current?.focus();
		}
	}, [] );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}

		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				close( true );
			}
		};
		const onPointerDown = ( event: MouseEvent ) => {
			if (
				event.target instanceof Node &&
				! root.current?.contains( event.target )
			) {
				close( false );
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		document.addEventListener( 'mousedown', onPointerDown );

		return () => {
			document.removeEventListener( 'keydown', onKeyDown );
			document.removeEventListener( 'mousedown', onPointerDown );
		};
	}, [ open, close ] );

	const toggle = () => {
		if ( open ) {
			close( false );
			return;
		}

		setOpen( true );
		void load( 1 );
	};

	const markAll = () => {
		void markNotificationsRead( 'all' ).then( ( result ) => {
			if ( result.ok ) {
				setUnread( result.data );
				setItems( ( current ) =>
					current.map( ( item ) => ( { ...item, read: true } ) )
				);
			}
		} );
	};

	const label =
		unread === 0
			? __( 'Notifications', 'merchboard' )
			: sprintf(
					/* translators: %d: number of unread notifications. */
					_n(
						'Notifications, %d unread',
						'Notifications, %d unread',
						unread,
						'merchboard'
					),
					unread
				);

	return (
		<div className="merchboard-bell" ref={ root }>
			<button
				ref={ button }
				type="button"
				className="merchboard-bell__button"
				aria-label={ label }
				aria-expanded={ open }
				aria-controls={ open ? PANEL_ID : undefined }
				onClick={ toggle }
			>
				<svg
					className="merchboard-bell__icon"
					viewBox="0 0 24 24"
					width="22"
					height="22"
					aria-hidden="true"
					focusable="false"
				>
					<path
						d="M12 3a6 6 0 0 0-6 6v3.6l-1.7 2.9A1 1 0 0 0 5.2 17h13.6a1 1 0 0 0 .9-1.5L18 12.6V9a6 6 0 0 0-6-6Zm-2.5 15.5a2.5 2.5 0 0 0 5 0"
						fill="none"
						stroke="currentColor"
						strokeWidth="1.8"
						strokeLinecap="round"
						strokeLinejoin="round"
					/>
				</svg>
				{ unread > 0 && (
					<span className="merchboard-bell__badge" aria-hidden="true">
						{ unread > 9 ? '9+' : String( unread ) }
					</span>
				) }
			</button>
			{ open && (
				<div id={ PANEL_ID } className="merchboard-bell__panel">
					<div className="merchboard-bell__header">
						<h2 className="merchboard-bell__title">
							{ __( 'Notifications', 'merchboard' ) }
						</h2>
						{ unread > 0 && (
							<button
								type="button"
								className="merchboard-bell__action"
								onClick={ markAll }
							>
								{ __( 'Mark all as read', 'merchboard' ) }
							</button>
						) }
					</div>
					{ items.length > 0 && (
						<ul className="merchboard-bell__list">
							{ items.map( ( item ) => (
								<li
									key={ item.id }
									className={
										item.read
											? 'merchboard-bell__item'
											: 'merchboard-bell__item merchboard-bell__item--unread'
									}
								>
									<Link
										to={ item.link }
										className="merchboard-bell__link"
										onNavigate={ () => close( false ) }
									>
										<span className="merchboard-bell__text">
											{ item.text }
										</span>
										<span className="merchboard-bell__time">
											{ notificationTime(
												item.createdAt,
												locale
											) }
										</span>
									</Link>
								</li>
							) ) }
						</ul>
					) }
					{ state === 'idle' && items.length === 0 && (
						<p className="merchboard-bell__empty">
							{ __(
								'You have no notifications yet.',
								'merchboard'
							) }
						</p>
					) }
					{ state === 'loading' && (
						<p className="merchboard-bell__empty" role="status">
							{ __( 'Loading…', 'merchboard' ) }
						</p>
					) }
					{ state === 'error' && (
						<p className="merchboard-bell__empty" role="alert">
							{ __(
								'The notifications could not be loaded. Try again later.',
								'merchboard'
							) }
						</p>
					) }
					{ state === 'idle' && page < pages && (
						<button
							type="button"
							className="merchboard-bell__action merchboard-bell__more"
							onClick={ () => void load( page + 1 ) }
						>
							{ __( 'Show more', 'merchboard' ) }
						</button>
					) }
				</div>
			) }
		</div>
	);
}
