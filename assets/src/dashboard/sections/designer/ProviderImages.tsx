/**
 * The main image in the shop chosen among the finished product's real
 * photos (step 57.22): the photos of the saved draft in one colour at a time,
 * made as "See the finished product" makes them, each named after its view
 * (Front, Left side …). A choice is `<view>:<colour>`; the server turns a
 * view into its stored photo once the photos are made. Who makes them is
 * never named. When they cannot be made, the drawn images are offered.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { fetchPreview, startPreview } from '../../api/designer';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { ImagePicker } from './ImagePicker';
import type { ImageChoice } from './ImagePicker';

/** Milliseconds between two looks at the photos. */
const POLL_MS = 3000;

/** Looks before giving up: five minutes, as the finished product's. */
const MAX_POLLS = 100;

/** A main image of the provider's kind: a view or a chosen photo, and a colour. */
const PROVIDER_CHOICE = /^(_view-[a-z0-9_-]{1,24}|_photo-[0-9]{1,18}):(.+)$/;

/**
 * The colour of a main image chosen among a provider's photos, or null.
 *
 * @param value The choice.
 */
export function providerColor( value: string | null ): string | null {
	return value === null
		? null
		: ( PROVIDER_CHOICE.exec( value )?.[ 2 ] ?? null );
}

/** A main image of a stored photo: also an environment photo (a live product's, copied to its draft). */
const STORED_CHOICE =
	/^(_view-[a-z0-9_-]{1,24}|_photo-[0-9]{1,18}|_scene-[0-9]{1,3}):(.+)$/;

/**
 * The colour of a main image that is a provider's photo or an environment
 * photo, or null: kept when the draft is saved, as the server checks it.
 *
 * @param value The choice.
 */
export function storedChoiceColor( value: string | null ): string | null {
	return value === null
		? null
		: ( STORED_CHOICE.exec( value )?.[ 2 ] ?? null );
}

export interface ProviderImagesProps {
	readonly product: number;
	/** The chosen colours with their names, in the shop's order. */
	readonly colors: readonly { readonly key: string; readonly name: string }[];
	/** Saves the draft and sends its files; a reason when it fails. */
	readonly prepare: () => Promise< string | null >;
	readonly selected: string | null;
	readonly onSelect: ( value: string ) => void;
	/** Wait between two looks (tests). */
	readonly pollMs?: number;
	/** What is offered when the photos cannot be made. */
	readonly fallback: ReactNode;
}

/** The photos of one colour. */
type Loaded =
	| { readonly status: 'loading' }
	| { readonly status: 'done'; readonly choices: readonly ImageChoice[] }
	| { readonly status: 'failed'; readonly reason: string | undefined };

/**
 * The text of photos that could not be made.
 *
 * @param reason The refusal's reason.
 */
function failure( reason: string | undefined ): string {
	switch ( reason ) {
		case 'preview_quota':
			return __(
				'You have made the most photos for today, so the drawn images are shown instead. You can still send your design.',
				'merchboard'
			);
		case 'preview_busy':
			return __(
				'We can only make a limited number of photos a minute, so the drawn images are shown instead. Try again in a minute.',
				'merchboard'
			);
		default:
			return __(
				'The photos could not be made, so the drawn images are shown instead. You can still send your design.',
				'merchboard'
			);
	}
}

/**
 * The colours and the photos of the one chosen.
 *
 * @param props Props.
 */
