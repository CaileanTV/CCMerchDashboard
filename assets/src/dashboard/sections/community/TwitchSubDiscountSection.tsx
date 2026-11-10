import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { connectChatbot } from '../../api/chatbot';
import type { ApiError } from '../../api/client';
import { MAX_PER_PAGE } from '../../api/client';
import {
	getTwitchSubDiscount,
	saveTwitchSubDiscount,
} from '../../api/community';
import type {
	TwitchSubDiscountState,
	TwitchSubDiscountValues,
} from '../../api/community';
import { getProducts } from '../../api/products';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';

/** Most product pages read for the picker (100 each; the selection has at most 500). */
const MAX_PRODUCT_PAGES = 5;

const TIERS = [ 1, 2, 3 ] as const;

/** The form field of each tier in the server's field errors. */
const TIER_FIELDS = { 1: 'tier_one', 2: 'tier_two', 3: 'tier_three' } as const;

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
		case 'not_connected':
			return __(
				'Connect your Twitch channel before you switch the discount on.',
				'merchboard'
			);
		case 'required':
			return __( 'Fill in this field.', 'merchboard' );
		case 'too_many':
			return __( 'Choose at most 500 products.', 'merchboard' );
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
 * Community Features → Twitch subscriber discount (phase 43, ADR-106): the
 * creator's Twitch subscribers get a discount on the creator's products in
 * the shop, a percent per tier, paid from the creator's profit. The creator
 * chooses whether gifted subscriptions count and which products. The
 * discount needs the creator's connected Twitch channel.
 */
