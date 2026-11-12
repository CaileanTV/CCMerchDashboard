import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import type { ApiError } from '../../api/client';
import {
	cancelGiveaway,
	createGiveaway,
	getGiveaways,
	startGiveaway,
} from '../../api/giveaways';
import type {
	GiveawayFunding,
	GiveawayMode,
	GiveawayRow,
	GiveawaysState,
} from '../../api/giveaways';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { Table } from '../../components/Table';
import { Toast } from '../../components/Toast';
import { useConfig } from '../../app/config-context';
import { useApi } from '../../hooks/useApi';
import { moneyText } from '../sales/format';
import { TwitchConnectionCard } from './TwitchConnectionCard';

/**
 * A UTC time of the API in the site's language and the viewer's time zone.
 *
 * @param utc    `YYYY-MM-DD HH:MM:SS` in UTC.
 * @param locale WordPress locale, e.g. da_DK.
 */
function timeText( utc: string, locale: string ): string {
	try {
		return new Intl.DateTimeFormat( locale.replace( '_', '-' ), {
			dateStyle: 'medium',
			timeStyle: 'short',
		} ).format( new Date( `${ utc.replace( ' ', 'T' ) }Z` ) );
	} catch {
		return utc.slice( 0, 16 );
	}
}

/**
 * Where a giveaway stands, in words.
 *
 * @param row    Giveaway.
 * @param locale WordPress locale.
 */
export function giveawayStatusText( row: GiveawayRow, locale: string ): string {
	switch ( row.status ) {
		case 'awaiting_payment':
			return __( 'Awaiting payment in the shop', 'merchboard' );
		case 'used':
			return __( 'The winner has used the code', 'merchboard' );
		case 'expired':
			return __( 'Ran out unused', 'merchboard' );
		case 'cancelled':
			return __( 'Cancelled', 'merchboard' );
	}

	const gift = row.gift;

	if ( gift === null ) {
		return __( 'Ready', 'merchboard' );
	}

	if ( gift.link !== null ) {
		return gift.deadline === null
			? __( 'Link ready to pass on', 'merchboard' )
			: sprintf(
					/* translators: %s: date and time. */
					__( 'Link ready to pass on (until %s)', 'merchboard' ),
					timeText( gift.deadline, locale )
				);
	}

	switch ( gift.status ) {
		case 'waiting':
			return __(
				'Waiting for you to start it in the chat',
				'merchboard'
			);
		case 'entering':
			return __( 'The chat is typing !enter now', 'merchboard' );
		case 'won':
			return sprintf(
				/* translators: %s: the winner's Twitch name. */
				__( 'Won by %s, waiting for the winner', 'merchboard' ),
				gift.winner ?? '–'
			);
		case 'claimed':
			return __( 'Redeemed; the code is not used yet', 'merchboard' );
		default:
			return __( 'Cancelled', 'merchboard' );
	}
}

/**
 * The text of a refused action.
 *
 * @param error The API's error.
 */
function refusedText( error: ApiError ): string | null {
	switch ( error.code ) {
		case 'merchboard_giveaway_balance':
			return __(
				'Your available balance does not cover the giveaway. Choose to pay in the shop instead.',
				'merchboard'
			);
		case 'merchboard_giveaway_limit':
			return __(
				'You have the most open giveaways allowed. Finish or cancel one first.',
				'merchboard'
			);
		case 'merchboard_giveaway_product':
			return __( 'This product cannot be given away.', 'merchboard' );
		case 'merchboard_giveaway_not_cancellable':
			return __(
				'The giveaway cannot be cancelled any more: its gift has been drawn or redeemed, or it was paid in the shop.',
				'merchboard'
			);
		default:
			return null;
	}
}

/**
 * The answer to "Start in the chat".
 *
 * @param started `started`, `not_live`, `busy` or `not_waiting`.
 */
function startedText( started: string | undefined ): string {
	switch ( started ) {
		case 'started':
			return __(
				'The giveaway is announced in your chat now.',
				'merchboard'
			);
		case 'not_live':
			return __(
				'The giveaway can only start while you are live on Twitch.',
				'merchboard'
			);
		case 'busy':
			return __(
				'Another draw is running in your chat. Start the giveaway when it is over.',
				'merchboard'
			);
		default:
			return __( 'The giveaway cannot start now.', 'merchboard' );
	}
}

