import { useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	createWebhook,
	deleteWebhook,
	newWebhookSecret,
	testWebhook,
	updateWebhook,
} from '../../api/alertApi';
import type {
	AlertApiState,
	WebhookDeliveryItem,
	WebhookFormat,
	WebhookItem,
} from '../../api/alertApi';
import type { ApiError, ApiResult } from '../../api/client';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';

/** Longest Discord message template (AlertWebhooks::TEMPLATE_MAX). */
export const WEBHOOK_TEMPLATE_MAX = 500;

/** An example event, as the guide shows it. */
const EXAMPLE_EVENT = `{
  "id": "evt_9f2c41d0b7a84e1c9a55e0f3c2b1d6a7",
  "type": "purchase",
  "created_at": "2026-10-23T18:00:00+00:00",
  "order_ref": "3f9a1c0b7d2e4a51",
  "username": "TestFan",
  "display_name": "TestFan",
  "total": "548.00",
  "currency": "DKK",
  "item_count": 3,
  "items": [
    { "product": "Hoodie", "quantity": 2, "category": "Merch",
      "variation": "Size: L", "price": "199.00", "line_total": "398.00" },
    { "product": "Mug", "quantity": 1, "category": "Merch",
      "variation": "", "price": "150.00", "line_total": "150.00" }
  ]
}`;

/** How a receiver checks the signature (Node.js). */
const VERIFY_EXAMPLE = `const crypto = require( 'node:crypto' );

function verify( secret, headers, rawBody ) {
  const timestamp = headers[ 'x-merchboard-timestamp' ];
  const expected = 'sha256=' + crypto.createHmac( 'sha256', secret )
    .update( timestamp + '.' + rawBody ).digest( 'hex' );
  const given = String( headers[ 'x-merchboard-signature' ] || '' );
  const fresh = Math.abs( Date.now() / 1000 - Number( timestamp ) ) < 300;

  return fresh && given.length === expected.length &&
    crypto.timingSafeEqual( Buffer.from( given ), Buffer.from( expected ) );
}`;

/**
 * The message of a refused change, from the server's error code.
 *
 * @param error       Error.
 * @param maxWebhooks Most webhooks.
 */
function refusal( error: ApiError, maxWebhooks: number ): string | null {
	switch ( error.code ) {
		case 'merchboard_alerts_api_disabled':
			return __(
				'The alert API is switched off. Ask the shop to switch it on.',
				'merchboard'
			);
		case 'merchboard_alerts_api_no_access':
			return __(
				'The shop has not given you access to this. Ask the shop.',
				'merchboard'
			);
		case 'merchboard_alerts_webhook_limit':
			return sprintf(
				/* translators: %d: most webhooks. */
				__(
					'You can have at most %d webhooks. Delete one first.',
					'merchboard'
				),
				maxWebhooks
			);
		case 'merchboard_alerts_webhook_unavailable':
			return __(
				'Webhooks cannot be stored safely on this site. Ask the shop.',
				'merchboard'
			);
		case 'merchboard_invalid_request':
			switch ( Object.keys( error.fields )[ 0 ] ) {
				case 'url':
					return __(
						'Enter a public https address. A Discord webhook must be a Discord webhook address.',
						'merchboard'
					);
				case 'secret':
					return __(
						'The secret must be 16–200 characters without spaces.',
						'merchboard'
					);
				case 'template':
					return __(
						'The message can be at most 500 characters.',
						'merchboard'
					);
				default:
					return __(
						'Give the webhook a name of at most 60 characters.',
						'merchboard'
					);
			}
		default:
			return null;
	}
}

/**
 * A delivery's outcome in words.
 *
 * @param delivery Delivery.
 */
