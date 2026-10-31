/**
 * The creator's deleted designs: out of the shop and of their lists, still to
 * be seen, so a design can be made again on another product, and to be
 * deleted for good.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { deletedDesignUrl } from '../../api/designer';
import type { DesignerDeleted } from '../../api/designer';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';

/**
 * A deleted design's picture.
 *
 * @param props        Props.
 * @param props.design The design.
 */
function Preview( props: { readonly design: DesignerDeleted } ) {
	const { id, preview, title } = props.design;
	const [ url, setUrl ] = useState< string | null >( null );

	useEffect( () => {
		const controller = new AbortController();

		if ( preview !== null ) {
			void deletedDesignUrl( id, preview, controller.signal ).then(
				setUrl
			);
		}

		return () => controller.abort();
	}, [ id, preview ] );

	return (
		<span className="merchboard-product-designer__preview">
			{ url === null ? (
				<span className="merchboard-product-designer__hint">
					{ preview === null
						? __( 'Never sent: no picture', 'merchboard' )
						: __( 'Loading…', 'merchboard' ) }
				</span>
			) : (
				<img src={ url } alt={ title } />
			) }
		</span>
	);
}

/**
 * The card; nothing without deleted designs.
 *
 * @param props         Props.
 * @param props.designs The deleted designs.
 * @param props.onPurge Asks whether a design is to be deleted for good.
 */
export function DeletedDesigns( props: {
	readonly designs: readonly DesignerDeleted[];
	readonly onPurge: ( design: DesignerDeleted ) => void;
} ) {
	if ( props.designs.length === 0 ) {
		return null;
	}

	return (
		<Card title={ __( 'Deleted designs', 'merchboard' ) }>
			<ul className="merchboard-product-designer__deleted">
				{ props.designs.map( ( design ) => (
					<li key={ design.id }>
						<Preview design={ design } />
						<strong>{ design.title }</strong>
						{ design.base === '' ? null : (
							<span className="merchboard-product-designer__hint">
								{ design.base }
							</span>
						) }
						<Button
							size="sm"
							variant="ghost"
							onClick={ () => props.onPurge( design ) }
							aria-label={ sprintf(
								/* translators: %s: design title. */ __(
									'Delete %s for good',
									'merchboard'
								),
								design.title
							) }
						>
							{ __( 'Delete for good', 'merchboard' ) }
						</Button>
					</li>
				) ) }
			</ul>
		</Card>
	);
}
