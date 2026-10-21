import { useCallback, useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	connectChatbot,
	disconnectChatbot,
	getChatbot,
	PLATFORMS,
	testChatbot,
	updateChatbot,
} from '../../api/chatbot';
import type {
	ChatbotPlatformState,
	ChatbotState,
	ChatPlatform,
} from '../../api/chatbot';
import type { ApiError } from '../../api/client';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';

/** Longest template in characters (the platforms' longest message). */
export const CHAT_TEMPLATE_MAX = 500;

/** Sample values of the preview (a test purchase). */
const SAMPLE: Readonly< Record< string, string > > = {
	$UserName: 'TestFan',
	$Name: 'TestFan',
	$Product: 'Hoodie',
	$Quantity: '1',
	$Category: 'Merch',
	$Variation: 'L',
	$OrderNumber: '1001',
};

/**
 * The template with sample values, as the chat would show it.
 *
 * @param template   Template.
 * @param itemFormat How each product shows in $Products.
 * @param separator  Between the products.
 */
export function previewMessage(
	template: string,
	itemFormat: string,
	separator: string
): string {
	const fill = ( text: string ) =>
		Object.entries( SAMPLE ).reduce(
			( result, [ name, value ] ) => result.split( name ).join( value ),
			text
		);
	const products = [
		fill( itemFormat ),
		fill(
			itemFormat
				.split( '$Quantity' )
				.join( '2' )
				.split( '$Product' )
				.join( 'Mug' )
		),
	].join( separator );

	return fill(
		template
			.split( '$Products' )
			.join( '\u0000' )
			.split( '$ItemCount' )
			.join( '3' )
			.split( '$Amount' )
			.join( '' )
	)
		.split( '\u0000' )
		.join( products );
}

/**
 * The message of the platform's answer after adding the bot (the
 * `chatbot` query argument of the redirect), or null.
 *
 * @param code  Result code.
 * @param label The platform's name.
 */
export function connectMessage(
	code: string,
	label: string
): {
	readonly tone: 'success' | 'danger' | 'info';
	readonly text: string;
} | null {
	switch ( code ) {
		case '':
			return null;
		case 'connected':
			return {
				tone: 'success',
				text: sprintf(
					/* translators: 1: platform (Twitch or Kick). */
					__(
						'The bot was added to your %1$s channel. It writes in your chat when someone buys your merch.',
						'merchboard'
					),
					label
				),
			};
		case 'denied':
			return {
				tone: 'info',
				text: sprintf(
					/* translators: 1: platform (Twitch or Kick). */
					__(
						'You cancelled at %1$s. Nothing was changed.',
						'merchboard'
					),
					label
				),
			};
		case 'bot_is_broadcaster':
			return {
				tone: 'danger',
				text: __(
					"You logged in with the bot's own account. Log in with your channel's account instead.",
					'merchboard'
				),
			};
		default:
			return {
				tone: 'danger',
				text: sprintf(
					/* translators: 1: technical error code. */
					__(
						'The bot could not be added (error code: %1$s). Try again later, or give an administrator the error code.',
						'merchboard'
					),
					code
				),
			};
	}
}

/**
 * The result the platform's redirect brought, removed from the address bar.
 */
function takeConnectResult(): {
	readonly code: string;
	readonly platform: ChatPlatform;
} {
	const url = new URL( window.location.href );
	const code = url.searchParams.get( 'chatbot' ) ?? '';
	const platform = url.searchParams.get( 'platform' ) ?? '';

	if ( code === '' ) {
		return { code: '', platform: 'twitch' };
	}

	url.searchParams.delete( 'chatbot' );
	url.searchParams.delete( 'platform' );
	window.history.replaceState( window.history.state, '', url.href );

	return {
		code: /^[a-z_]{1,40}$/.test( code ) ? code : 'error',
		platform: PLATFORMS.find( ( item ) => item === platform ) ?? 'twitch',
	};
}

