/**
 * A clipart with parts the creator colours (phase 56, ADR-121), as the
 * administrator's browser bakes it from a PSD and the designer draws it: a
 * stack of fixed images and parts, whose pixels are in one image (the atlas).
 * A part has a mask for its fill and, with an outer stroke, a mask of the
 * fill grown by the stroke.
 */

/** A rectangle in whole pixels of the clipart. */
export interface ClipartRect {
	readonly x: number;
	readonly y: number;
	readonly width: number;
	readonly height: number;
}

/** A place in the atlas. */
export interface AtlasPlace {
	readonly x: number;
	readonly y: number;
}

/** One colour as `#rrggbb`. */
export interface SolidPaint {
	readonly type: 'solid';
	readonly color: string;
}

/** Two colours and an angle, counter-clockwise from the right. */
export interface GradientPaint {
	readonly type: 'gradient';
	readonly from: string;
	readonly to: string;
	readonly angle: number;
}

/** What a creator chooses for a part. */
export type ClipartPaint = SolidPaint | GradientPaint;

/** A part the creator colours. */
export interface ClipartPart {
	/** The layer's name after "farvet lag", '' without one. */
	readonly label: string;
	/** The layer's shape without its stroke: the gradient's box. */
	readonly box: ClipartRect;
	/** The outer stroke's colour, which never changes; null without one. */
	readonly stroke: string | null;
	/** The gradient's length as a share of the line through the box. */
	readonly scale: number;
	/** The file's paint. */
	readonly paint: ClipartPaint;
	/** What a gradient starts as. */
	readonly gradient: GradientPaint;
}

/** Fixed pixels. */
export interface ImageStep {
	readonly type: 'image';
	readonly rect: ClipartRect;
	readonly image: AtlasPlace;
}

/** A part's masks, in their alpha. */
export interface PartStep {
	readonly type: 'part';
	/** The part's index. */
	readonly part: number;
	readonly rect: ClipartRect;
	readonly fill: AtlasPlace;
	/** The fill grown by the stroke; null without a stroke. */
	readonly grown: AtlasPlace | null;
}

/** One step of the drawing. */
export type ClipartStep = ImageStep | PartStep;

/** A clipart's parts and drawing order, bottom first. */
export interface ClipartArt {
	readonly width: number;
	readonly height: number;
	readonly parts: readonly ClipartPart[];
	readonly steps: readonly ClipartStep[];
}
