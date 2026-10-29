/**
 * The designer's canvas (phase 30, step 30.8, ADR-080 pkt. 4): Fabric (MIT,
 * 7.4) is loaded only here, lazily, when the editor opens. The stage shows
 * the design document of one side and reports changes; the document stays
 * the truth (design-model.ts). Coordinates are print file pixels: the
 * viewport transform places the print file on the template's print area,
 * and a clip path hides what lies outside it. renderPrintFile() draws a
 * side's print file for the submission (step 30.9) with the same objects.
 */
import {
	Canvas,
	Ellipse,
	FabricImage,
	Rect,
	StaticCanvas,
	Textbox,
	Triangle,
} from 'fabric';
import type { FabricObject } from 'fabric';

import type { DesignObject, TextObject } from './design-model';

export interface StageView {
	/** Displayed template width and height in CSS pixels at zoom 1. */
	readonly displayWidth: number;
	readonly displayHeight: number;
	/** Template size and print area (template px). */
	readonly templateWidth: number;
	readonly area: {
		readonly x: number;
		readonly y: number;
		readonly width: number;
		readonly height: number;
	};
	readonly printWidth: number;
	readonly printHeight: number;
	readonly zoom: number;
}

export interface StageCallbacks {
	/** The objects after the user moved, scaled, rotated or edited one. */
	readonly onChange: ( objects: readonly DesignObject[] ) => void;
	readonly onSelect: ( id: string | null ) => void;
}

type Tagged = FabricObject & { mbId?: string };

/**
 * The editor's Fabric canvas.
 */
export class FabricStage {
	private readonly canvas: Canvas;

	private objects: readonly DesignObject[] = [];

	private rendered = '';

	private imagesKey = '';

	private generation = 0;

	/**
	 * Constructor.
	 *
	 * @param element   The canvas element.
	 * @param callbacks Change and selection.
	 */
	public constructor(
		element: HTMLCanvasElement,
		private readonly callbacks: StageCallbacks
	) {
		this.canvas = new Canvas( element, {
			preserveObjectStacking: true,
			selection: false,
			controlsAboveOverlay: true,
		} );
		this.canvas.on( 'object:modified', () => this.report() );
		this.canvas.on( 'text:changed', () => this.report() );
		this.canvas.on( 'selection:created', () => this.selected() );
		this.canvas.on( 'selection:updated', () => this.selected() );
		this.canvas.on( 'selection:cleared', () =>
			this.callbacks.onSelect( null )
		);
	}

	/**
	 * Sizes the canvas for a side and zoom.
	 *
	 * @param view The side's geometry.
	 */
	public setView( view: StageView ): void {
		const scale = ( view.displayWidth / view.templateWidth ) * view.zoom;
		const k = ( view.area.width / view.printWidth ) * scale;

		this.canvas.setDimensions( {
			width: Math.round( view.displayWidth * view.zoom ),
			height: Math.round( view.displayHeight * view.zoom ),
		} );
		this.canvas.setViewportTransform( [
			k,
			0,
			0,
			k,
			view.area.x * scale,
			view.area.y * scale,
		] );
		this.canvas.clipPath = new Rect( {
			left: view.printWidth / 2,
			top: view.printHeight / 2,
			width: view.printWidth,
			height: view.printHeight,
			originX: 'center',
			originY: 'center',
			absolutePositioned: true,
		} );
		this.canvas.requestRenderAll();
	}

	/**
	 * Shows a side's objects (nothing happens when they are unchanged).
	 *
	 * @param objects  Objects, bottom first.
	 * @param images   `data:` URL by asset id.
	 * @param selected Selected object id.
	 */
	public async render(
		objects: readonly DesignObject[],
		images: ReadonlyMap< number, string >,
		selected: string | null
	): Promise< void > {
		this.imagesKey = Array.from( images.keys() ).join( ',' );
		const key = JSON.stringify( objects ) + '|' + this.imagesKey;

		this.objects = objects;

		if ( key === this.rendered ) {
			this.select( selected );

			return;
		}

		this.rendered = key;
		const generation = ++this.generation;
		const built = await Promise.all(
			objects.map( ( object ) => build( object, images ) )
		);

		if ( generation !== this.generation ) {
			return;
		}

		this.canvas.discardActiveObject();
		this.canvas.remove( ...this.canvas.getObjects() );

		for ( const object of built ) {
			if ( object !== null ) {
				this.canvas.add( object );
			}
		}

		this.select( selected );
		this.canvas.requestRenderAll();
	}

	/**
	 * Selects an object (null clears the selection).
	 *
	 * @param id Object id.
	 */
	public select( id: string | null ): void {
		const target = this.canvas
			.getObjects()
			.find( ( object ) => ( object as Tagged ).mbId === id );
		const active = this.canvas.getActiveObject() as Tagged | undefined;

		if ( target === undefined ) {
			if ( active !== undefined ) {
				this.canvas.discardActiveObject();
			}
		} else if ( active !== target && target.selectable ) {
			this.canvas.setActiveObject( target );
		}

		this.canvas.requestRenderAll();
	}

	/**
	 * Releases the canvas.
	 */
	public dispose(): void {
		void this.canvas.dispose();
	}

	/**
	 * Reports the selected object.
	 */
	private selected(): void {
		const active = this.canvas.getActiveObject() as Tagged | undefined;

		this.callbacks.onSelect( active?.mbId ?? null );
	}

