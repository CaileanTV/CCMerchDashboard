import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getNews, markNews } from '../../api/news';
import { useConfig } from '../../app/config-context';
import type { NewsItem } from '../../api/news';
import { Dialog } from '../../components/Dialog';
import { NewsContent } from './NewsContent';
import { newsDate } from './NewsSection';

/**
 * "What's new" (step 25.11, ADR-072): once the creator is known, the newest
 * published message they have not closed opens as a dialog, and the server
 * notes that they saw it; closing it notes that they closed it, so it never
 * opens again. Older messages stay on the page "News". Any failure is
 * silent: the dashboard works without the popup.
 */
export function WhatsNew() {
	const { locale } = useConfig();
	const [ message, setMessage ] = useState< NewsItem | null >( null );

	useEffect( () => {
		const controller = new AbortController();

		void getNews( controller.signal ).then( ( result ) => {
			const newest = result.ok ? result.data[ 0 ] : undefined;

			if ( newest === undefined || newest.closedAt !== null ) {
				return;
			}

			setMessage( newest );

			if ( newest.seenAt === null ) {
				void markNews( newest.id, 'seen' );
			}
		} );

		return () => controller.abort();
	}, [] );

	if ( message === null ) {
		return null;
	}

	const close = () => {
		setMessage( null );
		void markNews( message.id, 'closed' );
	};

	return (
		<Dialog
			title={ message.title }
			onClose={ close }
			closeLabel={ __( 'Got it', 'merchboard' ) }
		>
			<p className="merchboard-news__date">
				{ __( "What's new", 'merchboard' ) } ·{ ' ' }
				{ newsDate( message.publishedAt, locale ) }
			</p>
			<NewsContent html={ message.body } />
		</Dialog>
	);
}
