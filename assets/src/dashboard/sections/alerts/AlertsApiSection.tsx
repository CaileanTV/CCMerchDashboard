import { useCallback, useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	createAlertApiKey,
	getAlertApi,
	revokeAlertApiKey,
	testAlertApi,
} from '../../api/alertApi';
import type { AlertApiKeyItem, AlertApiState } from '../../api/alertApi';
import type { ApiError } from '../../api/client';
import { useConfig } from '../../app/config-context';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';

/** Longest key name (AlertApiKeys::NAME_MAX). */
export const KEY_NAME_MAX = 60;

/** An example answer of the API, as the guide shows it. */
const EXAMPLE_ANSWER = `{
  "cursor": 812,
  "events": [
    {
      "id": 812,
      "kind": "purchase",
      "created_at": "2026-10-23T18:00:00+00:00",
      "order_ref": "3f9a1c0b7d2e4a51",
      "username": "TestFan",
      "product": "Hoodie",
      "quantity": 2,
      "category": "Merch",
      "variation": "Size: L",
      "image": "https://shop.example/wp-content/uploads/hoodie-600x600.jpg",
      "price": "199.00",
      "line_total": "398.00",
      "total": "548.00",
      "currency": "DKK"
    }
  ]
}`;

/**
 * A time in the creator's language.
 *
 * @param iso    ISO 8601 time.
 * @param locale WordPress locale, e.g. da_DK.
 */
export function apiTime( iso: string, locale: string ): string {
	try {
		return new Intl.DateTimeFormat( locale.replace( '_', '-' ), {
			dateStyle: 'medium',
			timeStyle: 'short',
		} ).format( new Date( iso ) );
	} catch {
		return iso;
	}
}

/**
 * The message of a refused change, from the server's error code.
 *
 * @param error   Error.
 * @param maxKeys Most keys.
 */
function refusal( error: ApiError, maxKeys: number ): string | null {
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
		case 'merchboard_alerts_api_key_limit':
			return sprintf(
				/* translators: %d: most keys. */
				__(
					'You can have at most %d keys. Revoke one first.',
					'merchboard'
				),
				maxKeys
			);
		case 'merchboard_invalid_request':
			return __(
				'Give the key a name of at most 60 characters.',
				'merchboard'
			);
		default:
			return null;
	}
}

/**
 * "API" under Alerts (step 25.12.1, ADR-073): the creator's API keys for
 * their own bots, a test event and a guide. A new key is shown once.
 */
