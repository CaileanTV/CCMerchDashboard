/**
 * Entry point of the standard overlays tab (Merch Dashboard → Alerts →
 * Standard overlays, step 25.9.5; build/alerts-presets-admin.js): the list
 * (make, rename, delete) and the chosen standard overlay's settings form and
 * designer, the creator dashboard's own cards with the standard overlay's
 * calls.
 */
import { createRoot, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	createPreset,
	deletePreset,
	listPresets,
	presetApi,
	renamePreset,
} from '../dashboard/api/alerts-presets';
import type { PresetList } from '../dashboard/api/alerts-presets';
import type { ApiError, ApiResult } from '../dashboard/api/client';
import { ApiErrorState } from '../dashboard/components/ApiErrorState';
import { Button } from '../dashboard/components/Button';
import { Card } from '../dashboard/components/Card';
import { Field } from '../dashboard/components/Field';
import { Skeleton } from '../dashboard/components/Skeleton';
import { useApi } from '../dashboard/hooks/useApi';
import { AlertsContent } from '../dashboard/sections/alerts/AlertsSection';
import { OverlayApiContext } from '../dashboard/sections/alerts/overlay-api-context';
import '../dashboard/styles/index.scss';

/**
 * One standard overlay's settings form and designer.
 *
 * @param props    Props.
 * @param props.id The standard overlay's id.
 */
function PresetEditor( { id }: { readonly id: string } ) {
	const api = useMemo( () => presetApi( id ), [ id ] );
	const { state, retry } = useApi( api.get );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading the standard overlay…', 'merchboard' ) }
				lines={ 4 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return (
		<OverlayApiContext.Provider value={ api }>
			<AlertsContent initial={ state.data } variant="preset" />
		</OverlayApiContext.Provider>
	);
}

/**
 * The list of standard overlays and the chosen one's editor.
 *
 * @param props         Props.
 * @param props.initial The list as loaded.
 */
export function PresetsManager( {
	initial,
}: {
	readonly initial: PresetList;
} ) {
	const [ list, setList ] = useState( initial );
	const [ editing, setEditing ] = useState< string | null >(
		initial.presets[ 0 ]?.id ?? null
	);
	const [ name, setName ] = useState( '' );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );

	const run = async (
		key: string,
		call: () => Promise< ApiResult< PresetList > >,
		after?: ( next: PresetList ) => void
	) => {
		setBusy( key );
		setError( null );
		const result = await call();
		setBusy( null );

		if ( result.ok ) {
			setList( result.data );
			after?.( result.data );
		} else {
			setError( result.error );
		}
	};

	const create = () =>
		run(
			'create',
			() => createPreset( name ),
			( next ) => {
				setName( '' );
				setEditing(
					next.presets[ next.presets.length - 1 ]?.id ?? null
				);
			}
		);

	const rename = ( id: string, current: string ) => {
		// eslint-disable-next-line no-alert -- A short name is asked for in place.
		const next = window.prompt( __( 'New name', 'merchboard' ), current );

		if ( next !== null && next.trim() !== '' && next !== current ) {
			void run( `rename-${ id }`, () => renamePreset( id, next ) );
		}
	};

	const remove = ( id: string, current: string ) => {
		if (
			// eslint-disable-next-line no-alert -- Deleting needs an explicit confirmation.
			! window.confirm(
				sprintf(
					/* translators: %s: name of the standard overlay. */
					__(
						'Delete "%s"? Creators who copied it keep their copy.',
						'merchboard'
					),
					current
				)
			)
		) {
			return;
		}

		void run(
			`delete-${ id }`,
			() => deletePreset( id ),
			( next ) => {
				if ( editing === id ) {
					setEditing( next.presets[ 0 ]?.id ?? null );
				}
			}
		);
	};

	return (
		<div className="merchboard-alerts">
			<Card title={ __( 'Standard overlays', 'merchboard' ) }>
				{ error !== null && <ApiErrorState error={ error } /> }
				{ list.presets.length === 0 ? (
					<p>
						{ __(
							'No standard overlays yet. Make one below; creators see it as soon as it exists.',
							'merchboard'
						) }
					</p>
				) : (
					<ul className="merchboard-presets__list">
						{ list.presets.map( ( preset ) => (
							<li key={ preset.id }>
								<strong>{ preset.name }</strong>{ ' ' }
								<Button
									variant={
										editing === preset.id
											? 'primary'
											: 'secondary'
									}
									aria-pressed={ editing === preset.id }
									onClick={ () => setEditing( preset.id ) }
								>
									{ __( 'Design', 'merchboard' ) }
								</Button>{ ' ' }
								<Button
									variant="ghost"
									onClick={ () =>
										rename( preset.id, preset.name )
									}
									isBusy={ busy === `rename-${ preset.id }` }
								>
									{ __( 'Rename', 'merchboard' ) }
								</Button>{ ' ' }
								<Button
									variant="danger"
									onClick={ () =>
										remove( preset.id, preset.name )
									}
									isBusy={ busy === `delete-${ preset.id }` }
								>
									{ __( 'Delete', 'merchboard' ) }
								</Button>
							</li>
						) ) }
					</ul>
				) }
				{ list.presets.length < list.max && (
					<div className="merchboard-alerts__actions">
						<Field
							label={ __(
								'Name of a new standard overlay',
								'merchboard'
							) }
						>
							{ ( control ) => (
								<input
									{ ...control }
									type="text"
									className="merchboard-input"
									maxLength={ 60 }
									value={ name }
									onChange={ ( event ) =>
										setName( event.target.value )
									}
								/>
							) }
						</Field>
						<Button
							onClick={ create }
							isBusy={ busy === 'create' }
							disabled={ name.trim() === '' }
						>
							{ __( 'Make standard overlay', 'merchboard' ) }
						</Button>
					</div>
				) }
			</Card>
			{ editing !== null && (
				<PresetEditor key={ editing } id={ editing } />
			) }
		</div>
	);
}

/**
 * The tab's content.
 */
export function PresetsAdmin() {
	const { state, retry } = useApi( listPresets );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading the standard overlays…', 'merchboard' ) }
				lines={ 3 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return <PresetsManager initial={ state.data } />;
}

const root = document.getElementById( 'merchboard-presets-root' );

if ( root !== null ) {
	createRoot( root ).render( <PresetsAdmin /> );
}
