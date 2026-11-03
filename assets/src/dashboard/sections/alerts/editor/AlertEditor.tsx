/**
 * The alert designer (alert designer 2.0, ADR-088): a top bar, the side
 * panel and the canvas. The owner's choice 2026-11-03: in the style of
 * StreamElements, not a copy. "Merch alert" is open from the start with its
 * sections below it; "Position, size and style" is at the bottom.
 *
 * Every change is one undo step (a drag is one step) and is saved
 * automatically a moment after the last one (AUTOSAVE_DELAY); invalid
 * values are marked and not saved. Saving changes the overlay's version,
 * so an open OBS source reloads with the new look.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { KeyboardEvent, ReactNode } from 'react';

import type { ApiError } from '../../../api/client';
import { ApiErrorState } from '../../../components/ApiErrorState';
import { NUMBER_FIELDS, TEMPLATE_MAX_LENGTH } from '../../../types/alerts';
import type {
	AlertFontOption,
	AlertsImages,
	AlertsOverlayState,
	NumberField,
	OverlayLayout,
	OverlaySettings,
} from '../../../types/alerts';
import { historyKey, useHistory } from '../history';
import { useOverlayApi } from '../overlay-api-context';
import { AlertCanvas } from './AlertCanvas';
import {
	AnimationSection,
	MerchAlertPanel,
	PositionPanel,
	TextSection,
} from './AlertPanels';
import type { EditorFields } from './AlertPanels';
import { lookOf, sampleParts } from './look';

/** Waiting time after the last change before it is saved (ms). */
export const AUTOSAVE_DELAY = 800;

type Errors = Partial< Record< keyof OverlaySettings, string > >;

/**
 * Client-side checks with the server's limits; the server normalizes again.
 *
 * @param values Settings.
 */
export function validateOverlaySettings( values: OverlaySettings ): Errors {
	const errors: Errors = {};

	for ( const [ field, range ] of Object.entries( NUMBER_FIELDS ) ) {
		const value = values[ field as NumberField ];

		if (
			! Number.isInteger( value ) ||
			value < range.min ||
			value > range.max
		) {
			errors[ field as NumberField ] = sprintf(
				/* translators: 1: minimum, 2: maximum. */
				__( 'Enter a whole number from %1$d to %2$d.', 'merchboard' ),
				range.min,
				range.max
			);
		}
	}

	if ( values.template.trim() === '' ) {
		errors.template = __( 'Enter a text for the alert.', 'merchboard' );
	}

	for ( const field of [ 'template', 'secondary_template' ] as const ) {
		if ( values[ field ].length > TEMPLATE_MAX_LENGTH ) {
			errors[ field ] = sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'Use at most %d characters.', 'merchboard' ),
				TEMPLATE_MAX_LENGTH
			);
		}
	}

	return errors;
}

type SaveStatus = 'idle' | 'pending' | 'saving' | 'saved' | 'invalid' | 'error';

function statusText( status: SaveStatus ): string {
	switch ( status ) {
		case 'pending':
		case 'saving':
			return __( 'Saving…', 'merchboard' );
		case 'saved':
			return __( 'All changes are saved.', 'merchboard' );
		case 'invalid':
			return __( 'Not saved: correct the marked fields.', 'merchboard' );
		case 'error':
			return __(
				'Not saved. Change a field to try again.',
				'merchboard'
			);
		default:
			return __( 'Changes are saved automatically.', 'merchboard' );
	}
}

interface Document {
	readonly settings: OverlaySettings;
	readonly layout: OverlayLayout;
}

/** Drawers of the top bar. */
type Drawer = 'source' | 'designs' | 'help' | null;

interface Props {
	readonly settings: OverlaySettings;
	readonly layout: OverlayLayout;
	readonly fonts: readonly AlertFontOption[];
	readonly fontApproved: boolean;
	readonly images: AlertsImages | null;
	/** The default sound's URL, for the preview ('' for none). */
	readonly sound: string;
	/** The sound controls (null when sounds are not available). */
	readonly sounds: ReactNode;
	/** The browser source's card (null for a standard overlay). */
	readonly browserSource: ReactNode;
	/** The saved designs' card (null outside the creator's page). */
	readonly designs: ReactNode;
	/** Cards above the editor (the global overlay's switch and replay). */
	readonly controls?: ReactNode;
	readonly onSaved: ( state: AlertsOverlayState ) => void;
	readonly notify: ( message: string ) => void;
}

