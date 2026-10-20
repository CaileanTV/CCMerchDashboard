import { useCallback, useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	connectChatbot,
	disconnectChatbot,
	getChatbot,
	testChatbot,
	updateChatbot,
} from '../../api/chatbot';
import type { ChatbotState } from '../../api/chatbot';
import type { ApiError } from '../../api/client';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';

/** Longest template in characters (Twitch's longest message). */
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
 * The message of Twitch's answer after adding the bot (the `twitch` query
 * argument of the redirect), or null.
 *
 * @param code Result code.
 */
export function connectMessage( code: string ): {
	readonly tone: 'success' | 'danger' | 'info';
	readonly text: string;
} | null {
	switch ( code ) {
		case '':
			return null;
		case 'connected':
			return {
				tone: 'success',
				text: __(
					'The bot was added to your channel. It writes in your chat when someone buys your merch.',
					'merchboard'
				),
			};
		case 'denied':
			return {
				tone: 'info',
				text: __(
					'You cancelled at Twitch. Nothing was changed.',
					'merchboard'
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
				text: __(
					'The bot could not be added. Try again later.',
					'merchboard'
				),
			};
	}
}

/**
 * The result code Twitch's redirect brought, removed from the address bar.
 */
function takeConnectResult(): string {
	const url = new URL( window.location.href );
	const code = url.searchParams.get( 'twitch' ) ?? '';

	if ( code === '' ) {
		return '';
	}

	url.searchParams.delete( 'twitch' );
	window.history.replaceState( window.history.state, '', url.href );

	return /^[a-z_]{1,40}$/.test( code ) ? code : 'error';
}

/**
 * The latest message's status as the creator reads it.
 *
 * @param status Status from the message log.
 */
export function statusLabel( status: string ): string {
	switch ( status ) {
		case 'sent':
			return __( 'sent', 'merchboard' );
		case 'waiting':
			return __( 'waiting to be sent', 'merchboard' );
		case 'skipped':
			return __( 'skipped (empty message)', 'merchboard' );
		default:
			return __( 'not sent', 'merchboard' );
	}
}

function testMessage( result: string ): string {
	switch ( result ) {
		case 'sent':
			return __( 'The test message was sent.', 'merchboard' );
		case 'rate_limited':
			return __(
				'Twitch asked the bot to wait. Try again in a moment.',
				'merchboard'
			);
		case 'forbidden':
			return __(
				'Twitch refused the message. Add the bot to your channel again.',
				'merchboard'
			);
		default:
			return __(
				'Twitch did not show the message (e.g. because of your chat settings).',
				'merchboard'
			);
	}
}

function ChatbotContent( { initial }: { readonly initial: ChatbotState } ) {
	const id = useId();
	const [ state, setState ] = useState( initial );
	const [ enabled, setEnabled ] = useState( initial.enabled );
	const [ template, setTemplate ] = useState( initial.template );
	const [ busy, setBusy ] = useState<
		'save' | 'connect' | 'disconnect' | 'test' | null
	>( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ result ] = useState( takeConnectResult );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );
	const connected = connectMessage( result );

	const save = async () => {
		setBusy( 'save' );
		setError( null );
		const answer = await updateChatbot( { enabled, template } );
		setBusy( null );

		if ( answer.ok ) {
			setState( answer.data );
			setTemplate( answer.data.template );
			setToast( __( 'Chatbot settings saved.', 'merchboard' ) );
		} else {
			setError( answer.error );
		}
	};

	const connect = async () => {
		setBusy( 'connect' );
		setError( null );
		const answer = await connectChatbot();

		if ( answer.ok ) {
			window.location.assign( answer.data );
			return;
		}

		setBusy( null );
		setError( answer.error );
	};

	const disconnect = async () => {
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

		setBusy( 'disconnect' );
		setError( null );
		const answer = await disconnectChatbot();
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

	const test = async () => {
		setBusy( 'test' );
		setError( null );
		const answer = await testChatbot();
		setBusy( null );

		if ( answer.ok ) {
			setToast( testMessage( answer.data.result ) );
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
			{ ! state.available && (
				<Alert tone="info">
					{ __(
						'The chatbot is not set up yet. When an administrator has connected it, you can add it to your channel here.',
						'merchboard'
					) }
				</Alert>
			) }
			{ error !== null && <ApiErrorState error={ error } /> }
			<Card title={ __( 'Your Twitch channel', 'merchboard' ) }>
				{ state.channel === null ? (
					<>
						<p>
							{ __(
								"Add the bot to your channel, and it writes in your Twitch chat when someone buys your merch. You go to Twitch and allow the bot to chat in your channel; log in with your channel's account.",
								'merchboard'
							) }
						</p>
						<Button
							onClick={ connect }
							isBusy={ busy === 'connect' }
							disabled={ ! state.available || busy !== null }
						>
							{ __(
								'Add the bot to your channel',
								'merchboard'
							) }
						</Button>
					</>
				) : (
					<>
						<p>
							{ sprintf(
								/* translators: 1: bot login, 2: channel login. */
								__(
									'The bot %1$s writes in the chat of %2$s.',
									'merchboard'
								),
								state.botLogin,
								state.channel.login
							) }
						</p>
						<p className="merchboard-field__help">
							{ sprintf(
								/* translators: 1: bot login. */
								__(
									'Tip: give the bot moderator or VIP in your channel (/mod %1$s or /vip %1$s), so it may send more messages when many buy at once.',
									'merchboard'
								),
								state.botLogin
							) }
						</p>
						<div className="merchboard-alerts__actions">
							<Button
								variant="secondary"
								onClick={ test }
								isBusy={ busy === 'test' }
								disabled={ busy !== null }
							>
								{ __( 'Send test message', 'merchboard' ) }
							</Button>
							<Button
								variant="ghost"
								onClick={ disconnect }
								isBusy={ busy === 'disconnect' }
								disabled={ busy !== null }
							>
								{ __( 'Remove the bot', 'merchboard' ) }
							</Button>
						</div>
					</>
				) }
			</Card>
			<Card title={ __( 'Chat message', 'merchboard' ) }>
				<label
					className="merchboard-alerts__check"
					htmlFor={ `${ id }-enabled` }
				>
					<input
						id={ `${ id }-enabled` }
						type="checkbox"
						checked={ enabled }
						onChange={ ( event ) =>
							setEnabled( event.target.checked )
						}
					/>{ ' ' }
					{ __(
						'Write in the chat when someone buys',
						'merchboard'
					) }
				</label>
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
				{ state.lastMessage !== null && (
					<p className="merchboard-field__help">
						{ sprintf(
							/* translators: 1: status, 2: Twitch's reason or nothing. */
							__( 'Latest message: %1$s%2$s', 'merchboard' ),
							statusLabel( state.lastMessage.status ),
							state.lastMessage.reason === '' ||
								state.lastMessage.status === 'sent'
								? ''
								: ` (${ state.lastMessage.reason })`
						) }
					</p>
				) }
			</Card>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</div>
	);
}

/**
 * "Alerts → Chatbot" (phase 25, step 8; ADR-066): the creator adds the bot
 * to the own Twitch channel (Twitch's page, `channel:bot`), switches chat
 * messages on or off, edits the message and sends a test message.
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
