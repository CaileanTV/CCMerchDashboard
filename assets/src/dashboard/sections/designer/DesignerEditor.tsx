/**
 * The product designer (phase 30, step 30.8, ADR-080 pkt. 4): one design
 * for every colour, a canvas per side on the base product's mockup
 * template, and the tools Product, Text, Shapes, Cliparts, Upload and
 * Layers, with undo and redo, zoom and a saved draft. Fabric is loaded only
 * when the editor opens (fabric-stage.ts). "Send for approval" (step 30.9)
 * saves the draft, renders and sends its files (submission.ts) and submits;
 * a waiting version can be withdrawn and edited again. During "view as
 * creator" the administrator cannot send. A product in the shop shows its
 * live version and "Make a change" (step 30.10); a rejected design shows
 * the administrator's note above the editor.
 */
import {
	useCallback,
	useContext,
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
	discardChange,
	saveDesignerDraft,
	setShopPublished,
	startChange,
	uploadDesignerImage,
	withdrawDesign,
} from '../../api/designer';
import type {
	DesignerAsset,
	DesignerBase,
	DesignerOverview,
	DesignerProduct,
	DesignerThread,
	ProfitKind,
} from '../../api/designer';
import { ConfigContext } from '../../app/config-context';
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
	usedSides,
	withKnownThreads,
	withObjects,
	withThread,
} from './design-model';
import type {
	DesignDocument,
	DesignObject,
	ShapeType,
	TextObject,
} from './design-model';
import type { FabricStage } from './fabric-stage';
import { FinishedProduct } from './FinishedProduct';
import { submissionSize, submitWithFiles, uploadFiles } from './submission';
import { LayerList } from './LayerList';
import { TemplateLayers } from './TemplateLayers';
import { threadObject, threadSide } from './threads';
import type { ThreadedSide, ThreadPlan } from './threads';

type Tab = 'product' | 'text' | 'shapes' | 'cliparts' | 'uploads';

/** The canvas at zoom 1 until the space for it is known (CSS px). */
const DISPLAY_WIDTH = 560;

/** The narrowest and widest canvas at zoom 1 (CSS px). */
const DISPLAY_LIMITS = [ 280, 760 ] as const;

const ZOOMS = [ 0.5, 0.75, 1, 1.5, 2, 3 ] as const;

export interface DesignerEditorProps {
	readonly overview: DesignerOverview;
	readonly product: DesignerProduct;
	readonly locale: string;
	readonly onBack: () => void;
	readonly onOverview: ( overview: DesignerOverview ) => void;
	/** The product after a submission or withdrawal. */
	readonly onProduct: ( product: DesignerProduct ) => void;
	/** Asks whether the product with this title is to be deleted. */
	readonly onDelete: ( title: string ) => void;
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

	if ( base !== undefined && draft === null && product.pending !== null ) {
		return <Waiting { ...props } />;
	}

