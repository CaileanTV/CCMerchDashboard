/**
 * The choice of the product image (ADR-096): the chosen image large, and a
 * gallery of every choice to pick from, one side at a time. A product can
 * have many images (4 sides × 23 colours), so the designer draws a tile only
 * when it comes into view, one at a time; a live product's approved images
 * load lazily.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Button } from '../../components/Button';
import { Dialog } from '../../components/Dialog';

export interface ImageChoice {
	/** `<side>:<colour>`. */
	readonly value: string;
	/** "Colour – Side". */
	readonly label: string;
	/** The image when it exists already (a live product); a thumbnail. */
	readonly url?: string | null;
	/** The image large, when it exists already. */
	readonly large?: string | null;
}

export interface ImagePickerProps {
	readonly choices: readonly ImageChoice[];
	/** The chosen image; null for the first choice. */
	readonly selected: string | null;
	readonly onSelect: ( value: string ) => void;
	/** Draws a choice as a `data:` URL (the designer); without it, the choices' URLs are shown. */
	readonly draw?: ( value: string ) => Promise< string | null >;
	/** Changes when what draw() shows changes: everything is drawn again. */
	readonly drawKey?: unknown;
	/** How long the design must be still before anything is drawn (ms). */
	readonly delay?: number;
}

/** "All sides" in the filter. */
const ALL = '';

/**
 * The side of a choice and its name.
 *
 * @param choice The choice.
 */
function sideOf( choice: ImageChoice ): { key: string; name: string } {
	const key = choice.value.slice( 0, choice.value.lastIndexOf( ':' ) );
	const parts = choice.label.split( ' – ' );

	return { key, name: parts[ parts.length - 1 ] ?? key };
}

/**
 * The chosen product image and the gallery.
 *
 * @param props Props.
 */
export function ImagePicker( props: ImagePickerProps ) {
	const { choices, draw, delay = 600 } = props;
	const selected =
		choices.find( ( choice ) => choice.value === props.selected )?.value ??
		choices[ 0 ]?.value ??
		null;
	const chosen = choices.find( ( choice ) => choice.value === selected );
	const sides = useMemo( () => {
		const found = new Map< string, string >();

		for ( const choice of choices ) {
			const side = sideOf( choice );
			found.set( side.key, side.name );
		}

		return Array.from( found, ( [ key, name ] ) => ( { key, name } ) );
	}, [ choices ] );
	const [ filter, setFilter ] = useState< string >(
		chosen === undefined ? ALL : sideOf( chosen ).key
	);
	const [ large, setLarge ] = useState( false );
	const [ drawn, setDrawn ] = useState<
		Readonly< Record< string, string | null > >
	>( {} );
	const [ ready, setReady ] = useState( draw === undefined );
	const drawRef = useRef( draw );
	const queue = useRef< string[] >( [] );
	const asked = useRef( new Set< string >() );
	const running = useRef( false );
	const generation = useRef( 0 );

	drawRef.current = draw;

	// What is drawn shows the design as it was: another design draws it again, once it is still.
	useEffect( () => {
		if ( drawRef.current === undefined ) {
			return undefined;
		}

		generation.current++;
		queue.current = [];
		asked.current = new Set();
		setDrawn( {} );
		setReady( false );

		const timer = window.setTimeout( () => setReady( true ), delay );

		return () => window.clearTimeout( timer );
	}, [ props.drawKey, delay ] );

	const pump = useCallback( async () => {
		if ( running.current ) {
			return;
		}

		running.current = true;

		while ( queue.current.length > 0 ) {
			const value = queue.current.shift()!;
			const at = generation.current;
			const url = await (
				drawRef.current?.( value ) ?? Promise.resolve( null )
			).catch( () => null );

			if ( at === generation.current ) {
				setDrawn( ( current ) => ( { ...current, [ value ]: url } ) );
			}
		}

		running.current = false;
	}, [] );

	/** Asks for a choice to be drawn (once). */
	const request = useCallback(
		( value: string ) => {
			if (
				! ready ||
				drawRef.current === undefined ||
				asked.current.has( value )
			) {
				return;
			}

			asked.current.add( value );
			queue.current.push( value );
			void pump();
		},
		[ ready, pump ]
	);

	/** The image of a choice: drawn, or its own; undefined while it is not drawn yet. */
	const imageOf = (
		choice: ImageChoice,
		big: boolean
	): string | null | undefined =>
		draw === undefined
			? ( ( big ? ( choice.large ?? choice.url ) : choice.url ) ?? null )
			: drawn[ choice.value ];

	// The chosen image first.
	useEffect( () => {
		if ( selected !== null ) {
			request( selected );
		}
	}, [ selected, request ] );

	if ( chosen === undefined ) {
		return null;
	}

	const big = imageOf( chosen, true );
	const shown = choices.filter(
		( choice ) => filter === ALL || sideOf( choice ).key === filter
	);

	return (
		<div className="merchboard-image-picker">
			<div className="merchboard-image-picker__chosen">
				{ big ? (
					<button
						type="button"
						className="merchboard-image-picker__large"
						onClick={ () => setLarge( true ) }
						title={ __( 'Show it large', 'merchboard' ) }
					>
						<img src={ big } alt={ chosen.label } />
					</button>
				) : (
					<span className="merchboard-product-designer__hint">
						{ big === null
							? __( 'The image cannot be shown.', 'merchboard' )
							: __( 'Drawing the product image…', 'merchboard' ) }
					</span>
				) }
				<span>{ chosen.label }</span>
			</div>
			{ sides.length > 1 ? (
				<div
					className="merchboard-image-picker__sides"
					role="group"
					aria-label={ __( 'Side', 'merchboard' ) }
				>
					{ [
						{ key: ALL, name: __( 'All sides', 'merchboard' ) },
						...sides,
					].map( ( side ) => (
						<Button
							key={ side.key }
							size="sm"
							variant={
								filter === side.key ? 'primary' : 'secondary'
							}
							aria-pressed={ filter === side.key }
							onClick={ () => setFilter( side.key ) }
						>
							{ side.name }
						</Button>
					) ) }
				</div>
			) : null }
			<div
				className="merchboard-product-designer__image-gallery"
				role="group"
				aria-label={ __( 'Product images', 'merchboard' ) }
			>
				{ shown.map( ( choice ) => (
					<Tile
						key={ choice.value }
						choice={ choice }
						image={ imageOf( choice, false ) }
						pressed={ choice.value === selected }
						onSelect={ props.onSelect }
						onVisible={ request }
						lazy={ draw === undefined }
					/>
				) ) }
			</div>
			{ large && big ? (
				<Dialog
					title={ chosen.label }
					closeLabel={ __( 'Close', 'merchboard' ) }
					onClose={ () => setLarge( false ) }
				>
					<img
						className="merchboard-image-picker__full"
						src={ big }
						alt={ chosen.label }
					/>
				</Dialog>
			) : null }
		</div>
	);
}