	/**
	 * Reads the canvas back into the document and reports it.
	 */
	private report(): void {
		const byId = new Map< string, Tagged >();

		for ( const object of this.canvas.getObjects() ) {
			const id = ( object as Tagged ).mbId;

			if ( id !== undefined ) {
				byId.set( id, object as Tagged );
			}
		}

		const next = this.objects.map( ( object ) => {
			const shown = byId.get( object.id );

			return shown === undefined ? object : readBack( object, shown );
		} );

		this.objects = next;
		// The canvas already shows this state: the next render() is a no-op.
		this.rendered = JSON.stringify( next ) + '|' + this.imagesKey;
		this.callbacks.onChange( next );
	}
}

/**
 * A side's print file: its visible objects at their print file pixels on a
 * transparent canvas of the print file's size (step 30.9, ADR-080 pkt. 5).
 *
 * @param objects Objects, bottom first.
 * @param images  `data:` URL by asset id (every image of the objects).
 * @param width   Print file width (px).
 * @param height  Print file height (px).
 */
export async function renderPrintFile(
	objects: readonly DesignObject[],
	images: ReadonlyMap< number, string >,
	width: number,
	height: number
): Promise< HTMLCanvasElement > {
	const canvas = new StaticCanvas( undefined, {
		width,
		height,
		enableRetinaScaling: false,
		renderOnAddRemove: false,
	} );

	try {
		const built = await Promise.all(
			objects
				.filter( ( object ) => ! object.hidden )
				.map( ( object ) => build( object, images ) )
		);

		for ( const object of built ) {
			if ( object !== null ) {
				canvas.add( object );
			}
		}

		canvas.renderAll();

		return canvas.toCanvasElement( 1 );
	} finally {
		void canvas.dispose();
	}
}

/**
 * The common Fabric options of an object.
 *
 * @param object The design object.
 */
function common( object: DesignObject ): Record< string, unknown > {
	return {
		left: object.x,
		top: object.y,
		originX: 'center',
		originY: 'center',
		angle: object.angle,
		scaleX: object.scaleX,
		scaleY: object.scaleY,
		flipX: object.flipX,
		flipY: object.flipY,
		opacity: object.opacity,
		visible: ! object.hidden,
		selectable: ! object.locked,
		evented: ! object.locked,
		lockScalingFlip: true,
		objectCaching: false,
	};
}

/**
 * The Fabric object of a design object.
 *
 * @param object The design object.
 * @param images `data:` URL by asset id.
 */
async function build(
	object: DesignObject,
	images: ReadonlyMap< number, string >
): Promise< Tagged | null > {
	let shown: Tagged | null = null;

	switch ( object.type ) {
		case 'text':
			shown = new Textbox( object.text, {
				...common( object ),
				width: object.width,
				fontFamily: object.font,
				fontWeight: object.weight,
				fontStyle: object.italic ? 'italic' : 'normal',
				fontSize: object.size,
				fill: object.fill,
				textAlign: object.align,
				lineHeight: object.lineHeight,
				charSpacing: object.charSpacing,
				stroke: object.stroke,
				strokeWidth: object.stroke === null ? 0 : object.strokeWidth,
				splitByGrapheme: false,
			} );
			break;
		case 'image': {
			const url = images.get( object.asset );

			if ( url === undefined ) {
				return null;
			}

			shown = await FabricImage.fromURL( url, {}, common( object ) );
			break;
		}
		case 'rect':
			shown = new Rect( {
				...common( object ),
				...paint( object ),
				width: object.width,
				height: object.height,
				rx: object.radius,
				ry: object.radius,
			} );
			break;
		case 'ellipse':
			shown = new Ellipse( {
				...common( object ),
				...paint( object ),
				rx: object.width / 2,
				ry: object.height / 2,
			} );
			break;
		case 'triangle':
			shown = new Triangle( {
				...common( object ),
				...paint( object ),
				width: object.width,
				height: object.height,
			} );
			break;
	}

	shown.mbId = object.id;

	return shown;
}

/**
 * Fill and stroke of a shape.
 *
 * @param object             Shape.
 * @param object.fill        Fill.
 * @param object.stroke      Stroke.
 * @param object.strokeWidth Stroke width.
 */
function paint( object: {
	readonly fill: string | null;
	readonly stroke: string | null;
	readonly strokeWidth: number;
} ): Record< string, unknown > {
	return {
		fill: object.fill ?? 'transparent',
		stroke: object.stroke,
		strokeWidth: object.stroke === null ? 0 : object.strokeWidth,
		strokeUniform: true,
	};
}

/**
 * A design object with the geometry (and text) the user gave it on the canvas.
 *
 * @param object The design object.
 * @param shown  Its Fabric object.
 */
function readBack( object: DesignObject, shown: Tagged ): DesignObject {
	const round = ( value: number, digits = 2 ): number =>
		Number( value.toFixed( digits ) );
	const geometry = {
		x: round( shown.left ),
		y: round( shown.top ),
		angle: round(
			( ( shown.angle % 360 ) + 360 ) % 360 > 180
				? ( shown.angle % 360 ) - 360
				: shown.angle % 360
		),
		scaleX: Math.min( 100, Math.max( 0.01, round( shown.scaleX, 4 ) ) ),
		scaleY: Math.min( 100, Math.max( 0.01, round( shown.scaleY, 4 ) ) ),
		flipX: shown.flipX,
		flipY: shown.flipY,
	};

	if ( object.type === 'text' ) {
		const text = ( shown as Textbox ).text;

		return {
			...object,
			...geometry,
			width: Math.max( 1, round( shown.width ) ),
			height: Math.max( 1, round( shown.height ) ),
			text:
				text.trim() === ''
					? ( object as TextObject ).text
					: text.slice( 0, 500 ),
		};
	}

	return { ...object, ...geometry } as DesignObject;
}
