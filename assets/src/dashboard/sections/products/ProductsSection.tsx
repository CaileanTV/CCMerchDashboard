import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getProducts, requestProductRemoval } from '../../api/products';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { Pagination } from '../../components/Pagination';
import { ProfitLegend, ProfitValue } from '../../components/ProfitValue';
import { Table } from '../../components/Table';
import type { TableColumn } from '../../components/Table';
import { usePagedApi } from '../../hooks/usePagedApi';
import { ConfirmDelete } from '../designer/ConfirmDelete';
import { DesignerProductsCard } from './DesignerProductsCard';
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

/**
 * A cross on a legacy product, which asks us to remove it; a designer product
 * has its own deletion below.
 *
 * @param props           Props.
 * @param props.product   The product.
 * @param props.onRequest Opens the confirmation.
 */
function RemovalCell( {
	product,
	onRequest,
}: {
	readonly product: Product;
	readonly onRequest: ( product: Product ) => void;
} ) {
	if ( product.designer ) {
		return null;
	}

	if ( product.removalPending ) {
		return <>{ __( 'Deletion awaiting handling', 'merchboard' ) }</>;
	}

	return (
		<Button
			size="sm"
			variant="ghost"
			onClick={ () => onRequest( product ) }
			aria-label={ sprintf(
				/* translators: %s: product name. */ __(
					'Delete %s',
					'merchboard'
				),
				product.name
			) }
			title={ __( 'Delete product', 'merchboard' ) }
		>
			✕
		</Button>
	);
}

const columns = (
	onRequest: ( product: Product ) => void
): readonly TableColumn< Product >[] => [
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
	{
		key: 'profit',
		header: __( 'Profit', 'merchboard' ),
		render: ( product ) => <ProductProfit product={ product } />,
		align: 'end',
	},
	{
		key: 'delete',
		header: __( 'Delete', 'merchboard' ),
		render: ( product ) => (
			<RemovalCell product={ product } onRequest={ onRequest } />
		),
		align: 'end',
	},
];

/**
 * The product's historical profit, one line per currency; "No sales" when the
 * creator has never sold it (ADR-050).
 */
function ProductProfit( { product }: { readonly product: Product } ) {
	if ( product.profit === null ) {
		return (
			<ProfitValue
				profit={ null }
				emptyText={ __( 'No sales', 'merchboard' ) }
			/>
		);
	}

	return (
		<span className="merchboard-profit__list">
			{ product.profit.map( ( profit ) => (
				<ProfitValue key={ profit.currency } profit={ profit } />
			) ) }
		</span>
	);
}

/**
 * Products (ADR-039): the creator's products from `GET /me/products`, a page
 * at a time. Status and stock are text, not colour. While a page loads the
 * pagination stays (disabled) with the last known number of pages, so
 * keyboard focus is not lost. While the designer is on, the creator's designs
 * follow (step 30.14). A legacy product is never deleted from here: the cross
 * asks us to do it by hand (ADR-083).
 */
export function ProductsSection() {
	const { state, page, setPage, retry } = usePagedApi( getProducts );
	const [ knownPages, setKnownPages ] = useState( 0 );
	const [ removing, setRemoving ] = useState< Product | null >( null );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setKnownPages( state.data.totalPages );
		}
	}, [ state ] );

	if ( state.status === 'error' ) {
		return (
			<>
				<ApiErrorState error={ state.error } onRetry={ retry } />
				<DesignerProductsCard />
			</>
		);
	}

	const title = __( 'Your products', 'merchboard' );

	return (
		<>
			<Card title={ title }>
				<Table
					caption={ title }
					captionHidden
					columns={ columns( setRemoving ) }
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
				{ state.status === 'success' && state.data.items.length > 0 && (
					<ProfitLegend />
				) }
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
				{ removing === null ? null : (
					<ConfirmDelete
						title={ removing.name }
						inShop
						request
						onConfirm={ async () => {
							const result = await requestProductRemoval(
								removing.id
							);

							if ( result.ok ) {
								setRemoving( null );
								retry();
							}

							return result.ok;
						} }
						onClose={ () => setRemoving( null ) }
					/>
				) }
			</Card>
			<DesignerProductsCard />
		</>
	);
}
