export interface SkeletonProps {
	/** Text for screen readers, e.g. "Loading overview…". */
	readonly label: string;
	readonly lines?: number;
}

/**
 * Loading placeholder: grey bars for sighted users (hidden from assistive
 * technology) and a polite status message for screen readers.
 */
export function Skeleton( { label, lines = 3 }: SkeletonProps ) {
	return (
		<div className="merchboard-skeleton" role="status" aria-live="polite">
			<span className="merchboard-visually-hidden">{ label }</span>
			{ Array.from( { length: lines }, ( _, index ) => (
				<span
					key={ index }
					className="merchboard-skeleton__line"
					aria-hidden="true"
				/>
			) ) }
		</div>
	);
}