/**
 * The editor.
 *
 * @param props               Props.
 * @param props.settings      The saved settings.
 * @param props.layout        The saved layout.
 * @param props.fonts         The approved fonts.
 * @param props.fontApproved  Whether the saved font is still approved.
 * @param props.images        Images a text can be filled with.
 * @param props.sound         The default sound's URL.
 * @param props.sounds        The sound controls.
 * @param props.browserSource The browser source's card.
 * @param props.designs       The saved designs' card.
 * @param props.controls      Cards above the editor.
 * @param props.onSaved       Called with the answer after saving.
 * @param props.notify        Shows a short message.
 */
export function AlertEditor( {
	settings,
	layout,
	fonts,
	fontApproved,
	images,
	sound,
	sounds,
	browserSource,
	designs,
	controls,
	onSaved,
	notify,
}: Props ) {
	const api = useOverlayApi();
	const doc = useHistory< Document >( { settings, layout } );
	const values = doc.value.settings;
	const [ status, setStatus ] = useState< SaveStatus >( 'idle' );
	const [ errors, setErrors ] = useState< Errors >( {} );
	const [ saveError, setSaveError ] = useState< ApiError | null >( null );
	const [ drawer, setDrawer ] = useState< Drawer >( null );
	const [ testing, setTesting ] = useState( false );
	const [ playRequest, setPlayRequest ] = useState( 0 );
	const saved = useRef( JSON.stringify( { settings, layout } ) );
	const latest = useRef( doc.value );
	const saving = useRef( false );
	const timer = useRef< number | null >( null );
	const saveRef = useRef< () => Promise< void > >( async () => undefined );

	latest.current = doc.value;

	const fields: EditorFields = {
		values,
		layout: doc.value.layout,
		set: ( change ) =>
			doc.set( ( current ) => ( {
				...current,
				settings: { ...current.settings, ...change },
			} ) ),
		setBoth: ( nextSettings, nextLayout ) =>
			doc.set( { settings: nextSettings, layout: nextLayout } ),
	};

	const schedule = useCallback( ( delay: number ) => {
		if ( timer.current !== null ) {
			window.clearTimeout( timer.current );
		}

		timer.current = window.setTimeout( () => {
			timer.current = null;
			void saveRef.current();
		}, delay );
	}, [] );

	saveRef.current = async () => {
		const current = latest.current;
		const key = JSON.stringify( current );

		if ( key === saved.current ) {
			return;
		}

		const clientErrors = validateOverlaySettings( current.settings );
		setErrors( clientErrors );

		if ( Object.keys( clientErrors ).length > 0 ) {
			setStatus( 'invalid' );
			return;
		}

		if ( saving.current ) {
			schedule( AUTOSAVE_DELAY );
			return;
		}

		// A font that is no longer approved is kept until another is picked;
		// whether sounds play is the sound controls' own.
		const unavailable =
			current.settings.font_family !== 'inherit' &&
			! fonts.some(
				( font ) => font.family === current.settings.font_family
			);
		const {
			font_family: fontFamily,
			audio_enabled: audioEnabled,
			...rest
		} = current.settings;

		void audioEnabled;
		saving.current = true;
		setStatus( 'saving' );
		setSaveError( null );
		const result = await api.update( {
			...rest,
			...( unavailable ? {} : { font_family: fontFamily } ),
			layout: current.layout,
		} );
		saving.current = false;

		if ( ! result.ok ) {
			setSaveError( result.error );
			setStatus( 'error' );
			return;
		}

		saved.current = key;

		// The server's values (normalized) when nothing changed meanwhile.
		if (
			result.data.overlay !== null &&
			JSON.stringify( latest.current ) === key
		) {
			const normalized = {
				settings: result.data.overlay.settings,
				layout: result.data.overlay.layout,
			};
			saved.current = JSON.stringify( normalized );
			doc.live( normalized );
		}

		onSaved( result.data );
		setStatus( 'saved' );
	};

	useEffect( () => {
		if ( JSON.stringify( doc.value ) !== saved.current ) {
			setStatus( 'pending' );
			schedule( AUTOSAVE_DELAY );
		}
	}, [ doc.value, schedule ] );

	// Leaving the section saves a change that still waits.
	useEffect(
		() => () => {
			if ( timer.current !== null ) {
				window.clearTimeout( timer.current );
				void saveRef.current();
			}
		},
		[]
	);

	const test = async () => {
		setTesting( true );
		const result = await api.sendTest();
		setTesting( false );
		notify(
			result.ok
				? __(
						'Test alert sent. It appears in your open overlay within a few seconds.',
						'merchboard'
					)
				: __( 'The test alert could not be sent.', 'merchboard' )
		);
	};

	// Ctrl+Z and Ctrl+Y (or Ctrl+Shift+Z) outside text fields.
	const keys = ( event: KeyboardEvent< HTMLDivElement > ) => {
		const action = historyKey( event );

		if (
			action === null ||
			( event.target as HTMLElement ).matches( 'input, textarea, select' )
		) {
			return;
		}

		event.preventDefault();

		if ( action === 'undo' ) {
			doc.undo();
		} else {
			doc.redo();
		}
	};

	const look = useMemo(
		() => lookOf( values, doc.value.layout, images ),
		[ values, doc.value.layout, images ]
	);
	const sample = useMemo(
		() => ( {
			parts: sampleParts(
				values.template,
				values.number_format,
				values.max_name_length
			),
			secondaryParts: sampleParts(
				values.secondary_template,
				values.number_format,
				values.max_name_length
			),
		} ),
		[
			values.template,
			values.secondary_template,
			values.number_format,
			values.max_name_length,
		]
	);

	const drawerButton = ( which: Exclude< Drawer, null >, label: string ) => (
		<button
			type="button"
			className="merchboard-alert-editor__bar-button"
			aria-expanded={ drawer === which }
			onClick={ () => setDrawer( drawer === which ? null : which ) }
		>
			{ label }
		</button>
	);

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions -- Undo and redo for the whole editor; every control has its own keys.
		<div className="merchboard-alert-editor" onKeyDown={ keys }>
			{ controls }
			<div className="merchboard-alert-editor__bar">
				<h2 className="merchboard-alert-editor__title">
					{ __( 'Alert designer', 'merchboard' ) }
				</h2>
				<div className="merchboard-alert-editor__bar-actions">
					{ drawerButton( 'help', __( 'Help', 'merchboard' ) ) }
					{ browserSource !== null &&
						drawerButton(
							'source',
							__( 'Browser source', 'merchboard' )
						) }
					{ designs !== null &&
						drawerButton(
							'designs',
							__( 'Designs', 'merchboard' )
						) }
					<p
						className="merchboard-alert-editor__status"
						role="status"
						aria-live="polite"
					>
						{ statusText( status ) }
					</p>
				</div>
			</div>
			{ drawer !== null && (
				<div className="merchboard-alert-editor__drawer">
					{ drawer === 'help' && (
						<div className="merchboard-alert-editor__help">
							<p>
								{ __(
									'Design the alert on the right: drag the box to place it and its corners to size it. Change what it shows and how in the panel on the left. Everything is saved automatically, and your open overlay reloads with the new look.',
									'merchboard'
								) }
							</p>
							<p>
								{ __(
									'Play shows the alert here with its animations; Test alert sends one to your overlay in OBS.',
									'merchboard'
								) }
							</p>
						</div>
					) }
					{ drawer === 'source' && browserSource }
					{ drawer === 'designs' && designs }
				</div>
			) }
			{ saveError !== null && <ApiErrorState error={ saveError } /> }
			{ Object.keys( errors ).length > 0 && (
				<ul className="merchboard-alert-editor__errors" role="alert">
					{ Object.values( errors ).map( ( message ) => (
						<li key={ message }>{ message }</li>
					) ) }
				</ul>
			) }
			<div className="merchboard-alert-editor__main">
				<aside
					className="merchboard-alert-editor__side"
					aria-label={ __( 'Alert settings', 'merchboard' ) }
				>
					<MerchAlertPanel fields={ fields } sounds={ sounds }>
						<TextSection
							fields={ fields }
							fonts={ fonts }
							fontApproved={ fontApproved }
						/>
						<AnimationSection
							fields={ fields }
							images={ images }
							onSaved={ onSaved }
							notify={ notify }
							onPlay={ () =>
								setPlayRequest( ( count ) => count + 1 )
							}
						/>
					</MerchAlertPanel>
					<PositionPanel fields={ fields } />
				</aside>
				<section
					className="merchboard-alert-editor__stage"
					aria-label={ __( 'Canvas', 'merchboard' ) }
				>
					<AlertCanvas
						settings={ values }
						layout={ doc.value.layout }
						look={ look }
						sample={ sample }
						sound={ sound }
						onBegin={ doc.checkpoint }
						onLive={ ( nextSettings, nextLayout ) =>
							doc.live( {
								settings: nextSettings,
								layout: nextLayout,
							} )
						}
						onEnd={ doc.settle }
						onChange={ ( nextSettings, nextLayout ) =>
							doc.set( {
								settings: nextSettings,
								layout: nextLayout,
							} )
						}
						canUndo={ doc.canUndo }
						canRedo={ doc.canRedo }
						onUndo={ doc.undo }
						onRedo={ doc.redo }
						onTest={ () => void test() }
						testing={ testing }
						playRequest={ playRequest }
					/>
				</section>
			</div>
		</div>
	);
}
