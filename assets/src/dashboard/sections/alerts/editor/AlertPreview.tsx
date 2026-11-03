/**
 * A saved design or standard overlay as OBS shows it, read-only (alert
 * designer 2.0, ADR-088): the canvas without handles or toolbar.
 */
import { useMemo } from '@wordpress/element';

import type {
	AlertsImages,
	OverlayLayout,
	OverlaySettings,
} from '../../../types/alerts';
import { AlertCanvas } from './AlertCanvas';
import { lookOf, sampleParts } from './look';

/**
 * The preview.
 *
 * @param props          Props.
 * @param props.settings Settings.
 * @param props.layout   Layout.
 * @param props.images   Images a text can be filled with.
 */
export function AlertPreview( {
	settings,
	layout,
	images,
}: {
	readonly settings: OverlaySettings;
	readonly layout: OverlayLayout;
	readonly images: AlertsImages | null;
} ) {
	const look = useMemo(
		() => lookOf( settings, layout, images ),
		[ settings, layout, images ]
	);
	const sample = useMemo(
		() => ( {
			parts: sampleParts(
				settings.template,
				settings.number_format,
				settings.max_name_length
			),
			secondaryParts: sampleParts(
				settings.secondary_template,
				settings.number_format,
				settings.max_name_length
			),
		} ),
		[ settings ]
	);

	return (
		<AlertCanvas
			settings={ settings }
			layout={ layout }
			look={ look }
			sample={ sample }
			readOnly
		/>
	);
}
