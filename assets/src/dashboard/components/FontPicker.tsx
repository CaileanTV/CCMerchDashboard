import { useEffect, useRef, useState } from '@wordpress/element';
import type { KeyboardEvent } from 'react';

import { addFontStylesheet } from '../sections/alerts/useFontStylesheet';

export interface FontChoice {
	/** What is saved. */
	readonly value: string;
	readonly label: string;
	/** The font's stylesheet; null for the default font. */
	readonly url: string | null;
	/** Shown, but it cannot be chosen again. */
	readonly disabled?: boolean;
}

export interface FontPickerProps {
	readonly value: string;
	readonly choices: readonly FontChoice[];
	readonly onChange: ( value: string ) => void;
	readonly id?: string;
	readonly 'aria-label'?: string;
	readonly 'aria-describedby'?: string;
}

/**
 * The CSS font of a choice. Families come from the server's validated list.
 *
 * @param choice The choice.
 */
function fontOf( choice: FontChoice | undefined ): string | undefined {
	return choice?.url === null || choice?.url === undefined
		? undefined
		: `"${ choice.value }", sans-serif`;
}

/**
 * A list of fonts where each is shown in its own font. A plain select cannot
 * do that: most browsers ignore the style of its options. The fonts' files are
 * only fetched once the list is opened.
 *
 * @param props                  Props.
 * @param props.value            The chosen font.
 * @param props.choices          The fonts.
 * @param props.onChange         Called with a new choice.
 * @param props.id               Id of the button (its label's target).
 * @param props.aria-label       Label of the button without a visible one.
 * @param props.aria-describedby The help text.
 */
export function FontPicker( props: FontPickerProps ) {
	const { value, choices, onChange } = props;
	const [ open, setOpen ] = useState( false );
	const holder = useRef< HTMLDivElement >( null );
	const toggle = useRef< HTMLButtonElement >( null );
	const chosen = choices.find( ( choice ) => choice.value === value );
	const chosenUrl = chosen?.url ?? null;

	useEffect( () => {
		if ( chosenUrl !== null ) {
			addFontStylesheet( chosenUrl );
		}
	}, [ chosenUrl ] );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}

		for ( const choice of choices ) {
			if ( choice.url !== null ) {
				addFontStylesheet( choice.url );
			}
		}

		holder.current
			?.querySelector< HTMLElement >(
				'[role="option"][aria-selected="true"]'
			)
			?.focus();

		const outside = ( event: MouseEvent ) => {
			if ( ! holder.current?.contains( event.target as Node ) ) {
				setOpen( false );
			}
		};

		document.addEventListener( 'mousedown', outside );

		return () => document.removeEventListener( 'mousedown', outside );
	}, [ open, choices ] );

	const close = () => {
		setOpen( false );
		toggle.current?.focus();
	};

	const onKeyDown = ( event: KeyboardEvent< HTMLDivElement > ) => {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			close();

			return;
		}

		if ( event.key === 'Tab' ) {
			setOpen( false );

			return;
		}

		if ( event.key !== 'ArrowDown' && event.key !== 'ArrowUp' ) {
			return;
		}

		const options = Array.from(
			event.currentTarget.querySelectorAll< HTMLElement >(
				'[role="option"]:not([aria-disabled="true"])'
			)
		);
		const at = options.indexOf( event.target as HTMLElement );
		const step = event.key === 'ArrowDown' ? 1 : -1;

		event.preventDefault();
		options[
			Math.min( options.length - 1, Math.max( 0, at + step ) )
		]?.focus();
	};

	return (
		<div className="merchboard-font-picker" ref={ holder }>
			<button
				type="button"
				ref={ toggle }
				id={ props.id }
				className="merchboard-input merchboard-font-picker__button"
				aria-haspopup="listbox"
				aria-expanded={ open }
				aria-label={ props[ 'aria-label' ] }
				aria-describedby={ props[ 'aria-describedby' ] }
				style={ { fontFamily: fontOf( chosen ) } }
				onClick={ () => setOpen( ! open ) }
			>
				{ chosen?.label ?? value }
			</button>
			{ open ? (
				<div
					className="merchboard-font-picker__list"
					role="listbox"
					tabIndex={ -1 }
					aria-label={ props[ 'aria-label' ] }
					onKeyDown={ onKeyDown }
				>
					{ choices.map( ( choice ) => (
						<button
							key={ choice.value }
							type="button"
							role="option"
							className="merchboard-font-picker__option"
							aria-selected={ choice.value === value }
							aria-disabled={ choice.disabled === true }
							data-value={ choice.value }
							style={ { fontFamily: fontOf( choice ) } }
							onClick={ () => {
								if ( choice.disabled !== true ) {
									onChange( choice.value );
									close();
								}
							} }
						>
							{ choice.label }
						</button>
					) ) }
				</div>
			) : null }
		</div>
	);
}
