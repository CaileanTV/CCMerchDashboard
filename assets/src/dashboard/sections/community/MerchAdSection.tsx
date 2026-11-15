import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ChangeEvent, FormEvent } from 'react';

import type { ApiError, ApiResult } from '../../api/client';
import { MAX_PER_PAGE } from '../../api/client';
import {
	ANIMATIONS,
	DESIGNS,
	addCutout,
	getMerchAd,
	regenerateMerchAd,
	removeCutout,
	saveMerchAd,
	showMerchAd,
} from '../../api/merchAd';
import type {
	MerchAdAnimation,
	MerchAdDesign,
	MerchAdProduct,
	MerchAdState,
	MerchAdValues,
} from '../../api/merchAd';
import { getProducts } from '../../api/products';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';
import { cutoutFrom } from './cutout';

const MAX_PRODUCT_PAGES = 5;

interface ProductChoice {
	readonly id: number;
	readonly name: string;
}

/**
 * A design's name and description.
 *
 * @param design Design.
 */
function designText( design: MerchAdDesign ): [ string, string ] {
	switch ( design ) {
		case 'shelf':
			return [
				__( 'Shelf', 'merchboard' ),
				__(
					'Up to four products in a row on a glass bar.',
					'merchboard'
				),
			];
		case 'carousel':
			return [
				__( 'Carousel', 'merchboard' ),
				__( 'A strip where the products run past.', 'merchboard' ),
			];
		case 'corner':
			return [
				__( 'Corner card', 'merchboard' ),
				__( 'A small card with product, name and pill.', 'merchboard' ),
			];
		case 'stack':
			return [
				__( 'Stack', 'merchboard' ),
				__(
					'One product in front, the two others right behind it, turned to the sides.',
					'merchboard'
				),
			];
		case 'showcase':
			return [
				__( 'Three in front', 'merchboard' ),
				__(
					'Three products, the outer two turned at an angle, with the pill below.',
					'merchboard'
				),
			];
		default:
			return [
				__( 'Spotlight', 'merchboard' ),
				__(
					'One big product at a time, with its name and the pill.',
					'merchboard'
				),
			];
	}
}

/**
 * An animation's name.
 *
 * @param animation Animation.
 */
function animationText( animation: MerchAdAnimation ): string {
	switch ( animation ) {
		case 'slide':
			return __( 'Slide', 'merchboard' );
		case 'pop':
			return __( 'Pop', 'merchboard' );
		case 'zoom':
			return __( 'Zoom', 'merchboard' );
		case 'blur':
			return __( 'Blur', 'merchboard' );
		default:
			return __( 'Fade', 'merchboard' );
	}
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
				'Links and web addresses are not allowed in the pill. Write a chat command instead, e.g. !merch.',
				'merchboard'
			);
		case 'too_large':
			return __( 'The image is too large (at most 4 MB).', 'merchboard' );
		case 'not_png':
			return __( 'The image must be a PNG.', 'merchboard' );
		case 'size':
			return __(
				'The image must be between 16 and 2000 pixels wide and high.',
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
 * Reads a chosen file as a data URL.
 *
 * @param file The file.
 */
function readFile( file: File ): Promise< string > {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = () => resolve( String( reader.result ) );
		reader.onerror = () => reject( reader.error );
		reader.readAsDataURL( file );
	} );
}

/**
 * Community Features → Merch ad (ADR-113): the creator designs an ad with
 * products without background that shows in the stream every few minutes,
 * adds it to OBS as a browser source and can show it now.
 */
