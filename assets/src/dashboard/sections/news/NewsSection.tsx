import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getNews, markNews } from '../../api/news';
import { useConfig } from '../../app/config-context';
import { ApiErrorState } from '../../components/ApiErrorState';
import { EmptyState } from '../../components/EmptyState';
import { Skeleton } from '../../components/Skeleton';
import { useApi } from '../../hooks/useApi';
import { NewsContent } from './NewsContent';

/**
 * A date in the creator's language.
 *
 * @param iso    ISO 8601 time.
 * @param locale WordPress locale, e.g. da_DK.
 */
export function newsDate( iso: string, locale: string ): string {
	try {
		return new Intl.DateTimeFormat( locale.replace( '_', '-' ), {
			dateStyle: 'long',
		} ).format( new Date( iso ) );
	} catch {
		return iso.slice( 0, 10 );
	}
}

/**
 * "News" (step 25.11, ADR-072): every published message the creator sees,
 * newest first, each folded up with its date; opening one notes that the
 * creator saw it.
 */
export function NewsSection() {
	const { locale } = useConfig();
	const { state, retry } = useApi( getNews );
	const [ opened, setOpened ] = useState< ReadonlySet< number > >(
		new Set()
	);

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading the news…', 'merchboard' ) }
				lines={ 3 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	if ( state.data.length === 0 ) {
		return (
			<EmptyState
				title={ __( 'No news yet', 'merchboard' ) }
				description={ __(
					'News about Merch Dashboard shows up here.',
					'merchboard'
				) }
			/>
		);
	}

	return (
		<div className="merchboard-news">
			{ state.data.map( ( item, index ) => (
				<details
					key={ item.id }
					className="merchboard-card merchboard-news__item"
					open={ index === 0 }
					onToggle={ ( event ) => {
						if (
							event.currentTarget.open &&
							item.seenAt === null &&
							! opened.has( item.id )
						) {
							setOpened( new Set( [ ...opened, item.id ] ) );
							void markNews( item.id, 'seen' );
						}
					} }
				>
					<summary>
						<span className="merchboard-news__title">
							{ item.title }
						</span>{ ' ' }
						<span className="merchboard-news__date">
							{ newsDate( item.publishedAt, locale ) }
						</span>
					</summary>
					<NewsContent html={ item.body } />
				</details>
			) ) }
		</div>
	);
}
