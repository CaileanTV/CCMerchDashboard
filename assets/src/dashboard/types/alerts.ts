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
	readonly image_size: number;
	readonly bg_color: string;
	readonly bg_opacity: number;
	readonly border_radius: number;
	readonly padding: number;
	readonly anim_duration: number;
	readonly display_duration: number;
	readonly show_secondary: boolean;
}

export interface AlertOverlay {
	/** The OBS browser source URL; contains the secret overlay id. */
	readonly url: string;
	readonly settings: OverlaySettings;
}

export interface AlertsOverlayState {
	/** Null before the creator has set the overlay up. */
	readonly overlay: AlertOverlay | null;
	/** Whether an administrator has switched purchase alerts on. */
	readonly alertsEnabled: boolean;
	/** Whether MerchAlert is still active (then no alerts are recorded). */
	readonly merchalertActive: boolean;
}
