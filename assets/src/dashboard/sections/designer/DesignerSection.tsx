/**
 * The section "Designer" (phase 30, step 30.8, ADR-080): the creator's
 * designs and the products they can design on; opening a design starts the
 * editor. Shown only while the administrator has switched the designer on.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	createDesignerProduct,
	deleteDesignerProduct,
	getDesigner,
	getDesignerProduct,
} from '../../api/designer';
import type { DesignerOverview, DesignerProduct } from '../../api/designer';
import type { ApiError } from '../../api/client';
import { useConfig } from '../../app/config-context';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { Skeleton } from '../../components/Skeleton';
import { moneyText } from '../sales/format';
import { DesignerEditor } from './DesignerEditor';

/**
 * A status in words.
 *
 * @param status Version status.
 */
function statusLabel( status: string ): string {
	switch ( status ) {
		case 'draft':
			return __( 'Draft', 'merchboard' );
		case 'pending':
			return __( 'Waiting for approval', 'merchboard' );
		case 'approved':
			return __( 'In the shop', 'merchboard' );
		case 'rejected':
			return __( 'Rejected', 'merchboard' );
		default:
			return status;
	}
}

/**
 * The section.
 */
export function DesignerSection() {
	const { locale } = useConfig();
	const [ overview, setOverview ] = useState< DesignerOverview | null >(
		null
	);
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ open, setOpen ] = useState< DesignerProduct | null >( null );
	const [ busy, setBusy ] = useState< number | null >( null );
	const [ notice, setNotice ] = useState< string | null >( null );

	const load = useCallback( ( signal?: AbortSignal ) => {
		setError( null );
		void getDesigner( signal ).then( ( result ) => {
			if ( signal?.aborted ) {
				return;
			}

			if ( result.ok ) {
				setOverview( result.data );
			} else {
				setError( result.error );
			}
		} );
	}, [] );

	useEffect( () => {
		const controller = new AbortController();
		load( controller.signal );

		return () => controller.abort();
	}, [ load ] );

	const start = async ( baseId: number ) => {
		setBusy( baseId );
		setNotice( null );
		const result = await createDesignerProduct( baseId );
		setBusy( null );

		if ( result.ok ) {
			setOpen( result.data );
			load();
		} else {
			setNotice(
				__(
					'The design could not be started. Please try again.',
					'merchboard'
				)
			);
		}
	};

	const edit = async ( id: number ) => {
		setBusy( id );
		const result = await getDesignerProduct( id );
		setBusy( null );

		if ( result.ok ) {
			setOpen( result.data );
		} else {
			setNotice(
				__(
					'The design could not be opened. Please try again.',
					'merchboard'
				)
			);
		}
	};

	const remove = async ( id: number ) => {
		setBusy( id );
		const result = await deleteDesignerProduct( id );
		setBusy( null );

		if ( result.ok ) {
			setOverview( result.data );
		} else {
			setNotice( __( 'Only a draft can be deleted.', 'merchboard' ) );
		}
	};

	if ( error !== null ) {
		return <ApiErrorState error={ error } onRetry={ () => load() } />;
	}

	if ( overview === null ) {
		return (
			<Skeleton label={ __( 'Loading the designer…', 'merchboard' ) } />
		);
	}

	if ( open !== null ) {
		return (
			<DesignerEditor
				key={ open.id }
				overview={ overview }
				product={ open }
				locale={ locale }
				onBack={ () => {
					setOpen( null );
					load();
				} }
				onOverview={ setOverview }
				onProduct={ setOpen }
			/>
		);
	}

	return (
		<>
			{ notice === null ? null : <Alert tone="danger">{ notice }</Alert> }
			<Card title={ __( 'My designs', 'merchboard' ) }>
				{ overview.products.length === 0 ? (
					<EmptyState
						title={ __( 'No designs yet.', 'merchboard' ) }
						description={ __(
							'Choose a product below and design it.',
							'merchboard'
						) }
					/>
				) : (
					<ul className="merchboard-product-designer__products">
						{ overview.products.map( ( product ) => (
							<li key={ product.id }>
								<span>
									<strong>{ product.title }</strong>
									{ ' · ' }
									{ statusLabel( product.status ) }
								</span>
								<span className="merchboard-product-designer__actions">
									<Button
										size="sm"
										onClick={ () =>
											void edit( product.id )
										}
										isBusy={ busy === product.id }
									>
										{ product.status === 'draft'
											? __( 'Edit', 'merchboard' )
											: __( 'Open', 'merchboard' ) }
									</Button>
									{ product.status === 'draft' &&
									! product.inShop ? (
										<Button
											size="sm"
											variant="ghost"
											onClick={ () =>
												void remove( product.id )
											}
											aria-label={ sprintf(
												/* translators: %s: design title. */ __(
													'Delete %s',
													'merchboard'
												),
												product.title
											) }
										>
											{ __( 'Delete', 'merchboard' ) }
										</Button>
									) : null }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</Card>
			<Card title={ __( 'Design a new product', 'merchboard' ) }>
				{ overview.bases.length === 0 ? (
					<EmptyState
						title={ __(
							'No products to design yet.',
							'merchboard'
						) }
					/>
				) : (
					<ul className="merchboard-product-designer__bases">
						{ overview.bases.map( ( base ) => (
							<li key={ base.id }>
								<strong>{ base.name }</strong>
								<span>
									{ sprintf(
										/* translators: 1: base price, 2: number of colours, 3: sizes. */
										__(
											'Base price %1$s · %2$d colours · %3$s',
											'merchboard'
										),
										moneyText(
											base.basePrice,
											base.currency,
											locale
										),
										base.colors.length,
										base.sizes.join( ', ' )
									) }
								</span>
								<Button
									size="sm"
									onClick={ () => void start( base.id ) }
									isBusy={ busy === base.id }
								>
									{ __( 'Design', 'merchboard' ) }
								</Button>
							</li>
						) ) }
					</ul>
				) }
			</Card>
		</>
	);
}