/**
 * The latest message's status as the creator reads it.
 *
 * @param status Status from the message log.
 * @param reason Its reason code ('' for none).
 */
export function statusLabel( status: string, reason = '' ): string {
	switch ( status ) {
		case 'sent':
			return __( 'sent', 'merchboard' );
		case 'waiting':
			return reason === 'not_live'
				? __(
						'waiting (you were not live; checked again after 5 minutes)',
						'merchboard'
					)
				: __( 'waiting to be sent', 'merchboard' );
		case 'skipped':
			return reason === 'not_live'
				? __( 'skipped (you were not live)', 'merchboard' )
				: __( 'skipped (empty message)', 'merchboard' );
		default:
			return __( 'not sent', 'merchboard' );
	}
}

function testMessage(
	result: string,
	label: string,
	platform: ChatPlatform
): string {
	// YouTube refuses the bot's message because of the stream or chat settings, not the bot.
	if ( result === 'forbidden' && platform === 'youtube' ) {
		return __(
			'YouTube did not let the bot write. Check that your livestream is not private and that your chat is not for subscribers or members only, and make the bot a moderator.',
			'merchboard'
		);
	}

	switch ( result ) {
		case 'sent':
			return __( 'The test message was sent.', 'merchboard' );
		case 'not_live':
			return __(
				'You are not live on YouTube right now. Test messages can only be written during a livestream.',
				'merchboard'
			);
		case 'rate_limited':
			return sprintf(
				/* translators: 1: platform (Twitch or Kick). */
				__(
					'%1$s asked the bot to wait. Try again in a moment.',
					'merchboard'
				),
				label
			);
		case 'forbidden':
		case 'unauthorized':
			return sprintf(
				/* translators: 1: platform (Twitch or Kick). */
				__(
					'%1$s refused the message. Add the bot to your channel again.',
					'merchboard'
				),
				label
			);
		default:
			return sprintf(
				/* translators: 1: platform (Twitch or Kick). */
				__(
					'%1$s did not show the message (e.g. because of your chat settings).',
					'merchboard'
				),
				label
			);
	}
}

type Busy = 'save' | `${ 'connect' | 'disconnect' | 'test' }:${ ChatPlatform }`;

interface PlatformCardProps {
	readonly platform: ChatbotPlatformState;
	readonly busy: Busy | null;
	readonly onConnect: () => void;
	readonly onDisconnect: () => void;
	readonly onTest: () => void;
}

