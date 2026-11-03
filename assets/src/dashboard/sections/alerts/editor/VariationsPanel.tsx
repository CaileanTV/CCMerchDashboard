/**
 * Variations (ADR-088): other looks of the alert, chosen at random when a
 * purchase comes in. A variation with conditions (least amount, least
 * quantity, categories) is chosen only when they are met, and then before
 * the others; without conditions it shares the chance with the default.
 */
import { __, sprintf } from '@wordpress/i18n';

import { Field } from '../../../components/Field';
import { VARIATION_NAME_LENGTH } from '../../../../overlay/look';
import type { Variation } from '../../../../overlay/look';
import type { AlertsAudio } from '../../../types/alerts';
import { NumberControl, Switch } from './controls';
import { Panel } from './Panel';

/**
 * The list of variations: add, edit, switch on or off and delete.
 *
 * @param props            Props.
 * @param props.variations The variations.
 * @param props.canAdd     Whether another can be added.
 * @param props.onAdd      Adds a variation (a copy of the default).
 * @param props.onEdit     Edits a variation.
 * @param props.onChange   Saves the list.
 */
export function VariationsSection( {
	variations,
	canAdd,
	onAdd,
	onEdit,
	onChange,
}: {
	readonly variations: readonly Variation[];
	readonly canAdd: boolean;
	readonly onAdd: () => void;
	readonly onEdit: ( id: string ) => void;
	readonly onChange: ( variations: readonly Variation[] ) => void;
} ) {
	const remove = ( variation: Variation ) => {
		if (
			// eslint-disable-next-line no-alert -- Deleting a variation cannot be undone after saving.
			window.confirm(
				sprintf(
					/* translators: %s: name of the variation. */
					__( 'Delete the variation "%s"?', 'merchboard' ),
					variation.name
				)
			)
		) {
			onChange(
				variations.filter( ( item ) => item.id !== variation.id )
			);
		}
	};

	return (
		<Panel title={ __( 'Variations', 'merchboard' ) } level="section">
			<p className="merchboard-field__help">
				{ __(
					'Other looks of your alert. A purchase is shown with one of them at random. A variation with conditions is only used when they are met, and then before the others.',
					'merchboard'
				) }
			</p>
			{ variations.length > 0 && (
				<ul className="merchboard-alert-variations">
					{ variations.map( ( variation ) => (
						<li key={ variation.id }>
							<Switch
								label={
									variation.name ||
									__( 'Unnamed', 'merchboard' )
								}
								checked={ variation.enabled }
								onChange={ ( enabled ) =>
									onChange(
										variations.map( ( item ) =>
											item.id === variation.id
												? { ...item, enabled }
												: item
										)
									)
								}
							/>
							<span className="merchboard-alert-variations__actions">
								<button
									type="button"
									className="merchboard-button merchboard-button--secondary merchboard-button--sm"
									onClick={ () => onEdit( variation.id ) }
								>
									{ __( 'Edit', 'merchboard' ) }
								</button>
								<button
									type="button"
									className="merchboard-button merchboard-button--ghost merchboard-button--sm"
									onClick={ () => remove( variation ) }
								>
									{ __( 'Delete', 'merchboard' ) }
								</button>
							</span>
						</li>
					) ) }
				</ul>
			) }
			<button
				type="button"
				className="merchboard-button merchboard-button--secondary merchboard-button--sm"
				onClick={ onAdd }
				disabled={ ! canAdd }
			>
				{ __( '+ New variation', 'merchboard' ) }
			</button>
			{ ! canAdd && (
				<p className="merchboard-field__help">
					{ __( 'You have the most variations (10).', 'merchboard' ) }
				</p>
			) }
		</Panel>
	);
}

/**
 * The variation being edited: its name, conditions and sound, and the way
 * back to the default.
 *
 * @param props            Props.
 * @param props.variation  The variation.
 * @param props.audio      The sounds (the library); null when not available.
 * @param props.categories The product categories conditions can name.
 * @param props.onChange   Saves the variation.
 * @param props.onBack     Back to editing the default.
 */
