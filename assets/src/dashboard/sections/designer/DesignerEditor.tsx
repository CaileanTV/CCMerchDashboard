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
	copyDesignerProduct,
	deleteDesignerImage,
	clipartAtlasUrl,
	designerAssetUrl,
	discardChange,
	saveDesignerDraft,
	setDesignerImage,
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
	backgroundOf,
	duplicateObject,
	objectSizeInCm,
	sizeInCm,
	withKnownBackground,
	moveObject,
	fitted,
	newShape,
	newText,
	objectsOf,
	quotePrice,
	updateObject,
	extraAreas,
	extraTotal,
	onColor,
	usedSides,
	withKnownThreads,
	withObjects,
	withThread,
	allPartDocs,
	designsOf,
	mapParts,
	moveColor,
	newColorDesign,
	partDoc,
	partOf,
	prunedDesigns,
	withPart,
	MAX_DESIGNS,
	effectiveParts,
} from './design-model';
import type {
	AdaptMode,
	DesignDocument,
	DesignObject,
	ExtraArea,
	FitMode,
	ShapeType,
	TextObject,
} from './design-model';
import {
	adaptOf,
	shapeOf,
	linkOf,
	linked,
	sharedWith,
	syncedAll,
	unlinked,
	unshared,
	withAdapt,
	withSideBackground,
	withSideObjects,
} from './format-designs';
import type { FabricStage, StageMeasure } from './fabric-stage';
import { FontPicker } from '../../components/FontPicker';
import { addFontStylesheet } from '../alerts/useFontStylesheet';
import { FinishedProduct } from './FinishedProduct';
import {
	mockupPreview,
	hasStitches,
	sceneImages,
	submissionSize,
	submitWithFiles,
	uploadFiles,
} from './submission';
import { BackgroundPanel } from './BackgroundPanel';
import {
	ColorDesignPicker,
	designName,
	MergeDesigns,
	NEW_DESIGN,
} from './ColorDesigns';
import { ImagePicker } from './ImagePicker';
import { SubmitDialog } from './SubmitDialog';
import { LayerList } from './LayerList';
import { ClipartPaints } from './ClipartPaints';
import {
	artOf,
	ClipartPictures,
	handlingOf,
	newAssetImage,
	pixelsOfUrl,
	ruledObjects,
} from './clipart-objects';
import type { AssetLookup } from './clipart-objects';
import { guideLines } from './guides';
import { TemplateLayers } from './TemplateLayers';
import { threadObject, threadSide } from './threads';
import type { ThreadedSide, ThreadPlan } from './threads';

type Tab =
	'product' | 'text' | 'shapes' | 'cliparts' | 'uploads' | 'background';

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
 * A Google font's stylesheet.
 *
 * @param family  Family.
 * @param weights Weights the family has.
 */
