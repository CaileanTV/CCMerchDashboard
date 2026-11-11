import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { connectChatbot, takeConnectResult } from '../../api/chatbot';
import type { ConnectPage } from '../../api/chatbot';
import type { ApiError } from '../../api/client';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Link } from '../../app/router';
import { connectMessage } from '../alerts/AlertsChatbotSection';

export interface TwitchConnectionCardProps {
	/** The connected channel's login, or null. */
	readonly channel: string | null;
	/** Whether Twitch is set up in the shop (the app and the bot). */
	readonly twitch: boolean;
	/** The page Twitch sends the creator back to. */
	readonly from: ConnectPage;
	/** Extra status after the channel (e.g. whether the creator is live). */
	readonly status?: string;
}

/**
 * The message of a connection that just came back from Twitch, or null.
 *
 * @param code Result code (`?chatbot=`).
 */
function resultMessage( code: string ): {
	readonly tone: 'success' | 'danger' | 'info';
	readonly text: string;
} | null {
	return code === 'connected'
		? {
				tone: 'success',
				text: __(
					'Your Twitch channel is connected. The bot can now work in your chat.',
					'merchboard'
				),
			}
		: connectMessage( code, 'Twitch' );
}

/**
 * The creator's Twitch connection on a community feature's page (ADR-108):
 * the connected channel or "Connect Twitch", and that the connection is
 * shared by all community features and the alert chatbot. It is removed only
 * under Alerts → Chatbot. After connecting, Twitch sends the creator back to
 * the page it was started from, which shows the outcome once.
 *
 * @param props         Props.
 * @param props.channel The connected channel login, or null.
 * @param props.twitch  Whether Twitch is set up in the shop.
 * @param props.from    The page Twitch sends the creator back to.
 * @param props.status  Extra status after the channel.
 */
export function TwitchConnectionCard( {
	channel,
	twitch,
	from,
	status,
}: TwitchConnectionCardProps ) {
	const [ connecting, setConnecting ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ result ] = useState( takeConnectResult );
	const message =
		result.platform === 'twitch' ? resultMessage( result.code ) : null;

	const connect = async () => {
		setConnecting( true );
		setError( null );
		const answer = await connectChatbot( 'twitch', false, from );

		if ( answer.ok ) {
			window.location.assign( answer.data );
			return;
		}

		setConnecting( false );
		setError( answer.error );
	};

	return (
		<section
			className="merchboard-twitch-connection"
			aria-label={ __( 'Twitch connection', 'merchboard' ) }
		>
			<h3 className="merchboard-twitch-connection__title">
				{ __( 'Twitch connection', 'merchboard' ) }
			</h3>
			{ message !== null && (
				<Alert tone={ message.tone }>{ message.text }</Alert>
			) }
			{ channel !== null && (
				<p className="merchboard-twitch-connection__status">
					{ sprintf(
						/* translators: %s: the creator's Twitch channel. */
						__( 'Connected as %s', 'merchboard' ),
						channel
					) }
					{ status !== undefined &&
						status !== '' &&
						` · ${ status }` }
				</p>
			) }
			{ channel === null && ! twitch && (
				<Alert tone="warning">
					{ __(
						'Twitch is not set up in the shop yet. Please contact us.',
						'merchboard'
					) }
				</Alert>
			) }
			{ channel === null && twitch && (
				<>
					<p className="merchboard-twitch-connection__status">
						{ __(
							'Your Twitch channel is not connected yet.',
							'merchboard'
						) }
					</p>
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
				</>
			) }
			{ error !== null && <ApiErrorState error={ error } /> }
			<p className="merchboard-field__help">
				{ __(
					'The same Twitch connection is used by all Community Features and by the alert chatbot, so you only connect once.',
					'merchboard'
				) }
				{ channel !== null && (
					<>
						{ ' ' }
						<Link to="alerts/chatbot">
							{ __(
								'You can remove it under Alerts → Chatbot.',
								'merchboard'
							) }
						</Link>
					</>
				) }
			</p>
		</section>
	);
}
