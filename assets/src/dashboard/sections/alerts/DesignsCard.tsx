import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	applyDesign,
	deleteDesign,
	getDesigns,
	renameDesign,
	saveDesign,
	updateDesign,
} from '../../api/alerts-designs';
import type { DesignList } from '../../api/alerts-designs';
import { applyAlertPreset, getAlertPresets } from '../../api/alerts-presets';
import type { ApiError, ApiResult } from '../../api/client';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { useApi } from '../../hooks/useApi';
import type {
	AlertFontOption,
	AlertsImages,
	AlertsOverlayState,
	OverlayLayout,
	OverlaySettings,
} from '../../types/alerts';
import { OverlayDesigner } from './OverlayDesigner';
import { useFontStylesheet } from './useFontStylesheet';

interface Props {
	readonly fonts: readonly AlertFontOption[];
	readonly onApplied: ( state: AlertsOverlayState ) => void;
	readonly notify: ( message: string ) => void;
}

/** A design to choose: the creator's own or a standard overlay. */
interface Choice {
	readonly key: string;
	readonly kind: 'own' | 'standard';
	readonly id: string;
	readonly name: string;
	readonly settings: OverlaySettings;
	readonly layout: OverlayLayout;
	readonly images: AlertsImages | null;
}

/**
 * "Designs", at the top of the overlay settings: the creator saves the
 * overlay's current design under a name (look, texts and sounds; at most
 * DesignList.max, only theirs) and takes one into use again later, e.g. one
 * per part of a stream; the administrator's standard overlays are offered
 * too (their look and texts only, step 25.9.5). The chosen design is
 * previewed; taking one into use replaces the overlay's design and keeps
 * the URL.
 *
 * @param props           Props.
 * @param props.fonts     Approved fonts (for the preview).
 * @param props.onApplied Called with the overlay after a design was taken into use.
 * @param props.notify    Shows a message in the section's toasts.
 */
