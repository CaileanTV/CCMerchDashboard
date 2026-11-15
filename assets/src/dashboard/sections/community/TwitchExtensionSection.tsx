import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import type { ApiError } from '../../api/client';
import { MAX_PER_PAGE } from '../../api/client';
import { getProducts } from '../../api/products';
import {
	getTwitchExtension,
	saveTwitchExtension,
} from '../../api/twitchExtension';
import type {
	PanelProduct,
	TwitchExtensionState,
	TwitchExtensionValues,
} from '../../api/twitchExtension';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';
import { TwitchConnectionCard } from './TwitchConnectionCard';

const MAX_PRODUCT_PAGES = 5;

interface ProductChoice {
	readonly id: number;
	readonly name: string;
}

/**
 * The text of a field error code.
 *
 * @param code Code from the server.
 */
function fieldError( code: string ): string {
	switch ( code ) {
		case 'too_many':
			return __( 'Choose at most 12 products.', 'merchboard' );
		case 'not_owned':
			return __( 'Choose only your own products.', 'merchboard' );
		case 'too_long':
			return __( 'Use at most 60 characters.', 'merchboard' );
		case 'no_links':
			return __(
				'Links and web addresses are not allowed: Twitch does not allow them in the panel. Write a chat command instead, e.g. !merch.',
				'merchboard'
			);
		default:
			return __( 'Check this field.', 'merchboard' );
	}
}

/**
 * The creator's products for the picker: every page, at most 500.
 *
 * @param signal Abort signal.
 */
async function loadProducts(
	signal: AbortSignal
): Promise< ProductChoice[] | null > {
	const products: ProductChoice[] = [];

	for ( let page = 1; page <= MAX_PRODUCT_PAGES; page++ ) {
		const result = await getProducts( page, MAX_PER_PAGE, signal );

		if ( ! result.ok ) {
			return null;
		}

		products.push(
			...result.data.items.map( ( product ) => ( {
				id: product.id,
				name: product.name,
			} ) )
		);

		if ( page >= result.data.totalPages ) {
			break;
		}
	}

	return products;
}

/**
 * The pill's text with chat commands (`!merch`) in bold, as on Twitch.
 *
 * @param props      Props.
 * @param props.note The text.
 */
function Pill( { note }: { readonly note: string } ) {
	return (
		<p className="merchboard-twitch-panel-pill">
			{ note
				.split( /(![A-Za-z0-9_]+)/ )
				.filter( ( part ) => part !== '' )
				.map( ( part, index ) =>
					/^![A-Za-z0-9_]+$/.test( part ) ? (
						<strong key={ index }>{ part }</strong>
					) : (
						<span key={ index }>{ part }</span>
					)
				) }
		</p>
	);
}

/**
 * The panel as viewers see it on Twitch: the pill and the products with
 * image and name.
 *
 * @param props          Props.
 * @param props.products The products.
 * @param props.note     The pill's text.
 */