export function deliveryOutcome( delivery: WebhookDeliveryItem ): string {
	switch ( delivery.status ) {
		case 'sent':
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Delivered (%d)', 'merchboard' ),
				delivery.httpStatus ?? 200
			);
		case 'pending':
			return __( 'Waiting', 'merchboard' );
		case 'skipped':
			return __( 'Not sent (switched off)', 'merchboard' );
		default:
			return delivery.httpStatus === null
				? sprintf(
						/* translators: %s: the error, e.g. "http_request_failed: …". */
						__( 'Failed: %s', 'merchboard' ),
						delivery.error ?? ''
					)
				: sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Failed (%d)', 'merchboard' ),
						delivery.httpStatus
					);
	}
}

/**
 * The kind of a delivery in words.
 *
 * @param kind purchase, test or resend.
 */
function kindLabel( kind: WebhookDeliveryItem[ 'kind' ] ): string {
	switch ( kind ) {
		case 'test':
			return __( 'Test', 'merchboard' );
		case 'resend':
			return __( 'Resend', 'merchboard' );
		default:
			return __( 'Purchase', 'merchboard' );
	}
}

/**
 * A webhook's status in words.
 *
 * @param webhook Webhook.
 */
function statusLabel( webhook: WebhookItem ): string {
	if ( ! webhook.enabled ) {
		return __( 'Off', 'merchboard' );
	}

	if ( webhook.paused ) {
		return sprintf(
			/* translators: %d: failed deliveries in a row. */
			__( 'Paused after %d failed deliveries', 'merchboard' ),
			webhook.failures
		);
	}

	return __( 'On', 'merchboard' );
}

export interface WebhooksCardProps {
	readonly api: AlertApiState;
	/** A new answer of the server. */
	readonly onChange: ( state: AlertApiState ) => void;
	/** Shows a short message. */
	readonly notify: ( message: string ) => void;
	/** Formats a time in the creator's language. */
	readonly when: ( iso: string ) => string;
}

/**
 * "Webhooks" (step 25.12.2, ADR-073): purchases sent to the creator's own
 * bots (for example SparkyBot) as one signed JSON event per order, or to a
 * Discord channel. A made signing secret is shown once.
 *
 * @param props          Props.
 * @param props.api      The API page.
 * @param props.onChange A new answer of the server.
 * @param props.notify   Shows a short message.
 * @param props.when     Formats a time.
 */
