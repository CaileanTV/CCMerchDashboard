/**
 * The creator's designer products in the section Products (phase 30, step
 * 30.14, ADR-080): in the shop, waiting for approval or rejected (with the
 * administrator's note), and those taken out of the shop. Drafts stay in the
 * section Designer, which this card links to. Shown only while the designer
 * section is there.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { getDesignerShopProducts } from '../../api/designer';
import type { DesignerShopProduct } from '../../api/designer';
import type { ApiError } from '../../api/client';
import { useConfig } from '../../app/config-context';
import { Link } from '../../app/router';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { Table } from '../../components/Table';
import type { TableColumn } from '../../components/Table';
import { dayText, moneyText } from '../sales/format';

/**
 * The state in words, with a waiting or rejected change of a product in
 * the shop.
 *
 * @param product The product.
 * @param locale  WordPress locale.
 */
function stateText( product: DesignerShopProduct, locale: string ): string {
	const sent =
		product.pending === null
			? ''
			: dayText( product.pending.submittedAt.slice( 0, 10 ), locale );

	switch ( product.state ) {
		case 'in_shop':
			if ( product.pending !== null ) {
				return sprintf(
					/* translators: %s: date the change was sent. */
					__(
						'In the shop · a change waits for approval (sent %s)',
						'merchboard'
					),
					sent
				);
			}

			return product.rejection === null
				? __( 'In the shop', 'merchboard' )
				: __( 'In the shop · your change was rejected', 'merchboard' );
		case 'waiting':
			return sprintf(
				/* translators: %s: date the product was sent. */
				__( 'Waiting for approval (sent %s)', 'merchboard' ),
				sent
			);
		case 'rejected':
			return __( 'Rejected', 'merchboard' );
		case 'retired':
			return __( 'Taken out of the shop', 'merchboard' );
		default:
			return __( 'Draft', 'merchboard' );
	}
}

/**
 * The price in the shop, or the price it gets when approved.
 *
 * @param product The product.
 * @param locale  WordPress locale.
 */
function priceText( product: DesignerShopProduct, locale: string ): string {
	const shop =
		product.price === null
			? null
			: moneyText( product.price, product.currency, locale );
	const next =
		product.pending?.price === null || product.pending === null
			? null
			: moneyText( product.pending.price, product.currency, locale );

	if ( shop !== null && next !== null && next !== shop ) {
		return sprintf(
			/* translators: 1: price in the shop, 2: price after the change is approved. */
			__( '%1$s (after approval %2$s)', 'merchboard' ),
			shop,
			next
		);
	}

	return shop ?? next ?? '–';
}

/**
 * The card.
 */
export function DesignerProductsCard() {
	const { locale, sections } = useConfig();
	const designer = sections.find( ( section ) => section.id === 'designer' );
	const [ products, setProducts ] = useState< DesignerShopProduct[] | null >(
		null
	);
	const [ error, setError ] = useState< ApiError | null >( null );

	const load = useCallback(
		( signal?: AbortSignal ) => {
			if ( designer === undefined ) {
				return;
			}

			setError( null );
			void getDesignerShopProducts( signal ).then( ( result ) => {
				if ( signal?.aborted ) {
					return;
				}

				if ( result.ok ) {
					setProducts( result.data );
				} else {
					setError( result.error );
				}
			} );
		},
		[ designer ]
	);

	useEffect( () => {
		const controller = new AbortController();
		load( controller.signal );

		return () => controller.abort();
	}, [ load ] );

	if ( designer === undefined ) {
		return null;
	}

	const title = __( 'Your designs', 'merchboard' );

	if ( error !== null ) {
		return (
			<Card title={ title }>
				<ApiErrorState error={ error } onRetry={ () => load() } />
			</Card>
		);
	}

	const shown = ( products ?? [] ).filter(
		( product ) => product.state !== 'draft'
	);
	const drafts = ( products ?? [] ).filter(
		( product ) => product.draft
	).length;
	const columns: readonly TableColumn< DesignerShopProduct >[] = [
		{
			key: 'product',
			header: __( 'Product', 'merchboard' ),
			render: ( product ) =>
				product.url === null ? (
					product.title
				) : (
					<a href={ product.url }>{ product.title }</a>
				),
		},
		{
			key: 'state',
			header: __( 'Status', 'merchboard' ),
			render: ( product ) => (
				<>
					{ stateText( product, locale ) }
					{ product.rejection === null ? null : (
						<span className="merchboard-designer__note">
							{ sprintf(
								/* translators: %s: the administrator's note. */
								__( 'Note: %s', 'merchboard' ),
								product.rejection.note
							) }
						</span>
					) }
				</>
			),
		},
		{
			key: 'price',
			header: __( 'Price incl. VAT', 'merchboard' ),
			render: ( product ) => priceText( product, locale ),
			align: 'end',
		},
	];

	return (
		<Card
			title={ title }
			actions={
				<Link
					to={ designer.path }
					className="merchboard-button merchboard-button--secondary merchboard-button--sm"
				>
					{ __( 'Open the designer', 'merchboard' ) }
				</Link>
			}
		>
			<Table
				caption={ title }
				captionHidden
				columns={ columns }
				rows={ shown }
				rowKey={ ( product ) => product.id }
				loading={ products === null }
				loadingLabel={ __( 'Loading your designs…', 'merchboard' ) }
				empty={
					<EmptyState
						title={ __(
							'No designs in the shop or waiting for approval yet.',
							'merchboard'
						) }
						description={ __(
							'Design a product in the designer and send it for approval.',
							'merchboard'
						) }
					/>
				}
			/>
			{ drafts > 0 ? (
				<p className="merchboard-designer__drafts">
					{ sprintf(
						/* translators: %d: number of drafts. */
						_n(
							'You have %d draft in the designer.',
							'You have %d drafts in the designer.',
							drafts,
							'merchboard'
						),
						drafts
					) }
				</p>
			) : null }
		</Card>
	);
}