	if ( base !== undefined && draft === null && product.live !== null ) {
		return <InShop { ...props } />;
	}

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
			onProduct={ props.onProduct }
			onDelete={ props.onDelete }
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
	const {
		overview,
		product,
		base,
		locale,
		onBack,
		onOverview,
		onProduct,
		onDelete,
	} = props;
	const impersonating = useContext( ConfigContext )?.impersonating === true;
	const [ finished, setFinished ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ sending, setSending ] = useState( false );
	const draft = product.draft!;
	const history = useHistory< DesignDocument >( props.design );
	const [ title, setTitle ] = useState( draft.postfix );
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
	const [ threaded, setThreaded ] = useState<
		( ThreadedSide & { readonly side: string } ) | null
	>( null );
	const [ fontsReady, setFontsReady ] = useState( 0 );
	const [ message, setMessage ] = useState< {
		tone: 'success' | 'danger' | 'info';
		text: string;
	} | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ discarding, setDiscarding ] = useState( false );
	const [ saved, setSaved ] = useState( {
		design: props.design,
		price: draft.price,
	} );
	const [ stageError, setStageError ] = useState( false );
	const [ displayWidth, setDisplayWidth ] = useState( DISPLAY_WIDTH );
	const viewport = useRef< HTMLDivElement | null >( null );
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
	const fullName = `${ overview.creator } ${ base.name } ${ title }`.trim();
	const objects = view === undefined ? [] : objectsOf( design, view.key );
	const embroidery = view?.embroidery ?? null;
	const swaps = view === undefined ? undefined : design.threads?.[ view.key ];
	const plan =
		threaded?.side === view?.key ? ( threaded?.plan ?? null ) : null;
	const selectedObject =
		objects.find( ( object ) => object.id === selected ) ?? null;
	const hex =
		base.colors.find( ( color ) => color.key === previewColor )?.hex ??
		'#ffffff';
	const displayHeight =
		view === undefined
			? 0
			: Math.round( ( displayWidth * view.height ) / view.width );
	const allAssets = useMemo(
		() => [ ...overview.uploads, ...overview.cliparts ],
		[ overview ]
	);
	const fonts = overview.fonts;
	const areas = usedSides( design ).length;
	const price = quotePrice(
		base.basePrice,
		profitKind,
		profitValue,
		overview.priceDecimals,
		base.extraSidePrice,
		areas
	);
	const dirty =
		design !== saved.design ||
		title !== draft.postfix ||
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
				[
					...Object.values( view.layers ),
					...Object.values( view.backgrounds ),
				].map( ( id ) => ( {
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

	// The product fills the space it has.
	useEffect( () => {
		const element = viewport.current;

		if ( element === null || typeof ResizeObserver === 'undefined' ) {
			return undefined;
		}

		const observer = new ResizeObserver( () => {
			const style = getComputedStyle( element );
			const free =
				element.clientWidth -
				parseFloat( style.paddingLeft ) -
				parseFloat( style.paddingRight );

			setDisplayWidth(
				Math.round(
					Math.min(
						DISPLAY_LIMITS[ 1 ],
						Math.max( DISPLAY_LIMITS[ 0 ], free )
					)
				)
			);
		} );

		observer.observe( element );

		return () => observer.disconnect();
	}, [] );

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
				displayWidth,
				displayHeight,
				templateWidth: view.width,
				area: view.area,
				printWidth: view.printWidth,
				printHeight: view.printHeight,
				zoom,
			} );
		}
	}, [ stageReady, view, zoom, displayWidth, displayHeight ] );

	// An embroidered side is shown in its threads.
	useEffect( () => {
		if ( view === undefined || embroidery === null ) {
			setThreaded( null );

			return undefined;
		}

		let cancelled = false;

		void threadSide( objects, images, swaps ?? {}, embroidery ).then(
			( result ) => {
				if ( ! cancelled ) {
					setThreaded( { ...result, side: view.key } );
				}
			}
		);

		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- objects and swaps are derived from design and side.
	}, [ design, side, images, embroidery ] );

	useEffect( () => {
		if ( embroidery !== null && plan === null ) {
			return;
		}

		void stage.current?.render(
			objects,
			plan === null || threaded === null ? images : threaded.images,
			selected,
			plan === null
				? null
				: {
						key: plan.signature,
						object: ( object ) => threadObject( object, plan ),
					}
		);
		// Fonts arriving re-render the texts.
		// eslint-disable-next-line react-hooks/exhaustive-deps -- objects is derived from design and side.
	}, [ stageReady, design, side, images, threaded, selected, fontsReady ] );

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

	const swapThread = ( found: string, used: string | null ) => {
		if ( view !== undefined ) {
			history.set( ( present ) =>
				withThread( present, view.key, found, used )
			);
		}
	};

	// A thread picked as the colour shows as that thread, even if it was swapped.
	const pickThread = ( thread: DesignerThread ) => {
		if ( selectedObject !== null && view !== undefined ) {
			const next = updateObject( objects, selectedObject.id, {
				fill: thread.hex,
			} as Partial< DesignObject > );

			history.set( ( present ) =>
				withThread(
					withObjects( present, view.key, next ),
					view.key,
					thread.code,
					null
				)
			);
		}
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

	/** Drops the change of a product in the shop. */
	const discard = async () => {
		setDiscarding( true );
		setMessage( null );
		const result = await discardChange( product.id );
		setDiscarding( false );

		if ( result.ok ) {
			onProduct( result.data );
		} else {
			setMessage( {
				tone: 'danger',
				text: __(
					'The change could not be cancelled. Please try again.',
					'merchboard'
				),
			} );
		}
	};

	/** Saves the draft; the saved product, or null after a message. */
	const persist = async (): Promise< DesignerProduct | null > => {
		setSaving( true );
		setMessage( null );
		const result = await saveDesignerDraft( product.id, {
			title,
			description: draft.description,
			colors,
			sizes,
			profitKind,
			profitValue,
			design: withKnownThreads( design, base.views ),
		} );
		setSaving( false );

		if ( ! result.ok ) {
			setMessage( {
				tone: 'danger',
				text: saveError( result.error.reason ),
			} );

			return null;
		}

		setSaved( { design, price: result.data.draft?.price ?? null } );

		return result.data;
	};

	const save = async () => {
		if ( ( await persist() ) !== null ) {
			setMessage( {
				tone: 'success',
				text: __( 'Your design is saved.', 'merchboard' ),
			} );
		}
	};

	/** A file as a `data:` URL: loaded already, or fetched now. */
	const imageOf = async ( id: number ): Promise< string | null > => {
		const known = images.get( id );

		if ( known !== undefined ) {
			return known;
		}

		const asset = allAssets.find( ( candidate ) => candidate.id === id );

		return designerAssetUrl( { id, mime: asset?.mime ?? 'image/png' } );
	};

	/** Waits for the fonts of a design's texts. */
	const fontsOf = async ( shown: DesignDocument ): Promise< void > => {
		const families = new Set(
			Object.values( shown.sides )
				.flat()
				.flatMap( ( object ) =>
					object.type === 'text' ? [ object.font ] : []
				)
		);

		await Promise.all(
			Array.from( families ).map( ( family ) => {
				const font = fonts.find(
					( candidate ) => candidate.family === family
				);

				return font === undefined
					? Promise.resolve()
					: loadFont( font.family, font.weights );
			} )
		);
	};

	const send = async () => {
		setConfirming( false );
		setSending( true );
		const stored = await persist();
		const storedDraft = stored?.draft ?? null;

		if ( storedDraft === null ) {
			setSending( false );

			return;
		}

		const result = await submitWithFiles(
			{
				product: product.id,
				base,
				design: storedDraft.design,
				colors: storedDraft.colors,
				image: imageOf,
				fonts: () => fontsOf( storedDraft.design ),
			},
			( done, total ) =>
				setMessage( {
					tone: 'info',
					text: sprintf(
						/* translators: 1: files sent, 2: all files. */ __(
							'Preparing your design for approval: %1$d of %2$d files sent…',
							'merchboard'
						),
						done,
						total
					),
				} )
		);
		setSending( false );

		if ( ! result.ok ) {
			setMessage( {
				tone: 'danger',
				text: submitError( result.reason ),
			} );

			return;
		}

		onProduct( result.product );
	};

	const showFinished = async (): Promise< string | null > => {
		const stored = await persist();
		const storedDraft = stored?.draft ?? null;

		if ( storedDraft === null ) {
			return 'save';
		}

		const uploaded = await uploadFiles(
			{
				product: product.id,
				base,
				design: storedDraft.design,
				colors: storedDraft.colors,
				image: imageOf,
				fonts: () => fontsOf( storedDraft.design ),
			},
			() => undefined
		);

		return uploaded.ok ? null : uploaded.reason;
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
	];

	return (
		// The key handler serves the canvas (Delete, undo and redo); every control keeps its own keys.
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<div className="merchboard-product-designer" onKeyDown={ onKeyDown }>
			<div className="merchboard-product-designer__bar">
				<Button variant="ghost" size="sm" onClick={ onBack }>
					{ __( '← My designs', 'merchboard' ) }
				</Button>
				<strong className="merchboard-product-designer__bar-title">
					{ fullName }
				</strong>
				<div className="merchboard-product-designer__bar-group">
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
						className="merchboard-product-designer__zoom"
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
				</div>
				<div className="merchboard-product-designer__bar-group">
					{ product.live === null ? null : (
						<Button
							variant="ghost"
							onClick={ () => void discard() }
							isBusy={ discarding }
							disabled={ impersonating }
						>
							{ __( 'Cancel the change', 'merchboard' ) }
						</Button>
					) }
					{ impersonating ? null : (
						<Button
							variant="ghost"
							onClick={ () => onDelete( fullName ) }
						>
							{ __( 'Delete product', 'merchboard' ) }
						</Button>
					) }
					<Button
						variant="secondary"
						onClick={ () => void save() }
						isBusy={ saving }
						disabled={ ! dirty && ! saving }
					>
						{ dirty
							? __( 'Save draft', 'merchboard' )
							: __( 'Saved', 'merchboard' ) }
					</Button>
					{ impersonating || ! base.preview ? null : (
						<Button
							variant="ghost"
							onClick={ () => setFinished( true ) }
							disabled={
								sending || saving || confirming || finished
							}
						>
							{ __( 'See the finished product', 'merchboard' ) }
						</Button>
					) }
					{ impersonating ? null : (
						<Button
							onClick={ () => setConfirming( true ) }
							isBusy={ sending }
							disabled={ sending || saving || confirming }
						>
							{ __( 'Send for approval', 'merchboard' ) }
						</Button>
					) }
				</div>
			</div>

			{ product.rejection !== null &&
			product.rejection.number < draft.number ? (
				<Alert tone="warning">
					{ sprintf(
						/* translators: %s: the administrator's note. */ __(
							'We could not approve your last design: %s Change it, and send it again.',
							'merchboard'
						),
						product.rejection.note
					) }
				</Alert>
			) : null }

			{ product.live !== null ? (
				<Alert tone="info">
					{ __(
						'You are changing a product in the shop. The shop keeps the current version until we approve your change.',
						'merchboard'
					) }
				</Alert>
			) : null }

			{ impersonating ? (
				<Alert tone="info">
					{ __(
						'You view the dashboard as the creator: you can edit the draft, but only the creator can send it for approval.',
						'merchboard'
					) }
				</Alert>
			) : null }

			{ confirming ? (
				<Alert tone="info">
					{ sprintf(
						/* translators: 1: number of files, 2: the customer's price. */ __(
							'Your design is saved and sent with %1$d images (a print file per side and a product image per colour). It waits for our approval; until then it cannot be changed, but you can withdraw it. Customer price incl. VAT: %2$s.',
							'merchboard'
						),
						submissionSize( design, colors ),
						price === null
							? '–'
							: moneyText(
									price.toFixed( overview.priceDecimals ),
									base.currency,
									locale
								)
					) }{ ' ' }
					{ reviewNote() }{ ' ' }
					<span className="merchboard-product-designer__actions">
						<Button size="sm" onClick={ () => void send() }>
							{ __( 'Send', 'merchboard' ) }
						</Button>
						<Button
							size="sm"
							variant="ghost"
							onClick={ () => setConfirming( false ) }
						>
							{ __( 'Cancel', 'merchboard' ) }
						</Button>
					</span>
				</Alert>
			) : null }

			{ message === null ? null : (
				<Alert tone={ message.tone }>{ message.text }</Alert>
			) }

			{ finished ? (
				<FinishedProduct
					product={ product.id }
					views={ base.views }
					prepare={ showFinished }
					onClose={ () => setFinished( false ) }
				/>
			) : null }

			<div className="merchboard-product-designer__body">
				<div className="merchboard-product-designer__left">
					<div className="merchboard-product-designer__tools">
						<div
							className="merchboard-product-designer__tabs"
							role="tablist"
							aria-label={ __( 'Designer tools', 'merchboard' ) }
						>
							{ tabs.map( ( entry ) => (
								<button
									key={ entry.id }
									type="button"
									role="tab"
									aria-selected={ tab === entry.id }
									className="merchboard-product-designer__tab"
									onClick={ () => setTab( entry.id ) }
								>
									{ entry.label }
								</button>
							) ) }
						</div>
						<div
							className="merchboard-product-designer__panel"
							role="tabpanel"
						>
							{ tab === 'product' ? (
								<ProductPanel
									base={ base }
									locale={ locale }
									creator={ overview.creator }
									title={ title }
									colors={ colors }
									sizes={ sizes }
									onTitle={ setTitle }
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
								/>
							) : null }
							{ tab === 'text' ? (
								<div className="merchboard-product-designer__stack">
									<Button onClick={ addText }>
										{ __( 'Add text', 'merchboard' ) }
									</Button>
									<p className="merchboard-product-designer__hint">
										{ __(
											'Select a text on the product to change its font, size and colour.',
											'merchboard'
										) }
									</p>
								</div>
							) : null }
							{ tab === 'shapes' ? (
								<div className="merchboard-product-designer__stack">
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
									empty={ __(
										'No cliparts yet.',
										'merchboard'
									) }
									onPick={ addImage }
								/>
							) : null }
							{ tab === 'uploads' ? (
								<div className="merchboard-product-designer__stack">
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
												className="merchboard-product-designer__file"
												onChange={ ( event ) =>
													void upload( event )
												}
											/>
										) }
									</Labelled>
									<p className="merchboard-product-designer__hint">
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
						</div>
					</div>
					<LayerList
						objects={ objects }
						selected={ selected }
						onSelect={ setSelected }
						onChange={ ( id, fields ) =>
							setObjects( updateObject( objects, id, fields ) )
						}
						onMove={ ( id, direction ) =>
							setObjects( moveObject( objects, id, direction ) )
						}
						onRemove={ remove }
					/>
				</div>

				<div className="merchboard-product-designer__stage-column">
					<div
						className="merchboard-product-designer__sides"
						role="group"
						aria-label={ __( 'Sides', 'merchboard' ) }
					>
						<span className="merchboard-product-designer__segment">
							{ base.views.map( ( candidate ) => (
								<button
									key={ candidate.key }
									type="button"
									aria-pressed={ candidate.key === view.key }
									onClick={ () => {
										setSide( candidate.key );
										setSelected( null );
									} }
								>
									{ candidate.name }
								</button>
							) ) }
						</span>
						<span
							className="merchboard-product-designer__swatches"
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
										className="merchboard-product-designer__swatch"
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
					<div
						className="merchboard-product-designer__viewport"
						ref={ viewport }
					>
						<div
							className="merchboard-product-designer__stage"
							style={ {
								width: displayWidth * zoom,
								height: displayHeight * zoom,
							} }
						>
							<TemplateLayers
								view={ view }
								images={ images }
								color={ hex }
								colorKey={ previewColor }
								part="under"
							/>
							<div className="merchboard-product-designer__canvas">
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
							<div className="merchboard-product-designer__over">
								<TemplateLayers
									view={ view }
									images={ images }
									color={ hex }
									part="over"
								/>
							</div>
							<div
								className="merchboard-product-designer__area"
								style={ {
									left:
										( view.area.x / view.width ) *
										displayWidth *
										zoom,
									top:
										( view.area.y / view.height ) *
										displayHeight *
										zoom,
									width:
										( view.area.width / view.width ) *
										displayWidth *
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
					<p className="merchboard-product-designer__hint">
						{ selectedObject === null
							? `${ __(
									'Drag, scale and rotate the elements on the product. The dashed frame is the print area.',
									'merchboard'
								) } `
							: null }
						{ view.flat
							? `${ __(
									'This is the flat part before it is sewn. Fill it to the edges, for what falls outside the solid line is cut away. The labels on the pattern are in English: keep text and logos inside "Safe Print Area" (the dashed line), "Folding line" is where the part is folded, "Collar" is the collar, and "Placement on product" shows where the part sits. See how it looks with "See the finished product".',
									'merchboard'
								) } `
							: null }
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

				<aside
					className="merchboard-product-designer__inspector"
					aria-label={ __( 'Design details', 'merchboard' ) }
				>
					{ selectedObject === null ? null : (
						<section className="merchboard-product-designer__card">
							<h3>{ __( 'Selected element', 'merchboard' ) }</h3>
							<Properties
								object={ selectedObject }
								fonts={ fonts }
								threads={ embroidery?.threads ?? null }
								plan={ plan }
								onThread={ pickThread }
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
						</section>
					) }
					{ embroidery === null ? null : (
						<ThreadPanel
							threads={ embroidery.threads }
							max={ embroidery.max }
							plan={ plan }
							onSwap={ swapThread }
						/>
					) }
					<PricePanel
						base={ base }
						locale={ locale }
						decimals={ overview.priceDecimals }
						profitKind={ profitKind }
						profitValue={ profitValue }
						price={ price }
						areas={ areas }
						onProfitKind={ setProfitKind }
						onProfitValue={ setProfitValue }
					/>
					<NextSteps />
				</aside>
			</div>
		</div>
	);
}

/**
 * What happens after a design is sent.
 */
function reviewNote(): string {
	return __(
		'The finished mockups are made automatically before we can approve your design. We then review it and answer you, also if it does not look right or something else is wrong.',
		'merchboard'
	);
}

/**
 * The way from a design to the shop.
 */
function NextSteps() {
	return (
		<section className="merchboard-product-designer__card">
			<h3>{ __( 'From design to shop', 'merchboard' ) }</h3>
			<ol className="merchboard-product-designer__steps">
				<li>
					{ __(
						'Design your product and set your profit.',
						'merchboard'
					) }
				</li>
				<li>
					{ __(
						'Send it for approval. The finished mockups are made automatically before we can approve your design.',
						'merchboard'
					) }
				</li>
				<li>
					{ __(
						'We review your design and answer you, also if it does not look right or something else is wrong.',
						'merchboard'
					) }
				</li>
			</ol>
		</section>
	);
}

/**
 * The creator's profit with the customer's price.
 *
 * @param props               Props.
 * @param props.base          The base product.
 * @param props.locale        Locale.
 * @param props.decimals      Store price decimals.
 * @param props.profitKind    Amount or percent.
 * @param props.profitValue   The profit as entered.
 * @param props.price         The customer's price; null when the profit is invalid.
 * @param props.areas         Used design areas; one is included in the base price.
 * @param props.onProfitKind  Changes the kind.
 * @param props.onProfitValue Changes the value.
 */
function PricePanel( props: {
	readonly base: DesignerBase;
	readonly locale: string;
	readonly decimals: number;
	readonly profitKind: ProfitKind;
	readonly profitValue: string;
	readonly price: number | null;
	readonly areas: number;
	readonly onProfitKind: ( value: ProfitKind ) => void;
	readonly onProfitValue: ( value: string ) => void;
} ) {
	const { base, locale, price, decimals } = props;
	const extra = Math.max( 0, props.areas - 1 );
	const secured =
		Number( base.basePrice ) + extra * Number( base.extraSidePrice );
	const money = ( value: number | string ): string =>
		moneyText(
			typeof value === 'number' ? value.toFixed( decimals ) : value,
			base.currency,
			locale
		);

	return (
		<section className="merchboard-product-designer__card">
			<h3>{ __( 'Price and profit', 'merchboard' ) }</h3>
			<span
				className="merchboard-product-designer__segment"
				role="group"
				aria-label={ __( 'Your profit', 'merchboard' ) }
			>
				<button
					type="button"
					aria-pressed={ props.profitKind === 'fixed' }
					onClick={ () => props.onProfitKind( 'fixed' ) }
				>
					{ sprintf(
						/* translators: %s: currency code. */ __(
							'Amount (%s)',
							'merchboard'
						),
						base.currency
					) }
				</button>
				<button
					type="button"
					aria-pressed={ props.profitKind === 'percent' }
					onClick={ () => props.onProfitKind( 'percent' ) }
				>
					{ __( 'Percent', 'merchboard' ) }
				</button>
			</span>
			<Labelled
				className="merchboard-field"
				before
				label={
					<>
						<span className="merchboard-field__label">
							{ __( 'Your profit', 'merchboard' ) }
						</span>
					</>
				}
			>
				{ ( id ) => (
					<input
						id={ id }
						className="merchboard-input"
						inputMode="decimal"
						value={ props.profitValue }
						onChange={ ( event ) =>
							props.onProfitValue( event.target.value )
						}
					/>
				) }
			</Labelled>
			<dl className="merchboard-product-designer__summary">
				<div>
					<dt>{ __( 'Base price', 'merchboard' ) }</dt>
					<dd>{ money( base.basePrice ) }</dd>
				</div>
				{ extra > 0 && Number( base.extraSidePrice ) > 0 ? (
					<div>
						<dt>
							{ sprintf(
								/* translators: 1: extra design areas, 2: price of one. */ __(
									'Extra design areas: %1$d × %2$s',
									'merchboard'
								),
								extra,
								money( base.extraSidePrice )
							) }
						</dt>
						<dd>
							{ money( extra * Number( base.extraSidePrice ) ) }
						</dd>
					</div>
				) : null }
				<div>
					<dt>{ __( 'Your profit', 'merchboard' ) }</dt>
					<dd>{ price === null ? '–' : money( price - secured ) }</dd>
				</div>
				<div className="merchboard-product-designer__total">
					<dt>{ __( 'Customer price incl. VAT', 'merchboard' ) }</dt>
					<dd>{ price === null ? '–' : money( price ) }</dd>
				</div>
			</dl>
			{ price === null ? (
				<p className="merchboard-product-designer__hint">
					{ __( 'Enter a profit of at least 0.', 'merchboard' ) }
				</p>
			) : null }
			{ extra === 0 && Number( base.extraSidePrice ) > 0 ? (
				<p className="merchboard-product-designer__hint">
					{ sprintf(
						/* translators: %s: price of one extra design area. */ __(
							'One design area is included. Each extra area costs %s.',
							'merchboard'
						),
						money( base.extraSidePrice )
					) }
				</p>
			) : null }
			<p className="merchboard-product-designer__hint">
				{ __(
					'The company always keeps the base price and the price of extra design areas. Your profit is incl. VAT; the VAT on it is deducted if you are not VAT registered.',
					'merchboard'
				) }
			</p>
		</section>
	);
}

/**
 * The threads of a pack to pick from.
 *
 * @param props          Props.
 * @param props.threads  The threads.
 * @param props.selected The colour of the thread in use.
 * @param props.onPick   Called with the picked thread.
 */
function ThreadSwatches( props: {
	readonly threads: readonly DesignerThread[];
	readonly selected: string | null;
	readonly onPick: ( thread: DesignerThread ) => void;
} ) {
	return (
		<div className="merchboard-product-designer__threads">
			{ props.threads.map( ( thread ) => (
				<button
					key={ thread.code }
					type="button"
					className="merchboard-product-designer__thread"
					style={ { backgroundColor: thread.hex } }
					title={ `${ thread.code } ${ thread.name }` }
					aria-label={ `${ thread.code } ${ thread.name }` }
					aria-pressed={ props.selected === thread.hex }
					onClick={ () => props.onPick( thread ) }
				/>
			) ) }
		</div>
	);
}

/**
 * The threads of an embroidered side: what was found, and the choice of
 * another thread for each.
 *
 * @param props         Props.
 * @param props.threads The pack's threads.
 * @param props.max     The most threads on the side.
 * @param props.plan    The side's threads; null while they are worked out.
 * @param props.onSwap  Called with the thread found and the one to use (null: back to the one found).
 */
function ThreadPanel( props: {
	readonly threads: readonly DesignerThread[];
	readonly max: number;
	readonly plan: ThreadPlan | null;
	readonly onSwap: ( found: string, used: string | null ) => void;
} ) {
	const { plan } = props;

	return (
		<section className="merchboard-product-designer__card">
			<h3>
				{ sprintf(
					/* translators: 1: threads in use, 2: the most threads allowed. */
					__( 'Thread colours (%1$d of %2$d)', 'merchboard' ),
					plan?.threads.length ?? 0,
					props.max
				) }
			</h3>
			<p className="merchboard-product-designer__hint">
				{ __(
					'This side is embroidered: every colour becomes the nearest thread. Choose another thread below if you like.',
					'merchboard'
				) }
			</p>
			<p className="merchboard-product-designer__hint">
				{ __(
					'Designs with a lot of fine detail are rejected, as they often cannot be embroidered correctly.',
					'merchboard'
				) }
			</p>
			{ ( plan?.merged ?? 0 ) > 0 ? (
				<Alert tone="info">
					{ __(
						'Your design has more colours than the threads allow, so the smallest ones use the nearest thread.',
						'merchboard'
					) }
				</Alert>
			) : null }
			{ plan?.rows.map( ( row ) => (
				<details key={ row.found.code }>
					<summary>
						<span
							className="merchboard-product-designer__thread"
							style={ { backgroundColor: row.used.hex } }
						/>{ ' ' }
						{ `${ row.used.code } ${ row.used.name }` }
					</summary>
					<ThreadSwatches
						threads={ props.threads }
						selected={ row.used.hex }
						onPick={ ( thread ) =>
							props.onSwap( row.found.code, thread.code )
						}
					/>
					{ row.used.code === row.found.code ? null : (
						<Button
							variant="secondary"
							size="sm"
							onClick={ () =>
								props.onSwap( row.found.code, null )
							}
						>
							{ sprintf(
								/* translators: %s: the thread found. */
								__( 'Back to %s', 'merchboard' ),
								row.found.name
							) }
						</Button>
					) }
				</details>
			) ) }
		</section>
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
		case 'design_name_taken':
			return __(
				'A product with this name already exists. Add something to the name to tell them apart.',
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

/**
 * The message of a refused submission.
 *
 * @param reason The server's (or the renderer's) reason.
 */
function submitError( reason: string ): string {
	switch ( reason ) {
		case 'design_empty':
			return __(
				'Add something to your product before you send it.',
				'merchboard'
			);
		case 'design_stale':
			return __(
				'The product was changed by us in the meantime. Open your design again and send it.',
				'merchboard'
			);
		case 'submission_pending':
		case 'design_not_draft':
			return __(
				'This product already waits for approval.',
				'merchboard'
			);
		case 'file_too_large':
			return __(
				'An image of your design is too large to send. Please contact us.',
				'merchboard'
			);
		case 'print_threads':
			return __(
				'The thread colours of this product have changed. Reload the page and send your design again.',
				'merchboard'
			);
		case 'render':
			return __(
				'Your design could not be drawn in this browser. Please try again, or use another browser.',
				'merchboard'
			);
		default:
			return __(
				'Your design could not be sent. Please try again.',
				'merchboard'
			);
	}
}

/**
 * A product that waits for approval: what was sent and "Withdraw".
 *
 * @param props Props.
 */
function Waiting( props: DesignerEditorProps ) {
	const { product, overview, locale, onBack, onProduct, onDelete } = props;
	const pending = product.pending!;
	const currency =
		overview.bases.find( ( base ) => base.id === product.baseId )
			?.currency ?? '';
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( false );

	const withdraw = async () => {
		setBusy( true );
		setFailed( false );
		const result = await withdrawDesign( product.id );
		setBusy( false );

		if ( result.ok ) {
			onProduct( result.data );
		} else {
			setFailed( true );
		}
	};

	return (
		<>
			<Alert tone="info">
				{ sprintf(
					/* translators: 1: product title, 2: the customer's price. */ __(
						'%1$s waits for our approval (customer price incl. VAT: %2$s). We let you know when it has been reviewed. Until then nothing changes in the shop.',
						'merchboard'
					),
					pending.title,
					pending.price === null
						? '–'
						: moneyText( pending.price, currency, locale )
				) }{ ' ' }
				{ reviewNote() }
			</Alert>
			{ failed ? (
				<Alert tone="danger">
					{ __(
						'The design could not be withdrawn. Please try again.',
						'merchboard'
					) }
				</Alert>
			) : null }
			<div className="merchboard-product-designer__actions">
				<Button variant="secondary" onClick={ onBack }>
					{ __( 'Back to my designs', 'merchboard' ) }
				</Button>
				<Button
					variant="ghost"
					onClick={ () => void withdraw() }
					isBusy={ busy }
				>
					{ __( 'Withdraw and edit', 'merchboard' ) }
				</Button>
				<Button
					variant="ghost"
					onClick={ () => onDelete( pending.title ) }
				>
					{ __( 'Delete product', 'merchboard' ) }
				</Button>
			</div>
		</>
	);
}

/**
 * A product in the shop: its live version and "Make a change".
 *
 * @param props Props.
 */
function InShop( props: DesignerEditorProps ) {
	const { product, overview, locale, onBack, onProduct, onDelete } = props;
	const live = product.live!;
	const hidden = product.state === 'hidden';
	const shownPrice = ( currency: string ): string =>
		live.price === null ? '–' : moneyText( live.price, currency, locale );
	const currency =
		overview.bases.find( ( base ) => base.id === product.baseId )
			?.currency ?? '';
	const [ busy, setBusy ] = useState( false );
	const shown = shownPrice( currency );
	const [ moving, setMoving ] = useState( false );
	const [ failed, setFailed ] = useState( false );

	const move = async () => {
		setMoving( true );
		setFailed( false );
		const result = await setShopPublished( product.id, hidden );
		setMoving( false );

		if ( result.ok ) {
			onProduct( result.data );
		} else {
			setFailed( true );
		}
	};

	const change = async () => {
		setBusy( true );
		setFailed( false );
		const result = await startChange( product.id );
		setBusy( false );

		if ( result.ok ) {
			onProduct( result.data );
		} else {
			setFailed( true );
		}
	};

	return (
		<>
			<Alert tone={ hidden ? 'info' : 'success' }>
				{ hidden
					? sprintf(
							/* translators: 1: product title, 2: the customer's price. */ __(
								'%1$s is not in the shop now (customer price incl. VAT: %2$s). You can put it back without a new approval.',
								'merchboard'
							),
							live.title,
							shown
						)
					: sprintf(
							/* translators: 1: product title, 2: the customer's price. */ __(
								'%1$s is in the shop (customer price incl. VAT: %2$s). A change is sent for approval like a new design; the shop keeps this version until we approve it.',
								'merchboard'
							),
							live.title,
							shown
						) }
			</Alert>
			{ failed ? (
				<Alert tone="danger">
					{ __(
						'That did not work. Please try again.',
						'merchboard'
					) }
				</Alert>
			) : null }
			<div className="merchboard-product-designer__actions">
				<Button variant="secondary" onClick={ onBack }>
					{ __( 'Back to my designs', 'merchboard' ) }
				</Button>
				<Button onClick={ () => void change() } isBusy={ busy }>
					{ __( 'Make a change', 'merchboard' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => void move() }
					isBusy={ moving }
				>
					{ hidden
						? __( 'Put in the shop', 'merchboard' )
						: __( 'Set as draft', 'merchboard' ) }
				</Button>
				<Button
					variant="ghost"
					onClick={ () => onDelete( live.title ) }
				>
					{ __( 'Delete product', 'merchboard' ) }
				</Button>
			</div>
		</>
	);
}

interface ProductPanelProps {
	readonly base: DesignerBase;
	readonly locale: string;
	/** The creator's name. */
	readonly creator: string;
	/** The creator's addition to the name. */
	readonly title: string;
	readonly colors: readonly string[];
	readonly sizes: readonly string[];
	readonly onTitle: ( value: string ) => void;

	readonly onColors: ( value: readonly string[] ) => void;
	readonly onSizes: ( value: readonly string[] ) => void;
}

/**
 * The name, colours and sizes.
 *
 * @param props Props.
 */
function ProductPanel( props: ProductPanelProps ) {
	const { base } = props;
	const toggle = (
		list: readonly string[],
		value: string,
		order: readonly string[]
	) =>
		order.filter( ( item ) =>
			item === value ? ! list.includes( value ) : list.includes( item )
		);

	return (
		<div className="merchboard-product-designer__stack">
			<div className="merchboard-field">
				<span className="merchboard-field__label">
					{ __( 'Product name', 'merchboard' ) }
				</span>
				<strong>{ `${ props.creator } ${ base.name }`.trim() }</strong>
			</div>
			<Labelled
				className="merchboard-field"
				before
				label={
					<>
						<span className="merchboard-field__label">
							{ __( 'Add to the name', 'merchboard' ) }
						</span>
					</>
				}
			>
				{ ( id ) => (
					<input
						id={ id }
						className="merchboard-input"
						value={ props.title }
						maxLength={ 60 }
						onChange={ ( event ) =>
							props.onTitle( event.target.value )
						}
					/>
				) }
			</Labelled>
			<p className="merchboard-product-designer__hint">
				{ __(
					'The name starts with your name and the product. If you make more than one of the same product, add a word to tell them apart.',
					'merchboard'
				) }
			</p>
			<fieldset className="merchboard-product-designer__options">
				<legend className="merchboard-field__label">
					{ __( 'Colours', 'merchboard' ) }
				</legend>
				{ base.colors.map( ( color ) => (
					<Labelled
						key={ color.key }
						className="merchboard-product-designer__option"
						label={
							<>
								<span
									className="merchboard-product-designer__swatch merchboard-product-designer__swatch--small"
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
			<fieldset className="merchboard-product-designer__options">
				<legend className="merchboard-field__label">
					{ __( 'Sizes', 'merchboard' ) }
				</legend>
				{ base.sizes.map( ( size ) => (
					<Labelled
						key={ size }
						className="merchboard-product-designer__option"
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
		return (
			<p className="merchboard-product-designer__hint">{ props.empty }</p>
		);
	}

	return (
		<ul className="merchboard-product-designer__grid">
			{ props.assets.map( ( asset ) => {
				const url = props.images.get( asset.id );

				return (
					<li key={ asset.id }>
						<button
							type="button"
							className="merchboard-product-designer__thumb"
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
 * The selected object's settings.
 *
 * @param props          Props.
 * @param props.object   The object.
 * @param props.fonts    Approved fonts.
 * @param props.threads  The pack's threads on an embroidered side, otherwise null.
 * @param props.plan     The side's threads.
 * @param props.onThread Called with the thread picked as the colour.
 * @param props.onChange Changes it.
 * @param props.onRemove Removes it.
 * @param props.onMove   Moves it.
 */
function Properties( props: {
	readonly object: DesignObject;
	readonly fonts: DesignerOverview[ 'fonts' ];
	readonly threads: readonly DesignerThread[] | null;
	readonly plan: ThreadPlan | null;
	readonly onThread: ( thread: DesignerThread ) => void;
	readonly onChange: ( change: Partial< DesignObject > ) => void;
	readonly onRemove: () => void;
	readonly onMove: ( direction: 1 | -1 ) => void;
} ) {
	const { object, onChange, threads } = props;
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
		<div className="merchboard-product-designer__properties">
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
						className="merchboard-product-designer__option"
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
						className="merchboard-product-designer__option"
						before
						label={ <>{ __( 'Colour', 'merchboard' ) }</> }
					>
						{ ( id ) =>
							threads === null ? (
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
							) : (
								<ThreadSwatches
									threads={ threads }
									selected={
										props.plan?.colorOf( text.fill ) ?? null
									}
									onPick={ props.onThread }
								/>
							)
						}
					</Labelled>
				</>
			) }
			{ shape === null ? null : (
				<>
					<Labelled
						className="merchboard-product-designer__option"
						before
						label={ <>{ __( 'Fill', 'merchboard' ) }</> }
					>
						{ ( id ) =>
							threads === null ? (
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
							) : (
								<ThreadSwatches
									threads={ threads }
									selected={
										shape.fill === null
											? null
											: ( props.plan?.colorOf(
													shape.fill
												) ?? null )
									}
									onPick={ props.onThread }
								/>
							)
						}
					</Labelled>
					<Labelled
						className="merchboard-product-designer__option"
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
			{ threads !== null ? null : (
				<Labelled
					className="merchboard-product-designer__option"
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
			) }
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
