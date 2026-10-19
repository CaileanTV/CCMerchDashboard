/**
 * The creator's alert overlay (ADR-065, `/me/alerts/overlay`). The settings
 * keep the server's (and MerchAlert's) keys, so imported settings and the
 * form use the same names.
 */

export const FONT_WEIGHTS = [
	'100',
	'200',
	'300',
	'400',
	'500',
	'600',
	'700',
	'800',
	'900',
] as const;

export const TEXT_ALIGNS = [ 'left', 'center', 'right' ] as const;

export type FontWeight = ( typeof FONT_WEIGHTS )[ number ] | 'normal' | 'bold';
export type TextAlign = ( typeof TEXT_ALIGNS )[ number ];

import type { TextFit } from '../../overlay/fit';

export { TEXT_FITS } from '../../overlay/fit';
export type { TextFit };

/** Number fields with the server's ranges (OverlaySettings). */
export const NUMBER_FIELDS = {
	font_size: { min: 8, max: 200 },
	image_size: { min: 32, max: 512 },
	bg_opacity: { min: 0, max: 100 },
	border_radius: { min: 0, max: 100 },
	padding: { min: 0, max: 200 },
	anim_duration: { min: 0, max: 5000 },
	display_duration: { min: 1000, max: 30000 },
} as const;

export type NumberField = keyof typeof NUMBER_FIELDS;

/** Longest template in characters. */
export const TEMPLATE_MAX_LENGTH = 500;

export interface OverlaySettings {
	readonly template: string;
	readonly secondary_template: string;
	readonly font_size: number;
	readonly font_weight: FontWeight;
	readonly text_color: string;
	readonly text_shadow: string;
	readonly text_align: TextAlign;
	/** How text meets its box: fixed size, shrink to fit, or the box grows. */
	readonly text_fit: TextFit;
	readonly image_size: number;
	readonly bg_color: string;
	readonly bg_opacity: number;
	readonly border_radius: number;
	readonly padding: number;
	readonly anim_duration: number;
	readonly display_duration: number;
	readonly show_secondary: boolean;
	/** Alerts with a sound are shown as long as the sound plays (step 25.6.8). */
	readonly display_from_sound: boolean;
	/** "inherit" (the default font) or an approved Google font (ADR-067). */
	readonly font_family: string;
}

/** Elements of the 1920 × 1080 overlay canvas. */
export const LAYOUT_ELEMENTS = [
	'product_image',
	'main_text',
	'secondary_text',
] as const;

export type LayoutElement = ( typeof LAYOUT_ELEMENTS )[ number ];

/** The canvas size of OBS' default browser source. */
export const CANVAS = { width: 1920, height: 1080 } as const;

/** The server's bounds of a box (OverlaySettings). */
export const BOX_LIMITS = {
	x: { min: -3840, max: 3840 },
	y: { min: -2160, max: 2160 },
	w: { min: 10, max: 3840 },
	h: { min: 10, max: 2160 },
	border_radius: { min: 0, max: 500 },
} as const;

export interface LayoutBox {
	readonly x: number;
	readonly y: number;
	readonly w: number;
	readonly h: number;
	readonly visible: boolean;
	readonly lock_aspect: boolean;
	readonly border_radius: number;
}

export interface OverlayLayout {
	readonly elements: Readonly< Record< LayoutElement, LayoutBox > >;
	readonly canvas: {
		readonly snap_grid: boolean;
		readonly grid_size: number;
	};
}

/** An approved font with its Google Fonts stylesheet. */
export interface AlertFontOption {
	readonly family: string;
	readonly url: string;
}

/** An uploaded alert sound (ADR-065). */
export interface AlertSoundFile {
	/** File URL on this site. */
	readonly url: string;
	readonly name: string;
	/** Length in whole seconds. */
	readonly seconds: number;
	/** The library sound's id; 0 for the creator's own file. */
	readonly libraryId: number;
}

/** A sound of the administrator's library. */
export interface LibrarySound {
	readonly id: number;
	readonly url: string;
	readonly name: string;
	readonly seconds: number;
}

/** A sound slot: the default sound or one of the creator's categories. */
export type SoundSlot = 'default' | number;

/** The overlay's sounds. */
export interface AlertsAudio {
	/** Whether alerts play a sound. */
	readonly enabled: boolean;
	readonly defaultSound: AlertSoundFile | null;
	/** The creator's categories with their sound. */
	readonly categories: ReadonlyArray< {
		readonly termId: number;
		readonly name: string;
		readonly sound: AlertSoundFile | null;
	} >;
	/** Sounds the creator can choose instead of uploading. */
	readonly library: readonly LibrarySound[];
	/** Largest file in bytes. */
	readonly maxBytes: number;
	/** Longest sound in seconds. */
	readonly maxSeconds: number;
}

export interface AlertOverlay {
	/** The OBS browser source URL; contains the secret overlay id. */
	readonly url: string;
	readonly settings: OverlaySettings;
	readonly layout: OverlayLayout;
	/** False when the saved font is no longer approved (the default font is shown). */
	readonly fontApproved: boolean;
	/** The sounds; null when sounds are not available. */
	readonly audio: AlertsAudio | null;
}

export interface AlertsOverlayState {
	/** Null before the creator has set the overlay up. */
	readonly overlay: AlertOverlay | null;
	/** Whether an administrator has switched purchase alerts on. */
	readonly alertsEnabled: boolean;
	/** Whether MerchAlert is still active (then no alerts are recorded). */
	readonly merchalertActive: boolean;
	/** The fonts an administrator approved. */
	readonly fonts: readonly AlertFontOption[];
}