export function VariationHeader( {
	variation,
	audio,
	categories,
	onChange,
	onBack,
}: {
	readonly variation: Variation;
	readonly audio: AlertsAudio | null;
	readonly categories: ReadonlyArray< {
		readonly termId: number;
		readonly name: string;
	} >;
	readonly onChange: ( variation: Variation ) => void;
	readonly onBack: () => void;
} ) {
	const conditions = variation.conditions;
	const sound =
		typeof variation.look.audio_default_sound === 'number'
			? variation.look.audio_default_sound
			: 0;
	const setConditions = ( change: Partial< typeof conditions > ) =>
		onChange( {
			...variation,
			conditions: { ...conditions, ...change },
		} );

	return (
		<div className="merchboard-alert-variation">
			<p className="merchboard-alert-variation__editing">
				{ sprintf(
					/* translators: %s: name of the variation. */
					__( 'Editing: %s', 'merchboard' ),
					variation.name || __( 'Unnamed', 'merchboard' )
				) }
				<button
					type="button"
					className="merchboard-button merchboard-button--secondary merchboard-button--sm"
					onClick={ onBack }
				>
					{ __( 'Back to the default', 'merchboard' ) }
				</button>
			</p>
			<Field label={ __( 'Name of the variation', 'merchboard' ) }>
				{ ( control ) => (
					<input
						{ ...control }
						type="text"
						className="merchboard-input"
						maxLength={ VARIATION_NAME_LENGTH }
						value={ variation.name }
						onChange={ ( event ) =>
							onChange( {
								...variation,
								name: event.target.value,
							} )
						}
					/>
				) }
			</Field>
			<Panel
				level="group"
				title={ __( 'Conditions', 'merchboard' ) }
				defaultOpen
			>
				<div className="merchboard-alert-grid">
					<NumberControl
						label={ __( 'Least amount', 'merchboard' ) }
						value={ conditions.min_amount }
						min={ 0 }
						max={ 1000000 }
						help={ __(
							"What the customer paid for the product, in the shop's currency. 0: any.",
							'merchboard'
						) }
						onChange={ ( amount ) =>
							setConditions( {
								min_amount: Math.round( amount ),
							} )
						}
					/>
					<NumberControl
						label={ __( 'Least quantity', 'merchboard' ) }
						value={ conditions.min_quantity }
						min={ 0 }
						max={ 1000 }
						help={ __( '0 or 1: any.', 'merchboard' ) }
						onChange={ ( quantity ) =>
							setConditions( {
								min_quantity: Math.round( quantity ),
							} )
						}
					/>
				</div>
				{ categories.length > 0 && (
					<fieldset className="merchboard-alert-variation__categories">
						<legend>
							{ __(
								'Only these categories (none: any)',
								'merchboard'
							) }
						</legend>
						{ categories.map( ( category ) => (
							<Switch
								key={ category.termId }
								label={ category.name }
								checked={ conditions.categories.includes(
									category.termId
								) }
								onChange={ ( on ) =>
									setConditions( {
										categories: on
											? [
													...conditions.categories,
													category.termId,
												]
											: conditions.categories.filter(
													( term ) =>
														term !== category.termId
												),
									} )
								}
							/>
						) ) }
					</fieldset>
				) }
			</Panel>
			{ audio !== null && (
				<Field label={ __( 'Sound of the variation', 'merchboard' ) }>
					{ ( control ) => (
						<select
							{ ...control }
							className="merchboard-input"
							value={ sound }
							onChange={ ( event ) =>
								onChange( {
									...variation,
									look: {
										...variation.look,
										audio_default_sound: Number(
											event.target.value
										),
									},
								} )
							}
						>
							<option value={ 0 }>
								{ __( "The alert's own sounds", 'merchboard' ) }
							</option>
							{ audio.library.map( ( item ) => (
								<option key={ item.id } value={ item.id }>
									{ sprintf(
										/* translators: 1: sound name, 2: seconds. */
										__( '%1$s (%2$d s)', 'merchboard' ),
										item.name,
										item.seconds
									) }
								</option>
							) ) }
						</select>
					) }
				</Field>
			) }
		</div>
	);
}