function PlatformCard( {
	platform,
	busy,
	onConnect,
	onDisconnect,
	onTest,
}: PlatformCardProps ) {
	const { label, channel, botLogin, lastMessage } = platform;

	return (
		<Card
			title={ sprintf(
				/* translators: 1: platform (Twitch or Kick). */
				__( 'Your %1$s channel', 'merchboard' ),
				label
			) }
		>
			{ channel === null ? (
				<>
					<p>
						{ sprintf(
							/* translators: 1: platform (Twitch or Kick). */
							__(
								"Add the bot to your channel, and it writes in your %1$s chat when someone buys your merch. You go to %1$s and allow the bot to chat in your channel; log in with your channel's account.",
								'merchboard'
							),
							label
						) }
					</p>
					<Button
						onClick={ onConnect }
						isBusy={ busy === `connect:${ platform.platform }` }
						disabled={ busy !== null }
					>
						{ __( 'Add the bot to your channel', 'merchboard' ) }
					</Button>
				</>
			) : (
				<>
					<p>
						{ botLogin === ''
							? sprintf(
									/* translators: 1: channel login. */
									__(
										'The bot writes in the chat of %1$s.',
										'merchboard'
									),
									channel.login
								)
							: sprintf(
									/* translators: 1: bot login, 2: channel login. */
									__(
										'The bot %1$s writes in the chat of %2$s.',
										'merchboard'
									),
									botLogin,
									channel.login
								) }
					</p>
					{ platform.platform === 'youtube' && (
						<p className="merchboard-field__help">
							{ __(
								'On YouTube the bot writes only during your livestreams. If you are not live when someone buys, it checks once more after 5 minutes; otherwise the purchase is not announced in the chat. Tip: make the bot a moderator of your channel, so YouTube does not hold its messages back.',
								'merchboard'
							) }
						</p>
					) }
					{ platform.platform === 'twitch' && botLogin !== '' && (
						<p className="merchboard-field__help">
							{ sprintf(
								/* translators: 1: bot login. */
								__(
									'Tip: give the bot moderator or VIP in your channel (/mod %1$s or /vip %1$s), so it may send more messages when many buy at once.',
									'merchboard'
								),
								botLogin
							) }
						</p>
					) }
					{ lastMessage !== null && (
						<p className="merchboard-field__help">
							{ sprintf(
								/* translators: 1: status, 2: the platform's reason or nothing. */
								__( 'Latest message: %1$s%2$s', 'merchboard' ),
								statusLabel(
									lastMessage.status,
									lastMessage.reason
								),
								lastMessage.reason === '' ||
									lastMessage.reason === 'not_live' ||
									lastMessage.status === 'sent'
									? ''
									: ` (${ lastMessage.reason })`
							) }
						</p>
					) }
					<div className="merchboard-alerts__actions">
						<Button
							variant="secondary"
							onClick={ onTest }
							isBusy={ busy === `test:${ platform.platform }` }
							disabled={ busy !== null }
						>
							{ __( 'Send test message', 'merchboard' ) }
						</Button>
						<Button
							variant="ghost"
							onClick={ onDisconnect }
							isBusy={
								busy === `disconnect:${ platform.platform }`
							}
							disabled={ busy !== null }
						>
							{ __( 'Remove the bot', 'merchboard' ) }
						</Button>
					</div>
				</>
			) }
		</Card>
	);
}