/**
 * Community Features → Giveaways (phase 47, ADR-110): the creator gives one
 * of the own products away to a viewer, drawn by the bot in the Twitch chat
 * or as a link the creator passes on from an own giveaway. The creator pays
 * the base price plus 50 kr. shipping: from the balance when the code is
 * used (set aside until then), or at once in the shop. Never mixed up with
 * Twitch gifting, where viewers buy gifts.
 */
export function GiveawaysSection() {
	const { state, retry } = useApi( getGiveaways );
	const locale = useConfig().locale;
	const [ current, setCurrent ] = useState< GiveawaysState | null >( null );
	const [ productId, setProductId ] = useState< number >( 0 );
	const [ mode, setMode ] = useState< GiveawayMode >( 'manual' );
	const [ funding, setFunding ] = useState< GiveawayFunding >( 'balance' );
	const [ seconds, setSeconds ] = useState< number >( 60 );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setCurrent( state.data );
			setSeconds( state.data.windowDefault );
			setProductId( state.data.products[ 0 ]?.id ?? 0 );
		}
	}, [ state ] );

	// Back from the checkout tab: the list shows what was paid meanwhile.
	useEffect( () => {
		let active = true;
		const refresh = () => {
			if ( document.visibilityState !== 'visible' ) {
				return;
			}

			void getGiveaways().then( ( result ) => {
				if ( active && result.ok ) {
					setCurrent( result.data );
				}
			} );
		};

		document.addEventListener( 'visibilitychange', refresh );

		return () => {
			active = false;
			document.removeEventListener( 'visibilitychange', refresh );
		};
	}, [] );

	const title = __( 'Giveaways', 'merchboard' );

	if (
		state.status === 'loading' ||
		( state.status === 'success' && current === null )
	) {
		return (
			<Card title={ title }>
				<Skeleton
					label={ __( 'Loading giveaways…', 'merchboard' ) }
					lines={ 3 }
				/>
			</Card>
		);
	}

	if ( state.status === 'error' || current === null ) {
		return (
			<Card title={ title }>
				{ state.status === 'error' && (
					<ApiErrorState error={ state.error } onRetry={ retry } />
				) }
			</Card>
		);
	}

	const product = current.products.find( ( item ) => item.id === productId );
	const covers =
		product !== undefined &&
		current.available !== null &&
		Number( current.available ) >= Number( product.cost );
	const errors = error?.fields ?? {};
	const refused = error === null ? null : refusedText( error );

	const done = ( result: GiveawaysState, message: string ) => {
		setCurrent( result );
		setToast( message );
	};

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		const shop = ! covers || funding === 'shop';
		// The checkout opens in a new tab, so the creator keeps this page. The tab is
		// opened during the click (a browser blocks one opened after the request).
		const tab = shop ? window.open( '', '_blank' ) : null;

		if ( tab ) {
			tab.opener = null;
		}

		setBusy( 'create' );
		setError( null );
		const result = await createGiveaway( {
			productId,
			mode,
			funding: shop ? 'shop' : 'balance',
			window: seconds,
		} );
		setBusy( null );

		if ( ! result.ok ) {
			tab?.close();
			setError( result.error );

			return;
		}

		const created = result.data.giveaways.find(
			( row ) => row.id === result.data.created
		);

		if ( created?.payUrl ) {
			if ( tab ) {
				tab.location.href = created.payUrl;
			}

			done(
				result.data,
				tab
					? __(
							'The checkout opened in a new tab. Come back here when you have paid: the giveaway is ready when the order is paid.',
							'merchboard'
						)
					: __(
							'The giveaway is created. Press "Pay in the shop" to pay it.',
							'merchboard'
						)
			);

			return;
		}

		tab?.close();

		done(
			result.data,
			mode === 'manual'
				? __(
						'The giveaway is ready. Copy the link and pass it on to your winner.',
						'merchboard'
					)
				: __(
						'The giveaway is ready. Start it in the chat while you are live.',
						'merchboard'
					)
		);
	};

	const act = async ( row: GiveawayRow, action: 'start' | 'cancel' ) => {
		setBusy( `${ action }-${ row.id }` );
		setError( null );
		const result =
			action === 'start'
				? await startGiveaway( row.id )
				: await cancelGiveaway( row.id );
		setBusy( null );

		if ( ! result.ok ) {
			setError( result.error );

			return;
		}

		done(
			result.data,
			action === 'start'
				? startedText( result.data.started )
				: __(
						'The giveaway is cancelled. Nothing is charged.',
						'merchboard'
					)
		);
	};

	const copy = async ( link: string ) => {
		try {
			await navigator.clipboard.writeText( link );
			setToast( __( 'The link was copied.', 'merchboard' ) );
		} catch {
			setToast(
				__(
					'The link could not be copied. Select it and copy it by hand.',
					'merchboard'
				)
			);
		}
	};

	const cancellable = ( row: GiveawayRow ) =>
		row.status === 'awaiting_payment' ||
		( row.status === 'ready' &&
			row.funding === 'balance' &&
			( row.gift === null ||
				row.gift.status === 'waiting' ||
				row.gift.link !== null ) );

	const columns = [
		{
			key: 'created',
			header: __( 'Created', 'merchboard' ),
			render: ( row: GiveawayRow ) => timeText( row.createdAt, locale ),
		},
		{
			key: 'product',
			header: __( 'Product', 'merchboard' ),
			render: ( row: GiveawayRow ) =>
				row.product === '' ? '–' : row.product,
		},
		{
			key: 'mode',
			header: __( 'How', 'merchboard' ),
			render: ( row: GiveawayRow ) =>
				row.mode === 'bot'
					? __( 'Drawn in the chat', 'merchboard' )
					: __( 'Your own giveaway (link)', 'merchboard' ),
		},
		{
			key: 'cost',
			header: __( 'Price', 'merchboard' ),
			render: ( row: GiveawayRow ) =>
				sprintf(
					/* translators: 1: amount, 2: how it is paid. */
					__( '%1$s (%2$s)', 'merchboard' ),
					moneyText( row.cost, row.currency, locale ),
					row.funding === 'balance'
						? __( 'from the balance', 'merchboard' )
						: __( 'paid in the shop', 'merchboard' )
				),
		},
		{
			key: 'status',
			header: __( 'Status', 'merchboard' ),
			render: ( row: GiveawayRow ) => (
				<>
					{ giveawayStatusText( row, locale ) }
					{ row.gift?.link && (
						<div className="merchboard-giveaway__link">
							<input
								className="merchboard-input"
								readOnly
								value={ row.gift.link }
								aria-label={ __(
									'Giveaway link',
									'merchboard'
								) }
								onFocus={ ( event ) => event.target.select() }
							/>
							<Button
								type="button"
								variant="secondary"
								onClick={ () =>
									void copy( row.gift?.link ?? '' )
								}
							>
								{ __( 'Copy link', 'merchboard' ) }
							</Button>
						</div>
					) }
					{ actions( row ) }
				</>
			),
		},
	];

	const channel = current.channel;

	// The actions sit under the status, so the table stays narrow.
	function actions( row: GiveawayRow ) {
		return (
			<div className="merchboard-community-form__actions merchboard-giveaway__actions">
				{ row.payUrl !== null && (
					// A new tab, so the creator keeps this page.
					<a
						className="merchboard-button merchboard-button--primary merchboard-button--md"
						href={ row.payUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Pay in the shop', 'merchboard' ) }
					</a>
				) }
				{ row.status === 'ready' &&
					row.mode === 'bot' &&
					row.gift?.status === 'waiting' && (
						<Button
							type="button"
							isBusy={ busy === `start-${ row.id }` }
							disabled={ channel === null }
							onClick={ () => void act( row, 'start' ) }
						>
							{ __( 'Start in the chat', 'merchboard' ) }
						</Button>
					) }
				{ cancellable( row ) && (
					<Button
						type="button"
						variant="secondary"
						isBusy={ busy === `cancel-${ row.id }` }
						onClick={ () => void act( row, 'cancel' ) }
					>
						{ __( 'Cancel', 'merchboard' ) }
					</Button>
				) }
			</div>
		);
	}

	return (
		<>
			<Card title={ title }>
				<p className="merchboard-field__help">
					{ __(
						'Give one of your own products away to a viewer. The winner chooses size and colour in the shop, and the code covers the product and up to 50 kr. of the shipping. You pay the base price and 50 kr. shipping, so you neither earn on the giveaway nor pay your own profit.',
						'merchboard'
					) }
				</p>
				<p className="merchboard-field__help">
					{ __(
						'This is not Twitch gifting: there, viewers buy gifts for your chat. A giveaway is your own gift to your viewers.',
						'merchboard'
					) }
				</p>
				{ error !== null &&
					Object.keys( errors ).length === 0 &&
					( refused === null ? (
						<ApiErrorState error={ error } />
					) : (
						<Alert tone="danger">{ refused }</Alert>
					) ) }
				{ current.products.length === 0 ? (
					<Alert tone="info">
						{ __(
							'You have no products in the shop that can be given away yet.',
							'merchboard'
						) }
					</Alert>
				) : (
					<form
						className="merchboard-community-form"
						noValidate
						onSubmit={ ( event ) => void submit( event ) }
					>
						<div className="merchboard-field">
							<label
								className="merchboard-field__label"
								htmlFor="merchboard-giveaway-product"
							>
								{ __( 'Product', 'merchboard' ) }
							</label>
							<select
								id="merchboard-giveaway-product"
								className="merchboard-input"
								value={ productId }
								onChange={ ( event ) =>
									setProductId( Number( event.target.value ) )
								}
							>
								{ current.products.map( ( item ) => (
									<option key={ item.id } value={ item.id }>
										{ sprintf(
											/* translators: 1: product name, 2: price. */
											__( '%1$s – %2$s', 'merchboard' ),
											item.name,
											moneyText(
												item.cost,
												item.currency,
												locale
											)
										) }
									</option>
								) ) }
							</select>
							<p className="merchboard-field__help">
								{ __(
									'The price is the base price of the cheapest variant plus 50 kr. shipping.',
									'merchboard'
								) }
							</p>
						</div>
						<fieldset className="merchboard-field">
							<legend className="merchboard-field__label">
								{ __(
									'How the winner is found',
									'merchboard'
								) }
							</legend>
							<label htmlFor="merchboard-giveaway-manual">
								<input
									id="merchboard-giveaway-manual"
									type="radio"
									name="merchboard-giveaway-mode"
									checked={ mode === 'manual' }
									onChange={ () => setMode( 'manual' ) }
								/>{ ' ' }
								{ __(
									'My own giveaway system: I get a link',
									'merchboard'
								) }
							</label>
							<p className="merchboard-field__help">
								{ __(
									'You find the winner yourself and pass the link on. Whoever has the link presses "Redeem" – no Twitch login – and chooses size and colour. The link can be redeemed for 30 days.',
									'merchboard'
								) }
							</p>
							<label htmlFor="merchboard-giveaway-bot">
								<input
									id="merchboard-giveaway-bot"
									type="radio"
									name="merchboard-giveaway-mode"
									checked={ mode === 'bot' }
									onChange={ () => setMode( 'bot' ) }
								/>{ ' ' }
								{ __(
									'Drawn by our bot in my Twitch chat',
									'merchboard'
								) }
							</label>
							<p className="merchboard-field__help">
								{ __(
									'You start it here while you are live. The bot announces it, everyone who types !enter takes part, and the winner logs in with Twitch to get it. If nobody claims it after three draws, you get the link instead.',
									'merchboard'
								) }
							</p>
						</fieldset>
						{ mode === 'bot' && (
							<>
								<TwitchConnectionCard
									channel={ current.channel }
									twitch={ current.twitch }
									from="community/giveaways"
									status={
										current.live === true
											? __(
													'You are live now.',
													'merchboard'
												)
											: __(
													'You are not live.',
													'merchboard'
												)
									}
								/>
								<Alert tone="info">
									{ __(
										'The bot sends the winner a link in the chat. If your channel removes links from the chat, make our bot a moderator or add it to the exceptions.',
										'merchboard'
									) }
								</Alert>
								<div className="merchboard-field">
									<label
										className="merchboard-field__label"
										htmlFor="merchboard-giveaway-window"
									>
										{ __(
											'Seconds the chat can type !enter',
											'merchboard'
										) }
									</label>
									<input
										id="merchboard-giveaway-window"
										className="merchboard-input"
										type="number"
										min={ current.windowMin }
										max={ current.windowMax }
										step={ 1 }
										value={ seconds }
										onChange={ ( event ) =>
											setSeconds(
												Math.round(
													Number(
														event.target.value
													) || 0
												)
											)
										}
									/>
									<p className="merchboard-field__help">
										{ sprintf(
											/* translators: 1: fewest seconds, 2: most seconds. */
											__(
												'Choose between %1$d and %2$d seconds.',
												'merchboard'
											),
											current.windowMin,
											current.windowMax
										) }
									</p>
									{ errors.window !== undefined && (
										<p className="merchboard-field__error">
											{ sprintf(
												/* translators: 1: fewest seconds, 2: most seconds. */
												__(
													'Choose between %1$d and %2$d seconds.',
													'merchboard'
												),
												current.windowMin,
												current.windowMax
											) }
										</p>
									) }
								</div>
							</>
						) }
						<fieldset className="merchboard-field">
							<legend className="merchboard-field__label">
								{ __( 'Payment', 'merchboard' ) }
							</legend>
							<label htmlFor="merchboard-giveaway-balance">
								<input
									id="merchboard-giveaway-balance"
									type="radio"
									name="merchboard-giveaway-funding"
									checked={ covers && funding === 'balance' }
									disabled={ ! covers }
									onChange={ () => setFunding( 'balance' ) }
								/>{ ' ' }
								{ __(
									'From my balance when the code is used',
									'merchboard'
								) }
							</label>
							<p className="merchboard-field__help">
								{ current.available === null
									? __(
											'Your balance is not known right now, so the giveaway is paid in the shop.',
											'merchboard'
										)
									: sprintf(
											/* translators: %s: amount. */
											__(
												'Available: %s. The price is set aside now and only charged when the winner uses the code; if nobody uses it, nothing is charged.',
												'merchboard'
											),
											moneyText(
												current.available,
												product?.currency ?? 'DKK',
												locale
											)
										) }
							</p>
							<label htmlFor="merchboard-giveaway-shop">
								<input
									id="merchboard-giveaway-shop"
									type="radio"
									name="merchboard-giveaway-funding"
									checked={ ! covers || funding === 'shop' }
									onChange={ () => setFunding( 'shop' ) }
								/>{ ' ' }
								{ __( 'Pay now in the shop', 'merchboard' ) }
							</label>
							<p className="merchboard-field__help">
								{ __(
									'The checkout opens in a new tab, where you pay only the base price and 50 kr. shipping. The giveaway is ready when the order is paid.',
									'merchboard'
								) }
							</p>
						</fieldset>
						<div className="merchboard-community-form__actions">
							<Button
								type="submit"
								isBusy={ busy === 'create' }
								disabled={ product === undefined }
							>
								{ ! covers || funding === 'shop'
									? __(
											'Create and pay in the shop',
											'merchboard'
										)
									: __( 'Create giveaway', 'merchboard' ) }
							</Button>
						</div>
					</form>
				) }
			</Card>
			<Card title={ __( 'Your giveaways', 'merchboard' ) }>
				<Table
					caption={ __( 'Your latest giveaways', 'merchboard' ) }
					captionHidden
					columns={ columns }
					rows={ current.giveaways }
					rowKey={ ( row ) => row.id }
					empty={
						<p className="merchboard-field__help">
							{ __( 'No giveaways yet.', 'merchboard' ) }
						</p>
					}
				/>
			</Card>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</>
	);
}
