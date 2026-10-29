/**
 * The product designer (phase 30, step 30.8, ADR-080 pkt. 4): one design
 * for every colour, a canvas per side on the base product's mockup
 * template, and the tools Product, Text, Shapes, Cliparts, Upload and
 * Layers, with undo and redo, zoom and a saved draft. Fabric is loaded only
 * when the editor opens (fabric-stage.ts). Submitting for approval follows
 * in step 30.9.
 */
import {
	useCallback,
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ChangeEvent, KeyboardEvent, ReactNode } from 'react';

import {
	deleteDesignerImage,
	designerAssetUrl,
	saveDesignerDraft,
	uploadDesignerImage,
} from '../../api/designer';
import type {
	DesignerAsset,
	DesignerBase,
	DesignerOverview,
	DesignerProduct,
	ProfitKind,
} from '../../api/designer';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { useHistory } from '../alerts/history';
import { moneyText } from '../sales/format';
import {
	moveObject,
	newImage,
	newShape,
	newText,
	objectsOf,
	quotePrice,
	updateObject,
	withObjects,
} from './design-model';
import type {
	DesignDocument,
	DesignObject,
	ShapeType,
	TextObject,
} from './design-model';
import type { FabricStage } from './fabric-stage';
import { TemplateLayers } from './TemplateLayers';

type Tab = 'product' | 'text' | 'shapes' | 'cliparts' | 'uploads' | 'layers';

/** Widest canvas at zoom 1 (CSS px). */
const DISPLAY_WIDTH = 560;

const ZOOMS = [ 0.5, 0.75, 1, 1.5, 2, 3 ] as const;

export interface DesignerEditorProps {
	readonly overview: DesignerOverview;
	readonly product: DesignerProduct;
	readonly locale: string;
	readonly onBack: () => void;
	readonly onOverview: ( overview: DesignerOverview ) => void;
}

/**
 * Loads a Google font's stylesheet once and waits for the face.
 *
 * @param family  Family.
 * @param weights Weights the family has.
 */
async function loadFont(
	family: string,
	weights: readonly number[]
): Promise< void > {
	const href = `https://fonts.googleapis.com/css2?family=${ family.replace( / /g, '+' ) }:wght@${ weights.join( ';' ) }&display=swap`;

	if (
		! document.querySelector(
			`link[data-merchboard-font="${ CSS.escape( family ) }"]`
		)
	) {
		const link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.href = href;
		link.dataset.merchboardFont = family;
		document.head.appendChild( link );
	}

	try {
		await Promise.all(
			weights.map( ( weight ) =>
				document.fonts.load( `${ weight } 32px "${ family }"` )
			)
		);
	} catch {
		// The text is shown in a fallback font until the face arrives.
	}
}

/**
 * The editor.
 *
 * @param props Props.
 */
export function DesignerEditor( props: DesignerEditorProps ) {
	const { overview, product, locale, onBack, onOverview } = props;
	const base = overview.bases.find(
		( candidate ) => candidate.id === product.baseId
	);
	const draft = product.draft;

	if ( base === undefined || draft === null ) {
		return (
			<>
				<Alert tone="warning">
					{ base === undefined
						? __(
								'The base product of this design is no longer available.',
								'merchboard'
							)
						: __(
								'This design has been submitted and cannot be edited here.',
								'merchboard'
							) }
				</Alert>
				<Button variant="secondary" onClick={ onBack }>
					{ __( 'Back to my designs', 'merchboard' ) }
				</Button>
			</>
		);
	}

	return (
		<Editor
			overview={ overview }
			product={ product }
			base={ base }
			design={ draft.design }
			locale={ locale }
			onBack={ onBack }
			onOverview={ onOverview }
		/>
	);
}

interface EditorProps extends DesignerEditorProps {
	readonly base: DesignerBase;
	readonly design: DesignDocument;
}

/**
 * The editor of a draft.
 *
 * @param props Props.
 */