export function DesignsCard( { fonts, onApplied, notify }: Props ) {
	const own = useApi( getDesigns );
	const standard = useApi( getAlertPresets );
	const [ list, setList ] = useState< DesignList | null >( null );
	const [ chosen, setChosen ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const designs =
		list ?? ( own.state.status === 'success' ? own.state.data : null );
	const presets =
		standard.state.status === 'success' ? standard.state.data : [];
	const choices: Choice[] = [
		...( designs?.designs ?? [] ).map( ( design ): Choice => ( {
			key: `own:${ design.id }`,
			kind: 'own',
			id: design.id,
			name: design.name,
			settings: design.settings,
			layout: design.layout,
			images: design.images,
		} ) ),
		...presets.map( ( preset ): Choice => ( {
			key: `standard:${ preset.id }`,
			kind: 'standard',
			id: preset.id,
			name: preset.name,
			settings: preset.settings,
			layout: preset.layout,
			images: preset.images,
		} ) ),
	];
	const choice =
		choices.find( ( candidate ) => candidate.key === chosen ) ??
		choices[ 0 ] ??
		null;
	const font =
		choice === null
			? undefined
			: fonts.find(
					( option ) => option.family === choice.settings.font_family
				);

	useFontStylesheet( font?.url ?? null );

	if ( designs === null ) {
		return null;
	}

	const change = async (
		key: string,
		call: () => Promise< ApiResult< DesignList > >,
		message: string,
		after?: ( next: DesignList ) => void
	) => {
		setBusy( key );
		setError( null );
		const result = await call();
		setBusy( null );

		if ( result.ok ) {
			setList( result.data );
			after?.( result.data );
			notify( message );
		} else {
			setError( result.error );
		}
	};

	const save = () =>
		change(
			'save',
			() => saveDesign( name ),
			__( 'Design saved.', 'merchboard' ),
			( next ) => {
				setName( '' );
				const added = next.designs[ next.designs.length - 1 ];

				if ( added !== undefined ) {
					setChosen( `own:${ added.id }` );
				}
			}
		);

	const apply = async () => {
		if ( choice === null ) {
			return;
		}

		if (
			// eslint-disable-next-line no-alert -- Replacing the design needs an explicit confirmation.
			! window.confirm(
				choice.kind === 'own'
					? __(
							"Use this design? Its look, texts and sounds replace your overlay's current design; save the current one first if you want to keep it. Your overlay URL stays.",
							'merchboard'
						)
					: __(
							'Copy this standard overlay into your overlay? Its look and texts replace yours; your sounds and your overlay URL stay.',
							'merchboard'
						)
			)
		) {
			return;
		}

		setBusy( 'apply' );
		setError( null );
		const result =
			choice.kind === 'own'
				? await applyDesign( choice.id )
				: await applyAlertPreset( choice.id );
		setBusy( null );

		if ( result.ok ) {
			onApplied( result.data );
			notify( __( 'The design is in use now.', 'merchboard' ) );
		} else {
			setError( result.error );
		}
	};

	const rename = () => {
		if ( choice?.kind !== 'own' ) {
			return;
		}

		// eslint-disable-next-line no-alert -- A short name is asked for in place.
		const next = window.prompt(
			__( 'New name', 'merchboard' ),
			choice.name
		);

		if ( next !== null && next.trim() !== '' && next !== choice.name ) {
			void change(
				'rename',
				() => renameDesign( choice.id, next ),
				__( 'Design renamed.', 'merchboard' )
			);
		}
	};

	const overwrite = () => {
		if (
			choice?.kind !== 'own' ||
			// eslint-disable-next-line no-alert -- Overwriting needs an explicit confirmation.
			! window.confirm(
				sprintf(
					/* translators: %s: name of the design. */
					__(
						'Overwrite "%s" with your overlay\'s current design?',
						'merchboard'
					),
					choice.name
				)
			)
		) {
			return;
		}

		void change(
			'update',
			() => updateDesign( choice.id ),
			__( 'Design saved.', 'merchboard' )
		);
	};

	const remove = () => {
		if (
			choice?.kind !== 'own' ||
			// eslint-disable-next-line no-alert -- Deleting needs an explicit confirmation.
			! window.confirm(
				sprintf(
					/* translators: %s: name of the design. */
					__( 'Delete the design "%s"?', 'merchboard' ),
					choice.name
				)
			)
		) {
			return;
		}

		void change(
			'delete',
			() => deleteDesign( choice.id ),
			__( 'Design deleted.', 'merchboard' ),
			() => setChosen( '' )
		);
	};

	const mine = choices.filter( ( option ) => option.kind === 'own' );
	const theirs = choices.filter( ( option ) => option.kind === 'standard' );

	return (
		<Card title={ __( 'Designs', 'merchboard' ) }>
			<p>
				{ __(
					"Save your overlay's design (look, texts and sounds) under a name and use it again later, e.g. one design for each part of your stream. Only you see your designs. You can also start from a standard overlay. Your overlay URL always stays.",
					'merchboard'
				) }
			</p>
			{ error !== null && <ApiErrorState error={ error } /> }
			{ designs.designs.length < designs.max ? (
				<div className="merchboard-alerts__actions">
					<Field label={ __( 'Name of the design', 'merchboard' ) }>
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
						onClick={ save }
						isBusy={ busy === 'save' }
						disabled={ name.trim() === '' }
					>
						{ __( 'Save current design', 'merchboard' ) }
					</Button>
				</div>
			) : (
				<p className="merchboard-field__help">
					{ sprintf(
						/* translators: %d: most designs. */
						__(
							'You have %d designs, the most there can be. Delete or overwrite one to save another.',
							'merchboard'
						),
						designs.max
					) }
				</p>
			) }
			{ choice !== null && (
				<>
					<Field label={ __( 'Design', 'merchboard' ) }>
						{ ( control ) => (
							<select
								{ ...control }
								className="merchboard-input"
								value={ choice.key }
								onChange={ ( event ) =>
									setChosen( event.target.value )
								}
							>
								{ mine.length > 0 && (
									<optgroup
										label={ __(
											'My designs',
											'merchboard'
										) }
									>
										{ mine.map( ( option ) => (
											<option
												key={ option.key }
												value={ option.key }
											>
												{ option.name }
											</option>
										) ) }
									</optgroup>
								) }
								{ theirs.length > 0 && (
									<optgroup
										label={ __(
											'Standard overlays',
											'merchboard'
										) }
									>
										{ theirs.map( ( option ) => (
											<option
												key={ option.key }
												value={ option.key }
											>
												{ option.name }
											</option>
										) ) }
									</optgroup>
								) }
							</select>
						) }
					</Field>
					<OverlayDesigner
						key={ choice.key }
						layout={ choice.layout }
						settings={ choice.settings }
						images={ choice.images }
						fontFamily={
							font === undefined
								? 'inherit'
								: `"${ font.family }", sans-serif`
						}
						onSaved={ () => undefined }
						readOnly
					/>
					<div className="merchboard-alerts__actions">
						<Button onClick={ apply } isBusy={ busy === 'apply' }>
							{ __( 'Use this design', 'merchboard' ) }
						</Button>
						{ choice.kind === 'own' && (
							<>
								<Button
									variant="secondary"
									onClick={ overwrite }
									isBusy={ busy === 'update' }
								>
									{ __(
										'Overwrite with current design',
										'merchboard'
									) }
								</Button>
								<Button
									variant="ghost"
									onClick={ rename }
									isBusy={ busy === 'rename' }
								>
									{ __( 'Rename', 'merchboard' ) }
								</Button>
								<Button
									variant="danger"
									onClick={ remove }
									isBusy={ busy === 'delete' }
								>
									{ __( 'Delete', 'merchboard' ) }
								</Button>
							</>
						) }
					</div>
				</>
			) }
		</Card>
	);
}