/**
 * One choice in the gallery: drawn when it comes into view.
 *
 * @param props           Props.
 * @param props.choice    The choice.
 * @param props.image     Its image; undefined while it is not drawn.
 * @param props.pressed   Whether it is the chosen one.
 * @param props.onSelect  Chooses it.
 * @param props.onVisible Asks for it to be drawn.
 * @param props.lazy      Whether the browser loads the image lazily.
 */
function Tile( props: {
	readonly choice: ImageChoice;
	readonly image: string | null | undefined;
	readonly pressed: boolean;
	readonly onSelect: ( value: string ) => void;
	readonly onVisible: ( value: string ) => void;
	readonly lazy: boolean;
} ) {
	const { choice, image, onVisible } = props;
	const element = useRef< HTMLButtonElement | null >( null );
	const [ visible, setVisible ] = useState(
		typeof IntersectionObserver === 'undefined'
	);

	useEffect( () => {
		if (
			element.current === null ||
			typeof IntersectionObserver === 'undefined'
		) {
			return undefined;
		}

		const observer = new IntersectionObserver( ( entries ) =>
			setVisible( entries.some( ( entry ) => entry.isIntersecting ) )
		);

		observer.observe( element.current );

		return () => observer.disconnect();
	}, [] );

	useEffect( () => {
		if ( visible && image === undefined ) {
			onVisible( choice.value );
		}
	}, [ visible, image, onVisible, choice.value ] );

	return (
		<button
			ref={ element }
			type="button"
			aria-pressed={ props.pressed }
			title={ choice.label }
			onClick={ () => props.onSelect( choice.value ) }
		>
			{ image ? (
				<img
					src={ image }
					alt={ choice.label }
					loading={ props.lazy ? 'lazy' : undefined }
				/>
			) : (
				<span className="merchboard-image-picker__placeholder">
					{ choice.label }
				</span>
			) }
		</button>
	);
}
