import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { connectChatbot } from '../../api/chatbot';
import type { ApiError } from '../../api/client';
import { MAX_PER_PAGE } from '../../api/client';
import {
	MAX_CAMPAIGNS,
	getTwitchSubDiscount,
	maxPercent,
	saveTwitchSubDiscount,
} from '../../api/community';
import type {
	TwitchSubCampaign,
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

/** Most product pages read for the picker (100 each; a selection has at most 500). */
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
				'Connect your Twitch channel before you switch a campaign on.',
				'merchboard'
			);
		case 'required':
			return __( 'Fill in this field.', 'merchboard' );
		case 'too_long':
			return __( 'Use at most 60 characters.', 'merchboard' );
		case 'too_many':
			return __( 'Choose at most 500 products.', 'merchboard' );
		case 'too_high':
			return __(
				"Higher than your lowest profit on the campaign's products.",
				'merchboard'
			);
		case 'taken':
			return __(
				'Another campaign covers this at the same time. A product can be in one campaign of chosen products and one of all products at a time.',
				'merchboard'
			);
		case 'before_start':
			return __( 'The end must not be before the start.', 'merchboard' );
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

/** A new campaign. */
function newCampaign(): TwitchSubCampaign {
	return {
		id: '',
		name: '',
		enabled: true,
		tiers: { 1: 0, 2: 0, 3: 0 },
		scope: 'selected',
		productIds: [],
		starts: '',
		ends: '',
	};
}

interface CampaignEditorProps {
	readonly index: number;
	readonly campaign: TwitchSubCampaign;
	readonly products: ProductChoice[] | null;
	readonly productsFailed: boolean;
	readonly profitPercents: Readonly< Record< number, number > >;
	readonly errors: Readonly< Record< string, string > >;
	readonly onChange: ( campaign: TwitchSubCampaign ) => void;
	readonly onRemove: () => void;
}

/**
 * One campaign in the form.
 *
 * @param props                Props.
 * @param props.index          Its place in the list.
 * @param props.campaign       The campaign.
 * @param props.products       The creator's products, null while loading.
 * @param props.productsFailed Whether the products could not be loaded.
 * @param props.profitPercents The creator's profit percent by product.
 * @param props.errors         Field errors of the last save.
 * @param props.onChange       Called with the changed campaign.
 * @param props.onRemove       Removes it.
 */