function ChatbotContent( { initial }: { readonly initial: ChatbotState } ) {
	const id = useId();
	const [ state, setState ] = useState( initial );
	const [ enabled, setEnabled ] = useState<
		Partial< Record< ChatPlatform, boolean > >
	>( () =>
		Object.fromEntries(
			initial.platforms.map( ( platform ) => [
				platform.platform,
				platform.enabled,
			] )
		)
	);
	const [ template, setTemplate ] = useState( initial.template );
	const [ busy, setBusy ] = useState< Busy | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ result ] = useState( takeConnectResult );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );
	const available = state.platforms.filter(
		( platform ) => platform.available
	);
	const labelOf = ( platform: ChatPlatform ) =>
		state.platforms.find( ( item ) => item.platform === platform )?.label ??
		platform;
	const connected = connectMessage( result.code, labelOf( result.platform ) );

	const save = async () => {
		setBusy( 'save' );
		setError( null );
		const answer = await updateChatbot( { template, enabled } );
		setBusy( null );

		if ( answer.ok ) {
			setState( answer.data );
			setTemplate( answer.data.template );
			setToast( __( 'Chatbot settings saved.', 'merchboard' ) );
		} else {
			setError( answer.error );
		}
	};

	const connect = async ( platform: ChatPlatform ) => {
		setBusy( `connect:${ platform }` );
		setError( null );
		const answer = await connectChatbot( platform );

		if ( answer.ok ) {
			window.location.assign( answer.data );
			return;
		}

		setBusy( null );
		setError( answer.error );
	};

	const disconnect = async ( platform: ChatPlatform ) => {
		if (
			// eslint-disable-next-line no-alert -- Removing the bot needs an explicit confirmation.
			! window.confirm(
				__(
					'Remove the bot from your channel? It no longer writes in your chat.',
					'merchboard'
				)
			)
		) {
			return;
		}

		setBusy( `disconnect:${ platform }` );
		setError( null );
		const answer = await disconnectChatbot( platform );
		setBusy( null );

		if ( answer.ok ) {
			setState( answer.data );
			setToast(
				__( 'The bot was removed from your channel.', 'merchboard' )
			);
		} else {
			setError( answer.error );
		}
	};

	const test = async ( platform: ChatPlatform ) => {
		setBusy( `test:${ platform }` );
		setError( null );
		const answer = await testChatbot( platform );
		setBusy( null );

		if ( answer.ok ) {
			setToast(
				testMessage( answer.data.result, labelOf( platform ), platform )
			);
		} else {
			setError( answer.error );
		}
	};

	const preview = previewMessage(
		template === '' ? state.defaultTemplate : template,
		state.itemFormat,
		state.separator
	);

	return (
		<div className="merchboard-history">
			{ connected !== null && (
				<Alert tone={ connected.tone }>{ connected.text }</Alert>
			) }
			{ available.length === 0 && (
				<Alert tone="info">
					{ __(
						'The chatbot is not set up yet. When an administrator has connected it, you can add it to your channel here.',
						'merchboard'
					) }
				</Alert>
			) }
			{ error !== null && <ApiErrorState error={ error } /> }
			{ available.map( ( platform ) => (
				<PlatformCard
					key={ platform.platform }
					platform={ platform }
					busy={ busy }
					onConnect={ () => connect( platform.platform ) }
					onDisconnect={ () => disconnect( platform.platform ) }
					onTest={ () => test( platform.platform ) }
				/>
			) ) }
			<Card title={ __( 'Chat message', 'merchboard' ) }>
				{ PLATFORMS.filter( ( platform ) =>
					available.some( ( item ) => item.platform === platform )
				).map( ( platform ) => (
					<p key={ platform }>
						<label
							className="merchboard-alerts__check"
							htmlFor={ `${ id }-${ platform }` }
						>
							<input
								id={ `${ id }-${ platform }` }
								type="checkbox"
								checked={ enabled[ platform ] ?? true }
								onChange={ ( event ) => {
									const on = event.target.checked;

									setEnabled( ( current ) => ( {
										...current,
										[ platform ]: on,
									} ) );
								} }
							/>{ ' ' }
							{ sprintf(
								/* translators: 1: platform (Twitch or Kick). */
								__(
									'Write in the %1$s chat when someone buys',
									'merchboard'
								),
								labelOf( platform )
							) }
						</label>
					</p>
				) ) }
				<Field
					label={ __( 'Message', 'merchboard' ) }
					help={ sprintf(
						/* translators: 1: variables, 2: whether one message per line or per order. */
						__( 'Variables: %1$s. %2$s', 'merchboard' ),
						state.variables.join( ', ' ),
						state.mode === 'order'
							? __(
									"One message per order: $Products lists the order's products.",
									'merchboard'
								)
							: __(
									'One message per product in the order.',
									'merchboard'
								)
					) }
				>
					{ ( control ) => (
						<textarea
							{ ...control }
							className="merchboard-input"
							rows={ 2 }
							maxLength={ CHAT_TEMPLATE_MAX }
							placeholder={ state.defaultTemplate }
							value={ template }
							onChange={ ( event ) =>
								setTemplate( event.target.value )
							}
						/>
					) }
				</Field>
				<p className="merchboard-field__help">
					{ __( 'Preview:', 'merchboard' ) } <q>{ preview }</q>
				</p>
				<Button onClick={ save } isBusy={ busy === 'save' }>
					{ __( 'Save chatbot settings', 'merchboard' ) }
				</Button>
			</Card>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</div>
	);
}

/**
 * "Alerts → Chatbot" (phase 25, steps 8 and 8.2; ADR-066, ADR-069): the
 * creator adds the bot to the own Twitch and Kick channel (the platform's
 * page), switches chat messages on or off per platform, edits the shared
 * message and sends a test message.
 */
export function AlertsChatbotSection() {
	const { state, retry } = useApi( getChatbot );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading your chatbot…', 'merchboard' ) }
				lines={ 4 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return <ChatbotContent initial={ state.data } />;
}