function PanelPreview( {
	products,
	note,
}: {
	readonly products: readonly PanelProduct[];
	readonly note: string;
} ) {
	if ( products.length === 0 ) {
		return (
			<p className="merchboard-field__help">
				{ __( 'The panel has no products to show yet.', 'merchboard' ) }
			</p>
		);
	}

	return (
		<div className="merchboard-twitch-panel-preview-wrap">
			{ note !== '' && <Pill note={ note } /> }
			<ul
				className="merchboard-twitch-panel-preview"
				aria-label={ __( 'Preview of the panel', 'merchboard' ) }
			>
				{ products.map( ( product ) => (
					<li key={ product.id }>
						{ product.image !== null ? (
							<img src={ product.image } alt="" loading="lazy" />
						) : (
							<span className="merchboard-twitch-panel-preview__blank" />
						) }
						<span>{ product.name }</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Community Features → Twitch Extension (ADR-112): the creator switches
 * the panel on, chooses up to 12 products and their order (none chosen: the
 * newest), sees a preview and a guide to install the shop's extension on the
 * channel. Twitch does not allow prices, shop links or buying in the panel;
 * the guide tells the creator to link the shop from an ordinary panel.
 */
export function TwitchExtensionSection() {
	const { state, retry } = useApi( getTwitchExtension );
	const [ current, setCurrent ] = useState< TwitchExtensionState | null >(
		null
	);
	const [ values, setValues ] = useState< TwitchExtensionValues | null >(
		null
	);
	const [ products, setProducts ] = useState< ProductChoice[] | null >(
		null
	);
	const [ productsFailed, setProductsFailed ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setCurrent( state.data );
			setValues( state.data );
		}
	}, [ state ] );

	useEffect( () => {
		const controller = new AbortController();

		void loadProducts( controller.signal ).then( ( loaded ) => {
			if ( ! controller.signal.aborted ) {
				setProducts( loaded ?? [] );
				setProductsFailed( loaded === null );
			}
		} );

		return () => controller.abort();
	}, [] );

	const names = useMemo(
		() =>
			new Map(
				( products ?? [] ).map( ( product ) => [
					product.id,
					product.name,
				] )
			),
		[ products ]
	);
	const shown = useMemo( () => {
		const needle = search.trim().toLowerCase();

		return ( products ?? [] ).filter(
			( product ) =>
				needle === '' || product.name.toLowerCase().includes( needle )
		);
	}, [ products, search ] );

	const title = __( 'Twitch Extension', 'merchboard' );

	if (
		state.status === 'loading' ||
		( state.status === 'success' && values === null )
	) {
		return (
			<Card title={ title }>
				<Skeleton
					label={ __(
						'Loading the Twitch Extension…',
						'merchboard'
					) }
					lines={ 3 }
				/>
			</Card>
		);
	}

	if ( state.status === 'error' || values === null || current === null ) {
		return (
			<Card title={ title }>
				{ state.status === 'error' && (
					<ApiErrorState error={ state.error } onRetry={ retry } />
				) }
			</Card>
		);
	}

	const errors = error?.fields ?? {};
	const chosen = values.productIds;
	const full = chosen.length >= current.maxProducts;

	const toggle = ( id: number, on: boolean ) =>
		setValues( {
			...values,
			productIds: on
				? [ ...chosen.filter( ( item ) => item !== id ), id ]
				: chosen.filter( ( item ) => item !== id ),
		} );

	const move = ( index: number, by: -1 | 1 ) => {
		const target = index + by;
		const moved = chosen[ index ];
		const other = chosen[ target ];

		if ( moved === undefined || other === undefined ) {
			return;
		}

		const next = [ ...chosen ];
		next[ index ] = other;
		next[ target ] = moved;
		setValues( { ...values, productIds: next } );
	};

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		const result = await saveTwitchExtension( values );
		setBusy( false );

		if ( result.ok ) {
			setCurrent( result.data );
			setValues( result.data );
			setToast( __( 'Twitch Extension saved.', 'merchboard' ) );
		} else {
			setError( result.error );
		}
	};

	return (
		<Card title={ title }>
			<p className="merchboard-field__help">
				{ __(
					"Show your products in a panel under your stream on Twitch. The panel shows each product's image and name. Twitch does not allow prices, links to the shop or buying in an extension, so link to your shop from an ordinary Twitch panel.",
					'merchboard'
				) }
			</p>
			{ ! current.ready && (
				<Alert tone="info">
					{ __(
						'The shop has not finished setting up the Twitch Extension yet. You can already choose your products; the panel shows them once the extension is ready.',
						'merchboard'
					) }
				</Alert>
			) }
			{ error !== null && Object.keys( errors ).length === 0 && (
				<ApiErrorState error={ error } />
			) }
			<form
				className="merchboard-community-form"
				noValidate
				onSubmit={ ( event ) => void submit( event ) }
			>
				<div className="merchboard-community-top">
					<div className="merchboard-community-top__switch">
						<label
							className="merchboard-payouts__check"
							htmlFor="merchboard-twitch-extension-enabled"
						>
							<input
								id="merchboard-twitch-extension-enabled"
								type="checkbox"
								checked={ values.enabled }
								onChange={ ( event ) =>
									setValues( {
										...values,
										enabled: event.target.checked,
									} )
								}
							/>{ ' ' }
							{ __(
								'Show my products in the panel',
								'merchboard'
							) }
						</label>
						{ errors.enabled !== undefined && (
							<p className="merchboard-field__error">
								{ fieldError( errors.enabled ) }
							</p>
						) }
					</div>
					<TwitchConnectionCard
						channel={ current.channel }
						twitch={ current.twitch }
						from="community/twitch-extension"
					/>
				</div>

				<div className="merchboard-field">
					<label
						className="merchboard-field__label"
						htmlFor="merchboard-twitch-extension-note"
					>
						{ __( 'Text in the pill (optional)', 'merchboard' ) }
					</label>
					<input
						id="merchboard-twitch-extension-note"
						className="merchboard-input"
						type="text"
						value={ values.note }
						maxLength={ current.maxNote }
						placeholder={ __(
							'Type !merch in the chat',
							'merchboard'
						) }
						onChange={ ( event ) =>
							setValues( { ...values, note: event.target.value } )
						}
					/>
					<p className="merchboard-field__help">
						{ __(
							'Shown in a pill at the top of the panel, e.g. a chat command. Commands that start with ! are shown in bold. No links: Twitch does not allow them in the panel.',
							'merchboard'
						) }
					</p>
					{ errors.note !== undefined && (
						<p className="merchboard-field__error">
							{ fieldError( errors.note ) }
						</p>
					) }
				</div>

				<fieldset className="merchboard-community-campaign">
					<legend className="merchboard-field__label">
						{ __( 'Products in the panel', 'merchboard' ) }
					</legend>
					<p className="merchboard-field__help">
						{ sprintf(
							/* translators: %d: the most products. */
							__(
								'Choose up to %d products in the order you want. Choose none, and the panel shows your newest products.',
								'merchboard'
							),
							current.maxProducts
						) }
					</p>
					{ chosen.length > 0 && (
						<ol className="merchboard-twitch-panel-order">
							{ chosen.map( ( id, index ) => (
								<li key={ id }>
									<span>
										{ names.get( id ) ??
											sprintf(
												/* translators: %d: product id. */
												__(
													'Product %d',
													'merchboard'
												),
												id
											) }
									</span>
									<Button
										variant="ghost"
										size="sm"
										disabled={ index === 0 }
										aria-label={ __(
											'Move up',
											'merchboard'
										) }
										onClick={ () => move( index, -1 ) }
									>
										↑
									</Button>
									<Button
										variant="ghost"
										size="sm"
										disabled={ index === chosen.length - 1 }
										aria-label={ __(
											'Move down',
											'merchboard'
										) }
										onClick={ () => move( index, 1 ) }
									>
										↓
									</Button>
									<Button
										variant="ghost"
										size="sm"
										onClick={ () => toggle( id, false ) }
									>
										{ __( 'Remove', 'merchboard' ) }
									</Button>
								</li>
							) ) }
						</ol>
					) }
					<div className="merchboard-product-picker">
						{ products === null && (
							<Skeleton
								label={ __(
									'Loading your products…',
									'merchboard'
								) }
								lines={ 3 }
							/>
						) }
						{ productsFailed && (
							<Alert tone="warning">
								{ __(
									'Your products could not be loaded. Please try again later.',
									'merchboard'
								) }
							</Alert>
						) }
						{ products !== null && products.length > 0 && (
							<>
								<input
									className="merchboard-input"
									type="search"
									value={ search }
									placeholder={ __(
										'Search your products',
										'merchboard'
									) }
									aria-label={ __(
										'Search your products',
										'merchboard'
									) }
									onChange={ ( event ) =>
										setSearch( event.target.value )
									}
								/>
								<ul className="merchboard-product-picker__list">
									{ shown.map( ( product ) => {
										const checked = chosen.includes(
											product.id
										);

										return (
											<li key={ product.id }>
												<label
													htmlFor={ `merchboard-twitch-extension-product-${ product.id }` }
												>
													<input
														id={ `merchboard-twitch-extension-product-${ product.id }` }
														type="checkbox"
														checked={ checked }
														disabled={
															! checked && full
														}
														onChange={ ( event ) =>
															toggle(
																product.id,
																event.target
																	.checked
															)
														}
													/>{ ' ' }
													{ product.name }
												</label>
											</li>
										);
									} ) }
								</ul>
							</>
						) }
					</div>
					{ errors.product_ids !== undefined && (
						<p className="merchboard-field__error">
							{ fieldError( errors.product_ids ) }
						</p>
					) }
				</fieldset>

				<div className="merchboard-community-form__actions">
					<Button type="submit" isBusy={ busy }>
						{ __( 'Save', 'merchboard' ) }
					</Button>
				</div>
			</form>

			<h3 className="merchboard-field__label">
				{ __( 'Preview', 'merchboard' ) }
			</h3>
			<p className="merchboard-field__help">
				{ __(
					'What the panel shows after you save. Prices and links are not shown on Twitch.',
					'merchboard'
				) }
			</p>
			<PanelPreview products={ current.preview } note={ current.note } />

			<h3 className="merchboard-field__label">
				{ __( 'Get the panel on your channel', 'merchboard' ) }
			</h3>
			<ol className="merchboard-twitch-guide">
				<li>
					{ __(
						'Connect your Twitch channel above and switch the panel on.',
						'merchboard'
					) }
				</li>
				<li>
					{ current.installUrl !== null ? (
						<a
							href={ current.installUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __(
								"Install the shop's extension on Twitch",
								'merchboard'
							) }
						</a>
					) : (
						__(
							"Install the shop's extension on Twitch (the link comes when the shop has published it).",
							'merchboard'
						)
					) }
				</li>
				<li>
					{ __(
						'On Twitch, open Creator Dashboard → Extensions → My Extensions and activate it as a panel.',
						'merchboard'
					) }
				</li>
				<li>
					{ __(
						'Add an ordinary panel under "Edit panels" on your channel with a link to your shop, e.g. an image that says "Merch".',
						'merchboard'
					) }
				</li>
			</ol>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</Card>
	);
}
