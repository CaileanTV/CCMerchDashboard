import { __ } from '@wordpress/i18n';

import type { ApiError } from '../api/client';
import { useConfig } from '../app/config-context';
import { Button } from './Button';
import { ErrorState } from './ErrorState';

export interface ApiErrorStateProps {
	readonly error: ApiError;
	readonly onRetry?: () => void;
}

/**
 * The user-facing state for a failed API call. Messages are fixed texts per
 * error kind; nothing from the server response is shown. There is no
 * automatic redirect (no redirect loops): 401 offers a link to log in again
 * and 403 a logout link, both server-provided same-site URLs. Access itself
 * is always decided by the server.
 */
export function ApiErrorState( { error, onRetry }: ApiErrorStateProps ) {
	const config = useConfig();

	switch ( error.kind ) {
		case 'unauthenticated':
			return (
				<ErrorState
					title={ __( 'Your session has expired.', 'merchboard' ) }
					description={ __(
						'Please log in again to continue.',
						'merchboard'
					) }
					action={
						<a
							className="merchboard-button merchboard-button--primary merchboard-button--md"
							href={ config.loginUrl }
						>
							{ __( 'Log in', 'merchboard' ) }
						</a>
					}
				/>
			);
		case 'forbidden':
			return (
				<ErrorState
					title={ __(
						'You do not have access to this.',
						'merchboard'
					) }
					description={ __(
						'If you think this is a mistake, please contact us.',
						'merchboard'
					) }
					action={
						<a
							className="merchboard-button merchboard-button--secondary merchboard-button--md"
							href={ config.logoutUrl }
						>
							{ __( 'Log out', 'merchboard' ) }
						</a>
					}
				/>
			);
		case 'not_found':
			return (
				<ErrorState
					title={ __( 'This could not be found.', 'merchboard' ) }
				/>
			);
		default:
			return (
				<ErrorState
					title={ __( 'Something went wrong.', 'merchboard' ) }
					description={ __(
						'Please try again in a moment.',
						'merchboard'
					) }
					action={
						onRetry === undefined ? undefined : (
							<Button variant="secondary" onClick={ onRetry }>
								{ __( 'Try again', 'merchboard' ) }
							</Button>
						)
					}
				/>
			);
	}
}