export function WebhooksCard( {
	api,
	onChange,
	notify,
	when,
}: WebhooksCardProps ) {
	const id = useId();
	const [ name, setName ] = useState( '' );
	const [ format, setFormat ] = useState< WebhookFormat >( 'json' );
	const [ url, setUrl ] = useState( '' );
	const [ secret, setSecret ] = useState( '' );
	const [ template, setTemplate ] = useState( '' );
	const [ shownSecret, setShownSecret ] = useState< string | null >( null );
	const [ confirming, setConfirming ] = useState< number | null >( null );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const message = error === null ? null : refusal( error, api.maxWebhooks );
	const names = new Map(
		api.webhooks.map( ( webhook ) => [ webhook.id, webhook.name ] )
	);

	const act = async (
		label: string,
		call: () => Promise< ApiResult< AlertApiState > >,
		done?: ( state: AlertApiState ) => void
	) => {
		setBusy( label );
		setError( null );
		const answer = await call();
		setBusy( null );

		if ( ! answer.ok ) {
			setError( answer.error );

			return;
		}

		onChange( answer.data );

		// Only a new secret replaces the shown one; another action (e.g. a test) keeps it until it is closed.
		if ( answer.data.secret ) {
			setShownSecret( answer.data.secret );
		}

		done?.( answer.data );
	};

	const add = () =>
		act(
			'add',
			() =>
				createWebhook( {
					name: name.trim(),
					format,
					url: url.trim(),
					secret: format === 'json' ? secret.trim() : '',
					template: format === 'discord' ? template.trim() : '',
				} ),
			() => {
				setName( '' );
				setUrl( '' );
				setSecret( '' );
				setTemplate( '' );
				notify( __( 'The webhook was added.', 'merchboard' ) );
			}
		);

	const copy = async ( value: string ) => {
		try {
			await window.navigator.clipboard.writeText( value );
			notify( __( 'Secret copied.', 'merchboard' ) );
		} catch {
			const input = document.getElementById( `${ id }-secret` );

			if ( input instanceof HTMLInputElement ) {
				input.select();
			}

			notify(
				__( 'Copy the selected secret with Ctrl+C.', 'merchboard' )
			);
		}
	};

	return (
		<Card title={ __( 'Webhooks', 'merchboard' ) }>
			<p>
				{ __(
					'With a webhook, the shop sends each purchase to your bot at once: no polling. Choose JSON for your own bot, for example SparkyBot, or Discord to post the chat message in a Discord channel.',
					'merchboard'
				) }
			</p>
			{ error !== null &&
				( message === null ? (
					<ApiErrorState error={ error } />
				) : (
					<Alert tone="danger">{ message }</Alert>
				) ) }

			{ shownSecret !== null && (
				<div className="merchboard-alert-api__new-key" role="status">
					<Alert tone="success">
						{ __(
							'Paste this signing secret into your bot now. It is shown only this once.',
							'merchboard'
						) }
					</Alert>
					<label htmlFor={ `${ id }-secret` }>
						{ __( 'Signing secret', 'merchboard' ) }
					</label>
					<div className="merchboard-alert-api__key-row">
						<input
							id={ `${ id }-secret` }
							className="merchboard-input merchboard-alert-api__key"
							readOnly
							value={ shownSecret }
							onFocus={ ( event ) =>
								event.currentTarget.select()
							}
						/>
						<Button
							variant="secondary"
							onClick={ () => void copy( shownSecret ) }
						>
							{ __( 'Copy', 'merchboard' ) }
						</Button>
						<Button
							variant="ghost"
							onClick={ () => setShownSecret( null ) }
						>
							{ __( 'I have saved it', 'merchboard' ) }
						</Button>
					</div>
				</div>
			) }

			{ api.webhooks.length === 0 ? (
				<p>{ __( 'You have no webhooks yet.', 'merchboard' ) }</p>
			) : (
				<ul className="merchboard-alert-api__keys">
					{ api.webhooks.map( ( webhook ) => (
						<li
							key={ webhook.id }
							className="merchboard-alert-api__item"
						>
							<div>
								<strong>{ webhook.name }</strong>{ ' ' }
								<code>{ webhook.hint }</code>
								<div className="merchboard-alert-api__meta">
									{ sprintf(
										/* translators: 1: JSON or Discord, 2: on, off or paused, 3: when it last delivered. */
										__(
											'%1$s · %2$s · last delivery %3$s',
											'merchboard'
										),
										webhook.format === 'discord'
											? 'Discord'
											: 'JSON',
										statusLabel( webhook ),
										webhook.lastDeliveryAt === null
											? __( 'never', 'merchboard' )
											: when( webhook.lastDeliveryAt )
									) }
								</div>
							</div>
							{ confirming === webhook.id ? (
								<div className="merchboard-alert-api__confirm">
									<span>
										{ __(
											'The webhook and its log are deleted.',
											'merchboard'
										) }
									</span>
									<Button
										variant="danger"
										size="sm"
										isBusy={
											busy === `delete:${ webhook.id }`
										}
										onClick={ () =>
											void act(
												`delete:${ webhook.id }`,
												() =>
													deleteWebhook( webhook.id ),
												() => setConfirming( null )
											)
										}
									>
										{ __( 'Delete webhook', 'merchboard' ) }
									</Button>
									<Button
										variant="ghost"
										size="sm"
										onClick={ () => setConfirming( null ) }
									>
										{ __( 'Cancel', 'merchboard' ) }
									</Button>
								</div>
							) : (
								<div className="merchboard-alert-api__confirm">
									<Button
										variant="secondary"
										size="sm"
										disabled={
											! api.enabled ||
											! api.access.webhooks
										}
										isBusy={
											busy === `test:${ webhook.id }`
										}
										onClick={ () =>
											void act(
												`test:${ webhook.id }`,
												() => testWebhook( webhook.id ),
												( state ) =>
													state.delivery !==
														undefined &&
													notify(
														deliveryOutcome(
															state.delivery
														)
													)
											)
										}
									>
										{ __( 'Send test', 'merchboard' ) }
									</Button>
									{ webhook.paused && webhook.enabled && (
										<Button
											variant="secondary"
											size="sm"
											isBusy={
												busy ===
												`resume:${ webhook.id }`
											}
											onClick={ () =>
												void act(
													`resume:${ webhook.id }`,
													() =>
														updateWebhook(
															webhook.id,
															{
																resume: true,
															}
														)
												)
											}
										>
											{ __( 'Resume', 'merchboard' ) }
										</Button>
									) }
									<Button
										variant="secondary"
										size="sm"
										isBusy={
											busy === `switch:${ webhook.id }`
										}
										disabled={
											! webhook.enabled &&
											! api.access.webhooks
										}
										onClick={ () =>
											void act(
												`switch:${ webhook.id }`,
												() =>
													updateWebhook( webhook.id, {
														enabled:
															! webhook.enabled,
													} )
											)
										}
									>
										{ webhook.enabled
											? __( 'Switch off', 'merchboard' )
											: __( 'Switch on', 'merchboard' ) }
									</Button>
									{ webhook.format === 'json' && (
										<Button
											variant="ghost"
											size="sm"
											disabled={ ! api.access.webhooks }
											isBusy={
												busy ===
												`secret:${ webhook.id }`
											}
											onClick={ () =>
												void act(
													`secret:${ webhook.id }`,
													() =>
														newWebhookSecret(
															webhook.id,
															''
														)
												)
											}
										>
											{ __( 'New secret', 'merchboard' ) }
										</Button>
									) }
									<Button
										variant="ghost"
										size="sm"
										onClick={ () =>
											setConfirming( webhook.id )
										}
									>
										{ __( 'Delete', 'merchboard' ) }
									</Button>
								</div>
							) }
						</li>
					) ) }
				</ul>
			) }

			{ api.enabled &&
				api.access.webhooks &&
				api.webhooks.length < api.maxWebhooks && (
					<form
						className="merchboard-alert-api__create"
						onSubmit={ ( event ) => {
							event.preventDefault();
							void add();
						} }
					>
						<h3>{ __( 'Add a webhook', 'merchboard' ) }</h3>
						<Field label={ __( 'Name', 'merchboard' ) }>
							{ ( control ) => (
								<input
									{ ...control }
									className="merchboard-input"
									maxLength={ 60 }
									value={ name }
									onChange={ ( event ) =>
										setName( event.target.value )
									}
								/>
							) }
						</Field>
						<Field label={ __( 'Format', 'merchboard' ) }>
							{ ( control ) => (
								<select
									{ ...control }
									className="merchboard-input"
									value={ format }
									onChange={ ( event ) =>
										setFormat(
											event.target.value === 'discord'
												? 'discord'
												: 'json'
										)
									}
								>
									<option value="json">
										{ __(
											'JSON (your own bot, e.g. SparkyBot)',
											'merchboard'
										) }
									</option>
									<option value="discord">
										{ __(
											'Discord channel',
											'merchboard'
										) }
									</option>
								</select>
							) }
						</Field>
						<Field
							label={ __( 'Address (https)', 'merchboard' ) }
							help={
								format === 'discord'
									? __(
											'In Discord: Server settings → Integrations → Webhooks → New webhook → Copy webhook URL. Keep the address secret.',
											'merchboard'
										)
									: __(
											'The address your bot gives you for purchases.',
											'merchboard'
										)
							}
						>
							{ ( control ) => (
								<input
									{ ...control }
									className="merchboard-input"
									type="url"
									maxLength={ 500 }
									value={ url }
									onChange={ ( event ) =>
										setUrl( event.target.value )
									}
								/>
							) }
						</Field>
						{ format === 'json' ? (
							<Field
								label={ __(
									'Signing secret (optional)',
									'merchboard'
								) }
								help={ __(
									"Paste the secret from your bot, for example from SparkyBot's dashboard, or leave it empty to get one.",
									'merchboard'
								) }
							>
								{ ( control ) => (
									<input
										{ ...control }
										className="merchboard-input"
										autoComplete="off"
										value={ secret }
										onChange={ ( event ) =>
											setSecret( event.target.value )
										}
									/>
								) }
							</Field>
						) : (
							<Field
								label={ __(
									'Message (optional)',
									'merchboard'
								) }
								help={ __(
									"The same variables as the chatbot, e.g. $Name just bought $Product! Empty for the chatbot's default.",
									'merchboard'
								) }
							>
								{ ( control ) => (
									<input
										{ ...control }
										className="merchboard-input"
										maxLength={ WEBHOOK_TEMPLATE_MAX }
										value={ template }
										onChange={ ( event ) =>
											setTemplate( event.target.value )
										}
									/>
								) }
							</Field>
						) }
						<Button
							type="submit"
							isBusy={ busy === 'add' }
							disabled={ name.trim() === '' || url.trim() === '' }
						>
							{ __( 'Add webhook', 'merchboard' ) }
						</Button>
					</form>
				) }

			<h3>{ __( 'Deliveries', 'merchboard' ) }</h3>
			{ api.deliveries.length === 0 ? (
				<p>{ __( 'Nothing has been sent yet.', 'merchboard' ) }</p>
			) : (
				<ul className="merchboard-alert-api__deliveries">
					{ api.deliveries.map( ( delivery ) => (
						<li key={ delivery.id }>
							{ sprintf(
								/* translators: 1: time, 2: webhook name, 3: purchase, test or resend, 4: outcome. */
								__( '%1$s · %2$s · %3$s · %4$s', 'merchboard' ),
								when( delivery.createdAt ),
								names.get( delivery.webhookId ) ?? '',
								kindLabel( delivery.kind ),
								deliveryOutcome( delivery )
							) }
						</li>
					) ) }
				</ul>
			) }

			<details className="merchboard-alert-api__details">
				<summary>
					{ __( 'How your bot receives webhooks', 'merchboard' ) }
				</summary>
				<ul>
					<li>
						{ __(
							'One POST with JSON per paid order: the shop sends it after the payment, never at checkout. Tests and resends have type "test" and "resend" and should not show as real purchases.',
							'merchboard'
						) }
					</li>
					<li>
						{ __(
							'"id" is the same every time the same purchase is sent; use it to drop repeats. "order_ref" is not the order number.',
							'merchboard'
						) }
					</li>
					<li>
						{ __(
							'Check the headers X-MerchBoard-Timestamp and X-MerchBoard-Signature (sha256= HMAC of "timestamp.body" with the secret) before you accept an event.',
							'merchboard'
						) }
					</li>
					<li>
						{ __(
							'Answer with a 2xx status within 5 seconds. Each delivery is tried once; after 10 failures in a row the webhook pauses until you resume it. Use the API or resend from History to get what was missed.',
							'merchboard'
						) }
					</li>
				</ul>
				<h4>{ __( 'Example event', 'merchboard' ) }</h4>
				<pre className="merchboard-alert-api__code">
					<code>{ EXAMPLE_EVENT }</code>
				</pre>
				<h4>
					{ __( 'Checking the signature (Node.js)', 'merchboard' ) }
				</h4>
				<pre className="merchboard-alert-api__code">
					<code>{ VERIFY_EXAMPLE }</code>
				</pre>
			</details>
		</Card>
	);
}