export function TwitchSubDiscountSection() {
	const { state, retry } = useApi( getTwitchSubDiscount );
	const [ current, setCurrent ] = useState< TwitchSubDiscountState | null >(
		null
	);
	const [ values, setValues ] = useState< TwitchSubDiscountValues | null >(
		null
	);
	const [ products, setProducts ] = useState< ProductChoice[] | null >(
		null
	);
	const [ productsFailed, setProductsFailed ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ connecting, setConnecting ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setCurrent( state.data );
			setValues( state.data );
		}
	}, [ state ] );

	const selecting = values?.scope === 'selected';

	useEffect( () => {
		if ( ! selecting || products !== null ) {
			return undefined;
		}

		const controller = new AbortController();

		void loadProducts( controller.signal ).then( ( loaded ) => {
			if ( ! controller.signal.aborted ) {
				setProducts( loaded ?? [] );
				setProductsFailed( loaded === null );
			}
		} );

		return () => controller.abort();
	}, [ selecting, products ] );

	const shown = useMemo( () => {
		const needle = search.trim().toLowerCase();

		return ( products ?? [] ).filter(
			( product ) =>
				needle === '' || product.name.toLowerCase().includes( needle )
		);
	}, [ products, search ] );

	const title = __( 'Twitch subscriber discount', 'merchboard' );

	if (
		state.status === 'loading' ||
		( state.status === 'success' && values === null )
	) {
		return (
			<Card title={ title }>
				<Skeleton
					label={ __( 'Loading the discount…', 'merchboard' ) }
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

	const connect = async () => {
		setConnecting( true );
		setError( null );
		const answer = await connectChatbot( 'twitch' );

		if ( answer.ok ) {
			window.location.assign( answer.data );
			return;
		}

		setConnecting( false );
		setError( answer.error );
	};

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		const result = await saveTwitchSubDiscount( values );
		setBusy( false );

		if ( result.ok ) {
			setCurrent( result.data );
			setValues( result.data );
			setToast( __( 'Twitch subscriber discount saved.', 'merchboard' ) );
		} else {
			setError( result.error );
		}
	};

	const toggleProduct = ( id: number, checked: boolean ) =>
		setValues( {
			...values,
			productIds: checked
				? [ ...values.productIds, id ]
				: values.productIds.filter( ( selected ) => selected !== id ),
		} );

	return (
		<Card title={ title }>
			<p className="merchboard-field__help">
				{ __(
					'Your Twitch subscribers get a discount on your products in the shop. They log in with Twitch in the cart, and the discount follows their tier on your channel. The discount is taken from your profit, never more than your profit on a product, and it is not added to other discount codes: the customer gets the bigger one.',
					'merchboard'
				) }
			</p>
			{ current.channel === null ? (
				<Alert tone="warning">
					{ current.twitch
						? __(
								'Connect your Twitch channel, so we can check who subscribes to it. The chat messages of the chatbot can stay off.',
								'merchboard'
							)
						: __(
								'Twitch is not set up in the shop yet. Please contact us.',
								'merchboard'
							) }
					{ current.twitch && (
						<p>
							<Button
								type="button"
								variant="secondary"
								isBusy={ connecting }
								onClick={ () => void connect() }
							>
								{ __( 'Connect Twitch', 'merchboard' ) }
							</Button>
						</p>
					) }
				</Alert>
			) : (
				<p className="merchboard-field__help">
					{ sprintf(
						/* translators: %s: the creator's Twitch channel. */
						__( 'Twitch channel: %s', 'merchboard' ),
						current.channel
					) }
				</p>
			) }
			{ error !== null && Object.keys( errors ).length === 0 && (
				<ApiErrorState error={ error } />
			) }
			<form
				className="merchboard-community-form"
				noValidate
				onSubmit={ ( event ) => void submit( event ) }
			>
				<label
					className="merchboard-payouts__check"
					htmlFor="merchboard-twitch-sub-enabled"
				>
					<input
						id="merchboard-twitch-sub-enabled"
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
						'Give my Twitch subscribers a discount',
						'merchboard'
					) }
				</label>
				{ errors.enabled !== undefined && (
					<p className="merchboard-field__error">
						{ fieldError( errors.enabled ) }
					</p>
				) }
				<fieldset className="merchboard-community-form__tiers">
					<legend className="merchboard-field__label">
						{ __( 'Discount per tier', 'merchboard' ) }
					</legend>
					{ TIERS.map( ( tier ) => {
						const id = `merchboard-twitch-sub-tier-${ tier }`;
						const tierError = errors[ TIER_FIELDS[ tier ] ];

						return (
							<div className="merchboard-field" key={ tier }>
								<label
									className="merchboard-field__label"
									htmlFor={ id }
								>
									{ sprintf(
										/* translators: %d: Twitch subscription tier (1, 2 or 3). */
										__( 'Tier %d (%%)', 'merchboard' ),
										tier
									) }
								</label>
								<input
									id={ id }
									className="merchboard-input"
									type="number"
									min={ 0 }
									max={ 100 }
									step={ 1 }
									value={ values.tiers[ tier ] }
									aria-invalid={
										tierError === undefined
											? undefined
											: true
									}
									onChange={ ( event ) =>
										setValues( {
											...values,
											tiers: {
												...values.tiers,
												[ tier ]: Math.max(
													0,
													Math.min(
														100,
														Math.round(
															Number(
																event.target
																	.value
															) || 0
														)
													)
												),
											},
										} )
									}
								/>
								{ tierError !== undefined && (
									<p className="merchboard-field__error">
										{ fieldError( tierError ) }
									</p>
								) }
							</div>
						);
					} ) }
					<p className="merchboard-field__help">
						{ __(
							'Prime subscriptions count as tier 1.',
							'merchboard'
						) }
					</p>
				</fieldset>
				<label
					className="merchboard-payouts__check"
					htmlFor="merchboard-twitch-sub-gifted"
				>
					<input
						id="merchboard-twitch-sub-gifted"
						type="checkbox"
						checked={ values.gifted }
						onChange={ ( event ) =>
							setValues( {
								...values,
								gifted: event.target.checked,
							} )
						}
					/>{ ' ' }
					{ __(
						'Subscriptions someone gifted also get the discount',
						'merchboard'
					) }
				</label>
				<fieldset className="merchboard-community-form__scope">
					<legend className="merchboard-field__label">
						{ __( 'Products', 'merchboard' ) }
					</legend>
					<label htmlFor="merchboard-twitch-sub-all">
						<input
							id="merchboard-twitch-sub-all"
							type="radio"
							name="scope"
							checked={ values.scope === 'all' }
							onChange={ () =>
								setValues( { ...values, scope: 'all' } )
							}
						/>{ ' ' }
						{ __( 'All my products', 'merchboard' ) }
					</label>
					<label htmlFor="merchboard-twitch-sub-selected">
						<input
							id="merchboard-twitch-sub-selected"
							type="radio"
							name="scope"
							checked={ values.scope === 'selected' }
							onChange={ () =>
								setValues( { ...values, scope: 'selected' } )
							}
						/>{ ' ' }
						{ __( 'Only the products I choose', 'merchboard' ) }
					</label>
				</fieldset>
				{ selecting && (
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
								<p className="merchboard-field__help">
									{ sprintf(
										/* translators: %d: number of chosen products. */
										__( '%d chosen', 'merchboard' ),
										values.productIds.length
									) }
								</p>
								<ul className="merchboard-product-picker__list">
									{ shown.map( ( product ) => (
										<li key={ product.id }>
											<label
												htmlFor={ `merchboard-twitch-sub-product-${ product.id }` }
											>
												<input
													id={ `merchboard-twitch-sub-product-${ product.id }` }
													type="checkbox"
													checked={ values.productIds.includes(
														product.id
													) }
													onChange={ ( event ) =>
														toggleProduct(
															product.id,
															event.target.checked
														)
													}
												/>{ ' ' }
												{ product.name }
											</label>
										</li>
									) ) }
								</ul>
							</>
						) }
						{ products !== null &&
							products.length === 0 &&
							! productsFailed && (
								<p className="merchboard-field__help">
									{ __(
										'You have no products yet.',
										'merchboard'
									) }
								</p>
							) }
						{ errors.product_ids !== undefined && (
							<p className="merchboard-field__error">
								{ fieldError( errors.product_ids ) }
							</p>
						) }
					</div>
				) }
				<Button type="submit" isBusy={ busy }>
					{ __( 'Save', 'merchboard' ) }
				</Button>
			</form>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</Card>
	);
}