export function MerchAdSection() {
	const { state, retry } = useApi( getMerchAd );
	const [ current, setCurrent ] = useState< MerchAdState | null >( null );
	const [ values, setValues ] = useState< MerchAdValues | null >( null );
	const [ products, setProducts ] = useState< ProductChoice[] | null >(
		null
	);
	const [ productsFailed, setProductsFailed ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const [ failed, setFailed ] = useState< readonly number[] >( [] );
	const [ frame, setFrame ] = useState( 0 );
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

	const title = __( 'Merch ad', 'merchboard' );

	if (
		state.status === 'loading' ||
		( state.status === 'success' && values === null )
	) {
		return (
			<Card title={ title }>
				<Skeleton
					label={ __( 'Loading the merch ad…', 'merchboard' ) }
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
	const full = chosen.length >= current.limits.maxProducts;

	const apply = ( result: ApiResult< MerchAdState >, message?: string ) => {
		if ( result.ok ) {
			setCurrent( result.data );
			setFrame( ( value ) => value + 1 );

			if ( message !== undefined ) {
				setToast( message );
			}

			return result.data;
		}

		setError( result.error );

		return null;
	};

	// Removes the background of every product that has no background-free image yet.
	const autoCutouts = async ( list: readonly MerchAdProduct[] ) => {
		const missed: number[] = [];

		for ( const product of list ) {
			if ( product.cutout !== null || product.image === null ) {
				continue;
			}

			setBusy( `cutout-${ product.id }` );
			const png = await cutoutFrom( product.image );

			if ( png === null ) {
				missed.push( product.id );
				continue;
			}

			apply( await addCutout( product.id, png ) );
		}

		setBusy( null );
		setFailed( missed );
	};

	const toggle = ( id: number, on: boolean ) =>
		setValues( {
			...values,
			productIds: on
				? [ ...chosen.filter( ( item ) => item !== id ), id ]
				: chosen.filter( ( item ) => item !== id ),
		} );

	const move = ( index: number, by: -1 | 1 ) => {
		const moved = chosen[ index ];
		const other = chosen[ index + by ];

		if ( moved === undefined || other === undefined ) {
			return;
		}

		const next = [ ...chosen ];
		next[ index ] = other;
		next[ index + by ] = moved;
		setValues( { ...values, productIds: next } );
	};

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( 'save' );
		setError( null );
		const saved = apply(
			await saveMerchAd( values ),
			__( 'Merch ad saved.', 'merchboard' )
		);
		setBusy( null );

		if ( saved !== null ) {
			setValues( saved );
			await autoCutouts( saved.products );
		}
	};

	const upload = async (
		product: MerchAdProduct,
		event: ChangeEvent< HTMLInputElement >
	) => {
		const file = event.target.files?.[ 0 ];
		event.target.value = '';

		if ( file === undefined ) {
			return;
		}

		setBusy( `cutout-${ product.id }` );
		setError( null );
		apply(
			await addCutout( product.id, await readFile( file ) ),
			__( 'The image was saved.', 'merchboard' )
		);
		setBusy( null );
		setFailed( failed.filter( ( id ) => id !== product.id ) );
	};

	const number = ( field: 'every' | 'seconds', text: string ) =>
		setValues( { ...values, [ field ]: Number.parseInt( text, 10 ) || 0 } );

	return (
		<>
			<Card title={ title }>
				<p className="merchboard-field__help">
					{ __(
						'Show your products in your stream every few minutes. Products are shown without background, with an optional pill such as "Type !merch in the chat". Add the ad to OBS as its own browser source.',
						'merchboard'
					) }
				</p>
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
						htmlFor="merchboard-merch-ad-enabled"
					>
						<input
							id="merchboard-merch-ad-enabled"
							type="checkbox"
							checked={ values.enabled }
							onChange={ ( event ) =>
								setValues( {
									...values,
									enabled: event.target.checked,
								} )
							}
						/>{ ' ' }
						{ __( 'Show the ad in my stream', 'merchboard' ) }
					</label>

					<fieldset className="merchboard-community-campaign">
						<legend className="merchboard-field__label">
							{ __( 'Design', 'merchboard' ) }
						</legend>
						<div className="merchboard-merch-ad-designs">
							{ DESIGNS.map( ( design ) => {
								const [ name, description ] =
									designText( design );

								return (
									<label
										key={ design }
										className={ `merchboard-merch-ad-design${
											values.design === design
												? ' is-selected'
												: ''
										}` }
										htmlFor={ `merchboard-merch-ad-design-${ design }` }
									>
										<input
											id={ `merchboard-merch-ad-design-${ design }` }
											type="radio"
											name="merchboard-merch-ad-design"
											checked={ values.design === design }
											onChange={ () =>
												setValues( {
													...values,
													design,
												} )
											}
										/>
										<span
											className={ `merchboard-merch-ad-thumb merchboard-merch-ad-thumb--${ design }` }
											aria-hidden="true"
										>
											<i />
											<i />
											<i />
										</span>
										<strong>{ name }</strong>
										<span>{ description }</span>
									</label>
								);
							} ) }
						</div>
					</fieldset>

					<div className="merchboard-merch-ad-grid">
						<div className="merchboard-field">
							<label
								className="merchboard-field__label"
								htmlFor="merchboard-merch-ad-note"
							>
								{ __(
									'Text in the pill (optional)',
									'merchboard'
								) }
							</label>
							<input
								id="merchboard-merch-ad-note"
								className="merchboard-input"
								type="text"
								value={ values.note }
								maxLength={ current.limits.maxNote }
								placeholder={ __(
									'Type !merch in the chat',
									'merchboard'
								) }
								onChange={ ( event ) =>
									setValues( {
										...values,
										note: event.target.value,
									} )
								}
							/>
							{ errors.note !== undefined && (
								<p className="merchboard-field__error">
									{ fieldError( errors.note ) }
								</p>
							) }
						</div>
						<div className="merchboard-field">
							<label
								className="merchboard-field__label"
								htmlFor="merchboard-merch-ad-every"
							>
								{ __( 'Show every … minutes', 'merchboard' ) }
							</label>
							<input
								id="merchboard-merch-ad-every"
								className="merchboard-input"
								type="number"
								min={ current.limits.every[ 0 ] }
								max={ current.limits.every[ 1 ] }
								value={ values.every }
								onChange={ ( event ) =>
									number( 'every', event.target.value )
								}
							/>
							{ errors.every !== undefined && (
								<p className="merchboard-field__error">
									{ sprintf(
										/* translators: 1: least, 2: most. */
										__(
											'Choose between %1$d and %2$d.',
											'merchboard'
										),
										current.limits.every[ 0 ],
										current.limits.every[ 1 ]
									) }
								</p>
							) }
						</div>
						<div className="merchboard-field">
							<label
								className="merchboard-field__label"
								htmlFor="merchboard-merch-ad-seconds"
							>
								{ __( 'For … seconds', 'merchboard' ) }
							</label>
							<input
								id="merchboard-merch-ad-seconds"
								className="merchboard-input"
								type="number"
								min={ current.limits.seconds[ 0 ] }
								max={ current.limits.seconds[ 1 ] }
								value={ values.seconds }
								onChange={ ( event ) =>
									number( 'seconds', event.target.value )
								}
							/>
							{ errors.seconds !== undefined && (
								<p className="merchboard-field__error">
									{ sprintf(
										/* translators: 1: least, 2: most. */
										__(
											'Choose between %1$d and %2$d.',
											'merchboard'
										),
										current.limits.seconds[ 0 ],
										current.limits.seconds[ 1 ]
									) }
								</p>
							) }
						</div>
						<div className="merchboard-field">
							<label
								className="merchboard-field__label"
								htmlFor="merchboard-merch-ad-enter"
							>
								{ __( 'Animation in', 'merchboard' ) }
							</label>
							<select
								id="merchboard-merch-ad-enter"
								className="merchboard-input"
								value={ values.enter }
								onChange={ ( event ) =>
									setValues( {
										...values,
										enter: event.target
											.value as MerchAdAnimation,
									} )
								}
							>
								{ ANIMATIONS.map( ( animation ) => (
									<option
										key={ animation }
										value={ animation }
									>
										{ animationText( animation ) }
									</option>
								) ) }
							</select>
						</div>
						<div className="merchboard-field">
							<label
								className="merchboard-field__label"
								htmlFor="merchboard-merch-ad-leave"
							>
								{ __( 'Animation out', 'merchboard' ) }
							</label>
							<select
								id="merchboard-merch-ad-leave"
								className="merchboard-input"
								value={ values.leave }
								onChange={ ( event ) =>
									setValues( {
										...values,
										leave: event.target
											.value as MerchAdAnimation,
									} )
								}
							>
								{ ANIMATIONS.map( ( animation ) => (
									<option
										key={ animation }
										value={ animation }
									>
										{ animationText( animation ) }
									</option>
								) ) }
							</select>
						</div>
						<div className="merchboard-field">
							<label
								className="merchboard-field__label"
								htmlFor="merchboard-merch-ad-theme"
							>
								{ __( 'Card', 'merchboard' ) }
							</label>
							<select
								id="merchboard-merch-ad-theme"
								className="merchboard-input"
								value={ values.theme }
								onChange={ ( event ) =>
									setValues( {
										...values,
										theme:
											event.target.value === 'light'
												? 'light'
												: 'dark',
									} )
								}
							>
								<option value="dark">
									{ __( 'Dark', 'merchboard' ) }
								</option>
								<option value="light">
									{ __( 'Light', 'merchboard' ) }
								</option>
							</select>
						</div>
						<div className="merchboard-field">
							<label
								className="merchboard-field__label"
								htmlFor="merchboard-merch-ad-accent"
							>
								{ __( 'Accent colour', 'merchboard' ) }
							</label>
							<input
								id="merchboard-merch-ad-accent"
								className="merchboard-input merchboard-merch-ad-colour"
								type="color"
								value={ values.accent }
								onChange={ ( event ) =>
									setValues( {
										...values,
										accent: event.target.value,
									} )
								}
							/>
						</div>
					</div>

					<fieldset className="merchboard-community-campaign">
						<legend className="merchboard-field__label">
							{ __( 'Products in the ad', 'merchboard' ) }
						</legend>
						<p className="merchboard-field__help">
							{ __(
								'Choose up to 12 products in the order you want. Choose none, and the ad shows your newest products.',
								'merchboard'
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
											disabled={
												index === chosen.length - 1
											}
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
											onClick={ () =>
												toggle( id, false )
											}
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
														htmlFor={ `merchboard-merch-ad-product-${ product.id }` }
													>
														<input
															id={ `merchboard-merch-ad-product-${ product.id }` }
															type="checkbox"
															checked={ checked }
															disabled={
																! checked &&
																full
															}
															onChange={ (
																event
															) =>
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
						<Button type="submit" isBusy={ busy === 'save' }>
							{ __( 'Save', 'merchboard' ) }
						</Button>
					</div>
				</form>
			</Card>

			<Card title={ __( 'Images without background', 'merchboard' ) }>
				<p className="merchboard-field__help">
					{ __(
						'When you save, the background is removed automatically from each product image with a plain background. If the result is not good enough, or the image has a pattern behind the product, upload your own PNG without background.',
						'merchboard'
					) }
				</p>
				{ errors.png !== undefined && (
					<Alert tone="danger">{ fieldError( errors.png ) }</Alert>
				) }
				{ current.products.length === 0 ? (
					<p className="merchboard-field__help">
						{ __(
							'You have no products to show yet.',
							'merchboard'
						) }
					</p>
				) : (
					<ul className="merchboard-merch-ad-cutouts">
						{ current.products.map( ( product ) => (
							<li key={ product.id }>
								<span className="merchboard-merch-ad-cutouts__img">
									{ ( product.cutout ?? product.image ) !==
										null && (
										<img
											src={
												product.cutout ??
												product.image ??
												''
											}
											alt=""
											loading="lazy"
										/>
									) }
								</span>
								<span className="merchboard-merch-ad-cutouts__text">
									<strong>{ product.name }</strong>
									<span>
										{ product.cutout !== null &&
											__(
												'Without background',
												'merchboard'
											) }
										{ product.cutout === null &&
											failed.includes( product.id ) &&
											__(
												'The background could not be removed automatically. Upload a PNG without background.',
												'merchboard'
											) }
										{ product.cutout === null &&
											! failed.includes( product.id ) &&
											__(
												'With the shop image',
												'merchboard'
											) }
									</span>
								</span>
								<span className="merchboard-merch-ad-cutouts__actions">
									{ product.cutout === null &&
										product.image !== null && (
											<Button
												variant="secondary"
												size="sm"
												isBusy={
													busy ===
													`cutout-${ product.id }`
												}
												onClick={ () =>
													void autoCutouts( [
														product,
													] )
												}
											>
												{ __(
													'Remove background',
													'merchboard'
												) }
											</Button>
										) }
									<label
										className="merchboard-button merchboard-button--secondary merchboard-button--sm"
										htmlFor={ `merchboard-merch-ad-upload-${ product.id }` }
									>
										{ __( 'Upload PNG', 'merchboard' ) }
										<input
											id={ `merchboard-merch-ad-upload-${ product.id }` }
											type="file"
											accept="image/png"
											className="merchboard-merch-ad-file"
											onChange={ ( event ) =>
												void upload( product, event )
											}
										/>
									</label>
									{ product.cutout !== null && (
										<Button
											variant="ghost"
											size="sm"
											onClick={ () =>
												void removeCutout(
													product.id
												).then( ( result ) =>
													apply( result )
												)
											}
										>
											{ __(
												'Use the shop image',
												'merchboard'
											) }
										</Button>
									) }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</Card>

			<Card title={ __( 'In OBS', 'merchboard' ) }>
				{ current.url === null ? (
					<p className="merchboard-field__help">
						{ __(
							'Save the ad to get your link for OBS.',
							'merchboard'
						) }
					</p>
				) : (
					<>
						<p className="merchboard-field__help">
							{ __(
								'Add a browser source in OBS with this link, e.g. 1920 × 1080, and place and scale it where you want the ad. Keep the link to yourself.',
								'merchboard'
							) }
						</p>
						<div className="merchboard-merch-ad-link">
							<input
								className="merchboard-input"
								readOnly
								value={ current.url }
								aria-label={ __(
									'Link for OBS',
									'merchboard'
								) }
								onFocus={ ( event ) => event.target.select() }
							/>
							<Button
								variant="secondary"
								onClick={ () =>
									void navigator.clipboard
										?.writeText( current.url ?? '' )
										.then( () =>
											setToast(
												__(
													'The link was copied.',
													'merchboard'
												)
											)
										)
								}
							>
								{ __( 'Copy', 'merchboard' ) }
							</Button>
							<Button
								variant="secondary"
								isBusy={ busy === 'show' }
								onClick={ async () => {
									setBusy( 'show' );
									apply(
										await showMerchAd(),
										__(
											'The ad shows in your stream within 10 seconds.',
											'merchboard'
										)
									);
									setBusy( null );
								} }
							>
								{ __( 'Show now', 'merchboard' ) }
							</Button>
							<Button
								variant="ghost"
								isBusy={ busy === 'regenerate' }
								onClick={ async () => {
									setBusy( 'regenerate' );
									apply(
										await regenerateMerchAd(),
										__(
											'You have a new link. Update the browser source in OBS; the old link no longer works.',
											'merchboard'
										)
									);
									setBusy( null );
								} }
							>
								{ __( 'New link', 'merchboard' ) }
							</Button>
						</div>
						{ current.previewUrl !== null && (
							<>
								<h3 className="merchboard-field__label">
									{ __( 'Preview', 'merchboard' ) }
								</h3>
								<p className="merchboard-field__help">
									{ __(
										'The saved ad, shown again and again. Save to see your changes.',
										'merchboard'
									) }
								</p>
								<div className="merchboard-merch-ad-preview">
									<iframe
										key={ frame }
										src={ current.previewUrl }
										title={ __(
											'Preview of the merch ad',
											'merchboard'
										) }
									/>
								</div>
							</>
						) }
					</>
				) }
			</Card>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</>
	);
}