function Editor( props: EditorProps ) {
	const { overview, product, base, locale, onBack, onOverview } = props;
	const draft = product.draft!;
	const history = useHistory< DesignDocument >( props.design );
	const [ title, setTitle ] = useState( draft.title );
	const [ description, setDescription ] = useState( draft.description );
	const [ colors, setColors ] = useState< readonly string[] >( draft.colors );
	const [ sizes, setSizes ] = useState< readonly string[] >( draft.sizes );
	const [ profitKind, setProfitKind ] = useState< ProfitKind >(
		draft.profitKind
	);
	const [ profitValue, setProfitValue ] = useState(
		Number( draft.profitValue ).toString()
	);
	const [ side, setSide ] = useState( base.views[ 0 ]?.key ?? '' );
	const [ previewColor, setPreviewColor ] = useState(
		draft.colors[ 0 ] ?? base.colors[ 0 ]?.key ?? ''
	);
	const [ selected, setSelected ] = useState< string | null >( null );
	const [ zoom, setZoom ] = useState< number >( 1 );
	const [ tab, setTab ] = useState< Tab >( 'product' );
	const [ images, setImages ] = useState< ReadonlyMap< number, string > >(
		new Map()
	);
	const [ fontsReady, setFontsReady ] = useState( 0 );
	const [ message, setMessage ] = useState< {
		tone: 'success' | 'danger' | 'info';
		text: string;
	} | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( {
		design: props.design,
		price: draft.price,
	} );
	const [ stageError, setStageError ] = useState( false );
	const canvasElement = useRef< HTMLCanvasElement | null >( null );
	const stage = useRef< FabricStage | null >( null );
	const [ stageReady, setStageReady ] = useState( false );
	const loading = useRef( new Set< number >() );
	const sideRef = useRef( side );

	const view =
		base.views.find( ( candidate ) => candidate.key === side ) ??
		base.views[ 0 ];
	const design = history.value;
	sideRef.current = view?.key ?? side;
	const objects = view === undefined ? [] : objectsOf( design, view.key );
	const selectedObject =
		objects.find( ( object ) => object.id === selected ) ?? null;
	const hex =
		base.colors.find( ( color ) => color.key === previewColor )?.hex ??
		'#ffffff';
	const displayHeight =
		view === undefined
			? 0
			: Math.round( ( DISPLAY_WIDTH * view.height ) / view.width );
	const allAssets = useMemo(
		() => [ ...overview.uploads, ...overview.cliparts ],
		[ overview ]
	);
	const fonts = overview.fonts;
	const price = quotePrice(
		base.basePrice,
		profitKind,
		profitValue,
		overview.priceDecimals
	);
	const dirty =
		design !== saved.design ||
		title !== draft.title ||
		description !== draft.description ||
		colors.join() !== draft.colors.join() ||
		sizes.join() !== draft.sizes.join() ||
		profitKind !== draft.profitKind ||
		Number( profitValue.replace( ',', '.' ) ) !==
			Number( draft.profitValue );

	/** Loads `data:` URLs of the given assets that are not loaded yet. */
	const ensureImages = useCallback(
		( assets: readonly { id: number; mime: string }[] ) => {
			for ( const asset of assets ) {
				if ( loading.current.has( asset.id ) ) {
					continue;
				}

				loading.current.add( asset.id );
				void designerAssetUrl( asset ).then( ( url ) => {
					if ( url !== null ) {
						setImages( ( current ) =>
							new Map( current ).set( asset.id, url )
						);
					}
				} );
			}
		},
		[]
	);

	// The template layers of the side and every image of the design.
	useEffect( () => {
		if ( view !== undefined ) {
			ensureImages(
				Object.values( view.layers ).map( ( id ) => ( {
					id,
					mime: 'image/png',
				} ) )
			);
		}

		const used = new Set(
			Object.values( design.sides )
				.flat()
				.flatMap( ( object ) =>
					object.type === 'image' ? [ object.asset ] : []
				)
		);
		ensureImages( allAssets.filter( ( asset ) => used.has( asset.id ) ) );
	}, [ view, design, allAssets, ensureImages ] );

	// The thumbnails of the open library.
	useEffect( () => {
		if ( tab === 'cliparts' ) {
			ensureImages( overview.cliparts );
		} else if ( tab === 'uploads' ) {
			ensureImages( overview.uploads );
		}
	}, [ tab, overview, ensureImages ] );

	// The fonts of the design's texts.
	useEffect( () => {
		const families = new Set(
			Object.values( design.sides )
				.flat()
				.flatMap( ( object ) =>
					object.type === 'text' ? [ object.font ] : []
				)
		);

		for ( const family of families ) {
			const font = fonts.find(
				( candidate ) => candidate.family === family
			);

			if ( font !== undefined ) {
				void loadFont( font.family, font.weights ).then( () =>
					setFontsReady( ( count ) => count + 1 )
				);
			}
		}
	}, [ design, fonts ] );

	// The canvas, loaded with Fabric only now.
	useEffect( () => {
		let current: FabricStage | null = null;
		let cancelled = false;

		import( './fabric-stage' )
			.then( ( module ) => {
				if ( cancelled || canvasElement.current === null ) {
					return;
				}

				current = new module.FabricStage( canvasElement.current, {
					onChange: ( next ) =>
						history.set( ( present ) =>
							withObjects( present, sideRef.current, next )
						),
					onSelect: setSelected,
				} );
				stage.current = current;
				setStageReady( true );
			} )
			.catch( () => setStageError( true ) );

		return () => {
			cancelled = true;
			current?.dispose();
			stage.current = null;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- Created once per editor.
	}, [] );

	useEffect( () => {
		if ( stage.current !== null && view !== undefined ) {
			stage.current.setView( {
				displayWidth: DISPLAY_WIDTH,
				displayHeight,
				templateWidth: view.width,
				area: view.area,
				printWidth: view.printWidth,
				printHeight: view.printHeight,
				zoom,
			} );
		}
	}, [ stageReady, view, zoom, displayHeight ] );

	useEffect( () => {
		void stage.current?.render( objects, images, selected );
		// Fonts arriving re-render the texts.
		// eslint-disable-next-line react-hooks/exhaustive-deps -- objects is derived from design and side.
	}, [ stageReady, design, side, images, selected, fontsReady ] );

	const setObjects = ( next: readonly DesignObject[] ) => {
		if ( view !== undefined ) {
			history.set( ( present ) =>
				withObjects( present, view.key, next )
			);
		}
	};

	const add = ( object: DesignObject ) => {
		if ( objects.length >= overview.maxObjects ) {
			setMessage( {
				tone: 'danger',
				text: sprintf(
					/* translators: %d: most objects per side. */ __(
						'A side can have at most %d elements.',
						'merchboard'
					),
					overview.maxObjects
				),
			} );

			return;
		}

		setObjects( [ ...objects, object ] );
		setSelected( object.id );
	};

	const change = ( fields: Partial< DesignObject > ) => {
		if ( selectedObject !== null ) {
			setObjects( updateObject( objects, selectedObject.id, fields ) );
		}
	};

	const remove = ( id: string ) => {
		setObjects( objects.filter( ( object ) => object.id !== id ) );
		setSelected( null );
	};

	const addText = () => {
		const font = fonts[ 0 ];

		if ( font === undefined || view === undefined ) {
			setMessage( {
				tone: 'danger',
				text: __(
					'No fonts are available yet. Please contact us.',
					'merchboard'
				),
			} );

			return;
		}

		add(
			newText(
				view.printWidth,
				view.printHeight,
				font.family,
				__( 'Your text', 'merchboard' )
			)
		);
	};

	const addShape = ( type: ShapeType ) => {
		if ( view !== undefined ) {
			add( newShape( type, view.printWidth, view.printHeight ) );
		}
	};

	const addImage = ( asset: DesignerAsset ) => {
		if ( view !== undefined ) {
			ensureImages( [ asset ] );
			add(
				newImage(
					asset.id,
					asset.width,
					asset.height,
					view.printWidth,
					view.printHeight
				)
			);
		}
	};

	const upload = async ( event: ChangeEvent< HTMLInputElement > ) => {
		const file = event.target.files?.[ 0 ];
		event.target.value = '';

		if ( file === undefined ) {
			return;
		}

		if ( file.size > overview.maxUploadBytes ) {
			setMessage( {
				tone: 'danger',
				text: __( 'The image is too large.', 'merchboard' ),
			} );

			return;
		}

		setMessage( { tone: 'info', text: __( 'Uploading…', 'merchboard' ) } );
		const result = await uploadDesignerImage( file );

		if ( ! result.ok ) {
			setMessage( {
				tone: 'danger',
				text:
					result.error.reason === 'upload_quota'
						? __(
								'You have used all your space for uploads. Delete an upload first.',
								'merchboard'
							)
						: __(
								'The image could not be uploaded. Use PNG, JPG or WebP of at most 8000 × 8000 pixels.',
								'merchboard'
							),
			} );

			return;
		}

		onOverview( {
			...overview,
			uploads: [ result.data, ...overview.uploads ],
		} );
		setMessage( null );
		addImage( result.data );
	};

	const deleteUpload = async ( asset: DesignerAsset ) => {
		const result = await deleteDesignerImage( asset.id );

		if ( result.ok ) {
			onOverview( result.data );
		}
	};

	const save = async () => {
		setSaving( true );
		setMessage( null );
		const result = await saveDesignerDraft( product.id, {
			title,
			description,
			colors,
			sizes,
			profitKind,
			profitValue,
			design,
		} );
		setSaving( false );

		if ( ! result.ok ) {
			setMessage( {
				tone: 'danger',
				text: saveError( result.error.reason ),
			} );

			return;
		}

		setSaved( { design, price: result.data.draft?.price ?? null } );
		setMessage( {
			tone: 'success',
			text: __( 'Your design is saved.', 'merchboard' ),
		} );
	};

	const onKeyDown = ( event: KeyboardEvent< HTMLDivElement > ) => {
		const target = event.target as HTMLElement;

		if (
			[ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( target.tagName ) ||
			target.isContentEditable
		) {
			return;
		}

		if (
			( event.key === 'Delete' || event.key === 'Backspace' ) &&
			selectedObject !== null &&
			! selectedObject.locked
		) {
			event.preventDefault();
			remove( selectedObject.id );
		} else if (
			( event.ctrlKey || event.metaKey ) &&
			event.key.toLowerCase() === 'z'
		) {
			event.preventDefault();

			if ( event.shiftKey ) {
				history.redo();
			} else {
				history.undo();
			}
		} else if (
			( event.ctrlKey || event.metaKey ) &&
			event.key.toLowerCase() === 'y'
		) {
			event.preventDefault();
			history.redo();
		}
	};

	if ( view === undefined ) {
		return (
			<Alert tone="warning">
				{ __( 'The base product has no sides yet.', 'merchboard' ) }
			</Alert>
		);
	}

	const tabs: readonly { id: Tab; label: string }[] = [
		{ id: 'product', label: __( 'Product', 'merchboard' ) },
		{ id: 'text', label: __( 'Text', 'merchboard' ) },
		{ id: 'shapes', label: __( 'Shapes', 'merchboard' ) },
		{ id: 'cliparts', label: __( 'Cliparts', 'merchboard' ) },
		{ id: 'uploads', label: __( 'Upload', 'merchboard' ) },
		{ id: 'layers', label: __( 'Layers', 'merchboard' ) },
	];

	return (
		// The key handler serves the canvas (Delete, undo and redo); every control keeps its own keys.
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<div className="merchboard-designer" onKeyDown={ onKeyDown }>
			<div className="merchboard-designer__bar">
				<Button variant="ghost" size="sm" onClick={ onBack }>
					{ __( '← My designs', 'merchboard' ) }
				</Button>
				<Button
					variant="secondary"
					size="sm"
					onClick={ history.undo }
					disabled={ ! history.canUndo }
				>
					{ __( 'Undo', 'merchboard' ) }
				</Button>
				<Button
					variant="secondary"
					size="sm"
					onClick={ history.redo }
					disabled={ ! history.canRedo }
				>
					{ __( 'Redo', 'merchboard' ) }
				</Button>
				<Labelled
					className="merchboard-designer__zoom"
					before
					label={ <>{ __( 'Zoom', 'merchboard' ) }</> }
				>
					{ ( id ) => (
						<select
							id={ id }
							className="merchboard-input"
							value={ zoom }
							onChange={ ( event ) =>
								setZoom( Number( event.target.value ) )
							}
						>
							{ ZOOMS.map( ( value ) => (
								<option key={ value } value={ value }>
									{ `${ Math.round( value * 100 ) } %` }
								</option>
							) ) }
						</select>
					) }
				</Labelled>
				<Button
					onClick={ () => void save() }
					isBusy={ saving }
					disabled={ ! dirty && ! saving }
				>
					{ dirty
						? __( 'Save draft', 'merchboard' )
						: __( 'Saved', 'merchboard' ) }
				</Button>
			</div>

			{ message === null ? null : (
				<Alert tone={ message.tone }>{ message.text }</Alert>
			) }

			<div className="merchboard-designer__body">
				<div className="merchboard-designer__tools">
					<div
						className="merchboard-designer__tabs"
						role="tablist"
						aria-label={ __( 'Designer tools', 'merchboard' ) }
					>
						{ tabs.map( ( entry ) => (
							<button
								key={ entry.id }
								type="button"
								role="tab"
								aria-selected={ tab === entry.id }
								className="merchboard-designer__tab"
								onClick={ () => setTab( entry.id ) }
							>
								{ entry.label }
							</button>
						) ) }
					</div>
					<div className="merchboard-designer__panel" role="tabpanel">
						{ tab === 'product' ? (
							<ProductPanel
								base={ base }
								locale={ locale }
								title={ title }
								description={ description }
								colors={ colors }
								sizes={ sizes }
								profitKind={ profitKind }
								profitValue={ profitValue }
								price={ price }
								onTitle={ setTitle }
								onDescription={ setDescription }
								onColors={ ( next ) => {
									setColors( next );

									if (
										! next.includes( previewColor ) &&
										next[ 0 ] !== undefined
									) {
										setPreviewColor( next[ 0 ] );
									}
								} }
								onSizes={ setSizes }
								onProfitKind={ setProfitKind }
								onProfitValue={ setProfitValue }
							/>
						) : null }
						{ tab === 'text' ? (
							<div className="merchboard-designer__stack">
								<Button onClick={ addText }>
									{ __( 'Add text', 'merchboard' ) }
								</Button>
								<p className="merchboard-designer__hint">
									{ __(
										'Select a text on the product to change its font, size and colour.',
										'merchboard'
									) }
								</p>
							</div>
						) : null }
						{ tab === 'shapes' ? (
							<div className="merchboard-designer__stack">
								<Button
									variant="secondary"
									onClick={ () => addShape( 'rect' ) }
								>
									{ __( 'Rectangle', 'merchboard' ) }
								</Button>
								<Button
									variant="secondary"
									onClick={ () => addShape( 'ellipse' ) }
								>
									{ __( 'Circle', 'merchboard' ) }
								</Button>
								<Button
									variant="secondary"
									onClick={ () => addShape( 'triangle' ) }
								>
									{ __( 'Triangle', 'merchboard' ) }
								</Button>
							</div>
						) : null }
						{ tab === 'cliparts' ? (
							<AssetGrid
								assets={ overview.cliparts }
								images={ images }
								empty={ __( 'No cliparts yet.', 'merchboard' ) }
								onPick={ addImage }
							/>
						) : null }
						{ tab === 'uploads' ? (
							<div className="merchboard-designer__stack">
								<Labelled
									className="merchboard-button merchboard-button--secondary merchboard-button--md"
									before
									label={
										<>
											{ __(
												'Upload an image',
												'merchboard'
											) }
										</>
									}
								>
									{ ( id ) => (
										<input
											id={ id }
											type="file"
											accept="image/png,image/jpeg,image/webp"
											className="merchboard-designer__file"
											onChange={ ( event ) =>
												void upload( event )
											}
										/>
									) }
								</Labelled>
								<p className="merchboard-designer__hint">
									{ __(
										'PNG, JPG or WebP. Use PNG for a transparent background. Only images you have the rights to.',
										'merchboard'
									) }
								</p>
								<AssetGrid
									assets={ overview.uploads }
									images={ images }
									empty={ __(
										'No uploads yet.',
										'merchboard'
									) }
									onPick={ addImage }
									onDelete={ ( asset ) =>
										void deleteUpload( asset )
									}
								/>
							</div>
						) : null }
						{ tab === 'layers' ? (
							<LayerList
								objects={ objects }
								selected={ selected }
								onSelect={ setSelected }
								onChange={ ( id, next ) =>
									setObjects(
										updateObject( objects, id, next )
									)
								}
								onMove={ ( id, direction ) =>
									setObjects(
										moveObject( objects, id, direction )
									)
								}
								onRemove={ remove }
							/>
						) : null }
					</div>
				</div>

				<div className="merchboard-designer__stage-column">
					<div
						className="merchboard-designer__sides"
						role="group"
						aria-label={ __( 'Sides', 'merchboard' ) }
					>
						{ base.views.map( ( candidate ) => (
							<Button
								key={ candidate.key }
								size="sm"
								variant={
									candidate.key === view.key
										? 'primary'
										: 'secondary'
								}
								onClick={ () => {
									setSide( candidate.key );
									setSelected( null );
								} }
							>
								{ candidate.name }
							</Button>
						) ) }
						<span
							className="merchboard-designer__swatches"
							role="group"
							aria-label={ __( 'Preview colour', 'merchboard' ) }
						>
							{ base.colors
								.filter( ( color ) =>
									colors.includes( color.key )
								)
								.map( ( color ) => (
									<button
										key={ color.key }
										type="button"
										className="merchboard-designer__swatch"
										style={ { background: color.hex } }
										aria-pressed={
											color.key === previewColor
										}
										title={ color.name }
										aria-label={ color.name }
										onClick={ () =>
											setPreviewColor( color.key )
										}
									/>
								) ) }
						</span>
					</div>
					<div className="merchboard-designer__viewport">
						<div
							className="merchboard-designer__stage"
							style={ {
								width: DISPLAY_WIDTH * zoom,
								height: displayHeight * zoom,
							} }
						>
							<TemplateLayers
								view={ view }
								images={ images }
								color={ hex }
								part="under"
							/>
							<div className="merchboard-designer__canvas">
								<canvas
									ref={ canvasElement }
									aria-label={ sprintf(
										/* translators: %s: side name, e.g. "Front". */ __(
											'Design of the side %s',
											'merchboard'
										),
										view.name
									) }
								/>
							</div>
							<div className="merchboard-designer__over">
								<TemplateLayers
									view={ view }
									images={ images }
									color={ hex }
									part="over"
								/>
							</div>
							<div
								className="merchboard-designer__area"
								style={ {
									left:
										( view.area.x / view.width ) *
										DISPLAY_WIDTH *
										zoom,
									top:
										( view.area.y / view.height ) *
										displayHeight *
										zoom,
									width:
										( view.area.width / view.width ) *
										DISPLAY_WIDTH *
										zoom,
									height:
										( view.area.height / view.height ) *
										displayHeight *
										zoom,
								} }
							/>
						</div>
					</div>
					{ stageError ? (
						<Alert tone="danger">
							{ __(
								'The designer could not be loaded. Please reload the page.',
								'merchboard'
							) }
						</Alert>
					) : null }
					{ selectedObject === null ? (
						<p className="merchboard-designer__hint">
							{ __(
								'Drag, scale and rotate the elements on the product. The dashed frame is the print area.',
								'merchboard'
							) }
						</p>
					) : (
						<Properties
							object={ selectedObject }
							fonts={ fonts }
							onChange={ change }
							onRemove={ () => remove( selectedObject.id ) }
							onMove={ ( direction ) =>
								setObjects(
									moveObject(
										objects,
										selectedObject.id,
										direction
									)
								)
							}
						/>
					) }
					<p className="merchboard-designer__hint">
						{ sprintf(
							/* translators: 1: print width in px, 2: print height in px, 3: resolution in dpi. */
							__(
								'Print file: %1$d × %2$d px at %3$d dpi.',
								'merchboard'
							),
							view.printWidth,
							view.printHeight,
							view.dpi
						) }
						{ saved.price === null
							? null
							: ` ${ sprintf( /* translators: %s: price. */ __( 'Saved price: %s', 'merchboard' ), moneyText( saved.price, base.currency, locale ) ) }` }
					</p>
				</div>
			</div>
		</div>
	);
}

/**
 * A control with its label, connected by id.
 *
 * @param props           Props.
 * @param props.className Class of the wrapper.
 * @param props.label     The label's content.
 * @param props.before    Whether the label comes before the control.
 * @param props.children  The control, given the id.
 */
function Labelled( props: {
	readonly className: string;
	readonly label: ReactNode;
	readonly before?: boolean;
	readonly children: ( id: string ) => ReactNode;
} ) {
	const id = useId();
	const label = <label htmlFor={ id }>{ props.label }</label>;

	return (
		<div className={ props.className }>
			{ props.before === true ? label : null }
			{ props.children( id ) }
			{ props.before === true ? null : label }
		</div>
	);
}

/**
 * The message of a refused save.
 *
 * @param reason The server's reason.
 */
function saveError( reason: string | undefined ): string {
	switch ( reason ) {
		case 'design_title':
			return __(
				'Give your product a title of at most 120 characters.',
				'merchboard'
			);
		case 'design_profit':
			return __(
				'Your profit must be a number of at least 0.',
				'merchboard'
			);
		case 'design_options':
			return __(
				'Choose at least one colour and one size.',
				'merchboard'
			);
		case 'design_invalid':
			return __(
				'The design contains something that cannot be used (an unknown font, a removed image or too many elements).',
				'merchboard'
			);
		default:
			return __(
				'Your design could not be saved. Please try again.',
				'merchboard'
			);
	}
}

interface ProductPanelProps {
	readonly base: DesignerBase;
	readonly locale: string;
	readonly title: string;
	readonly description: string;
	readonly colors: readonly string[];
	readonly sizes: readonly string[];
	readonly profitKind: ProfitKind;
	readonly profitValue: string;
	readonly price: number | null;
	readonly onTitle: ( value: string ) => void;
	readonly onDescription: ( value: string ) => void;
	readonly onColors: ( value: readonly string[] ) => void;
	readonly onSizes: ( value: readonly string[] ) => void;
	readonly onProfitKind: ( value: ProfitKind ) => void;
	readonly onProfitValue: ( value: string ) => void;
}

/**
 * Title, colours, sizes and profit with the customer's price.
 *
 * @param props Props.
 */
function ProductPanel( props: ProductPanelProps ) {
	const { base, locale } = props;
	const toggle = (
		list: readonly string[],
		value: string,
		order: readonly string[]
	) =>
		order.filter( ( item ) =>
			item === value ? ! list.includes( value ) : list.includes( item )
		);

	return (
		<div className="merchboard-designer__stack">
			<Labelled
				className="merchboard-field"
				before
				label={
					<>
						<span className="merchboard-field__label">
							{ __( 'Title', 'merchboard' ) }
						</span>
					</>
				}
			>
				{ ( id ) => (
					<input
						id={ id }
						className="merchboard-input"
						value={ props.title }
						maxLength={ 120 }
						onChange={ ( event ) =>
							props.onTitle( event.target.value )
						}
					/>
				) }
			</Labelled>
			<Labelled
				className="merchboard-field"
				before
				label={
					<>
						<span className="merchboard-field__label">
							{ __( 'Description', 'merchboard' ) }
						</span>
					</>
				}
			>
				{ ( id ) => (
					<textarea
						id={ id }
						className="merchboard-input merchboard-input--textarea"
						rows={ 3 }
						maxLength={ 2000 }
						value={ props.description }
						onChange={ ( event ) =>
							props.onDescription( event.target.value )
						}
					/>
				) }
			</Labelled>
			<fieldset className="merchboard-designer__options">
				<legend className="merchboard-field__label">
					{ __( 'Colours', 'merchboard' ) }
				</legend>
				{ base.colors.map( ( color ) => (
					<Labelled
						key={ color.key }
						className="merchboard-designer__option"
						label={
							<>
								<span
									className="merchboard-designer__swatch merchboard-designer__swatch--small"
									style={ { background: color.hex } }
									aria-hidden="true"
								/>
								{ color.name }
							</>
						}
					>
						{ ( id ) => (
							<input
								id={ id }
								type="checkbox"
								checked={ props.colors.includes( color.key ) }
								onChange={ () =>
									props.onColors(
										toggle(
											props.colors,
											color.key,
											base.colors.map(
												( item ) => item.key
											)
										)
									)
								}
							/>
						) }
					</Labelled>
				) ) }
			</fieldset>
			<fieldset className="merchboard-designer__options">
				<legend className="merchboard-field__label">
					{ __( 'Sizes', 'merchboard' ) }
				</legend>
				{ base.sizes.map( ( size ) => (
					<Labelled
						key={ size }
						className="merchboard-designer__option"
						label={ <>{ size }</> }
					>
						{ ( id ) => (
							<input
								id={ id }
								type="checkbox"
								checked={ props.sizes.includes( size ) }
								onChange={ () =>
									props.onSizes(
										toggle( props.sizes, size, base.sizes )
									)
								}
							/>
						) }
					</Labelled>
				) ) }
			</fieldset>
			<fieldset className="merchboard-designer__options">
				<legend className="merchboard-field__label">
					{ __( 'Your profit', 'merchboard' ) }
				</legend>
				<select
					className="merchboard-input"
					value={ props.profitKind }
					onChange={ ( event ) =>
						props.onProfitKind(
							event.target.value === 'percent'
								? 'percent'
								: 'fixed'
						)
					}
				>
					<option value="fixed">
						{ sprintf(
							/* translators: %s: currency code. */ __(
								'Amount (%s)',
								'merchboard'
							),
							base.currency
						) }
					</option>
					<option value="percent">
						{ __( 'Percent of the base price', 'merchboard' ) }
					</option>
				</select>
				<input
					className="merchboard-input"
					inputMode="decimal"
					value={ props.profitValue }
					aria-label={ __( 'Profit', 'merchboard' ) }
					onChange={ ( event ) =>
						props.onProfitValue( event.target.value )
					}
				/>
			</fieldset>
			<p className="merchboard-designer__price">
				{ sprintf(
					/* translators: %s: base price. */ __(
						'Base price: %s',
						'merchboard'
					),
					moneyText( base.basePrice, base.currency, locale )
				) }
				<br />
				<strong>
					{ props.price === null
						? __( 'Enter a profit of at least 0.', 'merchboard' )
						: sprintf(
								/* translators: %s: the customer's price. */ __(
									'Customer price incl. VAT: %s',
									'merchboard'
								),
								moneyText(
									props.price.toFixed( 4 ),
									base.currency,
									locale
								)
							) }
				</strong>
			</p>
			<p className="merchboard-designer__hint">
				{ __(
					"The company always keeps the base price; a discount comes off your profit, never below 0. In the company's own campaigns the discount is shared.",
					'merchboard'
				) }
			</p>
		</div>
	);
}

/**
 * A grid of images to pick.
 *
 * @param props          Props.
 * @param props.assets   Assets.
 * @param props.images   `data:` URL by id.
 * @param props.empty    Text without assets.
 * @param props.onPick   Adds an asset to the design.
 * @param props.onDelete Deletes an asset (uploads).
 */
function AssetGrid( props: {
	readonly assets: readonly DesignerAsset[];
	readonly images: ReadonlyMap< number, string >;
	readonly empty: string;
	readonly onPick: ( asset: DesignerAsset ) => void;
	readonly onDelete?: ( asset: DesignerAsset ) => void;
} ) {
	if ( props.assets.length === 0 ) {
		return <p className="merchboard-designer__hint">{ props.empty }</p>;
	}

	return (
		<ul className="merchboard-designer__grid">
			{ props.assets.map( ( asset ) => {
				const url = props.images.get( asset.id );

				return (
					<li key={ asset.id }>
						<button
							type="button"
							className="merchboard-designer__thumb"
							onClick={ () => props.onPick( asset ) }
							title={ asset.name }
						>
							{ url === undefined ? (
								<span aria-hidden="true">…</span>
							) : (
								<img src={ url } alt={ asset.name } />
							) }
						</button>
						{ props.onDelete === undefined ? null : (
							<Button
								variant="ghost"
								size="sm"
								onClick={ () => props.onDelete?.( asset ) }
								aria-label={ sprintf(
									/* translators: %s: file name. */ __(
										'Delete %s',
										'merchboard'
									),
									asset.name
								) }
							>
								{ __( 'Delete', 'merchboard' ) }
							</Button>
						) }
					</li>
				);
			} ) }
		</ul>
	);
}

/**
 * The layers of a side, front first.
 *
 * @param props          Props.
 * @param props.objects  Objects, bottom first.
 * @param props.selected Selected id.
 * @param props.onSelect Selects.
 * @param props.onChange Changes an object.
 * @param props.onMove   Moves an object.
 * @param props.onRemove Removes an object.
 */
function LayerList( props: {
	readonly objects: readonly DesignObject[];
	readonly selected: string | null;
	readonly onSelect: ( id: string ) => void;
	readonly onChange: ( id: string, change: Partial< DesignObject > ) => void;
	readonly onMove: ( id: string, direction: 1 | -1 ) => void;
	readonly onRemove: ( id: string ) => void;
} ) {
	if ( props.objects.length === 0 ) {
		return (
			<p className="merchboard-designer__hint">
				{ __( 'This side has no elements yet.', 'merchboard' ) }
			</p>
		);
	}

	return (
		<ol className="merchboard-designer__layers">
			{ [ ...props.objects ].reverse().map( ( object ) => (
				<li
					key={ object.id }
					className={
						object.id === props.selected ? 'is-selected' : undefined
					}
				>
					<button
						type="button"
						className="merchboard-designer__layer-name"
						onClick={ () => props.onSelect( object.id ) }
					>
						{ layerName( object ) }
					</button>
					<Button
						variant="ghost"
						size="sm"
						onClick={ () => props.onMove( object.id, 1 ) }
						aria-label={ __( 'Forward', 'merchboard' ) }
					>
						↑
					</Button>
					<Button
						variant="ghost"
						size="sm"
						onClick={ () => props.onMove( object.id, -1 ) }
						aria-label={ __( 'Backward', 'merchboard' ) }
					>
						↓
					</Button>
					<Button
						variant="ghost"
						size="sm"
						aria-pressed={ object.hidden }
						onClick={ () =>
							props.onChange( object.id, {
								hidden: ! object.hidden,
							} )
						}
					>
						{ object.hidden
							? __( 'Show', 'merchboard' )
							: __( 'Hide', 'merchboard' ) }
					</Button>
					<Button
						variant="ghost"
						size="sm"
						aria-pressed={ object.locked }
						onClick={ () =>
							props.onChange( object.id, {
								locked: ! object.locked,
							} )
						}
					>
						{ object.locked
							? __( 'Unlock', 'merchboard' )
							: __( 'Lock', 'merchboard' ) }
					</Button>
					<Button
						variant="ghost"
						size="sm"
						onClick={ () => props.onRemove( object.id ) }
					>
						{ __( 'Delete', 'merchboard' ) }
					</Button>
				</li>
			) ) }
		</ol>
	);
}

/**
 * An object's name in the layer list.
 *
 * @param object The object.
 */
function layerName( object: DesignObject ): string {
	switch ( object.type ) {
		case 'text':
			return object.text.slice( 0, 30 );
		case 'image':
			return __( 'Image', 'merchboard' );
		case 'rect':
			return __( 'Rectangle', 'merchboard' );
		case 'ellipse':
			return __( 'Circle', 'merchboard' );
		default:
			return __( 'Triangle', 'merchboard' );
	}
}

/**
 * The selected object's settings.
 *
 * @param props          Props.
 * @param props.object   The object.
 * @param props.fonts    Approved fonts.
 * @param props.onChange Changes it.
 * @param props.onRemove Removes it.
 * @param props.onMove   Moves it.
 */
function Properties( props: {
	readonly object: DesignObject;
	readonly fonts: DesignerOverview[ 'fonts' ];
	readonly onChange: ( change: Partial< DesignObject > ) => void;
	readonly onRemove: () => void;
	readonly onMove: ( direction: 1 | -1 ) => void;
} ) {
	const { object, onChange } = props;
	const text = object.type === 'text' ? ( object as TextObject ) : null;
	const shape =
		object.type === 'rect' ||
		object.type === 'ellipse' ||
		object.type === 'triangle'
			? object
			: null;
	const font =
		text === null
			? undefined
			: props.fonts.find(
					( candidate ) => candidate.family === text.font
				);

	return (
		<div className="merchboard-designer__properties">
			{ text === null ? null : (
				<>
					<textarea
						className="merchboard-input merchboard-input--textarea"
						rows={ 2 }
						maxLength={ 500 }
						value={ text.text }
						aria-label={ __( 'Text', 'merchboard' ) }
						onChange={ ( event ) =>
							event.target.value.trim() !== '' &&
							onChange( {
								text: event.target.value,
							} as Partial< TextObject > )
						}
					/>
					<select
						className="merchboard-input"
						value={ text.font }
						aria-label={ __( 'Font', 'merchboard' ) }
						onChange={ ( event ) => {
							const next = props.fonts.find(
								( candidate ) =>
									candidate.family === event.target.value
							);

							onChange( {
								font: event.target.value,
								weight: next?.weights.includes( text.weight )
									? text.weight
									: ( next?.weights[ 0 ] ?? 400 ),
							} as Partial< TextObject > );
						} }
					>
						{ props.fonts.map( ( candidate ) => (
							<option
								key={ candidate.family }
								value={ candidate.family }
							>
								{ candidate.family }
							</option>
						) ) }
					</select>
					<select
						className="merchboard-input"
						value={ text.weight }
						aria-label={ __( 'Weight', 'merchboard' ) }
						onChange={ ( event ) =>
							onChange( {
								weight: Number( event.target.value ),
							} as Partial< TextObject > )
						}
					>
						{ ( font?.weights ?? [ text.weight ] ).map(
							( weight ) => (
								<option key={ weight } value={ weight }>
									{ weight }
								</option>
							)
						) }
					</select>
					<Labelled
						className="merchboard-designer__option"
						label={ <>{ __( 'Italic', 'merchboard' ) }</> }
					>
						{ ( id ) => (
							<input
								id={ id }
								type="checkbox"
								checked={ text.italic }
								onChange={ () =>
									onChange( {
										italic: ! text.italic,
									} as Partial< TextObject > )
								}
							/>
						) }
					</Labelled>
					<select
						className="merchboard-input"
						value={ text.align }
						aria-label={ __( 'Alignment', 'merchboard' ) }
						onChange={ ( event ) =>
							onChange( {
								align: event.target
									.value as TextObject[ 'align' ],
							} as Partial< TextObject > )
						}
					>
						<option value="left">
							{ __( 'Left', 'merchboard' ) }
						</option>
						<option value="center">
							{ __( 'Centre', 'merchboard' ) }
						</option>
						<option value="right">
							{ __( 'Right', 'merchboard' ) }
						</option>
					</select>
					<Labelled
						className="merchboard-designer__option"
						before
						label={ <>{ __( 'Colour', 'merchboard' ) }</> }
					>
						{ ( id ) => (
							<input
								id={ id }
								type="color"
								value={ text.fill }
								onChange={ ( event ) =>
									onChange( {
										fill: event.target.value,
									} as Partial< TextObject > )
								}
							/>
						) }
					</Labelled>
				</>
			) }
			{ shape === null ? null : (
				<>
					<Labelled
						className="merchboard-designer__option"
						before
						label={ <>{ __( 'Fill', 'merchboard' ) }</> }
					>
						{ ( id ) => (
							<input
								id={ id }
								type="color"
								value={ shape.fill ?? '#000000' }
								onChange={ ( event ) =>
									onChange( {
										fill: event.target.value,
									} as Partial< DesignObject > )
								}
							/>
						) }
					</Labelled>
					<Labelled
						className="merchboard-designer__option"
						label={ <>{ __( 'Outline', 'merchboard' ) }</> }
					>
						{ ( id ) => (
							<input
								id={ id }
								type="checkbox"
								checked={ shape.stroke !== null }
								onChange={ () =>
									onChange( {
										stroke:
											shape.stroke === null
												? '#000000'
												: null,
										strokeWidth:
											shape.stroke === null ? 8 : 0,
									} as Partial< DesignObject > )
								}
							/>
						) }
					</Labelled>
				</>
			) }
			<Labelled
				className="merchboard-designer__option"
				before
				label={ <>{ __( 'Opacity', 'merchboard' ) }</> }
			>
				{ ( id ) => (
					<input
						id={ id }
						type="range"
						min={ 0.1 }
						max={ 1 }
						step={ 0.05 }
						value={ object.opacity }
						onChange={ ( event ) =>
							onChange( {
								opacity: Number( event.target.value ),
							} )
						}
					/>
				) }
			</Labelled>
			<Button
				variant="secondary"
				size="sm"
				onClick={ () => props.onMove( 1 ) }
			>
				{ __( 'Forward', 'merchboard' ) }
			</Button>
			<Button
				variant="secondary"
				size="sm"
				onClick={ () => props.onMove( -1 ) }
			>
				{ __( 'Backward', 'merchboard' ) }
			</Button>
			<Button variant="danger" size="sm" onClick={ props.onRemove }>
				{ __( 'Delete', 'merchboard' ) }
			</Button>
		</div>
	);
}
