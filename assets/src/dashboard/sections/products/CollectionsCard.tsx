/**
 * "Collections" under "Your products" (ADR-120): the creator's requests with
 * their state, and the button that sends one.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getCollections } from '../../api/collections';
import type {
	CollectionRequestRow,
	CollectionsState,
} from '../../api/collections';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { useApi } from '../../hooks/useApi';
import { CollectionDialog } from './CollectionDialog';

/**
 * A request's state as text.
 *
 * @param row The request.
 */
function statusText( row: CollectionRequestRow ): string {
	switch ( row.status ) {
		case 'pending':
			return __( 'Waiting for our approval', 'merchboard' );
		case 'approved':
			return __( 'Approved, we are getting it ready', 'merchboard' );
		case 'ready':
			return row.note === null || row.note === ''
				? __( 'In the shop', 'merchboard' )
				: sprintf(
						/* translators: %s: design names. */
						__( 'Partly in the shop: %s', 'merchboard' ),
						row.note
					);
		default:
			return row.note === null || row.note === ''
				? __( 'Not approved', 'merchboard' )
				: sprintf(
						/* translators: %s: our note. */
						__( 'Not approved: %s', 'merchboard' ),
						row.note
					);
	}
}

/**
 * The card. Nothing is shown while it loads, without WooCommerce (no route),
 * or when there is neither a request nor a way to send one.
 */
export function CollectionsCard() {
	const { state, retry } = useApi( getCollections );
	const [ current, setCurrent ] = useState< CollectionsState | null >( null );
	const [ open, setOpen ] = useState( false );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setCurrent( state.data );
		}
	}, [ state ] );

	const title = __( 'Collections', 'merchboard' );

	if ( state.status === 'error' ) {
		return state.error.status === 404 ? null : (
			<Card title={ title }>
				<ApiErrorState error={ state.error } onRetry={ retry } />
			</Card>
		);
	}

	if (
		current === null ||
		( ! current.available && current.requests.length === 0 )
	) {
		return null;
	}

	const possible =
		current.available &&
		( current.products.length >= 2 ||
			( current.products.length > 0 && current.collections.length > 0 ) );

	return (
		<Card
			title={ title }
			actions={
				possible ? (
					<Button size="sm" onClick={ () => setOpen( true ) }>
						{ __( 'Request a collection', 'merchboard' ) }
					</Button>
				) : undefined
			}
		>
			<p className="merchboard-field__help">
				{ __(
					'Have several of your designs sold from one product page, where customers choose the design.',
					'merchboard'
				) }
			</p>
			{ current.available && ! possible ? (
				<p className="merchboard-field__help">
					{ __(
						'You need at least two products in the shop that are not already in a collection or a request.',
						'merchboard'
					) }
				</p>
			) : null }
			{ current.requests.length === 0 ? null : (
				<ul className="merchboard-collection__requests">
					{ current.requests.map( ( row ) => (
						<li key={ row.id }>
							<strong>
								{ row.kind === 'add'
									? sprintf(
											/* translators: %s: collection name. */
											__( 'Add to %s', 'merchboard' ),
											row.name
										)
									: row.name }
							</strong>
							<span className="merchboard-collection__status">
								{ statusText( row ) }
							</span>
							<span className="merchboard-field__help">
								{ row.designs.join( ', ' ) }
							</span>
						</li>
					) ) }
				</ul>
			) }
			{ open ? (
				<CollectionDialog
					state={ current }
					onClose={ () => setOpen( false ) }
					onSent={ ( next ) => {
						setCurrent( next );
						setOpen( false );
					} }
				/>
			) : null }
		</Card>
	);
}
