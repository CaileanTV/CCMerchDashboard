/**
 * The defaults of alert designer 2.0's settings (ADR-088) as the server
 * sends them for settings stored before: test fixtures add them to their
 * settings, so a parsed answer equals the fixture.
 */
export const DESIGNER_DEFAULTS = {
	text_opacity: 100,
	text_shadow_enabled: true,
	line_height: 130,
	letter_spacing: 0,
	word_spacing: 0,
	text_direction: 'ltr' as const,
	italic: false,
	underline: false,
	text_case: 'none' as const,
	stroke_width: 0,
	stroke_color: '#000000',
	stroke_opacity: 100,
	highlight: { enabled: false, color: '#6a6ffc', effect: 'none' as const },
	margin: { top: 0, right: 0, bottom: 0, left: 0 },
	number_format: 'shop' as const,
	max_name_length: 0,
	audio_volume: 100,
	audio_enabled: false,
	widget: { x: 40, y: 880, w: 836, h: 120, opacity: 100, rotation: 0 },
	layout_mode: 'free' as const,
	text_timing: { show_after: 0, hide_before: 0 },
	tts: {
		enabled: false,
		voice: 'da-DK-Standard-A' as const,
		volume: 75,
		delay: 0,
		min_amount: 0,
	},
	variations: [],
};

/** The default animation of the alert box. */
export const ALERT_ANIMATION = {
	in: 'fade' as const,
	out: 'fade' as const,
	in_ms: 500,
	out_ms: 500,
};