export function AlertsApiSection() {
	const { state, retry } = useApi( getAlertApi );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading the API keys…', 'merchboard' ) }
				lines={ 4 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return <AlertApiPanel initial={ state.data } />;
}

/**
 * The page with its data.
 *
 * @param props         Props.
 * @param props.initial The first answer.
 */
export function AlertApiPanel( {
	initial,
}: {
	readonly initial: AlertApiState;
} ) {
	const id = useId();
	const { locale } = useConfig();
	const [ api, setApi ] = useState< AlertApiState >( initial );
	const [ name, setName ] = useState( '' );
	const [ newKey, setNewKey ] = useState< string | null >( null );
	const [ confirming, setConfirming ] = useState< number | null >( null );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );
	const message = error === null ? null : refusal( error, api.maxKeys );

	const create = async () => {
		setBusy( 'create' );
		setError( null );
		const answer = await createAlertApiKey( name.trim() );
		setBusy( null );

		if ( ! answer.ok ) {
			setError( answer.error );

			return;
		}

		setApi( answer.data );
		setNewKey( answer.data.key ?? null );
		setName( '' );
	};

	const revoke = async ( key: AlertApiKeyItem ) => {
		setBusy( `revoke:${ key.id }` );
		setError( null );
		const answer = await revokeAlertApiKey( key.id );
		setBusy( null );
		setConfirming( null );

		if ( ! answer.ok ) {
			setError( answer.error );

			return;
		}

		setApi( answer.data );
		setToast(
			sprintf(
				/* translators: %s: the key's name. */
				__( 'The key "%s" was revoked.', 'merchboard' ),
				key.name
			)
		);
	};

	const test = async () => {
		setBusy( 'test' );
		setError( null );
		const answer = await testAlertApi();
		setBusy( null );

		if ( ! answer.ok ) {
			setError( answer.error );

			return;
		}

		setToast(
			answer.data.sent === 0
				? __(
						'Make a key first: the test goes to your keys.',
						'merchboard'
					)
				: __(
						'A test event is waiting for your bots. It comes with their next request within 5 minutes.',
						'merchboard'
					)
		);
	};

	const copy = async ( key: string ) => {
		try {
			await window.navigator.clipboard.writeText( key );
			setToast( __( 'Key copied.', 'merchboard' ) );
		} catch {
			const input = document.getElementById( `${ id }-new-key` );

			if ( input instanceof HTMLInputElement ) {
				input.select();
			}

			setToast(
				__( 'Copy the selected key with Ctrl+C.', 'merchboard' )
			);
		}
	};

	return (
		<div className="merchboard-history merchboard-alert-api">
			{ ! api.enabled && (
				<Alert tone="warning">
					{ __(
						'The alert API is switched off by the shop. Your keys are kept, but bots get no alerts until it is switched on again.',
						'merchboard'
					) }
				</Alert>
			) }
			{ api.enabled && ! api.access.keys && (
				<Alert tone="warning">
					{ __(
						'The shop has not given you access to API keys. Your keys are kept, but bots get no alerts. You can still revoke them.',
						'merchboard'
					) }
				</Alert>
			) }
			{ error !== null &&
				( message === null ? (
					<ApiErrorState error={ error } />
				) : (
					<Alert tone="danger">{ message }</Alert>
				) ) }

			<Card title={ __( 'API keys', 'merchboard' ) }>
				<p>
					{ __(
						'With an API key, your own bot (for example on Discord or Twitch) can fetch your purchase alerts. Keep the key secret: anyone with it can read your alerts. Revoke a key you no longer use or that others may have seen.',
						'merchboard'
					) }
				</p>

				{ newKey !== null && (
					<div
						className="merchboard-alert-api__new-key"
						role="status"
					>
						<Alert tone="success">
							{ __(
								'Save the key now. It is shown only this once.',
								'merchboard'
							) }
						</Alert>
						<label htmlFor={ `${ id }-new-key` }>
							{ __( 'Your new key', 'merchboard' ) }
						</label>
						<div className="merchboard-alert-api__key-row">
							<input
								id={ `${ id }-new-key` }
								className="merchboard-input merchboard-alert-api__key"
								readOnly
								value={ newKey }
								onFocus={ ( event ) =>
									event.currentTarget.select()
								}
							/>
							<Button
								variant="secondary"
								onClick={ () => void copy( newKey ) }
							>
								{ __( 'Copy', 'merchboard' ) }
							</Button>
							<Button
								variant="ghost"
								onClick={ () => setNewKey( null ) }
							>
								{ __( 'I have saved it', 'merchboard' ) }
							</Button>
						</div>
					</div>
				) }

				{ api.keys.length === 0 ? (
					<p>{ __( 'You have no keys yet.', 'merchboard' ) }</p>
				) : (
					<ul className="merchboard-alert-api__keys">
						{ api.keys.map( ( key ) => (
							<li
								key={ key.id }
								className="merchboard-alert-api__item"
							>
								<div>
									<strong>{ key.name }</strong>{ ' ' }
									<code>{ key.hint }</code>
									<div className="merchboard-alert-api__meta">
										{ sprintf(
											/* translators: 1: when the key was made, 2: when it was last used. */
											__(
												'Made %1$s · last used %2$s',
												'merchboard'
											),
											apiTime( key.createdAt, locale ),
											key.lastUsedAt === null
												? __( 'never', 'merchboard' )
												: apiTime(
														key.lastUsedAt,
														locale
													)
										) }
									</div>
								</div>
								{ confirming === key.id ? (
									<div className="merchboard-alert-api__confirm">
										<span>
											{ __(
												'Your bot stops working with this key.',
												'merchboard'
											) }
										</span>
										<Button
											variant="danger"
											size="sm"
											isBusy={
												busy === `revoke:${ key.id }`
											}
											onClick={ () => void revoke( key ) }
										>
											{ __( 'Revoke key', 'merchboard' ) }
										</Button>
										<Button
											variant="ghost"
											size="sm"
											onClick={ () =>
												setConfirming( null )
											}
										>
											{ __( 'Cancel', 'merchboard' ) }
										</Button>
									</div>
								) : (
									<Button
										variant="secondary"
										size="sm"
										onClick={ () =>
											setConfirming( key.id )
										}
									>
										{ __( 'Revoke', 'merchboard' ) }
									</Button>
								) }
							</li>
						) ) }
					</ul>
				) }

				{ api.enabled &&
					api.access.keys &&
					api.keys.length < api.maxKeys && (
						<form
							className="merchboard-alert-api__create"
							onSubmit={ ( event ) => {
								event.preventDefault();
								void create();
							} }
						>
							<Field
								label={ __(
									'Name of the new key',
									'merchboard'
								) }
								help={ __(
									'For example the bot that uses it.',
									'merchboard'
								) }
							>
								{ ( control ) => (
									<input
										{ ...control }
										className="merchboard-input"
										maxLength={ KEY_NAME_MAX }
										value={ name }
										onChange={ ( event ) =>
											setName( event.target.value )
										}
									/>
								) }
							</Field>
							<Button
								type="submit"
								isBusy={ busy === 'create' }
								disabled={ name.trim() === '' }
							>
								{ __( 'Make a key', 'merchboard' ) }
							</Button>
						</form>
					) }
				{ api.keys.length >= api.maxKeys && (
					<p>
						{ sprintf(
							/* translators: %d: most keys. */
							__(
								'You have %d keys, the most. Revoke one to make a new one.',
								'merchboard'
							),
							api.maxKeys
						) }
					</p>
				) }
			</Card>

			<Card
				title={ __( 'Test', 'merchboard' ) }
				actions={
					<Button
						variant="secondary"
						isBusy={ busy === 'test' }
						disabled={ ! api.enabled || ! api.access.keys }
						onClick={ () => void test() }
					>
						{ __( 'Send test event', 'merchboard' ) }
					</Button>
				}
			>
				<p>
					{ __(
						'Sends a test purchase to every key, marked "test". Resending an alert under History also reaches your bots, marked "resend".',
						'merchboard'
					) }
				</p>
			</Card>

			<Card title={ __( 'How to use the API', 'merchboard' ) }>
				<ol className="merchboard-alert-api__guide">
					<li>
						{ __(
							'Your bot asks this address every 5–10 seconds:',
							'merchboard'
						) }
						<pre className="merchboard-alert-api__code">
							<code>{ api.endpoint }</code>
						</pre>
					</li>
					<li>
						{ __(
							'It sends the key in the header Authorization: Bearer, never in the address. For example:',
							'merchboard'
						) }
						<pre className="merchboard-alert-api__code">
							<code>{ `curl -H "Authorization: Bearer mbk_…" "${ api.endpoint }?after=0"` }</code>
						</pre>
					</li>
					<li>
						{ __(
							'The first request without "after" gives the cursor to start from, and no purchases. Then the bot sends after={cursor} and saves the new cursor from every answer, so it only gets new purchases and never the same one twice.',
							'merchboard'
						) }
					</li>
					<li>
						{ sprintf(
							/* translators: %d: requests per minute. */
							__(
								'At most %d requests per key per minute; too many give the answer 429. A wrong or revoked key gives 401, and 503 means the shop has switched the API or alerts off.',
								'merchboard'
							),
							api.limit
						) }
					</li>
				</ol>
				<h3>{ __( 'Example answer', 'merchboard' ) }</h3>
				<pre className="merchboard-alert-api__code">
					<code>{ EXAMPLE_ANSWER }</code>
				</pre>
				<h3>{ __( 'The fields', 'merchboard' ) }</h3>
				<dl className="merchboard-alert-api__fields">
					<dt>id</dt>
					<dd>
						{ __(
							'The purchase line; 0 for tests and resends, which never move the cursor.',
							'merchboard'
						) }
					</dd>
					<dt>kind</dt>
					<dd>
						{ __(
							'"purchase", "test" or "resend".',
							'merchboard'
						) }
					</dd>
					<dt>order_ref</dt>
					<dd>
						{ __(
							'The same for every line of one order, so your bot can write one message per order. It is not the order number.',
							'merchboard'
						) }
					</dd>
					<dt>username</dt>
					<dd>
						{ __(
							'The username the customer chose to show; empty when they gave none.',
							'merchboard'
						) }
					</dd>
					<dt>product, quantity, category, variation</dt>
					<dd>{ __( 'What was bought.', 'merchboard' ) }</dd>
					<dt>image</dt>
					<dd>
						{ __(
							"The address of the product's image in the shop's product size; empty when it has none.",
							'merchboard'
						) }
					</dd>
					<dt>price, line_total, total, currency</dt>
					<dd>
						{ __(
							'The price per item, the line and your total in the order, paid with VAT after discounts, as numbers with a point; null when unknown.',
							'merchboard'
						) }
					</dd>
				</dl>
			</Card>
			{ toast !== null && (
				<Toast message={ toast } onDismiss={ dismissToast } />
			) }
		</div>
	);
}