export function ProviderImages( props: ProviderImagesProps ) {
	const { product, colors, prepare, selected, onSelect } = props;
	const pollMs = props.pollMs ?? POLL_MS;
	const first = colors[ 0 ]?.key ?? '';
	const [ color, setColor ] = useState( () => {
		const own = providerColor( selected ) ?? selected?.split( ':' ).pop();

		return colors.some( ( item ) => item.key === own )
			? ( own as string )
			: first;
	} );
	const [ loaded, setLoaded ] = useState<
		Readonly< Record< string, Loaded > >
	>( {} );
	// Counts the tries again: the colour's photos are made anew.
	const [ attempt, setAttempt ] = useState( 0 );
	const prepared = useRef< Promise< string | null > | null >( null );
	const alive = useRef( true );
	const calls = useRef( { prepare, onSelect, selected } );
	calls.current = { prepare, onSelect, selected };

	useEffect(
		() => () => {
			alive.current = false;
		},
		[]
	);

	const name = ( key: string ) =>
		colors.find( ( item ) => item.key === key )?.name ?? key;
	const busy = Object.values( loaded ).some(
		( state ) => state.status === 'loading'
	);

	// One colour at a time: a new start would replace the photos being made.
	useEffect( () => {
		if ( color === '' || loaded[ color ] !== undefined || busy ) {
			return;
		}

		const set = ( state: Loaded ) => {
			if ( alive.current ) {
				setLoaded( ( current ) => ( {
					...current,
					[ color ]: state,
				} ) );
			}
		};
		const wait = () =>
			new Promise( ( resolve ) => setTimeout( resolve, pollMs ) );
		const run = async () => {
			set( { status: 'loading' } );
			prepared.current ??= calls.current.prepare();

			const refused = await prepared.current;

			// Closed meanwhile (sent or cancelled): no photos are made.
			if ( ! alive.current ) {
				return;
			}

			if ( refused !== null ) {
				// The next colour tries to send the files again.
				prepared.current = null;
				set( { status: 'failed', reason: refused } );

				return;
			}

			const started = await startPreview( product, color );

			if ( ! alive.current ) {
				return;
			}

			if ( ! started.ok ) {
				set( { status: 'failed', reason: started.error.reason } );

				return;
			}

			for ( let poll = 0; alive.current && poll < MAX_POLLS; poll++ ) {
				await wait();

				const state = await fetchPreview( product );

				if ( ! state.ok ) {
					set( { status: 'failed', reason: state.error.reason } );

					return;
				}

				if ( state.data.status === 'pending' ) {
					continue;
				}

				const choices = state.data.images.flatMap(
					( image ): ImageChoice[] =>
						image.choice === undefined ||
						image.label === undefined ||
						image.color !== color
							? []
							: [
									{
										value: `${ image.choice }:${ color }`,
										label: `${ name( color ) } – ${ image.label }`,
										url: image.url,
										large: image.url,
									},
								]
				);

				if ( state.data.status !== 'done' || choices.length === 0 ) {
					set( { status: 'failed', reason: undefined } );

					return;
				}

				set( { status: 'done', choices } );

				// What the shop shows is what is chosen here: nothing chosen, or a choice of this colour not among its photos, becomes its first; another colour's stays.
				const current = calls.current.selected;
				const ownColor = current?.split( ':' ).pop();

				if (
					( current === null || ownColor === color ) &&
					! choices.some( ( choice ) => choice.value === current )
				) {
					calls.current.onSelect( choices[ 0 ]!.value );
				}

				return;
			}

			set( { status: 'failed', reason: undefined } );
		};

		void run();
		// The other values are read from refs.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ color, busy, attempt ] );

	/**
	 * Forgets a colour's failed photos, so they are made again.
	 *
	 * @param key The colour.
	 */
	const retry = ( key: string ) => {
		setLoaded( ( current ) => {
			const { [ key ]: _failed, ...rest } = current;

			return rest;
		} );
		setAttempt( ( count ) => count + 1 );
	};
	const shown = loaded[ color ];
	const choices = Object.values( loaded ).flatMap( ( state ) =>
		state.status === 'done' ? state.choices : []
	);

	return (
		<div className="merchboard-product-designer__provider-images">
			{ colors.length > 1 ? (
				<div
					className="merchboard-image-picker__sides"
					role="group"
					aria-label={ __( 'Colour', 'merchboard' ) }
				>
					{ colors.map( ( item ) => (
						<Button
							key={ item.key }
							size="sm"
							variant={
								color === item.key ? 'primary' : 'secondary'
							}
							aria-pressed={ color === item.key }
							disabled={ busy && color !== item.key }
							onClick={ () => {
								// A colour whose photos failed is tried again.
								if ( loaded[ item.key ]?.status === 'failed' ) {
									retry( item.key );
								}

								setColor( item.key );
							} }
						>
							{ item.name }
						</Button>
					) ) }
				</div>
			) : null }
			{ shown === undefined || shown.status === 'loading' ? (
				<p
					className="merchboard-product-designer__working"
					role="status"
				>
					<span
						className="merchboard-product-designer__spinner"
						aria-hidden="true"
					/>
					{ sprintf(
						/* translators: %s: a colour, e.g. "Lime". */ __(
							'We are making photos of your design in %s. It usually takes less than a minute…',
							'merchboard'
						),
						name( color )
					) }
				</p>
			) : null }
			{ shown?.status === 'failed' ? (
				<>
					<Alert tone="info">{ failure( shown.reason ) }</Alert>
					<div>
						<Button
							size="sm"
							variant="secondary"
							onClick={ () => retry( color ) }
						>
							{ __( 'Try again', 'merchboard' ) }
						</Button>
					</div>
					{ props.fallback }
				</>
			) : null }
			{ shown?.status !== 'failed' && choices.length > 0 ? (
				<ImagePicker
					choices={ choices }
					selected={ selected }
					onSelect={ onSelect }
					showAll
				/>
			) : null }
		</div>
	);
}