function CampaignEditor( {
	index,
	campaign,
	products,
	productsFailed,
	profitPercents,
	errors,
	onChange,
	onRemove,
}: CampaignEditorProps ) {
	const [ search, setSearch ] = useState( '' );
	const prefix = `campaigns.${ index }.`;
	const id = ( field: string ) =>
		`merchboard-twitch-sub-${ index }-${ field }`;
	const error = ( field: string ) => {
		const code = errors[ prefix + field ];

		return code === undefined ? null : (
			<p className="merchboard-field__error">{ fieldError( code ) }</p>
		);
	};
	const most = maxPercent( campaign, profitPercents );
	const shown = useMemo( () => {
		const needle = search.trim().toLowerCase();

		return ( products ?? [] ).filter(
			( product ) =>
				needle === '' || product.name.toLowerCase().includes( needle )
		);
	}, [ products, search ] );

	const toggle = ( product: number, checked: boolean ) =>
		onChange( {
			...campaign,
			productIds: checked
				? [ ...campaign.productIds, product ]
				: campaign.productIds.filter(
						( selected ) => selected !== product
					),
		} );

	const setTier = ( tier: 1 | 2 | 3, value: string ) =>
		onChange( {
			...campaign,
			tiers: {
				...campaign.tiers,
				[ tier ]: Math.max(
					0,
					Math.min( 100, Math.round( Number( value ) || 0 ) )
				),
			},
		} );

	return (
		<fieldset className="merchboard-community-campaign">
			<legend className="merchboard-field__label">
				{ campaign.name === ''
					? sprintf(
							/* translators: %d: campaign number. */
							__( 'Campaign %d', 'merchboard' ),
							index + 1
						)
					: campaign.name }
			</legend>
			<div className="merchboard-field">
				<label
					className="merchboard-field__label"
					htmlFor={ id( 'name' ) }
				>
					{ __( 'Name', 'merchboard' ) }
				</label>
				<input
					id={ id( 'name' ) }
					className="merchboard-input"
					type="text"
					maxLength={ 60 }
					value={ campaign.name }
					onChange={ ( event ) =>
						onChange( { ...campaign, name: event.target.value } )
					}
				/>
				{ error( 'name' ) }
			</div>
			<label
				className="merchboard-payouts__check"
				htmlFor={ id( 'enabled' ) }
			>
				<input
					id={ id( 'enabled' ) }
					type="checkbox"
					checked={ campaign.enabled }
					onChange={ ( event ) =>
						onChange( {
							...campaign,
							enabled: event.target.checked,
						} )
					}
				/>{ ' ' }
				{ __( 'On', 'merchboard' ) }
			</label>
			{ error( 'enabled' ) }
			<div className="merchboard-community-campaign__tiers">
				{ TIERS.map( ( tier ) => (
					<div className="merchboard-field" key={ tier }>
						<label
							className="merchboard-field__label"
							htmlFor={ id( TIER_FIELDS[ tier ] ) }
						>
							{ sprintf(
								/* translators: %d: Twitch subscription tier (1, 2 or 3). */
								__( 'Tier %d (%%)', 'merchboard' ),
								tier
							) }
						</label>
						<input
							id={ id( TIER_FIELDS[ tier ] ) }
							className="merchboard-input"
							type="number"
							min={ 0 }
							max={ most ?? 100 }
							step={ 1 }
							value={ campaign.tiers[ tier ] }
							onChange={ ( event ) =>
								setTier( tier, event.target.value )
							}
						/>
						{ error( TIER_FIELDS[ tier ] ) }
					</div>
				) ) }
			</div>
			<p className="merchboard-field__help">
				{ most === null
					? __( 'Prime subscriptions count as tier 1.', 'merchboard' )
					: sprintf(
							/* translators: %d: the highest percent allowed. */
							__(
								"At most %d %%: your lowest profit on the campaign's products. Prime subscriptions count as tier 1.",
								'merchboard'
							),
							most
						) }
			</p>
			<div className="merchboard-community-campaign__scope">
				<label htmlFor={ id( 'all' ) }>
					<input
						id={ id( 'all' ) }
						type="radio"
						name={ id( 'scope' ) }
						checked={ campaign.scope === 'all' }
						onChange={ () =>
							onChange( { ...campaign, scope: 'all' } )
						}
					/>{ ' ' }
					{ __( 'All my products', 'merchboard' ) }
				</label>
				<label htmlFor={ id( 'selected' ) }>
					<input
						id={ id( 'selected' ) }
						type="radio"
						name={ id( 'scope' ) }
						checked={ campaign.scope === 'selected' }
						onChange={ () =>
							onChange( { ...campaign, scope: 'selected' } )
						}
					/>{ ' ' }
					{ __( 'Only the products I choose', 'merchboard' ) }
				</label>
				{ error( 'scope' ) }
			</div>
			{ campaign.scope === 'selected' && (
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
									campaign.productIds.length
								) }
							</p>
							<ul className="merchboard-product-picker__list">
								{ shown.map( ( product ) => (
									<li key={ product.id }>
										<label
											htmlFor={ id(
												`product-${ product.id }`
											) }
										>
											<input
												id={ id(
													`product-${ product.id }`
												) }
												type="checkbox"
												checked={ campaign.productIds.includes(
													product.id
												) }
												onChange={ ( event ) =>
													toggle(
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
					{ error( 'product_ids' ) }
				</div>
			) }
			<div className="merchboard-community-campaign__period">
				<div className="merchboard-field">
					<label
						className="merchboard-field__label"
						htmlFor={ id( 'starts' ) }
					>
						{ __( 'From (optional)', 'merchboard' ) }
					</label>
					<input
						id={ id( 'starts' ) }
						className="merchboard-input"
						type="date"
						value={ campaign.starts }
						onChange={ ( event ) =>
							onChange( {
								...campaign,
								starts: event.target.value,
							} )
						}
					/>
					{ error( 'starts' ) }
				</div>
				<div className="merchboard-field">
					<label
						className="merchboard-field__label"
						htmlFor={ id( 'ends' ) }
					>
						{ __( 'To and including (optional)', 'merchboard' ) }
					</label>
					<input
						id={ id( 'ends' ) }
						className="merchboard-input"
						type="date"
						value={ campaign.ends }
						onChange={ ( event ) =>
							onChange( {
								...campaign,
								ends: event.target.value,
							} )
						}
					/>
					{ error( 'ends' ) }
				</div>
			</div>
			<Button
				type="button"
				variant="ghost"
				size="sm"
				onClick={ onRemove }
			>
				{ __( 'Remove campaign', 'merchboard' ) }
			</Button>
		</fieldset>
	);
}

/**
 * Community Features → Twitch subscriber discount (phase 43, ADR-106): the
 * creator's Twitch subscribers get a discount on the creator's products in
 * the shop, in campaigns with a percent per tier, all or chosen products and
 * an optional period, paid from the creator's profit and never above the
 * creator's lowest profit percent on the campaign's products. The discount
 * needs the creator's connected Twitch channel.
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

	const selecting =
		values?.campaigns.some(
			( campaign ) => campaign.scope === 'selected'
		) ?? false;

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

	const replace = ( index: number, campaign: TwitchSubCampaign | null ) =>
		setValues( {
			...values,
			campaigns:
				campaign === null
					? values.campaigns.filter( ( _, at ) => at !== index )
					: values.campaigns.map( ( existing, at ) =>
							at === index ? campaign : existing
						),
		} );

	return (
		<Card title={ title }>
			<p className="merchboard-field__help">
				{ __(
					"Your Twitch subscribers get a discount on your products in the shop. They log in with Twitch in the cart, and the discount follows their tier on your channel. The discount is taken from your profit and is never more than your lowest profit on the campaign's products. It is not added to other discount codes: the customer gets the bigger one.",
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
				<p className="merchboard-field__help">
					{ __(
						'You can run several campaigns at a time, e.g. one for all products and others for chosen products or a weekend. On a product, a campaign of chosen products goes before the one for all products.',
						'merchboard'
					) }
				</p>
				{ values.campaigns.length === 0 && (
					<p className="merchboard-field__help">
						{ __( 'You have no campaigns yet.', 'merchboard' ) }
					</p>
				) }
				{ values.campaigns.map( ( campaign, index ) => (
					<CampaignEditor
						key={ `${ campaign.id }-${ index }` }
						index={ index }
						campaign={ campaign }
						products={ products }
						productsFailed={ productsFailed }
						profitPercents={ current.profitPercents }
						errors={ errors }
						onChange={ ( changed ) => replace( index, changed ) }
						onRemove={ () => replace( index, null ) }
					/>
				) ) }
				{ errors.campaigns !== undefined && (
					<p className="merchboard-field__error">
						{ fieldError( errors.campaigns ) }
					</p>
				) }
				<div className="merchboard-community-form__actions">
					{ values.campaigns.length < MAX_CAMPAIGNS && (
						<Button
							type="button"
							variant="secondary"
							onClick={ () =>
								setValues( {
									...values,
									campaigns: [
										...values.campaigns,
										newCampaign(),
									],
								} )
							}
						>
							{ __( 'Add campaign', 'merchboard' ) }
						</Button>
					) }
					<Button type="submit" isBusy={ busy }>
						{ __( 'Save', 'merchboard' ) }
					</Button>
				</div>
			</form>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</Card>
	);
}
