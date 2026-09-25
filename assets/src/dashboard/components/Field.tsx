import { useId } from '@wordpress/element';
import type { ReactNode } from 'react';

/** Accessibility props the form control must receive. */
export interface FieldControlProps {
	readonly id: string;
	readonly 'aria-describedby'?: string;
	readonly 'aria-invalid'?: true;
}

export interface FieldProps {
	readonly label: string;
	readonly help?: string;
	readonly error?: string;
	/** Renders the control with the ids that connect it to label, help and error. */
	readonly children: ( control: FieldControlProps ) => ReactNode;
}

/**
 * Label, control, help text and error message, wired together with ids so
 * screen readers announce all of them.
 */
export function Field( { label, help, error, children }: FieldProps ) {
	const id = useId();
	const helpId = `${ id }-help`;
	const errorId = `${ id }-error`;
	const describedBy = [
		help === undefined ? null : helpId,
		error === undefined ? null : errorId,
	]
		.filter( ( value ): value is string => value !== null )
		.join( ' ' );

	const control: FieldControlProps = {
		id,
		...( describedBy === '' ? {} : { 'aria-describedby': describedBy } ),
		...( error === undefined ? {} : { 'aria-invalid': true as const } ),
	};

	return (
		<div
			className={
				error === undefined
					? 'merchboard-field'
					: 'merchboard-field merchboard-field--error'
			}
		>
			<label className="merchboard-field__label" htmlFor={ id }>
				{ label }
			</label>
			{ children( control ) }
			{ help !== undefined && (
				<p id={ helpId } className="merchboard-field__help">
					{ help }
				</p>
			) }
			{ error !== undefined && (
				<p id={ errorId } className="merchboard-field__error">
					{ error }
				</p>
			) }
		</div>
	);
}
