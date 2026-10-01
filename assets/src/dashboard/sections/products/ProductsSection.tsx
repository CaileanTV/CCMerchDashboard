import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getProducts } from '../../api/products';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { Pagination } from '../../components/Pagination';
import { Table } from '../../components/Table';
import type { TableColumn } from '../../components/Table';
import { usePagedApi } from '../../hooks/usePagedApi';
import type {
	Product,
	ProductPrice,
	ProductStatus,
	StockStatus,
} from '../../types/products';

function statusLabel( status: ProductStatus ): string {
	return status === 'publish'
		? __( 'Published', 'merchboard' )
		: __( 'Private', 'merchboard' );
}

function stockLabel( stock: StockStatus | null ): string {
	switch ( stock ) {
		case 'instock':
			return __( 'In stock', 'merchboard' );
		case 'outofstock':
			return __( 'Out of stock', 'merchboard' );
		case 'onbackorder':
			return __( 'On backorder', 'merchboard' );
		default:
			return __( 'Unknown', 'merchboard' );
	}
}

/**
 * The price exactly as the API sends it (the store's active price, ADR-039
 * P7): the amount strings are not parsed, converted or labelled with tax.
 *
 * @param price Price or null.
 */
function priceText( price: ProductPrice | null ): string {
	if ( price === null ) {
		return __( 'No price', 'merchboard' );
	}

	return price.min === price.max
		? sprintf(
				/* translators: 1: price as stored in the shop, 2: currency code (e.g. DKK). */
				__( '%1$s %2$s', 'merchboard' ),
				price.min,
				price.currency
			)
		: sprintf(
				/* translators: 1: lowest price, 2: highest price, 3: currency code (e.g. DKK). */
				__( '%1$s–%2$s %3$s', 'merchboard' ),
				price.min,
				price.max,
				price.currency
			);
}

function ProductCell( { product }: { readonly product: Product } ) {
	return (
		<span className="merchboard-product">
			{ product.image === null ? (
				<span
					className="merchboard-product__image merchboard-product__image--none"
					role="img"
					aria-label={ __( 'No image', 'merchboard' ) }
				/>
			) : (
				<img
					className="merchboard-product__image"
					src={ product.image.url }
					alt={ product.image.alt }
					width={ 40 }
					height={ 40 }
					loading="lazy"
				/>
			) }
			<span className="merchboard-product__name">{ product.name }</span>
		</span>
	);
}

const columns = (): readonly TableColumn< Product >[] => [
	{
		key: 'product',
		header: __( 'Product', 'merchboard' ),
		render: ( product ) => <ProductCell product={ product } />,
	},
	{
		key: 'status',
		header: __( 'Status', 'merchboard' ),
		render: ( product ) => statusLabel( product.status ),
	},
	{
		key: 'price',
		header: __( 'Price', 'merchboard' ),
		render: ( product ) => priceText( product.price ),
		align: 'end',
	},
	{
		key: 'stock',
		header: __( 'Stock', 'merchboard' ),
		render: ( product ) => stockLabel( product.stockStatus ),
	},
];

/**
 * Products (ADR-039): the creator's products from `GET /me/products`, read
 * only, a page at a time. Status and stock are text, not colour. While a page
 * loads the pagination stays (disabled) with the last known number of pages,
 * so keyboard focus is not lost.
 */
export function ProductsSection() {
	const { state, page, setPage, retry } = usePagedApi( getProducts );
	const [ knownPages, setKnownPages ] = useState( 0 );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setKnownPages( state.data.totalPages );
		}
	}, [ state ] );

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	const title = __( 'Your products', 'merchboard' );

	return (
		<Card title={ title }>
			<Table
				caption={ title }
				captionHidden
				columns={ columns() }
				rows={ state.status === 'success' ? state.data.items : [] }
				rowKey={ ( product ) => product.id }
				loading={ state.status === 'loading' }
				loadingLabel={ __( 'Loading products…', 'merchboard' ) }
				empty={
					page > 1 ? (
						<EmptyState
							title={ __(
								'There are no products on this page.',
								'merchboard'
							) }
						/>
					) : (
						<EmptyState
							title={ __(
								'You have no products yet.',
								'merchboard'
							) }
							description={ __(
								'Products linked to you in the shop will appear here.',
								'merchboard'
							) }
						/>
					)
				}
			/>
			<Pagination
				page={ page }
				totalPages={
					state.status === 'success'
						? state.data.totalPages
						: knownPages
				}
				onChange={ setPage }
				label={ __( 'Product pages', 'merchboard' ) }
				disabled={ state.status === 'loading' }
			/>
		</Card>
	);
}