function fontUrl( family: string, weights: readonly number[] ): string {
	return `https://fonts.googleapis.com/css2?family=${ family.replace( / /g, '+' ) }:wght@${ weights.join( ';' ) }&display=swap`;
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
	addFontStylesheet( fontUrl( family, weights ) );

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
			design={ mapParts( draft.design, ( part ) =>
				withKnownBackground( part, base.views, base.backgroundColor )
			) }
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
	// "Back and set a profit" (ADR-098): the profit field gets the focus.
	const profitInput = useRef< HTMLInputElement | null >( null );
	const [ toProfit, setToProfit ] = useState( false );
	const [ sending, setSending ] = useState( false );
	const draft = product.draft!;
	// ADR-118: a poster's formats share one design from the start (or never, when the base product does not offer it).
	const [ initial ] = useState( () =>
		base.shareFormats
			? syncedAll(
					props.design,
					base.views,
					draft.sizes,
					draft.colors,
					true
				)
			: unshared( props.design )
	);
	const history = useHistory< DesignDocument >( initial );
	const [ title, setTitle ] = useState( draft.postfix );
	const [ colors, setColors ] = useState< readonly string[] >( draft.colors );
	const [ stitch, setStitch ] = useState(
		props.design.stitch ?? base.stitches[ 0 ] ?? ''
	);
	const [ sizes, setSizes ] = useState< readonly string[] >( draft.sizes );
	// The product's image (ADR-096): `<side>:<colour>`, or null for the first colour's first side.
	const [ primaryImage, setPrimaryImage ] = useState< string | null >(
		draft.primaryImage
	);
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
	const [ measure, setMeasure ] = useState< StageMeasure | null >( null );
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
	const [ copying, setCopying ] = useState( false );
	// A colour joining another design: how to merge is asked first (ADR-096).
	const [ merging, setMerging ] = useState< {
		readonly color: string;
		readonly target: string;
	} | null >( null );
	const [ saved, setSaved ] = useState( {
		design: initial,
		stitch,
		price: draft.price,
		primaryImage: draft.primaryImage,
	} );
	const [ stageError, setStageError ] = useState( false );
	const [ displayWidth, setDisplayWidth ] = useState( DISPLAY_WIDTH );
	const viewport = useRef< HTMLDivElement | null >( null );
	const canvasElement = useRef< HTMLCanvasElement | null >( null );
	const stage = useRef< FabricStage | null >( null );
	const [ stageReady, setStageReady ] = useState( false );
	const loading = useRef( new Set< number >() );
	const sideRef = useRef( side );
	// The side the canvas shows now: a change on it is written to that side only (30.15 review).
	const shownSide = useRef< string | null >( null );
	// A side whose threads could not be worked out is shown as it is.
	const [ threadFailed, setThreadFailed ] = useState< string | null >( null );

	// ADR-115: only the sides of the colour shown and of a chosen size; all of them when none is left.
	const shownViews = useMemo( () => {
		const own = base.views.filter(
			( candidate ) =>
				( candidate.colors.length === 0 ||
					candidate.colors.includes( previewColor ) ) &&
				( candidate.sizes.length === 0 ||
					candidate.sizes.some( ( size ) => sizes.includes( size ) ) )
		);

		return own.length > 0 ? own : base.views;
	}, [ base.views, previewColor, sizes ] );
	const view =
		shownViews.find( ( candidate ) => candidate.key === side ) ??
		shownViews[ 0 ] ??
		base.views[ 0 ];
	// ADR-115: what every chosen size of the side keeps (its cut and bleed); null when nothing is cut.
	const trim = useMemo( () => {
		const own = Object.entries( view?.trims ?? {} )
			.filter( ( [ size ] ) => sizes.includes( size ) )
			.map( ( [ , cut ] ) => cut );

		if ( own.length === 0 ) {
			return null;
		}

		const left = Math.max( ...own.map( ( cut ) => cut.x ) );
		const top = Math.max( ...own.map( ( cut ) => cut.y ) );
		const right = Math.min( ...own.map( ( cut ) => cut.x + cut.width ) );
		const bottom = Math.min( ...own.map( ( cut ) => cut.y + cut.height ) );

		return right > left && bottom > top
			? { left, top, width: right - left, height: bottom - top }
			: null;
	}, [ view, sizes ] );
	// The whole document; the canvas edits the design of the colour shown (ADR-096).
	const whole = history.value;
	const activePart = partOf( whole, previewColor );
	// Memoised: effects depend on it, and a new object each render would run them again and again (review 34.12).
	const design = useMemo(
		() => partDoc( whole, activePart ),
		[ whole, activePart ]
	);
	const previewRef = useRef( previewColor );
	previewRef.current = previewColor;
	sideRef.current = view?.key ?? side;
	// ADR-118: the chosen sizes and the colours of the design shown, for edits of a shared format.
	const partColors = colors.filter(
		( key ) => partOf( whole, key ) === activePart
	);
	const formatRef = useRef( { sizes, colors: partColors } );
	formatRef.current = { sizes, colors: partColors };

	/**
	 * Changes the design of the colour shown (ADR-096); every colour with that design follows.
	 *
	 * @param change The change.
	 * @param live   Without a step of its own: a change still going on (between checkpoint and settle).
	 */
	const editPart = (
		change: ( part: DesignDocument ) => DesignDocument,
		live = false
	) =>
		( live ? history.live : history.set )( ( full ) => {
			const id = partOf( full, previewRef.current );

			return withPart( full, id, change( partDoc( full, id ) ) );
		} );
	// "Creator Base product - Addition", as the server names it (ADR-102).
	const addition = title
		.trim()
		.replace( /^[\s\-–—]+/u, '' )
		.trim();
	const fullName = [ `${ overview.creator } ${ base.name }`.trim(), addition ]
		.filter( ( part ) => part !== '' )
		.join( ' - ' );
	// The draft's own cliparts too: one removed from the library stays in it (ADR-121).
	const allAssets = useMemo( () => {
		const known = new Map(
			[ ...overview.uploads, ...overview.cliparts ].map( ( asset ) => [
				asset.id,
				asset,
			] )
		);

		for ( const asset of product.assets ) {
			if ( ! known.has( asset.id ) ) {
				known.set( asset.id, asset );
			}
		}

		return Array.from( known.values() );
	}, [ overview, product.assets ] );
	// The library of this base product: a clipart for chosen products only on those (ADR-121).
	const library = useMemo(
		() =>
			overview.cliparts.filter(
				( asset ) =>
					( asset.clipart?.bases ?? null ) === null ||
					( asset.clipart?.bases ?? [] ).includes( base.id )
			),
		[ overview.cliparts, base.id ]
	);
	const assetLookup = useMemo( (): AssetLookup => {
		const byId = new Map(
			allAssets.map( ( asset ) => [ asset.id, asset ] )
		);

		return ( id ) => byId.get( id );
	}, [ allAssets ] );
	// The cliparts' atlases and pictures in the creator's paints (ADR-121).
	const [ pictures ] = useState(
		() =>
			new ClipartPictures( async ( id ) => {
				const url = await clipartAtlasUrl( id );

				return url === null ? null : pixelsOfUrl( url );
			} )
	);
	const [ atlasTick, setAtlasTick ] = useState( 0 );
	const clipartKit = { lookup: assetLookup, pictures };
	// As the server stores them: a clipart's rules hold whatever edit made them (ADR-121).
	const objects =
		view === undefined
			? []
			: ruledObjects(
					objectsOf( design, view.key ),
					assetLookup,
					view.printWidth,
					view.printHeight,
					view.embroidery !== null
				);
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
	const fonts = overview.fonts;
	// Every colour, as the server prices a design (BaseProduct::extra_amount()).
	const baseColorKeys = base.colors.map( ( color ) => color.key );
	const extras = extraAreas(
		base.views,
		design,
		sizes,
		base.extraSidePrice,
		base.smallSidePrice,
		baseColorKeys
	);
	const feeText =
		Number( overview.digitizationFee ) > 0
			? moneyText( overview.digitizationFee, base.currency, locale )
			: null;
	const price = quotePrice(
		base.basePrice,
		profitKind,
		profitValue,
		overview.priceDecimals,
		extraTotal( extras )
	);
	// A design's price, and its price with profit 0 (ADR-098).
	const partPrices = ( part: DesignDocument ) => {
		const extra = extraTotal(
			extraAreas(
				base.views,
				part,
				sizes,
				base.extraSidePrice,
				base.smallSidePrice,
				baseColorKeys
			)
		);

		return {
			price: quotePrice(
				base.basePrice,
				profitKind,
				profitValue,
				overview.priceDecimals,
				extra
			),
			zero: quotePrice(
				base.basePrice,
				'fixed',
				'0',
				overview.priceDecimals,
				extra
			),
		};
	};
	// The price of each design the colours use, when there is more than one (ADR-096).
	const designPrices =
		designsOf( whole ).length === 0
			? []
			: effectiveParts( whole, colors ).map( ( part ) => ( {
					colors: part.colors.map(
						( key ) =>
							base.colors.find( ( color ) => color.key === key )
								?.name ?? key
					),
					...partPrices( partDoc( whole, part.id ) ),
				} ) );
	// The highest of the prices is what the creator is told (ADR-096 pkt. 4).
	const highest = designPrices.reduce< number | null >(
		( top, item ) =>
			item.price === null || ( top !== null && top >= item.price )
				? top
				: item.price,
		price
	);
	// A profit is 0 when it changes no design's price, also a percent rounded away (ADR-098).
	const zeroProfit = (
		designPrices.length > 0 ? designPrices : [ partPrices( design ) ]
	).every( ( item ) => item.price !== null && item.price === item.zero );
	const scenePreview = base.views.some(
		( candidate ) =>
			candidate.scene?.preview === true || hasStitches( candidate )
	);
	// The mockups the product's image can be: each chosen colour on the used sides of its design.
	// A side of some colours only has images of those (ADR-115).
	const imageChoices = colors.flatMap( ( color ) =>
		onColor(
			base.views,
			usedSides( partDoc( whole, partOf( whole, color ) ) ),
			color
		).map( ( key ) => ( {
			value: `${ key }:${ color }`,
			label: `${ base.colors.find( ( item ) => item.key === color )?.name ?? color } – ${ base.views.find( ( item ) => item.key === key )?.name ?? key }`,
		} ) )
	);
	const previewInput = {
		product: product.id,
		base,
		design: whole,
		colors,
		sizes,
		image: ( id: number ) => imageOf( id ),
		fonts: () => fontsOf( whole ),
		cliparts: clipartKit,
	};

	/** A choice of the product image drawn as the shop will show it (ADR-096). */
	const drawImage = ( value: string ): Promise< string | null > => {
		const at = value.lastIndexOf( ':' );

		return mockupPreview(
			previewInput,
			value.slice( 0, at ),
			value.slice( at + 1 )
		);
	};
	// What the drawn images show: the design and its colours.
	const drawKey = useMemo(
		() => ( { whole, colors: [ ...colors ].sort().join() } ),
		[ whole, colors ]
	);

	const dirty =
		primaryImage !== saved.primaryImage ||
		whole !== saved.design ||
		stitch !== saved.stitch ||
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
					// ADR-122: the views' photos under the guides.
					...view.guides
						.map( ( guide ) => guide.figure )
						.filter( ( id ) => id > 0 ),
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
			ensureImages( library );
		} else if ( tab === 'uploads' ) {
			ensureImages( overview.uploads );
		}
	}, [ tab, overview, library, ensureImages ] );

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
					onChange: ( next ) => {
						// Objects of another side, shown while the new side's threads load, are never saved to it.
						if ( shownSide.current !== sideRef.current ) {
							return;
						}

						editPart( ( present ) =>
							withSideObjects(
								present,
								base.views,
								sideRef.current,
								next,
								formatRef.current.sizes,
								formatRef.current.colors
							)
						);
					},
					onSelect: setSelected,
					onMeasure: setMeasure,
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

	// The pictures of the cliparts shown; a new key when an atlas arrives (ADR-121).
	const clipartResolver = useMemo(
		() =>
			pictures.resolver(
				assetLookup,
				embroidery !== null,
				embroidery?.threads ?? null
			),
		// eslint-disable-next-line react-hooks/exhaustive-deps -- atlasTick marks the atlases loaded.
		[ pictures, assetLookup, embroidery, atlasTick ]
	);

	// The atlases of every clipart in the design.
	useEffect( () => {
		let cancelled = false;
		const before = pictures.resolver( assetLookup ).key;
		const all = allPartDocs( whole )
			.flatMap( ( part ) => Object.values( part.sides ) )
			.flat();

		void pictures.ready( all, assetLookup ).then( () => {
			if (
				! cancelled &&
				pictures.resolver( assetLookup ).key !== before
			) {
				setAtlasTick( ( tick ) => tick + 1 );
			}
		} );

		return () => {
			cancelled = true;
		};
	}, [ whole, assetLookup, pictures ] );

	// An embroidered side is shown in its threads.
	useEffect( () => {
		if ( view === undefined || embroidery === null ) {
			setThreaded( null );

			return undefined;
		}

		let cancelled = false;

		threadSide( objects, images, swaps ?? {}, embroidery, ( object ) =>
			pictures.pixels( object, assetLookup, true, embroidery.threads )
		)
			.then( ( result ) => {
				if ( ! cancelled ) {
					setThreaded( { ...result, side: view.key } );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setThreadFailed( view.key );
				}
			} );

		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- objects and swaps are derived from design and side.
	}, [ design, side, images, embroidery, clipartResolver ] );

	useEffect( () => {
		if (
			embroidery !== null &&
			plan === null &&
			threadFailed !== view?.key
		) {
			// Until the threads are known the canvas shows nothing, not the previous side.
			shownSide.current = null;
			void stage.current?.render( [], images, null, null );

			return;
		}

		shownSide.current = view?.key ?? null;
		void stage.current?.render(
			objects,
			plan === null || threaded === null ? images : threaded.images,
			selected,
			plan === null
				? null
				: {
						key: plan.signature,
						object: ( object ) => threadObject( object, plan ),
					},
			view === undefined || embroidery !== null || ! base.backgroundColor
				? null
				: backgroundOf( design, view.key ),
			clipartResolver
		);
		// Fonts arriving re-render the texts.
		// eslint-disable-next-line react-hooks/exhaustive-deps -- objects is derived from design and side.
	}, [
		stageReady,
		design,
		side,
		images,
		threaded,
		threadFailed,
		selected,
		fontsReady,
		clipartResolver,
	] );

	const setObjects = ( next: readonly DesignObject[], live = false ) => {
		if ( view !== undefined ) {
			const ruled = ruledObjects(
				next,
				assetLookup,
				view.printWidth,
				view.printHeight,
				view.embroidery !== null
			);

			editPart(
				( present ) =>
					withSideObjects(
						present,
						base.views,
						view.key,
						ruled,
						sizes,
						partColors
					),
				live
			);
		}
	};

	// ADR-118: every linked format follows the sizes and colours chosen; sharing off leaves each format its own.
	useEffect( () => {
		history.live( ( full ) => {
			const next = base.shareFormats
				? syncedAll( full, base.views, sizes, colors, false )
				: unshared( full );

			return JSON.stringify( next ) === JSON.stringify( full )
				? full
				: next;
		} );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- Only when the choice changes.
	}, [ sizes, colors ] );

	const formatLink = view === undefined ? null : linkOf( design, view.key );

	/**
	 * Puts the side shown on its orientation's shared design, or on its own (ADR-118).
	 *
	 * @param shared Whether it shares.
	 */
	const chooseFormatDesign = ( shared: boolean ) => {
		if ( view === undefined ) {
			return;
		}

		if ( ! shared ) {
			editPart( ( present ) => unlinked( present, view.key ) );

			return;
		}

		if (
			objects.length > 0 &&
			design.formats?.shared[ view.colors[ 0 ] ?? '' ] !== undefined &&
			// eslint-disable-next-line no-alert -- A plain confirmation, as elsewhere in the dashboard.
			! window.confirm(
				__(
					'Use the shared design on this format? Its own design is replaced.',
					'merchboard'
				)
			)
		) {
			return;
		}

		editPart( ( present ) =>
			linked( present, base.views, view.key, sizes, partColors )
		);
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

	const change = ( fields: Partial< DesignObject >, live = false ) => {
		if ( selectedObject !== null ) {
			setObjects(
				updateObject( objects, selectedObject.id, fields ),
				live
			);
		}
	};

	const remove = ( id: string ) => {
		setObjects( objects.filter( ( object ) => object.id !== id ) );
		setSelected( null );
	};

	const duplicate = ( id: string ) => {
		if ( view === undefined ) {
			return;
		}

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

		const copied = duplicateObject(
			objects,
			id,
			view.printWidth,
			view.printHeight
		);

		if ( copied !== null ) {
			setObjects( copied.objects );
			setSelected( copied.id );
		}
	};

	// The sides "All sides" colours: the printed sides of the chosen sizes (ADR-095). A side of a
	// size that is not offered would count as a design there and refuse the product (review 33.5).
	const printed = base.views
		.filter(
			( candidate ) =>
				candidate.embroidery === null &&
				( candidate.sizes.length === 0 ||
					candidate.sizes.some( ( size ) => sizes.includes( size ) ) )
		)
		.map( ( candidate ) => candidate.key );

	const setBackground = ( chosen: string | null, allSides: boolean ) => {
		if ( view !== undefined ) {
			editPart( ( present ) =>
				withSideBackground(
					present,
					base.views,
					allSides ? printed : [ view.key ],
					chosen,
					sizes,
					partColors
				)
			);
		}
	};

	const swapThread = ( found: string, used: string | null ) => {
		if ( view !== undefined ) {
			editPart( ( present ) =>
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

			editPart( ( present ) =>
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
			add( newAssetImage( asset, view.printWidth, view.printHeight ) );
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

	const colorName = ( key: string ): string =>
		base.colors.find( ( color ) => color.key === key )?.name ?? key;

	/** A colour's new design: a copy of its design now, or another design (asked how first). */
	const chooseDesign = ( color: string, choice: string ) => {
		if ( choice === NEW_DESIGN ) {
			const made = newColorDesign( whole, color );

			if ( made !== null ) {
				history.set( made.design );
				setPreviewColor( color );
			}

			return;
		}

		if ( choice !== partOf( whole, color ) ) {
			setMerging( { color, target: choice } );
		}
	};

	/** "Copy design" (ADR-096 pkt. 7): saves what is open, then opens the copy. */
	const copy = async () => {
		if ( dirty && ( await persist() ) === null ) {
			return;
		}

		setCopying( true );
		setMessage( null );
		const result = await copyDesignerProduct( product.id );
		setCopying( false );

		if ( result.ok ) {
			onProduct( result.data );
		} else {
			setMessage( {
				tone: 'danger',
				text: __(
					'The design could not be copied. Please try again.',
					'merchboard'
				),
			} );
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
			primaryImage: imageChoices.some(
				( choice ) => choice.value === primaryImage
			)
				? primaryImage
				: null,
			design: {
				...mapParts(
					base.shareFormats
						? syncedAll(
								prunedDesigns( whole, colors ),
								base.views,
								sizes,
								colors,
								false
							)
						: prunedDesigns( whole, colors ),
					( part ) =>
						withKnownThreads(
							withKnownBackground(
								part,
								base.views,
								base.backgroundColor
							),
							base.views
						)
				),
				...( stitch === '' ? {} : { stitch } ),
			},
		} );
		setSaving( false );

		if ( ! result.ok ) {
			setMessage( {
				tone: 'danger',
				text: saveError( result.error.reason ),
			} );

			return null;
		}

		setSaved( {
			design: whole,
			stitch,
			price: result.data.draft?.price ?? null,
			primaryImage: result.data.draft?.primaryImage ?? null,
		} );

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
			allPartDocs( shown )
				.flatMap( ( part ) => Object.values( part.sides ) )
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

	useEffect( () => {
		if ( toProfit && ! confirming ) {
			setToProfit( false );
			profitInput.current?.focus();
		}
	}, [ toProfit, confirming ] );

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
				sizes: storedDraft.sizes,
				image: imageOf,
				fonts: () => fontsOf( storedDraft.design ),
				cliparts: clipartKit,
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
			// An image that cannot be drawn must not leave the button busy.
		).catch( () => ( { ok: false as const, reason: 'render' } ) );
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
				sizes: storedDraft.sizes,
				image: imageOf,
				fonts: () => fontsOf( storedDraft.design ),
				cliparts: clipartKit,
			},
			() => undefined
		);

		return uploaded.ok ? null : uploaded.reason;
	};

	/** Mockups of the design as it stands: nothing is saved or sent. */
	const showScenes = () =>
		sceneImages( {
			product: product.id,
			base,
			design: whole,
			colors: [ previewColor, ...colors ],
			sizes,
			image: imageOf,
			fonts: () => fontsOf( whole ),
			cliparts: clipartKit,
		} );

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
		...( base.backgroundColor
			? [
					{
						id: 'background' as const,
						label: __( 'Background', 'merchboard' ),
					},
				]
			: [] ),
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
					<Button
						variant="ghost"
						onClick={ () => void copy() }
						isBusy={ copying }
						disabled={ saving || sending }
					>
						{ __( 'Copy design', 'merchboard' ) }
					</Button>
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
					{ ( impersonating || ! base.preview ) &&
					! scenePreview ? null : (
						<Button
							variant="ghost"
							onClick={ () => setFinished( true ) }
							disabled={ sending || saving || finished }
						>
							{ __( 'See the finished product', 'merchboard' ) }
						</Button>
					) }
					{ impersonating ? null : (
						<Button
							onClick={ () => setConfirming( true ) }
							isBusy={ sending }
							disabled={ sending || saving }
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
				<SubmitDialog
					base={ base }
					colors={ colors }
					onColors={ setColors }
					design={ whole }
					images={ imageChoices }
					image={ primaryImage }
					onImage={ setPrimaryImage }
					imageDraw={ drawImage }
					imageDrawKey={ drawKey }
					zeroProfit={ zeroProfit }
					zeroText={ moneyText(
						( 0 ).toFixed( overview.priceDecimals ),
						base.currency,
						locale
					) }
					onSend={ () => void send() }
					onCancel={ () => setConfirming( false ) }
					onSetProfit={ () => {
						setToProfit( true );
						setConfirming( false );
					} }
					summary={
						<>
							{ feeText !== null &&
							base.views.some(
								( candidate ) =>
									candidate.embroidery !== null &&
									effectiveParts( whole, colors ).some(
										( part ) =>
											usedSides(
												partDoc( whole, part.id )
											).includes( candidate.key )
									)
							) ? (
								<Alert tone="info">
									{ feeNotice( feeText ) }
								</Alert>
							) : null }
							<p>
								{ sprintf(
									/* translators: 1: number of files, 2: the customer's price. */ __(
										'Your design is saved and sent with %1$d images (a print file per side and a product image per colour). It waits for our approval (customer price incl. VAT: %2$s); until then it cannot be changed, but you can withdraw it.',
										'merchboard'
									),
									submissionSize( whole, colors, base.views ),
									highest === null
										? '–'
										: moneyText(
												highest.toFixed(
													overview.priceDecimals
												),
												base.currency,
												locale
											)
								) }{ ' ' }
								{ reviewNote() }
							</p>
						</>
					}
				/>
			) : null }

			{ message === null ? null : (
				<Alert tone={ message.tone }>{ message.text }</Alert>
			) }

			{ base.note === '' ? null : (
				<div className="merchboard-product-designer__note">
					<Alert tone="info">{ base.note }</Alert>
				</div>
			) }

			{ merging === null ? null : (
				<MergeDesigns
					colorName={ colorName( merging.color ) }
					target={ merging.target }
					design={ whole }
					onClose={ () => setMerging( null ) }
					onMerge={ ( mode ) => {
						history.set(
							moveColor(
								whole,
								merging.color,
								merging.target,
								mode
							)
						);
						setPreviewColor( merging.color );
						setMerging( null );
					} }
				/>
			) }

			{ finished ? (
				<FinishedProduct
					product={ product.id }
					views={ base.views }
					prepare={ showFinished }
					draw={ scenePreview ? showScenes : undefined }
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
									stitch={ stitch }
									onStitch={ setStitch }
									design={ whole }
									onDesign={ chooseDesign }
									laterChoices={
										colors.length > 1 ||
										imageChoices.length > 1
									}
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
									assets={ library }
									images={ images }
									empty={ __(
										'No cliparts yet.',
										'merchboard'
									) }
									onPick={ addImage }
								/>
							) : null }
							{ tab === 'background' && base.backgroundColor ? (
								<BackgroundPanel
									current={ backgroundOf( design, view.key ) }
									side={ view.name }
									allowed={ view.embroidery === null }
									others={ printed.length > 1 }
									onApply={ ( chosen, allSides ) =>
										setBackground( chosen, allSides )
									}
									onRemove={ ( allSides ) =>
										setBackground( null, allSides )
									}
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
						imageName={ ( id ) => assetLookup( id )?.name }
						selected={ selected }
						onSelect={ setSelected }
						onChange={ ( id, fields ) =>
							setObjects( updateObject( objects, id, fields ) )
						}
						onMove={ ( id, direction ) =>
							setObjects( moveObject( objects, id, direction ) )
						}
						onDuplicate={ duplicate }
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
							{ shownViews.map( ( candidate ) => (
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
									{ candidate.sizes.length > 0 ? (
										<span className="merchboard-product-designer__side-sizes">
											{ candidate.format
												? `${ shapeOf( candidate ) } · `
												: '' }
											{ candidate.sizes
												.filter( ( size ) =>
													sizes.includes( size )
												)
												.join( ', ' ) }
										</span>
									) : null }
								</button>
							) ) }
						</span>
						{ base.shareFormats && view.format ? (
							<span className="merchboard-product-designer__design-status">
								<select
									className="merchboard-product-designer__design-choice"
									aria-label={ __(
										'Design of this format',
										'merchboard'
									) }
									value={
										formatLink === null ? 'own' : 'shared'
									}
									onChange={ ( event ) =>
										chooseFormatDesign(
											event.target.value === 'shared'
										)
									}
								>
									<option value="shared">
										{ sprintf(
											/* translators: %s: orientation, e.g. "Vertical". */
											__(
												'Shared design (%s)',
												'merchboard'
											),
											colorName( view.colors[ 0 ] ?? '' )
										) }
									</option>
									<option value="own">
										{ __( 'Own design', 'merchboard' ) }
									</option>
								</select>
								{ formatLink === null ? null : (
									<select
										className="merchboard-product-designer__design-choice"
										aria-label={ __(
											'How the shared design is fitted to this format',
											'merchboard'
										) }
										value={ adaptOf( design, view.key ) }
										onChange={ ( event ) =>
											editPart( ( present ) =>
												withAdapt(
													present,
													base.views,
													view.key,
													event.target
														.value as AdaptMode,
													sizes,
													partColors
												)
											)
										}
									>
										<option value="smart">
											{ __( 'Fit: smart', 'merchboard' ) }
										</option>
										<option value="fill">
											{ __(
												'Fit: fill the format',
												'merchboard'
											) }
										</option>
										<option value="fit">
											{ __(
												'Fit: whole design inside',
												'merchboard'
											) }
										</option>
									</select>
								) }
								{ formatLink === null
									? null
									: ( () => {
											const names = sharedWith(
												design,
												view.key
											)
												.map(
													( key ) =>
														shownViews.find(
															( candidate ) =>
																candidate.key ===
																key
														)?.name
												)
												.filter(
													( name ): name is string =>
														name !== undefined
												);

											return names.length === 0 ? null : (
												<span>
													{ sprintf(
														/* translators: %s: names of formats. */
														__(
															'Shared with: %s',
															'merchboard'
														),
														names.join( ', ' )
													) }
												</span>
											);
										} )() }
							</span>
						) : null }
						{ colors.length > 1 ? (
							<span className="merchboard-product-designer__design-status">
								<span>
									{ sprintf(
										/* translators: 1: design name, 2: colour names. */
										__( '%1$s – for %2$s', 'merchboard' ),
										designName( whole, activePart ),
										colors
											.filter(
												( key ) =>
													partOf( whole, key ) ===
													activePart
											)
											.map( colorName )
											.join( ', ' )
									) }
								</span>
								<ColorDesignPicker
									design={ whole }
									color={ previewColor }
									colorName={ colorName( previewColor ) }
									canAdd={
										designsOf( whole ).length < MAX_DESIGNS
									}
									onChoose={ ( choice ) =>
										chooseDesign( previewColor, choice )
									}
								/>
							</span>
						) : null }
						<span
							className="merchboard-product-designer__swatches"
							role="group"
							aria-label={ __( 'Preview colour', 'merchboard' ) }
						>
							{ colors
								.flatMap( ( key ) =>
									base.colors.filter(
										( color ) => color.key === key
									)
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
									colorKey={ previewColor }
									part="over"
								/>
							</div>
							<div
								className="merchboard-product-designer__area"
								style={ {
									overflow:
										trim === null ? undefined : 'hidden',
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
							>
								{ guideLines( view.guides ).map( ( x ) => (
									<span
										key={ x }
										className="merchboard-product-designer__guide"
										style={ { left: `${ x * 100 }%` } }
										aria-hidden="true"
									/>
								) ) }
								{ trim === null ? null : (
									<div
										className="merchboard-product-designer__trim"
										style={ {
											left: `${ trim.left * 100 }%`,
											top: `${ trim.top * 100 }%`,
											width: `${ trim.width * 100 }%`,
											height: `${ trim.height * 100 }%`,
										} }
									/>
								) }
							</div>
							{ view.guides.length === 0 ? null : (
								<ul
									className="merchboard-product-designer__views"
									aria-label={ __(
										'Where the design sits on the product',
										'merchboard'
									) }
									style={ {
										left:
											( view.area.x / view.width ) *
											displayWidth *
											zoom,
										top:
											( ( view.area.y +
												view.area.height ) /
												view.height ) *
											displayHeight *
											zoom,
										width:
											( view.area.width / view.width ) *
											displayWidth *
											zoom,
									} }
								>
									{ view.guides.map( ( guide ) => (
										<li
											key={ `${ guide.label }-${ guide.x }` }
											style={ {
												left: `${ guide.x * 100 }%`,
											} }
										>
											{ images.has( guide.figure ) ? (
												<img
													src={ images.get(
														guide.figure
													) }
													alt=""
												/>
											) : null }
											<span>{ guide.label }</span>
										</li>
									) ) }
								</ul>
							) }
							{ measure === null ? null : (
								<span
									className="merchboard-product-designer__measure"
									style={ {
										left: measure.left,
										top: measure.top,
									} }
								>
									{ cmText(
										sizeInCm(
											measure.width,
											measure.height,
											view.dpi
										),
										locale
									) }
								</span>
							) }
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
					{ trim === null ? null : (
						<p className="merchboard-product-designer__trim-note">
							{ __(
								'Everything outside the red line can be cut off.',
								'merchboard'
							) }
						</p>
					) }
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
							<p className="merchboard-product-designer__hint">
								{ sprintf(
									/* translators: %s: width × height in cm, e.g. "12,5 × 8,0 cm". */ __(
										'Size on the print: %s',
										'merchboard'
									),
									cmText(
										objectSizeInCm(
											selectedObject,
											view.dpi
										),
										locale
									)
								) }
							</p>
							<Properties
								object={ selectedObject }
								asset={
									selectedObject.type === 'image'
										? assetLookup( selectedObject.asset )
										: undefined
								}
								onBegin={ history.checkpoint }
								onEnd={ history.settle }
								fonts={ fonts }
								threads={ embroidery?.threads ?? null }
								plan={ plan }
								onThread={ pickThread }
								onChange={ change }
								onFit={ ( mode ) =>
									change(
										fitted(
											selectedObject,
											view.printWidth,
											view.printHeight,
											mode
										)
									)
								}
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
							fee={ feeText }
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
						extras={ extras }
						sizeExtra={ ( size ) =>
							extraTotal(
								extraAreas(
									base.views,
									design,
									[ size ],
									base.extraSidePrice,
									base.smallSidePrice,
									baseColorKeys
								)
							)
						}
						sizes={ sizes }
						onProfitKind={ setProfitKind }
						onProfitValue={ setProfitValue }
						profitRef={ profitInput }
					/>
					{ designPrices.length > 1 ? (
						<section className="merchboard-product-designer__card">
							<h3>{ __( 'Price per colour', 'merchboard' ) }</h3>
							<ul className="merchboard-product-designer__design-prices">
								{ designPrices.map( ( row ) => (
									<li key={ row.colors.join() }>
										<span>{ row.colors.join( ', ' ) }</span>
										<strong>
											{ row.price === null
												? '–'
												: moneyText(
														String( row.price ),
														base.currency,
														locale
													) }
										</strong>
									</li>
								) ) }
							</ul>
						</section>
					) : null }
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
 * @param props                   Props.
 * @param props.base              The base product.
 * @param props.locale            Locale.
 * @param props.decimals          Store price decimals.
 * @param props.profitKind        Amount or percent.
 * @param props.profitValue       The profit as entered.
 * @param props.price             The customer's price; null when the profit is invalid.
 * @param props.extras            The design areas that cost extra.
 * @param props.sizeExtra         What the extra design areas of a size cost.
 * @param props.sizes             The chosen sizes.
 * @param props.onProfitKind      Changes the kind.
 * @param props.onProfitValue     Changes the value.
 * @param props.profitRef         The profit field.
 * @param props.profitRef.current The field element.
 */
function PricePanel( props: {
	readonly base: DesignerBase;
	readonly locale: string;
	readonly decimals: number;
	readonly profitKind: ProfitKind;
	readonly profitValue: string;
	readonly price: number | null;
	readonly extras: readonly ExtraArea[];
	readonly sizeExtra: ( size: string ) => number;
	readonly sizes: readonly string[];
	readonly onProfitKind: ( value: ProfitKind ) => void;
	readonly onProfitValue: ( value: string ) => void;
	/** The profit field, focused on "Back and set a profit" (ADR-098). */
	readonly profitRef?: { current: HTMLInputElement | null };
} ) {
	const { base, locale, price, decimals } = props;
	const extra = extraTotal( props.extras );
	const secured = Number( base.basePrice ) + extra;
	const money = ( value: number | string ): string =>
		moneyText(
			typeof value === 'number' ? value.toFixed( decimals ) : value,
			base.currency,
			locale
		);
	// What each side costs as a further design area, when anything does.
	const prices = base.views
		.map( ( view ) => ( {
			name: view.name,
			price: view.small ? base.smallSidePrice : base.extraSidePrice,
		} ) )
		.filter( ( side ) => Number( side.price ) > 0 )
		.map( ( side ) => `${ side.name } ${ money( side.price ) }` );

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
						ref={ props.profitRef }
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
				{ props.extras
					.filter( ( area ) => area.price > 0 )
					.map( ( area ) => (
						<div key={ area.key }>
							<dt>
								{ sprintf(
									/* translators: %s: side, e.g. "Sleeve". */ __(
										'Extra design area: %s',
										'merchboard'
									),
									area.name
								) }
							</dt>
							<dd>{ money( area.price ) }</dd>
						</div>
					) ) }
				<div>
					<dt>{ __( 'Your profit', 'merchboard' ) }</dt>
					<dd>{ price === null ? '–' : money( price - secured ) }</dd>
				</div>
				<div className="merchboard-product-designer__total">
					<dt>{ __( 'Customer price incl. VAT', 'merchboard' ) }</dt>
					<dd>{ price === null ? '–' : money( price ) }</dd>
				</div>
				{ Object.keys( base.sizePrices ).length === 0
					? null
					: props.sizes.map( ( size ) => {
							const own = quotePrice(
								base.sizePrices[ size ] ?? base.basePrice,
								props.profitKind,
								props.profitValue,
								decimals,
								props.sizeExtra( size )
							);

							return (
								<div key={ size }>
									<dt>
										{ sprintf(
											/* translators: %s: size. */ __(
												'Customer price, %s',
												'merchboard'
											),
											size
										) }
									</dt>
									<dd>
										{ own === null ? '–' : money( own ) }
									</dd>
								</div>
							);
						} ) }
			</dl>
			{ price === null ? (
				<p className="merchboard-product-designer__hint">
					{ __( 'Enter a profit of at least 0.', 'merchboard' ) }
				</p>
			) : null }
			{ prices.length > 0 ? (
				<p className="merchboard-product-designer__hint">
					{ sprintf(
						/* translators: %s: sides with their prices, e.g. "Back 50.00 kr., Sleeve 20.00 kr.". */ __(
							'The most expensive design area you use is included in the base price. Each further area costs: %s.',
							'merchboard'
						),
						prices.join( ', ' )
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
 * What a new embroidery file costs the creator, and when.
 *
 * @param fee The fee as text.
 */
function feeNotice( fee: string ): string {
	return sprintf(
		/* translators: %s: the digitization fee. */ __(
			'A new embroidery file costs %s for digitization. It is drawn from your account at the first purchase of the product, whichever variant it is. If that is your own order, it is drawn from your account too, so your account can go below zero, and the amount is offset against your coming profit. A file you have used before costs nothing.',
			'merchboard'
		),
		fee
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
 * @param props.fee     The price of a new embroidery file as text; null without one.
 */
function ThreadPanel( props: {
	readonly fee: string | null;
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
			{ props.fee === null ? null : (
				<Alert tone="info">{ feeNotice( props.fee ) }</Alert>
			) }
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
			{ plan?.over === true ? (
				<Alert tone="danger">
					{ sprintf(
						/* translators: %d: most threads on the side. */ __(
							'The cliparts on this side need more than %d threads, so the design cannot be sent. Remove a clipart, or choose the same thread for more of its parts.',
							'merchboard'
						),
						props.max
					) }
				</Alert>
			) : null }
			{ plan?.rows.map( ( row ) =>
				row.pinned ? (
					<p
						key={ row.found.code }
						className="merchboard-product-designer__hint"
					>
						<span
							className="merchboard-product-designer__thread"
							style={ { backgroundColor: row.used.hex } }
						/>{ ' ' }
						{ sprintf(
							/* translators: %s: a thread's code and name. */ __(
								'%s (a clipart’s thread, cannot be swapped)',
								'merchboard'
							),
							`${ row.used.code } ${ row.used.name }`
						) }
					</p>
				) : (
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
				)
			) }
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
		case 'design_clipart':
			return __(
				'A clipart in the design can no longer be chosen for this product. Remove it and try again.',
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
		case 'design_sizes':
			return __(
				'Each size you offer needs its own design. Add a design to it, or remove the design from a size you do not offer.',
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
		case 'clipart_threads':
			return __(
				'The cliparts on an embroidered side need more threads than the side can have. Remove a clipart, or choose the same thread for more of its parts.',
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
	const [ image, setImage ] = useState( live.primaryImage ?? '' );
	const [ imaging, setImaging ] = useState( false );

	/** Another image for the product, without a new approval (ADR-096). */
	const chooseImage = async () => {
		setImaging( true );
		setFailed( false );
		const result = await setDesignerImage( product.id, image );
		setImaging( false );

		if ( result.ok ) {
			onProduct( result.data );
		} else {
			setFailed( true );
		}
	};

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
			{ live.images.length < 2 ? null : (
				<section className="merchboard-product-designer__card">
					<h3>{ __( 'Product image', 'merchboard' ) }</h3>
					<ImagePicker
						choices={ live.images.map( ( choice ) => ( {
							value: choice.image,
							label: choice.label,
							url: choice.url,
							large: choice.large,
						} ) ) }
						selected={ image === '' ? live.primaryImage : image }
						onSelect={ setImage }
					/>
					<div className="merchboard-product-designer__actions">
						<Button
							variant="secondary"
							onClick={ () => void chooseImage() }
							isBusy={ imaging }
							disabled={
								image === '' ||
								image === ( live.primaryImage ?? '' )
							}
						>
							{ __( 'Use as the product image', 'merchboard' ) }
						</Button>
					</div>
				</section>
			) }
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
	/** The chosen stitch colour. */
	readonly stitch: string;
	readonly onStitch: ( value: string ) => void;
	/** The whole design, for the design each colour uses (ADR-096). */
	readonly design?: DesignDocument;
	/** A colour's new design: an id, SHARED or NEW_DESIGN. */
	readonly onDesign?: ( color: string, choice: string ) => void;
	/** Whether the order or the main image is chosen when sending (ADR-098). */
	readonly laterChoices?: boolean;
}

/**
 * A size in centimetres, e.g. "12,5 × 8,0 cm".
 *
 * @param size        Width and height (cm).
 * @param size.width  Width (cm).
 * @param size.height Height (cm).
 * @param locale      The viewer's locale.
 */
export function cmText(
	size: { readonly width: number; readonly height: number },
	locale: string
): string {
	// WordPress gives "da_DK"; Intl wants "da-DK" and throws on the other.
	const format = ( value: number ): string => {
		try {
			return new Intl.NumberFormat( locale.replace( '_', '-' ), {
				minimumFractionDigits: 1,
				maximumFractionDigits: 1,
			} ).format( value );
		} catch {
			return value.toFixed( 1 );
		}
	};

	return sprintf(
		/* translators: 1: width in cm, 2: height in cm. */ __(
			'%1$s × %2$s cm',
			'merchboard'
		),
		format( size.width ),
		format( size.height )
	);
}

/**
 * The name of a stitch colour.
 *
 * @param stitch The stitch colour.
 */
function stitchName( stitch: string ): string {
	switch ( stitch ) {
		case 'white':
			return __( 'White', 'merchboard' );
		case 'black':
			return __( 'Black', 'merchboard' );
		case 'clear':
			return __( 'Clear', 'merchboard' );
		default:
			return stitch;
	}
}

/**
 * The name, colours and sizes.
 *
 * @param props Props.
 */
export function ProductPanel( props: ProductPanelProps ) {
	const { base, design, onDesign } = props;
	const chosen = props.colors.flatMap( ( key ) =>
		base.colors.filter( ( color ) => color.key === key )
	);
	const toggle = (
		list: readonly string[],
		value: string,
		order: readonly string[]
	) =>
		order.filter( ( item ) =>
			item === value ? ! list.includes( value ) : list.includes( item )
		);
	// ADR-118: a poster's sizes by format, those of the first chosen orientation.
	const orientation = props.colors[ 0 ] ?? base.colors[ 0 ]?.key ?? '';
	// A base product without sides (a test of the panel alone) has no formats.
	const groups = ( base.views ?? [] ).filter(
		( view ) =>
			view.format &&
			view.sizes.length > 0 &&
			view.colors.includes( orientation )
	);
	const sizeBox = ( size: string ) => (
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
						props.onSizes( toggle( props.sizes, size, base.sizes ) )
					}
				/>
			) }
		</Labelled>
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
			<fieldset className="merchboard-product-designer__options merchboard-product-designer__options--list">
				<legend className="merchboard-field__label">
					{ base.colorLabel !== ''
						? base.colorLabel
						: __( 'Colours', 'merchboard' ) }
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
										props.colors.includes( color.key )
											? props.colors.filter(
													( key ) => key !== color.key
												)
											: [ ...props.colors, color.key ]
									)
								}
							/>
						) }
					</Labelled>
				) ) }
				<Button
					variant="secondary"
					size="sm"
					disabled={ base.colors.every( ( color ) =>
						props.colors.includes( color.key )
					) }
					onClick={ () =>
						props.onColors( [
							...props.colors,
							...base.colors
								.map( ( color ) => color.key )
								.filter(
									( key ) => ! props.colors.includes( key )
								),
						] )
					}
				>
					{ __( 'Select all', 'merchboard' ) }
				</Button>
			</fieldset>
			{ base.stitches.length > 1 ? (
				<fieldset className="merchboard-product-designer__options">
					<legend className="merchboard-field__label">
						{ __( 'Stitching', 'merchboard' ) }
					</legend>
					{ base.stitches.map( ( stitch ) => (
						<Labelled
							key={ stitch }
							className="merchboard-product-designer__option"
							label={ <>{ stitchName( stitch ) }</> }
						>
							{ ( id ) => (
								<input
									id={ id }
									type="radio"
									name="merchboard-stitch"
									checked={ props.stitch === stitch }
									onChange={ () => props.onStitch( stitch ) }
								/>
							) }
						</Labelled>
					) ) }
				</fieldset>
			) : null }
			{ chosen.length > 1 &&
			design !== undefined &&
			onDesign !== undefined ? (
				<div className="merchboard-product-designer__order">
					<span className="merchboard-field__label">
						{ __( 'Design for each colour', 'merchboard' ) }
					</span>
					<ol>
						{ chosen.map( ( color ) => (
							<li key={ color.key }>
								<span
									className="merchboard-product-designer__swatch merchboard-product-designer__swatch--small"
									style={ { background: color.hex } }
									aria-hidden="true"
								/>
								<span>{ color.name }</span>
								<ColorDesignPicker
									design={ design }
									color={ color.key }
									colorName={ color.name }
									canAdd={
										designsOf( design ).length < MAX_DESIGNS
									}
									onChoose={ ( choice ) =>
										onDesign( color.key, choice )
									}
								/>
							</li>
						) ) }
					</ol>
				</div>
			) : null }
			{ props.laterChoices === true ? (
				<p className="merchboard-product-designer__hint">
					{ __(
						'You choose the order of the colours and the main product image when you send for approval.',
						'merchboard'
					) }
				</p>
			) : null }
			<fieldset className="merchboard-product-designer__options">
				<legend className="merchboard-field__label">
					{ __( 'Sizes', 'merchboard' ) }
				</legend>
				{ groups.length === 0
					? base.sizes.map( sizeBox )
					: [
							...groups.map( ( group, index ) => {
								const own = base.sizes.filter( ( size ) =>
									group.sizes.includes( size )
								);
								const all = own.every( ( size ) =>
									props.sizes.includes( size )
								);
								const some = own.some( ( size ) =>
									props.sizes.includes( size )
								);

								return (
									<div
										key={ group.key }
										className="merchboard-product-designer__size-group"
									>
										<Labelled
											className="merchboard-product-designer__option"
											// The sizes are the same in both orientations: no orientation in the name.
											label={
												<strong>
													{ sprintf(
														/* translators: 1: number of a poster format, 2: its aspect ratio, e.g. "2:3". */
														__(
															'Format %1$d · %2$s',
															'merchboard'
														),
														index + 1,
														shapeOf( group )
													) }
												</strong>
											}
										>
											{ ( id ) => (
												<input
													id={ id }
													type="checkbox"
													checked={ all }
													ref={ ( element ) => {
														if (
															element !== null
														) {
															element.indeterminate =
																some && ! all;
														}
													} }
													onChange={ () =>
														props.onSizes(
															base.sizes.filter(
																( size ) =>
																	all
																		? props.sizes.includes(
																				size
																			) &&
																			! own.includes(
																				size
																			)
																		: props.sizes.includes(
																				size
																			) ||
																			own.includes(
																				size
																			)
															)
														)
													}
												/>
											) }
										</Labelled>
										{ own.map( sizeBox ) }
									</div>
								);
							} ),
							...base.sizes
								.filter(
									( size ) =>
										! groups.some( ( group ) =>
											group.sizes.includes( size )
										)
								)
								.map( sizeBox ),
						] }
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
 * @param props.onFit    Sizes an image to the print area (ADR-102).
 * @param props.onRemove Removes it.
 * @param props.onMove   Moves it.
 * @param props.asset    An image's asset.
 * @param props.onBegin  A change that goes on starts (one undo step).
 * @param props.onEnd    It ends.
 */
function Properties( props: {
	readonly object: DesignObject;
	readonly asset: DesignerAsset | undefined;
	readonly onBegin: () => void;
	readonly onEnd: () => void;
	readonly fonts: DesignerOverview[ 'fonts' ];
	readonly threads: readonly DesignerThread[] | null;
	readonly plan: ThreadPlan | null;
	readonly onThread: ( thread: DesignerThread ) => void;
	readonly onChange: (
		change: Partial< DesignObject >,
		live?: boolean
	) => void;
	/** Sizes an image to the print area (ADR-102). */
	readonly onFit: ( mode: FitMode ) => void;
	readonly onRemove: () => void;
	readonly onMove: ( direction: 1 | -1 ) => void;
} ) {
	const { object, onChange, threads } = props;
	// A clipart with parts is never faded; a locked one is placed for the creator (ADR-121).
	const handling = handlingOf( props.asset );
	const art = artOf( props.asset );
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
					<FontPicker
						value={ text.font }
						aria-label={ __( 'Font', 'merchboard' ) }
						choices={ props.fonts.map( ( candidate ) => ( {
							value: candidate.family,
							label: candidate.family,
							url: fontUrl( candidate.family, candidate.weights ),
						} ) ) }
						onChange={ ( value ) => {
							const next = props.fonts.find(
								( candidate ) => candidate.family === value
							);

							onChange( {
								font: value,
								weight: next?.weights.includes( text.weight )
									? text.weight
									: ( next?.weights[ 0 ] ?? 400 ),
							} as Partial< TextObject > );
						} }
					/>
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
			{ object.type === 'image' &&
			art !== null &&
			art.parts.length > 0 ? (
				<ClipartPaints
					art={ art }
					palette={
						threads === null
							? ( props.asset?.clipart?.art?.palette ?? null )
							: threads.map( ( thread ) => thread.hex )
					}
					swatchName={ ( hex ) => {
						const thread = threads?.find(
							( candidate ) => candidate.hex === hex
						);

						return thread === undefined
							? hex
							: `${ thread.code } ${ thread.name }`;
					} }
					paints={ object.paints ?? [] }
					gradients={ threads === null }
					onChange={ ( paints, live ) =>
						onChange( { paints } as Partial< DesignObject >, live )
					}
					onBegin={ props.onBegin }
					onEnd={ props.onEnd }
				/>
			) : null }
			{ object.type === 'image' && handling === 'sideways' ? (
				<p className="merchboard-product-designer__hint">
					{ __(
						'This clipart is placed for you: its size cannot change. You can move it sideways.',
						'merchboard'
					) }
				</p>
			) : null }
			{ threads !== null || handling !== null ? null : (
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
			{ object.type === 'image' ? (
				<>
					<Button
						variant="secondary"
						size="sm"
						disabled={ object.locked || handling === 'sideways' }
						onClick={ () => props.onFit( 'fill' ) }
					>
						{ __( 'Fill the print area', 'merchboard' ) }
					</Button>
					<Button
						variant="secondary"
						size="sm"
						disabled={ object.locked || handling === 'sideways' }
						onClick={ () => props.onFit( 'fit' ) }
					>
						{ __( 'Fit in the print area', 'merchboard' ) }
					</Button>
				</>
			) : null }
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
